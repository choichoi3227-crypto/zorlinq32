<?php
/*
 * Plugin Name:       Zorlinq32
 * Description:       애널리틱스·SEO·애드센스 보호·캐싱·보안 등 32가지 세부 기능을 하나로 묶은 올인원 워드프레스 최적화 플러그인입니다.
 * Version:           1.1.5
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            인포100
 * Author URI:        https://profiles.wordpress.org/info100/profile/edit/group/1/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zorlinq32
 * Domain Path:       /languages
 */

// 워드프레스 외부에서 직접 접근하는 것을 차단합니다.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 플러그인 전역 상수
 */
define( 'ZORLINQ32_VERSION', '1.1.5' );
define( 'ZORLINQ32_FILE', __FILE__ );
define( 'ZORLINQ32_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZORLINQ32_URL', plugin_dir_url( __FILE__ ) );
define( 'ZORLINQ32_BASENAME', plugin_basename( __FILE__ ) );
define( 'ZORLINQ32_MIN_PHP', '7.4' );
define( 'ZORLINQ32_MIN_WP', '5.8' );
define( 'ZORLINQ32_MIN_MYSQL', '5.6' );

/**
 * 환경(PHP/워드프레스 버전) 요구사항을 만족하지 못하면
 * 플러그인의 나머지 코드를 아예 로드하지 않고 안전하게 관리자 알림만 띄웁니다.
 * -> "워드프레스 에러/장애 절대 금지" 요구사항에 대응하는 핵심 안전장치입니다.
 */
function zorlinq32_requirements_met() {
	if ( version_compare( PHP_VERSION, ZORLINQ32_MIN_PHP, '<' ) ) {
		return false;
	}

	global $wp_version;
	if ( version_compare( $wp_version, ZORLINQ32_MIN_WP, '<' ) ) {
		return false;
	}

	return true;
}

/**
 * 요구사항 미충족 시 관리자 화면에 안내만 하고, 플러그인 기능은 실행하지 않습니다.
 */
function zorlinq32_requirements_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: 1: 현재 PHP 버전, 2: 필요한 PHP 버전, 3: 필요한 워드프레스 버전 */
				esc_html__( 'Zorlinq32 플러그인이 비활성화되었습니다. 현재 PHP %1$s / 워드프레스 환경이 최소 요구사항(PHP %2$s 이상, 워드프레스 %3$s 이상)을 충족하지 않습니다.', 'zorlinq32' ),
				esc_html( PHP_VERSION ),
				esc_html( ZORLINQ32_MIN_PHP ),
				esc_html( ZORLINQ32_MIN_WP )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * 플러그인 초기화 (요구사항 충족 시에만 실행)
 */
function zorlinq32_init_plugin() {

	if ( ! zorlinq32_requirements_met() ) {
		add_action( 'admin_notices', 'zorlinq32_requirements_notice' );
		return;
	}

	// 핵심 유틸리티/헬퍼는 항상 먼저 로드
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-logger.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-settings.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-admin.php';

	// 기능 모듈 (각 모듈은 개별적으로 try/catch 및 조건부 실행으로 보호됩니다)
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-login-security.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-cache.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-storage-monitor.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-indexing.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-performance.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-security.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-seo-extended.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-code-injection.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-analytics-db.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-referrer-classifier.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-analytics-query.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-analytics.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-geoip.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-adprotect-db.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-edge-protection.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-adsense-protection.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-popup.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-blocks.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-templates.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-admin-experience.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-content-optimizer.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-cron-guardian.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-useapi-google-flow.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-ai-writer.php';
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-content-hub.php';

	// 각 모듈 부트스트랩. 모듈 하나가 예외를 던져도 전체 사이트가 죽지 않도록 개별 방어.
	$modules = array(
		'Zorlinq32_Login_Security',
		'Zorlinq32_Cache',
		'Zorlinq32_Storage_Monitor',
		'Zorlinq32_Indexing',
		'Zorlinq32_Performance',
		'Zorlinq32_Security',
		'Zorlinq32_SEO_Extended',
		'Zorlinq32_Code_Injection',
		'Zorlinq32_Analytics',
		'Zorlinq32_Edge_Protection',
		'Zorlinq32_AdSense_Protection',
		'Zorlinq32_Popup',
		'Zorlinq32_Blocks',
		'Zorlinq32_Templates',
		'Zorlinq32_Admin_Experience',
		'Zorlinq32_Content_Optimizer',
		'Zorlinq32_Cron_Guardian',
		'Zorlinq32_UseAPI_Google_Flow',
		'Zorlinq32_AI_Writer',
		'Zorlinq32_Content_Hub',
	);

	foreach ( $modules as $module_class ) {
		try {
			if ( class_exists( $module_class ) ) {
				$module_class::instance();
			}
		} catch ( \Throwable $e ) {
			// 프론트엔드/관리자 화면이 죽지 않도록 오류를 삼키고 로그만 남깁니다.
			if ( class_exists( 'Zorlinq32_Logger' ) ) {
				Zorlinq32_Logger::log( sprintf( '모듈 로드 실패: %s - %s', $module_class, $e->getMessage() ) );
			}
		}
	}

	// 관리자 화면 부트스트랩
	if ( is_admin() && class_exists( 'Zorlinq32_Admin' ) ) {
		try {
			Zorlinq32_Admin::instance();
		} catch ( \Throwable $e ) {
			if ( class_exists( 'Zorlinq32_Logger' ) ) {
				Zorlinq32_Logger::log( '관리자 모듈 로드 실패: ' . $e->getMessage() );
			}
		}
	}

	// SEO 설정 저장으로 사이트맵 rewrite 규칙이 바뀌었다면, 규칙이 모두 등록된 뒤(init 이후) 안전하게 반영합니다.
	add_action( 'wp_loaded', 'zorlinq32_maybe_flush_rewrite_rules' );
}
add_action( 'plugins_loaded', 'zorlinq32_init_plugin' );

/**
 * 플래그가 설정된 경우에만 rewrite 규칙을 재계산합니다.
 * 매 요청마다 실행되는 무거운 작업이 아니라, 설정 변경 직후 단 한 번만 실행되도록
 * 플래그를 즉시 삭제합니다.
 */
function zorlinq32_maybe_flush_rewrite_rules() {
	if ( get_option( 'zorlinq32_rewrite_flush_needed' ) ) {
		delete_option( 'zorlinq32_rewrite_flush_needed' );
		flush_rewrite_rules();
	}
}

/**
 * 활성화 훅: 기본 옵션값 세팅 + 정기 작업(cron) 스케줄 등록
 */
function zorlinq32_activate() {
	if ( ! zorlinq32_requirements_met() ) {
		return;
	}

	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-settings.php';

	// [리브랜딩 마이그레이션] 이 플러그인은 이전에 "Optimize 53"이라는 이름으로 배포되었습니다.
	// 사용자가 이전 버전을 비활성화하고 이 플러그인을 새로 설치하는 경우, 예전 옵션명
	// (optimize53_settings)에 저장되어 있던 설정을 그대로 잃지 않도록 새 옵션명
	// (zorlinq32_settings)으로 자동 복사합니다. 이미 새 옵션이 있다면(재활성화 등)
	// 덮어쓰지 않아 사용자가 이후에 변경한 설정이 유실되지 않습니다.
	if ( false === get_option( 'zorlinq32_settings', false ) ) {
		$legacy_settings = get_option( 'optimize53_settings', false );
		if ( false !== $legacy_settings ) {
			update_option( 'zorlinq32_settings', $legacy_settings );
		}
	}

	Zorlinq32_Settings::set_defaults();

	// 스토리지 정기 점검
	// [개선: 스토리지 관리 효율화] 기존 하루 1회 주기에서는 사용량이 위험 수준을 넘어도
	// 최대 24시간 동안 방치될 수 있었습니다(그 사이 정리도, 알림도 없음). 점검 자체는
	// disk_total_space() 호출 한 번으로 가볍고, 결과도 5분간 캐시되므로 매시간으로 앞당겨도
	// 서버 부하 증가는 무시할 수준입니다. 대신 대응 지연이 최대 1시간으로 크게 줄어듭니다.
	if ( wp_next_scheduled( 'zorlinq32_daily_storage_check' ) ) {
		wp_clear_scheduled_hook( 'zorlinq32_daily_storage_check' );
	}
	if ( ! wp_next_scheduled( 'zorlinq32_storage_check' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'zorlinq32_storage_check' );
	}

	// 캐시 정리 (기본: 하루 1회)
	if ( ! wp_next_scheduled( 'zorlinq32_daily_cache_cleanup' ) ) {
		wp_schedule_event( time() + 600, 'daily', 'zorlinq32_daily_cache_cleanup' );
	}

	// Cron 상태 점검 (기본: 1시간마다, 지연/누락 감지는 방문자 트래픽에 의존하므로
	// 너무 촘촘한 주기는 의미가 없어 1시간으로 고정합니다).
	if ( ! wp_next_scheduled( 'zorlinq32_cron_health_check' ) ) {
		wp_schedule_event( time() + 120, 'hourly', 'zorlinq32_cron_health_check' );
	}

	// 애널리틱스 테이블 생성 및 오래된 기록 정리 (기본: 하루 1회)
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-analytics-db.php';
	Zorlinq32_Analytics_DB::maybe_create_table();
	if ( ! wp_next_scheduled( 'zorlinq32_analytics_daily_cleanup' ) ) {
		wp_schedule_event( time() + 900, 'daily', 'zorlinq32_analytics_daily_cleanup' );
	}

	// 애드센스 부정클릭 방지 테이블 생성 및 만료된 차단/오래된 클릭 로그 정리 (기본: 하루 1회)
	require_once ZORLINQ32_DIR . 'includes/class-zorlinq32-adprotect-db.php';
	Zorlinq32_AdProtect_DB::maybe_create_tables();
	if ( ! wp_next_scheduled( 'zorlinq32_adprotect_daily_cleanup' ) ) {
		wp_schedule_event( time() + 1200, 'daily', 'zorlinq32_adprotect_daily_cleanup' );
	}

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'zorlinq32_activate' );

/**
 * 비활성화 훅: 예약된 cron 작업 정리 (설정/데이터는 유지 -> uninstall.php에서 처리)
 */
function zorlinq32_deactivate() {
	$timestamp = wp_next_scheduled( 'zorlinq32_storage_check' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'zorlinq32_storage_check' );
	}
	// 이전 버전에서 등록된 레거시 훅(하루 1회)이 남아있다면 함께 정리합니다.
	$legacy_timestamp = wp_next_scheduled( 'zorlinq32_daily_storage_check' );
	if ( $legacy_timestamp ) {
		wp_unschedule_event( $legacy_timestamp, 'zorlinq32_daily_storage_check' );
	}

	$timestamp2 = wp_next_scheduled( 'zorlinq32_daily_cache_cleanup' );
	if ( $timestamp2 ) {
		wp_unschedule_event( $timestamp2, 'zorlinq32_daily_cache_cleanup' );
	}

	$timestamp3 = wp_next_scheduled( 'zorlinq32_cron_health_check' );
	if ( $timestamp3 ) {
		wp_unschedule_event( $timestamp3, 'zorlinq32_cron_health_check' );
	}

	$timestamp4 = wp_next_scheduled( 'zorlinq32_analytics_daily_cleanup' );
	if ( $timestamp4 ) {
		wp_unschedule_event( $timestamp4, 'zorlinq32_analytics_daily_cleanup' );
	}

	$timestamp5 = wp_next_scheduled( 'zorlinq32_adprotect_daily_cleanup' );
	if ( $timestamp5 ) {
		wp_unschedule_event( $timestamp5, 'zorlinq32_adprotect_daily_cleanup' );
	}

	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'zorlinq32_deactivate' );
