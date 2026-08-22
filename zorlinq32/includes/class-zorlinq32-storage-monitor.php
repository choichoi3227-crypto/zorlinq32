<?php
/**
 * 호스팅 스토리지 사용량 모니터링 모듈.
 *
 * disk_total_space / disk_free_space PHP 함수를 사용해 스토리지 현황을 확인합니다.
 * 일부 호스팅 환경에서는 이 함수들이 비활성화되어 있을 수 있으므로,
 * 값을 가져오지 못하면 에러를 내지 않고 "확인 불가"로 안전하게 표시합니다.
 * (요청사항: 실시간 체크 실패 시 조용히 숨김 처리)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Storage_Monitor {

	private static $instance = null;
	private $settings = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = Zorlinq32_Settings::get_group( 'storage_monitor' );

		if ( empty( $this->settings['enabled'] ) ) {
			return;
		}

		add_action( 'zorlinq32_storage_check', array( $this, 'run_scheduled_check' ) );
	}

	/**
	 * 스토리지 용량 정보를 반환합니다.
	 * 함수 미지원/실패 시 available=false 로 반환하며, 절대 예외를 던지지 않습니다.
	 *
	 * [서버 자원 최적화] disk_total_space()/disk_free_space()는 실제 파일시스템 stat 호출로,
	 * 관리자가 대시보드를 열 때마다(또는 여러 관리자가 동시에 접속할 때마다) 매번 반복 호출되면
	 * 불필요한 디스크 I/O가 누적됩니다. 값이 초 단위로 바뀔 이유가 없는 지표이므로,
	 * 5분(300초) 동안은 캐시된 값을 그대로 재사용합니다. 강제 새로고침(ajax_refresh_storage)
	 * 요청 시에는 $force_refresh=true로 캐시를 우회해 항상 최신 값을 가져옵니다.
	 *
	 * @param bool $force_refresh 캐시를 무시하고 강제로 다시 조회할지 여부.
	 * @return array {
	 *   @type bool  $available   값을 가져올 수 있었는지 여부
	 *   @type float $total_bytes 전체 용량
	 *   @type float $free_bytes  잔여 용량
	 *   @type float $used_bytes  사용 중 용량
	 *   @type float $used_percent 사용률(%)
	 * }
	 */
	const USAGE_CACHE_KEY = 'zorlinq32_disk_usage_cache';
	const USAGE_CACHE_TTL = 300; // 5분

	public function get_disk_usage( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::USAGE_CACHE_KEY );
			if ( is_array( $cached ) && isset( $cached['available'] ) ) {
				return $cached;
			}
		}

		$result = array(
			'available'     => false,
			'total_bytes'   => 0,
			'free_bytes'    => 0,
			'used_bytes'    => 0,
			'used_percent'  => 0,
		);

		try {
			if ( ! function_exists( 'disk_total_space' ) || ! function_exists( 'disk_free_space' ) ) {
				set_transient( self::USAGE_CACHE_KEY, $result, self::USAGE_CACHE_TTL );
				return $result;
			}

			$path  = defined( 'ABSPATH' ) ? ABSPATH : '.';
			$total = @disk_total_space( $path );
			$free  = @disk_free_space( $path );

			if ( false === $total || false === $free || $total <= 0 ) {
				// 호스팅 환경 제약으로 값을 못 가져온 경우 -> 조용히 실패 처리.
				// 실패 결과도 짧게(60초) 캐시해 계속 실패하는 환경에서 매 요청마다 재시도하지 않게 합니다.
				set_transient( self::USAGE_CACHE_KEY, $result, 60 );
				return $result;
			}

			$used = $total - $free;

			$result['available']    = true;
			$result['total_bytes']  = $total;
			$result['free_bytes']   = $free;
			$result['used_bytes']   = $used;
			$result['used_percent'] = round( ( $used / $total ) * 100, 1 );

			set_transient( self::USAGE_CACHE_KEY, $result, self::USAGE_CACHE_TTL );

			return $result;
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '디스크 사용량 조회 실패: ' . $e->getMessage() );
			return $result;
		}
	}

	/**
	 * 바이트 값을 사람이 읽기 쉬운 단위(GB, MB 등)로 변환합니다.
	 */
	public static function format_bytes( $bytes, $precision = 2 ) {
		if ( $bytes <= 0 ) {
			return '0 B';
		}
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$pow   = floor( log( $bytes ) / log( 1024 ) );
		$pow   = min( $pow, count( $units ) - 1 );
		$value = $bytes / pow( 1024, $pow );
		return round( $value, $precision ) . ' ' . $units[ $pow ];
	}

	/**
	 * 캐시 디렉토리(들)의 파일 개수·총 용량을 스냅샷으로 반환합니다.
	 * 정리 전후 스냅샷을 비교해 "몇 개 지웠고 몇 바이트를 확보했는지"를 계산하는 데 사용합니다.
	 */
	private function snapshot_cache_dir() {
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'zorlinq32-cache/';

		$count = 0;
		$bytes = 0;

		if ( is_dir( $dir ) ) {
			$files = glob( $dir . '*.html' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						$count++;
						$bytes += filesize( $file );
					}
				}
			}
		}

		return array(
			'count' => $count,
			'bytes' => $bytes,
		);
	}

	/**
	 * 스토리지 확보를 위한 정리를 실행합니다.
	 * 사용자가 업로드한 실제 미디어 파일(이미지, 문서 등)은 절대 건드리지 않으며,
	 * 캐시 모듈(Zorlinq32_Cache)이 관리하는 "재생성 가능한" 파일만 대상으로 합니다.
	 *
	 * [개선: 스토리지 관리 효율화]
	 * - 기존에는 무조건 "7일 경과" 기준의 자체 로직으로 캐시 디렉토리를 훑었는데,
	 *   이는 캐시 모듈이 이미 갖고 있던 만료 로직(cleanup_expired_cache)과 기준이 달라
	 *   "설정한 캐시 수명(cache_lifetime)"과 무관하게 최대 7일까지 파일이 방치되는
	 *   비효율이 있었습니다. 이제 캐시 모듈의 기존 메서드를 그대로 재사용해 기준을 통일합니다.
	 * - $aggressive=true(위험 임계치 도달 시 또는 관리자가 수동으로 "지금 정리" 클릭 시)에는
	 *   만료 여부와 무관하게 캐시를 전량 비워 즉시 용량을 확보합니다(캐시는 재생성되는
	 *   파일이므로 전체 삭제해도 사이트 동작에는 영향이 없습니다).
	 * - $aggressive=false(정기 점검의 경고 단계)에는 이미 만료되어 어차피 서빙되지 않는
	 *   파일만 가볍게 정리합니다.
	 * - 정리 전후 스냅샷을 비교해 삭제된 파일 수·확보 용량을 반환하므로, 관리자 화면에서
	 *   "몇 개 정리했는지" 바로 확인할 수 있습니다.
	 *
	 * @param bool $aggressive true면 만료 여부와 무관하게 캐시를 전량 정리합니다.
	 * @return array { @type int $files_removed, @type int $bytes_freed }
	 */
	public function run_light_cleanup( $aggressive = false ) {
		$before = $this->snapshot_cache_dir();

		try {
			if ( class_exists( 'Zorlinq32_Cache' ) ) {
				$cache = Zorlinq32_Cache::instance();
				if ( $aggressive ) {
					$cache->clear_all_cache();
				} else {
					$cache->cleanup_expired_cache();
				}
			}
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '스토리지 정리 중 오류: ' . $e->getMessage() );
		}

		$after = $this->snapshot_cache_dir();

		$files_removed = max( 0, $before['count'] - $after['count'] );
		$bytes_freed   = max( 0, $before['bytes'] - $after['bytes'] );

		if ( $files_removed > 0 ) {
			// 정리 직후에는 디스크 사용량 캐시도 무효화해, 관리자가 곧바로 최신 수치를 보게 합니다.
			delete_transient( self::USAGE_CACHE_KEY );
			Zorlinq32_Logger::log(
				sprintf(
					'스토리지 정리 완료: 파일 %d개 삭제, %s 확보 (%s)',
					$files_removed,
					self::format_bytes( $bytes_freed ),
					$aggressive ? '수동/위험임계치' : '정기 점검(만료분만)'
				)
			);
		}

		return array(
			'files_removed' => $files_removed,
			'bytes_freed'   => $bytes_freed,
		);
	}

	/**
	 * 정기 cron 작업: 사용량을 확인하고 임계치 초과 시 관리자에게 알리며,
	 * 위험 수준일 때만 가벼운 자동 정리를 수행합니다.
	 */
	public function run_scheduled_check() {
		$usage = $this->get_disk_usage();

		if ( ! $usage['available'] ) {
			return; // 값을 못 가져오면 아무 조치도 취하지 않고 조용히 종료.
		}

		$warning  = isset( $this->settings['warning_threshold'] ) ? (int) $this->settings['warning_threshold'] : 80;
		$critical = isset( $this->settings['critical_threshold'] ) ? (int) $this->settings['critical_threshold'] : 95;

		if ( $usage['used_percent'] >= $critical ) {
			// 위험 수준: 만료 여부와 무관하게 캐시성 파일을 전량 정리해 즉시 용량을 확보합니다.
			$this->run_light_cleanup( true );
			$this->maybe_notify_admin( $usage, 'critical' );
		} elseif ( $usage['used_percent'] >= $warning ) {
			// 경고 수준: 이미 만료되어 어차피 못 쓰는 캐시 파일만 가볍게 정리합니다.
			$this->run_light_cleanup( false );
			$this->maybe_notify_admin( $usage, 'warning' );
		}
	}

	/**
	 * 관리자에게 이메일로 알림을 보냅니다 (설정에서 활성화된 경우에만, 하루 최대 1회).
	 */
	private function maybe_notify_admin( $usage, $level ) {
		if ( empty( $this->settings['notify_admin_email'] ) ) {
			return;
		}

		$last_notified = get_transient( 'zorlinq32_storage_notified_' . $level );
		if ( $last_notified ) {
			return; // 동일 레벨 알림은 24시간 내 중복 발송하지 않습니다.
		}

		try {
			$admin_email = get_option( 'admin_email' );
			if ( empty( $admin_email ) ) {
				return;
			}

			$subject = 'critical' === $level
				? __( '[Zorlinq32] 스토리지 용량 위험 수준 도달', 'zorlinq32' )
				: __( '[Zorlinq32] 스토리지 용량 경고', 'zorlinq32' );

			$message = sprintf(
				/* translators: 1: 사용률, 2: 사용중 용량, 3: 전체 용량 */
				__( '현재 스토리지 사용률이 %1$s%% 입니다. (사용중: %2$s / 전체: %3$s) 관리자 화면에서 확인해주세요.', 'zorlinq32' ),
				$usage['used_percent'],
				self::format_bytes( $usage['used_bytes'] ),
				self::format_bytes( $usage['total_bytes'] )
			);

			wp_mail( $admin_email, $subject, $message );
			set_transient( 'zorlinq32_storage_notified_' . $level, 1, DAY_IN_SECONDS );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '스토리지 알림 메일 발송 실패: ' . $e->getMessage() );
		}
	}
}
