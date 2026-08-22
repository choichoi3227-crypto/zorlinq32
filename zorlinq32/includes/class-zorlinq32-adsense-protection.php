<?php
/**
 * 애드센스 부정클릭 방지 모듈.
 *
 * 애드센스 정책 준수 원칙:
 * - 이 모듈은 광고 클릭을 절대 유도, 조작, 차단하지 않습니다. 클릭 자체는 항상 정상적으로
 *   애드센스로 전달됩니다.
 * - 오직 "이후 방문 시 특정 방문자에게 광고 슬롯을 보여줄지 여부"만 게시자 권한으로 결정하며,
 *   이는 콘텐츠 노출을 통제하는 게시자의 정상적인 권한 범위입니다.
 * - 설정된 시간 내 설정된 횟수를 초과하는 클릭이 관찰되면, 강한 조작 의심 신호로 보고
 *   해당 방문자(IP + 기기 핑거프린트 조합)에게는 지정된 기간 동안 광고 슬롯을 비워 렌더링합니다.
 * - 관리자는 언제든 차단 목록에서 특정 IP를 수동으로 해제할 수 있습니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_AdSense_Protection {

	private static $instance = null;
	private $settings = array();
	private $blocked_check_cache = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = Zorlinq32_Settings::get_group( 'adsense_protection' );

		if ( empty( $this->settings['enabled'] ) ) {
			return;
		}

		Zorlinq32_AdProtect_DB::maybe_create_tables();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_observer_script' ) );
		add_action( 'wp_ajax_zorlinq32_observe_ad_click', array( $this, 'ajax_observe_click' ) );
		add_action( 'wp_ajax_nopriv_zorlinq32_observe_ad_click', array( $this, 'ajax_observe_click' ) );

		// 차단된 방문자에게는 광고 슬롯 자체를 비워서 출력합니다 (클릭을 막는 것이 아니라 노출을 막는 것).
		add_filter( 'the_content', array( $this, 'maybe_strip_ads_from_content' ), 20 );

		add_action( 'zorlinq32_adprotect_daily_cleanup', array( $this, 'cleanup_expired_blocks' ) );
	}

	/**
	 * 프론트엔드에 클릭 관찰 스크립트를 로드합니다. 차단된 방문자에게는 광고 자체가
	 * 노출되지 않으므로 관찰 스크립트를 로드할 필요도 없습니다(불필요한 요청 방지).
	 */
	public function enqueue_observer_script() {
		if ( is_admin() ) {
			return;
		}
		if ( $this->is_current_visitor_blocked() ) {
			return;
		}

		wp_enqueue_script( 'zorlinq32-adprotect-observer', ZORLINQ32_URL . 'assets/js/adprotect-observer.js', array(), ZORLINQ32_VERSION, true );
		wp_localize_script(
			'zorlinq32-adprotect-observer',
			'zorlinq32AdProtect',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'zorlinq32_adprotect_nonce' ),
			)
		);
	}

	/**
	 * 클릭 관찰 보고를 받아 기록하고, 임계값 초과 시 차단 목록에 추가합니다.
	 * 이 핸들러는 클릭을 "막는" 역할이 아니라 "집계"만 하며, 응답 여부와 무관하게
	 * 클릭은 이미 정상적으로 발생한 뒤입니다 (sendBeacon으로 비동기 전송되므로).
	 */
	public function ajax_observe_click() {
		try {
			if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'zorlinq32_adprotect_nonce' ) ) {
				wp_send_json_error( array( 'message' => 'invalid nonce' ), 403 );
			}

			$fingerprint = isset( $_POST['fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['fingerprint'] ) ) : '';
			$ip          = $this->get_client_ip();
			$ip_hash     = hash( 'sha256', $ip );
			$visitor_key = $this->build_visitor_key( $ip, $fingerprint );
			$country     = Zorlinq32_GeoIP::detect_country_code( $ip );

			global $wpdb;
			$clicks_table = Zorlinq32_AdProtect_DB::clicks_table_name();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 커스텀 클릭 관찰 테이블에 대한 단순 삽입이며 코어 API가 없습니다.
			$wpdb->insert(
				$clicks_table,
				array(
					'visitor_key'  => $visitor_key,
					'ip_hash'      => $ip_hash,
					'country_code' => $country,
					'clicked_at'   => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s' )
			);

			$this->maybe_block_visitor( $visitor_key, $ip, $ip_hash );

			wp_send_json_success();
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '애드센스 클릭 관찰 처리 중 오류: ' . $e->getMessage() );
			// 실패해도 방문자 경험에는 영향이 없어야 하므로 성공으로 응답합니다.
			wp_send_json_success();
		}
	}

	/**
	 * 설정된 탐지 시간(hour) 내 설정된 최대 클릭수를 초과했는지 확인하고,
	 * 초과 시 차단 목록에 추가합니다.
	 */
	private function maybe_block_visitor( $visitor_key, $ip, $ip_hash ) {
		global $wpdb;
		$clicks_table = Zorlinq32_AdProtect_DB::clicks_table_name();
		$blocks_table = Zorlinq32_AdProtect_DB::blocks_table_name();

		$detection_hours = max( 1, (int) $this->settings['detection_hours'] );
		$max_clicks       = max( 1, (int) $this->settings['max_clicks'] );
		$window_start     = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $detection_hours . ' hours' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 커스텀 클릭 관찰 테이블 집계 쿼리로 코어 API가 없습니다.
		$recent_click_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$clicks_table} WHERE visitor_key = %s AND clicked_at >= %s",
				$visitor_key,
				$window_start
			)
		);

		if ( $recent_click_count < $max_clicks ) {
			return;
		}

		$block_duration_days = max( 1, (int) $this->settings['block_duration_days'] );
		$expires_at           = gmdate( 'Y-m-d H:i:s', strtotime( '+' . $block_duration_days . ' days' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 커스텀 차단 목록 테이블에 대한 upsert이며 코어 API가 없습니다.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$blocks_table} (visitor_key, ip_hash, reason, blocked_at, expires_at, manual)
				VALUES (%s, %s, 'auto_click', %s, %s, 0)
				ON DUPLICATE KEY UPDATE blocked_at = VALUES(blocked_at), expires_at = VALUES(expires_at), reason = 'auto_click'",
				$visitor_key,
				$ip_hash,
				current_time( 'mysql' ),
				$expires_at
			)
		);

		// 방금 차단했으므로, 짧은 캐시가 남아있어 이후 요청에서 곧바로 차단 상태가 반영되도록 갱신합니다.
		wp_cache_set( 'blocked_' . $ip_hash, '1', 'zorlinq32_adprotect', 30 );

		// [애드센스 보호: 엣지 연동] 이 훅은 확장 지점으로 남겨둡니다. 기본 제공되는
		// Cloudflare/AWS 연동은 API 호출 없이 "검증된 헤더 기반 오리진 조기 차단"과
		// "서버 설정 스니펫 자동 생성"(관리자가 직접 복사)으로 동작하므로, 별도 API 자격증명이
		// 전혀 필요 없습니다(class-zorlinq32-edge-protection.php 참고).
		do_action( 'zorlinq32_adprotect_visitor_blocked', $ip, 'auto_click' );

		Zorlinq32_Logger::log( sprintf( '애드센스 보호: 방문자를 자동 차단했습니다 (탐지 시간 %d시간 내 %d회 초과, %d일간 차단)', $detection_hours, $recent_click_count, $block_duration_days ) );
	}

	/**
	 * 현재 요청의 방문자가 차단 대상인지 확인합니다.
	 * 자동 차단(기간 만료로 자동 해제) + 관리자가 설정한 수동 차단 IP + 차단 국가를 모두 확인합니다.
	 */
	public function is_current_visitor_blocked() {
		if ( null !== $this->blocked_check_cache ) {
			return $this->blocked_check_cache;
		}

		try {
			$ip = $this->get_client_ip();

			// 1. 관리자가 지정한 차단 국가 확인.
			if ( ! empty( $this->settings['blocked_countries'] ) ) {
				$country = Zorlinq32_GeoIP::detect_country_code( $ip );
				if ( $country && in_array( $country, $this->settings['blocked_countries'], true ) ) {
					$this->blocked_check_cache = true;
					return true;
				}
			}

			// 2. 관리자가 수동으로 지정한 차단 IP 확인.
			if ( ! empty( $this->settings['blocked_ips'] ) && in_array( $ip, $this->settings['blocked_ips'], true ) ) {
				$this->blocked_check_cache = true;
				return true;
			}

			// 3. 자동 차단(클릭 임계값 초과) 목록 확인. 기기 핑거프린트는 요청 시점에 알 수 없으므로
			// (관찰은 JS 클릭 시점에만 가능) IP 해시만으로 자동 차단 여부를 조회합니다.
			$ip_hash = hash( 'sha256', $ip );

			// [서버 자원 최적화] 같은 IP가 짧은 시간 내 여러 페이지를 연달아 요청하는 경우
			// (일반적인 탐색 패턴)마다 매번 DB 쿼리를 던지지 않도록, 오브젝트 캐시(30초)로
			// 조회 결과를 재사용합니다. 외부 오브젝트 캐시(Redis 등)가 없는 환경에서도
			// wp_cache_*는 요청 내 캐시로 동작하므로 최소한 안전합니다.
			$cache_group = 'zorlinq32_adprotect';
			$cache_key   = 'blocked_' . $ip_hash;
			$cached      = wp_cache_get( $cache_key, $cache_group );

			if ( false !== $cached ) {
				$this->blocked_check_cache = ( '1' === $cached );
				return $this->blocked_check_cache;
			}

			global $wpdb;
			$blocks_table = Zorlinq32_AdProtect_DB::blocks_table_name();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 커스텀 차단 목록 테이블 조회로 코어 API가 없습니다.
			$blocked = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$blocks_table} WHERE ip_hash = %s AND (expires_at IS NULL OR expires_at > %s)",
					$ip_hash,
					current_time( 'mysql' )
				)
			);

			$this->blocked_check_cache = ( $blocked > 0 );
			wp_cache_set( $cache_key, $this->blocked_check_cache ? '1' : '0', $cache_group, 30 );

			return $this->blocked_check_cache;
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '차단 여부 확인 중 오류: ' . $e->getMessage() );
			// 판단 실패 시 광고를 막지 않는 쪽(false)을 기본값으로 하여, 정상 방문자의
			// 광고 노출(수익)에 지장을 주지 않도록 안전한 방향으로 폴백합니다.
			$this->blocked_check_cache = false;
			return false;
		}
	}

	/**
	 * 차단된 방문자의 콘텐츠에서 애드센스 광고 코드를 제거합니다.
	 * 광고 스크립트/클릭 자체를 조작하는 것이 아니라, 차단 대상에게는 애초에
	 * 광고 슬롯을 렌더링하지 않는 방식입니다 (콘텐츠 노출 범위는 게시자의 정상적인 권한입니다).
	 */
	public function maybe_strip_ads_from_content( $content ) {
		try {
			if ( is_admin() || ! is_singular() ) {
				return $content;
			}
			if ( ! $this->is_current_visitor_blocked() ) {
				return $content;
			}

			// <ins class="adsbygoogle">...</ins> 블록과 관련 인라인 스크립트 호출을 제거합니다.
			$content = preg_replace( '/<ins[^>]*class=["\'][^"\']*adsbygoogle[^"\']*["\'][^>]*>.*?<\/ins>/is', '', $content );
			return $content;
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '차단 방문자 광고 제거 중 오류: ' . $e->getMessage() );
			return $content;
		}
	}

	/**
	 * IP와 기기 핑거프린트를 결합한 방문자 식별 키를 생성합니다.
	 * NAT/공유 IP 환경(회사, 카페, 통신사 대역 공유)에서 같은 IP를 쓰는 여러 사용자를
	 * 구분해, 한 사람의 반복 클릭이 아닌데 오차단되는 상황을 줄입니다.
	 */
	private function build_visitor_key( $ip, $fingerprint ) {
		$fingerprint = ! empty( $fingerprint ) ? $fingerprint : 'no-fp';
		return hash( 'sha256', $ip . '|' . $fingerprint );
	}

	/**
	 * [애드센스 보호 개선] 기존에는 CF-Connecting-IP / X-Forwarded-For 헤더를 검증 없이
	 * 그대로 신뢰했습니다. 이 헤더들은 클라이언트가 직접 위조해 보낼 수 있는 값이라,
	 * 공격자가 임의의 IP를 넣어 차단을 우회하거나 무고한 제3자의 IP를 대신 차단시킬 수
	 * 있는 보안 허점이었습니다. 이제는 요청이 실제로 신뢰 가능한 경로(검증된 Cloudflare
	 * 엣지 대역, 또는 AWS CloudFront)를 거쳐 왔을 때만 해당 헤더를 신뢰합니다.
	 */
	private function get_client_ip() {
		if ( class_exists( 'Zorlinq32_Edge_Protection' ) ) {
			return Zorlinq32_Edge_Protection::get_trusted_client_ip();
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
	}

	/**
	 * 만료된 자동 차단 기록과 오래된 클릭 로그를 정리합니다.
	 */
	public function cleanup_expired_blocks() {
		try {
			global $wpdb;
			$blocks_table = Zorlinq32_AdProtect_DB::blocks_table_name();
			$clicks_table = Zorlinq32_AdProtect_DB::clicks_table_name();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 커스텀 테이블 정리 쿼리로 코어 API가 없습니다.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$blocks_table} WHERE manual = 0 AND expires_at IS NOT NULL AND expires_at <= %s",
					current_time( 'mysql' )
				)
			);

			// 클릭 로그는 탐지 윈도우 계산에만 쓰이므로 오래(예: 30일) 보관할 필요가 없습니다.
			$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$clicks_table} WHERE clicked_at < %s", $cutoff ) );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '애드센스 보호 정리 작업 중 오류: ' . $e->getMessage() );
		}
	}

	/**
	 * 관리자 화면에서 특정 IP를 수동으로 즉시 차단 해제합니다.
	 */
	public static function unblock_ip( $ip ) {
		global $wpdb;
		$blocks_table = Zorlinq32_AdProtect_DB::blocks_table_name();
		$ip_hash      = hash( 'sha256', $ip );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 커스텀 차단 목록 테이블에 대한 삭제이며 코어 API가 없습니다.
		$result = $wpdb->delete( $blocks_table, array( 'ip_hash' => $ip_hash ), array( '%s' ) );
		// 차단 여부 조회 결과를 캐시하고 있으므로, 해제 즉시 반영되도록 캐시도 함께 지웁니다.
		wp_cache_delete( 'blocked_' . $ip_hash, 'zorlinq32_adprotect' );
		// [애드센스 보호: 엣지 연동] 확장 지점 - 필요 시 추가 연동에서 사용할 수 있습니다.
		do_action( 'zorlinq32_adprotect_visitor_unblocked', $ip );
		return $result;
	}

	/**
	 * 현재 자동 차단 중인 목록을 관리자 화면에 표시하기 위해 반환합니다.
	 * 원본 IP는 저장하지 않으므로(해시만 저장) 목록에는 IP 원문 대신 해시 일부만 표시됩니다.
	 */
	public static function get_active_blocks( $limit = 50 ) {
		try {
			global $wpdb;
			$blocks_table = Zorlinq32_AdProtect_DB::blocks_table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 커스텀 차단 목록 테이블 조회로 코어 API가 없습니다.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$blocks_table} WHERE expires_at IS NULL OR expires_at > %s ORDER BY blocked_at DESC LIMIT %d",
					current_time( 'mysql' ),
					$limit
				),
				ARRAY_A
			);
			return is_array( $rows ) ? $rows : array();
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '차단 목록 조회 중 오류: ' . $e->getMessage() );
			return array();
		}
	}
}
