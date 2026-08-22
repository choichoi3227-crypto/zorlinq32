<?php
/**
 * 애드센스 보호: 엣지(CDN) 신호 활용 모듈.
 *
 * [핵심 원칙] 이 모듈은 Cloudflare/AWS를 절대 "사이트 접속 차단"에 사용하지 않습니다.
 * 방문자는 어떤 경우에도 사이트 전체를 정상적으로 열람할 수 있으며, 부정클릭이
 * 의심되는 경우에도 오직 "광고 영역만 콘텐츠에서 소거"됩니다(class-zorlinq32-adsense-protection.php
 * 의 maybe_strip_ads_from_content()가 담당). 이 모듈이 하는 일은 오직 다음 하나입니다:
 *
 * Cloudflare 또는 AWS(CloudFront)가 검증된 헤더로 알려주는 "실제 접속 국가/IP" 정보를
 * 신뢰해, 광고를 소거할지 판단하는 근거(국가 차단 목록 대조, 동일 방문자 식별)의
 * 정확도를 높입니다. 헤더가 없거나 신뢰할 수 없는 환경에서는 서버가 직접 관측한
 * 접속 정보(REMOTE_ADDR)로 안전하게 대체됩니다.
 *
 * [설계 원칙] API 키 없이 동작합니다. 대부분의 매니지드 워드프레스 호스팅
 * (카페24, 닷홈, 가비아, Bluehost, WP Engine, Kinsta 등)은 이용자에게 AWS나
 * Cloudflare의 API 자격증명을 발급해주지 않으며, 호스팅사 자체가 Cloudflare/AWS와
 * 파트너십으로 앞단을 구성해두는 경우가 대부분입니다. 검증된 헤더만으로 충분하므로
 * API 호출 자체가 필요 없습니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Edge_Protection {

	private static $instance = null;
	private $settings = array();

	/**
	 * Cloudflare가 공개적으로 발행하는 엣지 IP 대역입니다. 이 대역에서 온 요청만
	 * CF-Connecting-IP / CF-IPCountry 헤더를 신뢰합니다. 그렇지 않으면 공격자가
	 * 이 헤더들을 위조해 실제와 다른 국가/IP로 속일 수 있습니다.
	 * (출처: Cloudflare 공식 IP 목록, https://www.cloudflare.com/ips/)
	 */
	const CLOUDFLARE_IPV4_RANGES = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
	);

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

		// [자원 최적화] 이미 광고가 소거된(부정클릭 의심으로 판정된) 방문자가 광고 클릭
		// 관찰 AJAX 요청을 계속 보내도, 더 이상 관찰할 광고가 없으므로 클릭을 다시 기록할
		// 필요가 없습니다. 이 요청만 아주 이른 시점에 짧게 종료해 불필요한 DB 쓰기와
		// 나머지 워드프레스 부트스트랩 비용을 아낍니다. 페이지 열람 자체는 전혀 건드리지
		// 않습니다 - 접속을 막는 로직이 아닙니다.
		// 주의: 이 클래스 자체가 plugins_loaded(우선순위 10)에서 초기화되므로, 여기서
		// plugins_loaded에 더 이른 우선순위로 다시 걸어도 이번 요청 사이클에서는 실행되지
		// 않습니다(이미 지나간 우선순위는 건너뜀). 그래서 plugins_loaded 이후 별도로
		// 발생하는 init 훅을 사용합니다.
		add_action( 'init', array( $this, 'maybe_short_circuit_click_observer' ), 1 );
	}

	/**
	 * 이미 광고가 소거된 방문자가 보낸 클릭 관찰 AJAX 요청을 조기에 짧게 종료합니다.
	 * 그 외 모든 요청(일반 페이지 열람 포함)은 절대 건드리지 않고 그대로 진행시킵니다.
	 */
	public function maybe_short_circuit_click_observer() {
		try {
			if ( ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
				return; // AJAX 요청이 아니면 관여하지 않습니다.
			}
			$action = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';
			if ( 'zorlinq32_observe_ad_click' !== $action ) {
				return;
			}
			if ( ! class_exists( 'Zorlinq32_AdSense_Protection' ) ) {
				return;
			}
			$protection = Zorlinq32_AdSense_Protection::instance();
			if ( ! $protection->is_current_visitor_blocked() ) {
				return; // 아직 광고가 소거되지 않은 방문자의 클릭은 정상적으로 관찰(기록)되어야 합니다.
			}
			// 이미 광고가 소거된 방문자입니다 - 별도 처리(DB 기록) 없이 조용히 성공 응답만 반환합니다.
			wp_send_json_success();
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '클릭 관찰 조기 종료 확인 중 오류: ' . $e->getMessage() );
			// 실패해도 일반 요청 흐름에 영향이 없도록 그냥 반환합니다(뒤이어 정상 처리 경로가 실행됨).
		}
	}

	/* ==================== 환경 자동 감지 (API 불필요) ==================== */

	/**
	 * 현재 요청이 신뢰 가능한 Cloudflare 엣지를 거쳐 왔는지 확인합니다.
	 * (헤더 존재 여부만이 아니라, 실제 발신 IP가 Cloudflare 공식 대역에 속하는지까지 검증합니다.)
	 */
	public static function is_behind_cloudflare() {
		if ( empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && empty( $_SERVER['HTTP_CF_RAY'] ) ) {
			return false;
		}
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( empty( $remote_addr ) ) {
			return false;
		}
		foreach ( self::CLOUDFLARE_IPV4_RANGES as $cidr ) {
			if ( self::ip_in_cidr( $remote_addr, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 현재 요청이 AWS CloudFront를 거쳐 왔는지 확인합니다.
	 * CloudFront가 붙이는 표준 헤더(X-Amz-Cf-Id)의 존재로 판별합니다. 이 헤더는
	 * CloudFront가 오리진에 전달하기 직전 항상 덮어쓰는 값으로, 최종 사용자가 직접
	 * 조작해 오리진까지 그대로 도달시킬 수 없는 헤더입니다(신뢰 가능).
	 */
	public static function is_behind_cloudfront() {
		return ! empty( $_SERVER['HTTP_X_AMZ_CF_ID'] );
	}

	/**
	 * "서버가 AWS인지"를 함께 판단해, 설정 화면에서 "자동 지원" 배지를 보여줄지 결정합니다.
	 * API 키가 전혀 필요 없는 두 신호만 봅니다: (1) CloudFront를 거쳐 왔는지,
	 * (2) EC2/Lightsail 등 AWS 인스턴스 메타데이터 서비스에 짧은 타임아웃으로 접근
	 * 가능한지(자체 서버 존재 여부 확인용, 자격증명 불필요). 결과를 캐시해 매 요청마다
	 * 재확인하지 않습니다.
	 */
	public static function is_aws_environment_detected() {
		$cached = get_transient( 'zorlinq32_aws_env_detected' );
		if ( false !== $cached ) {
			return ( '1' === $cached );
		}

		$detected = self::is_behind_cloudfront();

		if ( ! $detected ) {
			$detected = self::probe_aws_instance_metadata();
		}

		set_transient( 'zorlinq32_aws_env_detected', $detected ? '1' : '0', DAY_IN_SECONDS );
		return $detected;
	}

	/**
	 * AWS 인스턴스 메타데이터 서비스(IMDSv1, 169.254.169.254)에 매우 짧은 타임아웃으로
	 * 한 번 접근을 시도합니다. 자격증명이 전혀 필요 없는 "이 서버가 AWS 인스턴스인가"
	 * 확인용이며, AWS 환경이 아니면 대부분 즉시 실패합니다. 이 실패가 사이트 동작에
	 * 전혀 영향을 주지 않도록 항상 예외를 흡수합니다.
	 */
	private static function probe_aws_instance_metadata() {
		try {
			$response = wp_remote_get(
				'http://169.254.169.254/latest/meta-data/',
				array(
					'timeout'   => 1,
					'blocking'  => true,
					'sslverify' => false,
				)
			);
			if ( is_wp_error( $response ) ) {
				return false;
			}
			return 200 === (int) wp_remote_retrieve_response_code( $response );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/* ==================== 신뢰 가능한 클라이언트 IP 판별 (광고 소거 판단 근거로만 사용) ==================== */

	/**
	 * 검증된 소스에서만 실제 클라이언트 IP를 신뢰해 반환합니다.
	 * 스푸핑 가능한 X-Forwarded-For를 무조건 신뢰하던 기존 방식의 보안 허점을 보완합니다:
	 * - Cloudflare 대역에서 온 요청만 CF-Connecting-IP를 신뢰
	 * - CloudFront를 거친 요청만 CloudFront-Viewer-Address를 신뢰
	 * - 둘 다 아니면 REMOTE_ADDR(서버가 직접 관측한 실제 접속 IP)을 사용
	 *
	 * [중요] 이 값은 사이트 접속 허용/차단을 결정하는 데 절대 쓰이지 않습니다. 오직
	 * "부정클릭이 의심되는 동일 방문자를 식별해 그 방문자에게만 광고를 숨길지"를
	 * 판단하는 근거로만 사용됩니다(class-zorlinq32-adsense-protection.php).
	 */
	public static function get_trusted_client_ip() {
		if ( self::is_behind_cloudflare() && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			return trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) );
		}

		if ( self::is_behind_cloudfront() && ! empty( $_SERVER['HTTP_CLOUDFRONT_VIEWER_ADDRESS'] ) ) {
			// 형식: "203.0.113.10:54321" (IP:포트) - 포트 부분을 제거합니다.
			$value = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLOUDFRONT_VIEWER_ADDRESS'] ) );
			$ip    = preg_replace( '/:\d+$/', '', $value );
			return trim( $ip );
		}

		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
	}

	private static function ip_in_cidr( $ip, $cidr ) {
		$parts = explode( '/', $cidr );
		if ( count( $parts ) !== 2 ) {
			return false;
		}
		list( $subnet, $bits ) = $parts;
		$bits = (int) $bits;
		if ( $bits < 0 || $bits > 32 ) {
			return false;
		}
		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );
		if ( false === $ip_long || false === $subnet_long ) {
			return false;
		}
		$mask = ( 0 === $bits ) ? 0 : ( ~0 << ( 32 - $bits ) );
		return ( $ip_long & $mask ) === ( $subnet_long & $mask );
	}
}
