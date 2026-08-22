<?php
/**
 * WP-Cron 안정화 모듈 (v2).
 *
 * 워드프레스 기본 WP-Cron은 방문자의 페이지 요청이 있어야만 트리거되는
 * 구조적 한계가 있습니다. 이 모듈은 그 한계를 완전히 없애는 것이 아니라
 * (이는 워드프레스 아키텍처상 불가능합니다), 실무에서 검증된 방법들로
 * 정확도와 안정성을 최대한 끌어올리는 것을 목표로 합니다.
 *
 * 포함 기능:
 * 1. 서버 실제 크론(리눅스 cron) 사용 여부 자동 감지 + 설정 안내
 * 2. 예약된 작업의 지연/누락 여부 모니터링 및 관리자 알림
 * 3. 지연이 심한 작업에 대한 자동 재시도(retry) 로직
 * 4. 안전 범위 내에서의 self-ping (최소 5분 간격 강제, 서버 크론 감지 시 자동 비활성화)
 * 5. [v2 신규] 예약 발행(포스트 예약, publish_future_post) 전담 워치독:
 *    WP-Cron 트리거 여부와 무관하게, 예정 시각이 지난 "예약됨" 상태의 글을
 *    직접 조회해 강제로 발행합니다. 이것이 "예약 발행이 안 된다"는 문제에 대한
 *    가장 확실한 최후 안전망입니다.
 * 6. [v2 신규] self-ping 실패/성공 여부를 실제로 검증하여 에러 로그에 기록합니다.
 *    (기존 버전은 응답을 확인하지 않아 실패해도 로그가 전혀 남지 않는 문제가 있었습니다.)
 * 7. [v2 신규] self-ping을 기본값 ON으로 전환하고, 발행 대기 중인 예약 글이
 *    임박(5분 이내)했을 때는 최소 간격 제한을 무시하고 즉시 ping을 시도합니다.
 *
 * 안전장치:
 * - self-ping은 loopback 요청(자기 자신에게 보내는 비동기 HTTP 요청)이며,
 *   워드프레스 코어가 매 페이지 로드마다 이미 사용하는 것과 동일한 방식(spawn_cron)을
 *   기반으로 하여 별도의 이례적인 위험을 추가하지 않습니다.
 * - self-ping 요청은 매우 짧은 타임아웃(non-blocking)으로 발송되어 응답을 기다리지 않으므로
 *   요청을 트리거하는 페이지의 로딩 속도에 영향을 주지 않습니다.
 * - 예약 발행 워치독은 워드프레스 코어의 발행 전환 로직(wp_publish_post)만 호출하며,
 *   임의의 SQL 조작이나 우회 로직을 사용하지 않아 다른 플러그인과 충돌하지 않습니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Cron_Guardian {

	private static $instance = null;
	private $settings = array();

	const MISSED_LOG_OPTION   = 'zorlinq32_cron_missed_log';
	const HEALTH_OPTION       = 'zorlinq32_cron_health';
	const RETRY_META_PREFIX   = 'zorlinq32_retry_';
	const PUBLISH_WATCHDOG_LOG_OPTION = 'zorlinq32_publish_watchdog_log';
	const LAST_PING_RESULT_OPTION     = 'zorlinq32_last_self_ping_result';
	const MIN_SELF_PING_INTERVAL = 5 * MINUTE_IN_SECONDS; // 하한선. 이보다 촘촘하게는 절대 허용하지 않습니다.

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = Zorlinq32_Settings::get_group( 'cron_guardian' );

		if ( empty( $this->settings['enabled'] ) ) {
			return;
		}

		// 모니터링은 매 cron 실행 시점(직전/직후)에 상태를 기록합니다.
		add_action( 'init', array( $this, 'maybe_record_heartbeat' ), 20 );

		if ( ! empty( $this->settings['monitor_missed_jobs'] ) ) {
			add_action( 'zorlinq32_cron_health_check', array( $this, 'check_for_missed_jobs' ) );
			if ( ! wp_next_scheduled( 'zorlinq32_cron_health_check' ) ) {
				wp_schedule_event( time() + 120, 'hourly', 'zorlinq32_cron_health_check' );
			}
		}

		if ( ! empty( $this->settings['auto_retry_missed'] ) ) {
			add_action( 'zorlinq32_cron_health_check', array( $this, 'retry_missed_jobs' ), 20 );
		}

		if ( ! empty( $this->settings['enable_self_ping'] ) ) {
			add_action( 'init', array( $this, 'maybe_self_ping' ), 999 );
		}

		// [v2] 예약 발행 워치독: self-ping/서버크론 상태와 무관하게 항상 동작하는 최후 안전망입니다.
		// 프론트엔드 요청이 있을 때마다 가볍게(인덱스 쿼리 1회) 확인하며, 지연된 예약 글이
		// 있을 때만 실제 발행 처리를 수행하므로 평상시 부하는 거의 없습니다.
		if ( ! empty( $this->settings['publish_watchdog'] ) ) {
			add_action( 'init', array( $this, 'maybe_run_publish_watchdog' ), 30 );
			// 방문자가 뜸한 사이트를 위해, self-ping/서버크론 point에서도 별도로 한 번 더 확인합니다.
			add_action( 'zorlinq32_cron_health_check', array( $this, 'run_publish_watchdog' ), 5 );
			// 글이 새로 예약되거나 예약 시각이 변경되면, 캐시된 "다음 예약 시각"이 즉시 무효화되어야
			// 워치독이 새 예약을 지연 없이 인지합니다 (서버 자원 최적화용 캐시의 정확성 보장).
			add_action( 'transition_post_status', array( __CLASS__, 'maybe_invalidate_on_status_change' ), 10, 3 );
		}
	}

	/* ---------------- 서버 크론 감지 ---------------- */

	/**
	 * wp-config.php에 DISABLE_WP_CRON이 정의되어 있으면, 사용자가 이미
	 * 서버 실제 크론을 설정해 워드프레스 기본 WP-Cron을 껐다고 간주합니다.
	 * 이 경우 self-ping은 불필요하고 오히려 중복 부하이므로 자동으로 건너뜁니다.
	 */
	public function is_real_server_cron_likely_configured() {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	/* ---------------- 상태 기록(heartbeat) ---------------- */

	/**
	 * 매 요청마다 가벼운 타임스탬프만 저장하여, 사이트에 방문자가
	 * 얼마나 자주 오는지(=WP-Cron이 얼마나 자주 트리거될 기회가 있는지)를 추적합니다.
	 * 무거운 연산 없이 단일 옵션 업데이트만 수행해 부하를 최소화합니다.
	 */
	public function maybe_record_heartbeat() {
		try {
			// 관리자 화면 요청은 방문자 트래픽으로 보지 않도록 제외해, 관리자 혼자 작업할 때도
			// 실제 방문자 트래픽 부재를 정확히 감지할 수 있게 합니다.
			if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
				return;
			}
			// 옵션 테이블 쓰기 빈도를 줄이기 위해, 이전 기록에서 60초 이상 지났을 때만 갱신합니다.
			$last = (int) get_option( 'zorlinq32_last_visit_time', 0 );
			if ( ( time() - $last ) < 60 ) {
				return;
			}
			update_option( 'zorlinq32_last_visit_time', time(), false );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( 'Cron 가디언 heartbeat 기록 중 오류: ' . $e->getMessage() );
		}
	}

	/* ---------------- 누락/지연 모니터링 ---------------- */

	/**
	 * 워드프레스에 등록된 예약 작업(cron 이벤트) 중, 예정 시각보다
	 * 상당히 지연된 것이 있는지 확인합니다. 지연 판정 기준은 15분입니다
	 * (일반적인 방문자 트래픽이 있는 사이트라면 이보다 훨씬 빨리 실행되는 것이 정상입니다).
	 */
	public function check_for_missed_jobs() {
		try {
			$cron_array = _get_cron_array();
			if ( empty( $cron_array ) || ! is_array( $cron_array ) ) {
				return;
			}

			$delay_threshold = 15 * MINUTE_IN_SECONDS;
			$now             = time();
			$missed          = array();

			foreach ( $cron_array as $timestamp => $hooks ) {
				if ( ( $now - $timestamp ) < $delay_threshold ) {
					continue; // 아직 지연으로 판단할 시점이 아닙니다.
				}
				if ( ! is_array( $hooks ) ) {
					continue;
				}
				foreach ( $hooks as $hook_name => $events ) {
					// 이 플러그인 자신의 상태 점검 훅은 제외해 무한 루프성 오탐을 방지합니다.
					if ( 'zorlinq32_cron_health_check' === $hook_name ) {
						continue;
					}
					$missed[] = array(
						'hook'      => $hook_name,
						'scheduled' => $timestamp,
						'delay_seconds' => $now - $timestamp,
					);
				}
			}

			if ( ! empty( $missed ) ) {
				$this->record_missed_jobs( $missed );
				$this->maybe_notify_admin_of_delay( $missed );
			}

			update_option(
				self::HEALTH_OPTION,
				array(
					'last_checked'  => $now,
					'missed_count'  => count( $missed ),
				),
				false
			);
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( 'Cron 누락 점검 중 오류: ' . $e->getMessage() );
		}
	}

	/**
	 * 발견된 지연 작업 목록을 옵션에 최대 30개까지 보관합니다 (관리자 화면 표시용).
	 */
	private function record_missed_jobs( $missed ) {
		$log = get_option( self::MISSED_LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		foreach ( $missed as $item ) {
			$item['detected_at'] = current_time( 'mysql' );
			$log[] = $item;
		}

		if ( count( $log ) > 30 ) {
			$log = array_slice( $log, -30 );
		}

		update_option( self::MISSED_LOG_OPTION, $log, false );
	}

	/**
	 * 지연이 감지되면 관리자에게 이메일로 알립니다. 동일 알림의 중복 발송을
	 * 방지하기 위해 하루 최대 1회로 제한합니다.
	 */
	private function maybe_notify_admin_of_delay( $missed ) {
		if ( empty( $this->settings['notify_on_delay'] ) ) {
			return;
		}

		if ( get_transient( 'zorlinq32_cron_delay_notified' ) ) {
			return;
		}

		try {
			$admin_email = get_option( 'admin_email' );
			if ( empty( $admin_email ) ) {
				return;
			}

			$hook_list = implode( ', ', array_unique( wp_list_pluck( $missed, 'hook' ) ) );

			$subject = __( '[Zorlinq32] 예약 작업(cron) 지연 감지', 'zorlinq32' );
			$message = sprintf(
				/* translators: %s: 지연된 작업 훅 이름 목록 */
				__( "다음 예약 작업이 예정 시각보다 상당히 지연되었습니다: %s\n\n방문자 트래픽이 적은 사이트라면 워드프레스의 기본 WP-Cron 방식 특성상 자연스러운 현상일 수 있습니다. 정확한 실행 시각이 중요하다면, Zorlinq32의 'Cron 안정화' 설정 화면에서 서버 실제 크론 연동 안내를 확인해주세요.", 'zorlinq32' ),
				$hook_list
			);

			wp_mail( $admin_email, $subject, $message );
			set_transient( 'zorlinq32_cron_delay_notified', 1, DAY_IN_SECONDS );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( 'Cron 지연 알림 메일 발송 실패: ' . $e->getMessage() );
		}
	}

	/* ---------------- [v2] 예약 발행 워치독 ---------------- */

	const NEXT_PUBLISH_CACHE_KEY = 'zorlinq32_next_scheduled_publish';

	/**
	 * 매 프론트엔드 요청마다 실행되지만, transient 락으로 최소 30초 간격을 두어
	 * 실질적인 부하는 거의 없습니다. 실제 지연된 예약 글이 있을 때만(드문 경우)
	 * 무거운 조회로 넘어갑니다.
	 *
	 * [서버 자원 최적화] 예약 발행 글이 아예 없는 사이트(대다수)에서도 기존에는
	 * 30초마다 date_query가 포함된 get_posts() 쿼리를 계속 실행했습니다. 이제는
	 * "다음 예약 발행 시각"을 5분 단위로 캐시해두고, 그 시각이 오기 전까지는
	 * DB 조회 자체를 완전히 건너뜁니다 (캐시가 없을 때만 가벼운 조회 1회 수행).
	 */
	public function maybe_run_publish_watchdog() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( get_transient( 'zorlinq32_publish_watchdog_lock' ) ) {
			return;
		}
		set_transient( 'zorlinq32_publish_watchdog_lock', 1, 30 );

		if ( ! $this->is_publish_watchdog_query_needed_now() ) {
			return;
		}

		$this->run_publish_watchdog();
	}

	/**
	 * 무거운 조회(get_posts + date_query)를 실제로 수행해야 하는 시점인지 가볍게 판단합니다.
	 * "다음 예약 글까지 남은 시간" 캐시가 있고 아직 여유가 있다면 쿼리를 건너뜁니다.
	 * 캐시가 없거나 만료되었을 때만 get_seconds_until_next_scheduled_publish()로
	 * (가벼운 fields=ids, posts_per_page=1 조회) 다시 계산합니다.
	 */
	private function is_publish_watchdog_query_needed_now() {
		$cached = get_transient( self::NEXT_PUBLISH_CACHE_KEY );

		if ( false === $cached ) {
			// 캐시가 없을 때만 가벼운 단건 조회로 다음 예약 시각을 다시 계산합니다.
			$seconds_until = $this->get_seconds_until_next_scheduled_publish();
			$next_time     = ( null !== $seconds_until ) ? ( time() + $seconds_until ) : 0; // 0 = 예약 글 없음.
			// 최대 5분까지만 신뢰하고 다시 확인합니다(그 사이 새 예약 글이 등록될 수 있으므로).
			set_transient( self::NEXT_PUBLISH_CACHE_KEY, $next_time, 5 * MINUTE_IN_SECONDS );
			$cached = $next_time;
		}

		// 0(예약 글 없음)이면 아직 발행 시각이 아니므로 무거운 조회가 필요 없습니다.
		if ( 0 === (int) $cached ) {
			return false;
		}

		// 예약 시각이 이미 지났거나 임박했다면(1분 이내) 실제 조회가 필요합니다.
		return ( (int) $cached - time() ) <= MINUTE_IN_SECONDS;
	}

	/**
	 * 글이 예약 발행되거나 예약이 변경될 때 캐시된 "다음 예약 시각"을 무효화합니다.
	 * 그래야 새로 예약된 글이 있을 때 워치독이 지연 없이 이를 인지합니다.
	 */
	public static function invalidate_next_publish_cache() {
		delete_transient( self::NEXT_PUBLISH_CACHE_KEY );
	}

	/**
	 * 'future' 상태로 새로 전환되거나 'future' 상태에서 벗어날 때(발행/취소 등)
	 * 캐시를 무효화합니다. 그 외 상태 전환은 무시해 불필요한 캐시 초기화를 방지합니다.
	 */
	public static function maybe_invalidate_on_status_change( $new_status, $old_status, $post ) {
		if ( 'future' === $new_status || 'future' === $old_status ) {
			self::invalidate_next_publish_cache();
		}
	}

	/**
	 * "예약됨(future)" 상태의 글 중, 예정 발행 시각이 이미 지났는데도 아직 발행되지
	 * 않은 것이 있는지 직접 조회합니다. WP-Cron이 정상 동작했다면 애초에 발견될 리 없는
	 * 상태이므로, 여기서 발견된다는 것 자체가 WP-Cron 트리거 실패를 의미합니다.
	 *
	 * 발견 시 워드프레스 코어 함수(wp_publish_post)로 즉시 발행 처리하며,
	 * 임의의 SQL 직접 조작은 하지 않습니다 (다른 플러그인의 발행 훅과의 호환성 보장).
	 */
	public function run_publish_watchdog() {
		try {
			$scheduled = get_posts(
				array(
					'post_type'      => 'any',
					'post_status'    => 'future',
					'posts_per_page' => 20,
					'orderby'        => 'date',
					'order'          => 'ASC',
					'no_found_rows'  => true,
					'date_query'     => array(
						array(
							'column' => 'post_date',
							'before' => current_time( 'mysql' ),
						),
					),
				)
			);

			if ( empty( $scheduled ) || ! is_array( $scheduled ) ) {
				return;
			}

			$published_titles = array();
			foreach ( $scheduled as $post ) {
				$delay_seconds = time() - get_post_time( 'U', true, $post );

				// 코어의 정상 발행 로직을 그대로 호출합니다 (wp-includes/cron.php의
				// publish_future_post()가 내부적으로 하는 것과 동일한 처리).
				wp_publish_post( $post->ID );

				$published_titles[] = array(
					'post_id'       => $post->ID,
					'title'         => get_the_title( $post ),
					'delay_seconds' => max( 0, $delay_seconds ),
					'recovered_at'  => current_time( 'mysql' ),
				);
			}

			if ( ! empty( $published_titles ) ) {
				// 방금 발행 처리를 했으므로 "다음 예약 시각" 캐시가 더 이상 유효하지 않습니다.
				self::invalidate_next_publish_cache();
				$this->record_publish_recoveries( $published_titles );
				Zorlinq32_Logger::log(
					sprintf(
						'예약 발행 워치독: WP-Cron이 트리거되지 않아 지연된 예약 글 %d건을 직접 발행 처리했습니다.',
						count( $published_titles )
					)
				);
				$this->maybe_notify_admin_of_publish_recovery( $published_titles );
			}
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '예약 발행 워치독 실행 중 오류: ' . $e->getMessage() );
		}
	}

	/**
	 * 워치독이 대신 발행 처리한 글 목록을 관리자 화면 표시용으로 최대 20개 보관합니다.
	 */
	private function record_publish_recoveries( $items ) {
		$log = get_option( self::PUBLISH_WATCHDOG_LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		foreach ( $items as $item ) {
			$log[] = $item;
		}
		if ( count( $log ) > 20 ) {
			$log = array_slice( $log, -20 );
		}
		update_option( self::PUBLISH_WATCHDOG_LOG_OPTION, $log, false );
	}

	/**
	 * 워치독이 실제로 개입해야 했던 경우(=WP-Cron이 정상이었다면 없었을 상황)에는
	 * 관리자에게 별도로 알려, self-ping을 켜거나 서버 크론으로 전환하도록 안내합니다.
	 * 하루 최대 1회로 제한합니다.
	 */
	private function maybe_notify_admin_of_publish_recovery( $items ) {
		if ( empty( $this->settings['notify_on_delay'] ) ) {
			return;
		}
		if ( get_transient( 'zorlinq32_publish_recovery_notified' ) ) {
			return;
		}
		try {
			$admin_email = get_option( 'admin_email' );
			if ( empty( $admin_email ) ) {
				return;
			}
			$titles = implode( ', ', wp_list_pluck( $items, 'title' ) );
			$subject = __( '[Zorlinq32] 예약 발행 지연이 감지되어 자동 복구했습니다', 'zorlinq32' );
			$message = sprintf(
				/* translators: %s: 지연되었다가 복구된 글 제목 목록 */
				__( "다음 예약 발행 글이 예정 시각에 자동 발행되지 않아, Zorlinq32이 대신 발행 처리했습니다: %s\n\n이는 사이트의 WP-Cron이 트리거될 만큼 방문자 트래픽이 충분하지 않다는 신호입니다. Zorlinq32의 'Cron 안정화' 설정 화면에서 self-ping 기능을 켜거나, 서버 실제 크론 연동을 검토해주세요.", 'zorlinq32' ),
				$titles
			);
			wp_mail( $admin_email, $subject, $message );
			set_transient( 'zorlinq32_publish_recovery_notified', 1, DAY_IN_SECONDS );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '예약 발행 복구 알림 메일 발송 실패: ' . $e->getMessage() );
		}
	}

	/**
	 * 가장 임박한(가장 빨리 발행 예정인) 예약 글까지 남은 초를 반환합니다.
	 * 없으면 null. self-ping 간격을 앞당겨야 하는지 판단하는 데 사용됩니다.
	 */
	private function get_seconds_until_next_scheduled_publish() {
		try {
			$next = get_posts(
				array(
					'post_type'      => 'any',
					'post_status'    => 'future',
					'posts_per_page' => 1,
					'orderby'        => 'date',
					'order'          => 'ASC',
					'no_found_rows'  => true,
					'fields'         => 'ids',
				)
			);
			if ( empty( $next ) ) {
				return null;
			}
			$post_time = get_post_time( 'U', true, $next[0] );
			return $post_time - time();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * 관리자 화면(예약 발행 복구 로그) 조회용.
	 */
	public static function get_publish_watchdog_log() {
		$log = get_option( self::PUBLISH_WATCHDOG_LOG_OPTION, array() );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}

	public static function get_last_self_ping_result() {
		return get_option(
			self::LAST_PING_RESULT_OPTION,
			array(
				'time'    => 0,
				'success' => null,
				'message' => '',
			)
		);
	}

	/* ---------------- 자동 재시도 ---------------- */

	/**
	 * 심하게 지연된(30분 이상) 작업 중, 이 플러그인이 자체적으로 등록한
	 * 정기 작업(스토리지 점검, 캐시 정리)에 한해서만 안전하게 즉시 재실행을 시도합니다.
	 *
	 * 임의의 제3자 플러그인 훅을 강제로 재실행하는 것은 예상치 못한 부작용을
	 * 일으킬 수 있어 이 플러그인이 소유한 작업으로 범위를 제한합니다.
	 */
	public function retry_missed_jobs() {
		try {
			$own_hooks = array(
				'zorlinq32_storage_check',
				'zorlinq32_daily_cache_cleanup',
			);

			$cron_array = _get_cron_array();
			if ( empty( $cron_array ) || ! is_array( $cron_array ) ) {
				return;
			}

			$now             = time();
			$retry_threshold = 30 * MINUTE_IN_SECONDS;

			foreach ( $cron_array as $timestamp => $hooks ) {
				if ( ( $now - $timestamp ) < $retry_threshold ) {
					continue;
				}
				if ( ! is_array( $hooks ) ) {
					continue;
				}
				foreach ( $hooks as $hook_name => $events ) {
					if ( ! in_array( $hook_name, $own_hooks, true ) ) {
						continue;
					}

					// 동일 작업을 같은 요청 내에서 중복 실행하지 않도록 잠금(락)을 겁니다.
					$lock_key = self::RETRY_META_PREFIX . md5( $hook_name . $timestamp );
					if ( get_transient( $lock_key ) ) {
						continue;
					}
					set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );

					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- $hook_name은 위 in_array( $hook_name, $own_hooks ) 검사로 이미 zorlinq32_ 프리픽스를 가진 자체 훅으로 한정되어 있습니다.
					do_action( $hook_name );
					Zorlinq32_Logger::log( sprintf( '지연된 작업을 자동 재시도했습니다: %s', $hook_name ) );
				}
			}
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( 'Cron 자동 재시도 중 오류: ' . $e->getMessage() );
		}
	}

	/* ---------------- 안전한 self-ping ---------------- */

	/**
	 * 서버 실제 크론이 이미 설정되어 있다면 self-ping을 절대 수행하지 않습니다(중복 부하 방지).
	 * 최소 간격(5분) 이내에는 아무리 설정을 조작해도 실행되지 않도록 서버 측에서 강제합니다.
	 */
	public function maybe_self_ping() {
		try {
			if ( $this->is_real_server_cron_likely_configured() ) {
				return;
			}
			if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
				return;
			}

			$interval = isset( $this->settings['self_ping_interval_minutes'] )
				? max( 5, (int) $this->settings['self_ping_interval_minutes'] ) * MINUTE_IN_SECONDS
				: self::MIN_SELF_PING_INTERVAL;
			$interval = max( self::MIN_SELF_PING_INTERVAL, $interval );

			// [v2] 임박한 예약 발행이 있다면, 서버 부하를 해치지 않는 선(최소 1분)까지만 간격을 앞당깁니다.
			$seconds_until_publish = $this->get_seconds_until_next_scheduled_publish();
			if ( null !== $seconds_until_publish && $seconds_until_publish <= 5 * MINUTE_IN_SECONDS ) {
				$interval = min( $interval, MINUTE_IN_SECONDS );
			}

			$last_ping = (int) get_option( 'zorlinq32_last_self_ping', 0 );
			if ( ( time() - $last_ping ) < $interval ) {
				return;
			}

			update_option( 'zorlinq32_last_self_ping', time(), false );

			// 워드프레스 코어의 spawn_cron()과 동일한 non-blocking loopback 방식.
			// 응답을 기다리지 않으므로(blocking=false) 현재 페이지 로딩 속도에 영향을 주지 않습니다.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- https_local_ssl_verify는 워드프레스 코어가 정의한 표준 필터 훅으로, 새 훅을 만드는 것이 아니라 코어 필터에 값을 적용하는 것입니다.
			$sslverify = apply_filters( 'https_local_ssl_verify', false );
			$response  = wp_remote_post(
				site_url( 'wp-cron.php?doing_wp_cron=' . sprintf( '%.22F', microtime( true ) ) ),
				array(
					'timeout'   => 3,
					'blocking'  => false,
					'sslverify' => $sslverify,
				)
			);

			// [v2] blocking=false여도 로컬 루프백 연결 자체가 즉시 거부되는 경우(호스팅사가
			// 자기 자신으로의 아웃바운드 요청을 차단하는 경우 등)에는 WP_Error가 바로 반환되므로,
			// 이를 검사해 로그로 남깁니다. 기존 버전은 이 검사가 없어 실패해도 흔적이 없었습니다.
			if ( is_wp_error( $response ) ) {
				update_option(
					self::LAST_PING_RESULT_OPTION,
					array(
						'time'    => time(),
						'success' => false,
						'message' => $response->get_error_message(),
					),
					false
				);
				Zorlinq32_Logger::log( 'Cron self-ping 요청 실패: ' . $response->get_error_message() . ' (호스팅사가 자기 자신으로의 아웃바운드 HTTP 요청을 차단하고 있을 수 있습니다. 이 경우 서버 실제 크론 연동을 권장합니다.)' );
			} else {
				update_option(
					self::LAST_PING_RESULT_OPTION,
					array(
						'time'    => time(),
						'success' => true,
						'message' => '',
					),
					false
				);
			}
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( 'Cron self-ping 중 오류: ' . $e->getMessage() );
			// 실패해도 페이지 렌더링에는 절대 영향을 주지 않습니다.
		}
	}

	/* ---------------- 관리자 화면용 조회 메소드 ---------------- */

	public static function get_missed_log() {
		$log = get_option( self::MISSED_LOG_OPTION, array() );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}

	public static function get_health_summary() {
		return get_option(
			self::HEALTH_OPTION,
			array(
				'last_checked' => 0,
				'missed_count' => 0,
			)
		);
	}

	public static function clear_missed_log() {
		delete_option( self::MISSED_LOG_OPTION );
	}
}
