<?php
/**
 * 관리자 화면(설정 페이지) 클래스.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Admin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_post_editor_assets' ) );
		add_action( 'admin_post_zorlinq32_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'wp_ajax_zorlinq32_clear_cache', array( $this, 'ajax_clear_cache' ) );
		add_action( 'wp_ajax_zorlinq32_refresh_storage', array( $this, 'ajax_refresh_storage' ) );
		add_action( 'wp_ajax_zorlinq32_cleanup_storage', array( $this, 'ajax_cleanup_storage' ) );
		add_action( 'wp_ajax_zorlinq32_unblock_visitor', array( $this, 'ajax_unblock_visitor' ) );
		add_action( 'wp_ajax_zorlinq32_remove_blocked_ip', array( $this, 'ajax_remove_blocked_ip' ) );
		add_action( 'wp_ajax_zorlinq32_save_popup', array( $this, 'ajax_save_popup' ) );
		add_action( 'wp_ajax_zorlinq32_delete_popup', array( $this, 'ajax_delete_popup' ) );
		add_action( 'wp_ajax_zorlinq32_toggle_popup', array( $this, 'ajax_toggle_popup' ) );
		add_action( 'wp_ajax_zorlinq32_delete_template', array( $this, 'ajax_delete_template' ) );
		// [요청 기능: 통계 초기화] 애널리틱스 페이지의 "통계 초기화" 버튼 처리.
		add_action( 'wp_ajax_zorlinq32_reset_analytics', array( $this, 'ajax_reset_analytics' ) );

		// [애널리틱스 요구사항 3] 워드프레스 코어 "알림판(Dashboard)" 페이지에서 별도 이동 없이
		// 바로 방문 통계 요약을 볼 수 있도록 표준 대시보드 위젯을 등록합니다.
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
	}

	/**
	 * 워드프레스 알림판(index.php)에 "Zorlinq32 방문 통계" 위젯을 등록합니다.
	 * 애널리틱스 기능이 꺼져 있으면 켜도록 안내하는 내용을 보여줍니다.
	 */
	public function register_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'zorlinq32_dashboard_widget',
			__( 'Zorlinq32 · 방문 통계', 'zorlinq32' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * 대시보드 위젯 본문. 오늘/이번주 방문자수·조회수를 간단히 보여주고, 전체 통계 페이지로
	 * 연결합니다. 무거운 그래프 렌더링 없이 숫자 위주로 구성해 알림판 로딩에 부담을 주지 않습니다.
	 */
	public function render_dashboard_widget() {
		$settings = Zorlinq32_Settings::get_group( 'analytics' );

		if ( empty( $settings['enabled'] ) || ! class_exists( 'Zorlinq32_Analytics_Query' ) ) {
			echo '<p>' . esc_html__( '애널리틱스 기능이 아직 켜져 있지 않습니다.', 'zorlinq32' ) . '</p>';
			printf(
				'<p><a href="%s" class="button button-primary">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=zorlinq32-analytics' ) ),
				esc_html__( '애널리틱스 켜기', 'zorlinq32' )
			);
			return;
		}

		$quick = Zorlinq32_Analytics_Query::get_quick_summary();
		?>
		<div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:10px;">
			<div>
				<div style="font-size:22px;font-weight:600;line-height:1.2;"><?php echo esc_html( number_format_i18n( $quick['today_visitors'] ) ); ?></div>
				<div style="font-size:12px;color:#646970;"><?php esc_html_e( '오늘 방문자수', 'zorlinq32' ); ?></div>
			</div>
			<div>
				<div style="font-size:22px;font-weight:600;line-height:1.2;color:#646970;"><?php echo esc_html( number_format_i18n( $quick['today_pageviews'] ) ); ?></div>
				<div style="font-size:12px;color:#646970;"><?php esc_html_e( '오늘 조회수', 'zorlinq32' ); ?></div>
			</div>
			<div>
				<div style="font-size:22px;font-weight:600;line-height:1.2;"><?php echo esc_html( number_format_i18n( $quick['week_visitors'] ) ); ?></div>
				<div style="font-size:12px;color:#646970;"><?php esc_html_e( '최근 7일 방문자수', 'zorlinq32' ); ?></div>
			</div>
		</div>
		<p style="margin-bottom:0;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=zorlinq32-analytics' ) ); ?>"><?php esc_html_e( '전체 애널리틱스 보기 →', 'zorlinq32' ); ?></a>
		</p>
		<?php
	}

	public function register_menu() {
		add_menu_page(
			__( 'Zorlinq32', 'zorlinq32' ),
			__( 'Zorlinq32', 'zorlinq32' ),
			'manage_options',
			'zorlinq32',
			array( $this, 'render_dashboard_page' ),
			'dashicons-performance',
			80
		);

		add_submenu_page( 'zorlinq32', __( '대시보드', 'zorlinq32' ), __( '대시보드', 'zorlinq32' ), 'manage_options', 'zorlinq32', array( $this, 'render_dashboard_page' ) );
		add_submenu_page( 'zorlinq32', __( '애널리틱스', 'zorlinq32' ), __( '애널리틱스', 'zorlinq32' ), 'manage_options', 'zorlinq32-analytics', array( $this, 'render_analytics_page' ) );
		add_submenu_page( 'zorlinq32', __( '애드센스 보호', 'zorlinq32' ), __( '애드센스 보호', 'zorlinq32' ), 'manage_options', 'zorlinq32-adsense-protection', array( $this, 'render_adsense_protection_page' ) );
		add_submenu_page( 'zorlinq32', __( '팝업', 'zorlinq32' ), __( '팝업', 'zorlinq32' ), 'manage_options', 'zorlinq32-popup', array( $this, 'render_popup_page' ) );
		add_submenu_page( 'zorlinq32', __( 'Op 템플릿', 'zorlinq32' ), __( 'Op 템플릿', 'zorlinq32' ), 'manage_options', 'zorlinq32-templates', array( $this, 'render_templates_page' ) );
		add_submenu_page( 'zorlinq32', __( '로그인 보안', 'zorlinq32' ), __( '로그인 보안', 'zorlinq32' ), 'manage_options', 'zorlinq32-login', array( $this, 'render_login_page' ) );
		add_submenu_page( 'zorlinq32', __( '캐싱', 'zorlinq32' ), __( '캐싱', 'zorlinq32' ), 'manage_options', 'zorlinq32-cache', array( $this, 'render_cache_page' ) );
		add_submenu_page( 'zorlinq32', __( '스토리지', 'zorlinq32' ), __( '스토리지', 'zorlinq32' ), 'manage_options', 'zorlinq32-storage', array( $this, 'render_storage_page' ) );
		add_submenu_page( 'zorlinq32', __( '성능 최적화', 'zorlinq32' ), __( '성능 최적화', 'zorlinq32' ), 'manage_options', 'zorlinq32-performance', array( $this, 'render_performance_page' ) );
		add_submenu_page( 'zorlinq32', __( '보안 강화', 'zorlinq32' ), __( '보안 강화', 'zorlinq32' ), 'manage_options', 'zorlinq32-security', array( $this, 'render_security_page' ) );
		add_submenu_page( 'zorlinq32', __( '관리 편의', 'zorlinq32' ), __( '관리 편의', 'zorlinq32' ), 'manage_options', 'zorlinq32-admin-experience', array( $this, 'render_admin_experience_page' ) );
		add_submenu_page( 'zorlinq32', __( '콘텐츠 최적화', 'zorlinq32' ), __( '콘텐츠 최적화', 'zorlinq32' ), 'manage_options', 'zorlinq32-content', array( $this, 'render_content_optimizer_page' ) );
		add_submenu_page( 'zorlinq32', __( 'Cron 안정화', 'zorlinq32' ), __( 'Cron 안정화', 'zorlinq32' ), 'manage_options', 'zorlinq32-cron', array( $this, 'render_cron_guardian_page' ) );
		add_submenu_page( 'zorlinq32', __( '자동 색인', 'zorlinq32' ), __( '자동 색인', 'zorlinq32' ), 'manage_options', 'zorlinq32-indexing', array( $this, 'render_indexing_page' ) );
		add_submenu_page( 'zorlinq32', __( '코드 삽입', 'zorlinq32' ), __( '코드 삽입', 'zorlinq32' ), 'manage_options', 'zorlinq32-code-injection', array( $this, 'render_code_injection_page' ) );
		add_submenu_page( 'zorlinq32', __( '로그', 'zorlinq32' ), __( '오류 로그', 'zorlinq32' ), 'manage_options', 'zorlinq32-logs', array( $this, 'render_logs_page' ) );
		add_submenu_page( 'zorlinq32', __( '콘텐츠 허브', 'zorlinq32' ), __( '콘텐츠 허브', 'zorlinq32' ), 'manage_options', 'zorlinq32-content-hub', array( $this, 'render_content_hub_page' ) );
		add_submenu_page( 'zorlinq32', __( 'AI 글쓰기', 'zorlinq32' ), __( 'AI 글쓰기', 'zorlinq32' ), 'manage_options', 'zorlinq32-ai-writer', function() {
			if ( class_exists( 'Zorlinq32_AI_Writer' ) ) Zorlinq32_AI_Writer::get_instance()->render_settings_page();
		} );
		add_submenu_page( 'zorlinq32', __( 'AI 썸네일 템플릿', 'zorlinq32' ), __( 'AI 썸네일 템플릿', 'zorlinq32' ), 'manage_options', 'zorlinq32-ai-thumb-templates', function() {
			if ( class_exists( 'Zorlinq32_AI_Writer' ) ) Zorlinq32_AI_Writer::get_instance()->render_template_manager();
		} );
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'zorlinq32' ) === false ) {
			return;
		}
		wp_enqueue_style( 'zorlinq32-admin', ZORLINQ32_URL . 'assets/css/admin.css', array(), ZORLINQ32_VERSION );
		wp_enqueue_script( 'zorlinq32-admin', ZORLINQ32_URL . 'assets/js/admin.js', array( 'jquery' ), ZORLINQ32_VERSION, true );
		wp_localize_script(
			'zorlinq32-admin',
			'zorlinq32Admin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'zorlinq32_admin_nonce' ),
				'i18n'    => array(
					'clearing'       => __( '캐시 삭제 중...', 'zorlinq32' ),
					'refreshing'     => __( '새로고침 중...', 'zorlinq32' ),
					'confirmReset'   => __( '정말로 모든 통계를 삭제하시겠습니까? 이 작업은 되돌릴 수 없습니다.', 'zorlinq32' ),
					'resetting'      => __( '초기화 중...', 'zorlinq32' ),
					'cleaning'       => __( '정리 중...', 'zorlinq32' ),
					'cleanupDone'    => __( '정리 완료', 'zorlinq32' ),
					/* translators: %s: 확보한 용량 (예: 3.2 MB) */
					'cleanupResult'  => __( '파일 %1$d개 삭제, %2$s 확보', 'zorlinq32' ),
					'cleanupFailed'  => __( '정리 중 오류가 발생했습니다.', 'zorlinq32' ),
				),
			)
		);

		// [애널리틱스] 그래프 렌더러는 애널리틱스 상세 페이지뿐 아니라, 미니 그래프가 추가된
		// 대시보드(메인 페이지, hook: toplevel_page_zorlinq32)에서도 필요합니다.
		$zorlinq32_is_analytics_hook = ( false !== strpos( $hook, 'zorlinq32-analytics' ) );
		$zorlinq32_is_dashboard_hook = ( 'toplevel_page_zorlinq32' === $hook );
		if ( $zorlinq32_is_analytics_hook || $zorlinq32_is_dashboard_hook ) {
			// 외부 CDN 없이 순수 SVG로 부드러운 곡선 그래프를 그리는 자체 스크립트입니다
			// (호스팅 환경에 따라 외부 스크립트 로딩이 차단되는 경우가 있어 의존성을 두지 않습니다).
			wp_enqueue_script( 'zorlinq32-analytics-chart', ZORLINQ32_URL . 'assets/js/analytics-chart.js', array(), ZORLINQ32_VERSION, true );
			wp_add_inline_script(
				'zorlinq32-analytics-chart',
				'window.zorlinq32AnalyticsChartI18n = ' . wp_json_encode(
					array(
						'visitors'  => __( '방문자수', 'zorlinq32' ),
						'pageviews' => __( '조회수', 'zorlinq32' ),
					)
				) . ';',
				'before'
			);
		}

		if ( false !== strpos( $hook, 'zorlinq32-popup' ) ) {
			wp_enqueue_media();
			wp_enqueue_script( 'zorlinq32-popup-admin', ZORLINQ32_URL . 'assets/js/popup-admin.js', array( 'jquery' ), ZORLINQ32_VERSION, true );
		}
	}

	/**
	 * 공통: nonce/권한 검사 후 설정 그룹을 저장하고 원래 페이지로 리다이렉트합니다.
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '권한이 없습니다.', 'zorlinq32' ) );
		}

		check_admin_referer( 'zorlinq32_save_settings' );

		$group = isset( $_POST['settings_group'] ) ? sanitize_key( wp_unslash( $_POST['settings_group'] ) ) : '';
		$redirect_page = isset( $_POST['redirect_page'] ) ? sanitize_key( wp_unslash( $_POST['redirect_page'] ) ) : 'zorlinq32';

		if ( empty( $group ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . $redirect_page ) );
			exit;
		}

		// 2026-08 추가: 콘텐츠 허브(경로 분류/관련글/이전-다음글)는
		// 옵션 항목 구조가 다른 그룹들과 달라 전용 옵션 키(Zorlinq32_Content_Hub)로
		// 별도 관리합니다. 나머지 그룹의 저장 흐름(Zorlinq32_Settings)에는 영향을 주지 않습니다.
		if ( 'content_hub' === $group ) {
			if ( class_exists( 'Zorlinq32_Content_Hub' ) ) {
				$sanitized_hub = $this->sanitize_content_hub_input( $_POST );
				Zorlinq32_Content_Hub::update_settings( $sanitized_hub );
			}
			// 경로(zorlinq32_path) 택소노미는 public rewrite(슬러그: /path/xxx)를 사용하므로,
			// 모듈이 켜지거나 꺼질 때(등록 여부가 바뀔 수 있으므로) rewrite 규칙을 다음 요청에서
			// 재계산하도록 플래그만 남긴다(요청당 즉시 flush는 비용이 크므로 지연 처리).
			update_option( 'zorlinq32_rewrite_flush_needed', 1 );
			wp_safe_redirect( add_query_arg( 'zorlinq32_saved', '1', admin_url( 'admin.php?page=' . $redirect_page ) ) );
			exit;
		}

		$sanitized = $this->sanitize_group_input( $group, $_POST );
		Zorlinq32_Settings::update_group( $group, $sanitized );

		// 사이트맵(seo) / IndexNow 키 파일(indexing) rewrite 규칙이 켜지거나 꺼졌을 수 있으므로,
		// 다음 요청에서 rewrite 규칙이 다시 계산되도록 플래그를 남깁니다.
		// (요청당 flush_rewrite_rules()는 비용이 크므로 매 저장마다 즉시 실행하지 않고 지연 처리합니다.)
		if ( 'indexing' === $group ) {
			update_option( 'zorlinq32_rewrite_flush_needed', 1 );
		}

		wp_safe_redirect( add_query_arg( 'zorlinq32_saved', '1', admin_url( 'admin.php?page=' . $redirect_page ) ) );
		exit;
	}

	/**
	 * 그룹별 입력값을 화이트리스트 기반으로 검증/정제합니다.
	 */
	private function sanitize_group_input( $group, $input ) {
		switch ( $group ) {
			case 'login_security':
				return array(
					'enabled'      => ! empty( $input['enabled'] ),
					'custom_slug'  => isset( $input['custom_slug'] ) ? sanitize_title( wp_unslash( $input['custom_slug'] ) ) : 'secure-login',
					'redirect_403' => ! empty( $input['redirect_403'] ),
				);

			case 'cache':
				return array(
					'enabled'            => ! empty( $input['enabled'] ),
					'cache_lifetime'     => isset( $input['cache_lifetime'] ) ? max( 1, (int) $input['cache_lifetime'] ) : 24,
					'exclude_logged_in'  => ! empty( $input['exclude_logged_in'] ),
				);

			case 'storage_monitor':
				return array(
					'enabled'             => ! empty( $input['enabled'] ),
					'warning_threshold'   => isset( $input['warning_threshold'] ) ? min( 100, max( 1, (int) $input['warning_threshold'] ) ) : 80,
					'critical_threshold'  => isset( $input['critical_threshold'] ) ? min( 100, max( 1, (int) $input['critical_threshold'] ) ) : 95,
					'notify_admin_email'  => ! empty( $input['notify_admin_email'] ),
				);

			case 'indexing':
				$existing_indexing = Zorlinq32_Settings::get_group( 'indexing' );
				return array(
					'enabled'                => ! empty( $input['enabled'] ),
					'indexnow_enabled'       => ! empty( $input['indexnow_enabled'] ),
					// indexnow_key는 이 폼을 통해 사용자가 직접 입력하지 않고 시스템이 자동 생성/보관합니다.
					// 기존 저장값을 그대로 유지해 키가 바뀌어 이미 제출한 키 파일 URL이 깨지는 것을 방지합니다.
					'indexnow_key'           => isset( $existing_indexing['indexnow_key'] ) ? $existing_indexing['indexnow_key'] : '',
					'auto_submit_on_publish' => ! empty( $input['auto_submit_on_publish'] ),
					'auto_submit_on_update'  => ! empty( $input['auto_submit_on_update'] ),
					'noindex_archives'       => ! empty( $input['noindex_archives'] ),
					// robots.txt 커스텀 규칙은 이미 이 메서드 진입 시점(handle_save_settings)에서
					// current_user_can( 'manage_options' )가 검증되어 있습니다. 일반 텍스트로만 저장하며
					// HTML 실행 맥락이 아닌 텍스트 파일(robots.txt) 안에만 출력되므로 XSS 위험이 없습니다.
					'custom_robots_rules'    => isset( $input['custom_robots_rules'] ) ? sanitize_textarea_field( wp_unslash( $input['custom_robots_rules'] ) ) : '',
				);

			case 'code_injection':
				return array(
					'enabled'     => ! empty( $input['enabled'] ),
					// 헤더/바디/푸터 코드는 의도적으로 스크립트 태그를 포함해야 하는 기능이므로
					// wp_kses() 등으로 태그를 제거하지 않습니다. 이 그룹은 manage_options 권한자만
					// 저장할 수 있으며(handle_save_settings 상단에서 이미 검증됨), 슬래시 제거만 수행합니다.
					'header_code' => isset( $input['header_code'] ) ? wp_unslash( $input['header_code'] ) : '',
					'body_code'   => isset( $input['body_code'] ) ? wp_unslash( $input['body_code'] ) : '',
					'footer_code' => isset( $input['footer_code'] ) ? wp_unslash( $input['footer_code'] ) : '',
				);

			case 'analytics':
				$retention = isset( $input['retention_days'] ) ? (int) $input['retention_days'] : 400;
				// 너무 짧거나(집계 무의미) 너무 긴(DB 비대화) 값을 방지하기 위한 안전 범위.
				$retention          = max( 30, min( $retention, 1825 ) );
				$exclude_admin_ips  = ! empty( $input['exclude_admin_ips'] );
				$existing_analytics = Zorlinq32_Settings::get_group( 'analytics' );
				// 기능을 계속 켜두는 경우 지금까지 자동으로 기억해둔 IP 목록을 그대로 유지합니다
				// (이 화면에는 IP 목록을 직접 입력하는 필드가 없으므로 wp_login 훅에서만 채워집니다).
				// 기능을 끄면 더 이상 쓰이지 않을 목록이므로 함께 비워 불필요한 데이터를 남기지 않습니다.
				$excluded_ip_list = $exclude_admin_ips && isset( $existing_analytics['excluded_ip_list'] ) && is_array( $existing_analytics['excluded_ip_list'] )
					? $existing_analytics['excluded_ip_list']
					: array();

				// [요청 기능: 애널리틱스 캐시 시간 설정] 1/5/10/30/60분 중에서만 선택 가능하도록
				// 화이트리스트로 검증합니다(임의의 값이 들어와 예기치 않은 캐시 동작을 만들지 않도록).
				$cache_minutes = isset( $input['cache_minutes'] ) ? (int) $input['cache_minutes'] : 5;
				if ( ! in_array( $cache_minutes, array( 1, 5, 10, 30, 60 ), true ) ) {
					$cache_minutes = 5;
				}
				// 캐시 시간 값 자체가 바뀌면, 이전 설정으로 캐시된 결과가 새 설정 적용 전까지
				// 남아있지 않도록 즉시 캐시를 비웁니다.
				if ( isset( $existing_analytics['cache_minutes'] ) && (int) $existing_analytics['cache_minutes'] !== $cache_minutes
					&& class_exists( 'Zorlinq32_Analytics_Query' ) ) {
					Zorlinq32_Analytics_Query::flush_all_query_cache();
				}

				return array(
					'enabled'           => ! empty( $input['enabled'] ),
					'retention_days'    => $retention,
					'exclude_admin_ips' => $exclude_admin_ips,
					'excluded_ip_list'  => $excluded_ip_list,
					'cache_minutes'     => $cache_minutes,
				);

			case 'adsense_protection':
				$max_clicks           = isset( $input['max_clicks'] ) ? (int) $input['max_clicks'] : 3;
				$detection_hours      = isset( $input['detection_hours'] ) ? (int) $input['detection_hours'] : 1;
				$block_duration_days  = isset( $input['block_duration_days'] ) ? (int) $input['block_duration_days'] : 7;

				$blocked_countries = array();
				if ( ! empty( $input['blocked_countries'] ) && is_array( $input['blocked_countries'] ) ) {
					foreach ( $input['blocked_countries'] as $country_code ) {
						$country_code = strtoupper( sanitize_text_field( wp_unslash( $country_code ) ) );
						if ( preg_match( '/^[A-Z]{2}$/', $country_code ) ) {
							$blocked_countries[] = $country_code;
						}
					}
				}

				// 기존 차단 IP 목록을 불러와, 이 폼에서 새로 추가된 IP가 있으면 병합합니다.
				// (수동 해제는 별도의 AJAX 액션으로 처리되므로, 이 저장 경로에서는 목록을 덮어쓰지 않고
				// "새로 추가할 IP" 입력란만 병합해 기존 차단이 실수로 사라지지 않게 합니다.)
				$existing_adsense = Zorlinq32_Settings::get_group( 'adsense_protection' );
				$blocked_ips      = isset( $existing_adsense['blocked_ips'] ) && is_array( $existing_adsense['blocked_ips'] ) ? $existing_adsense['blocked_ips'] : array();

				if ( ! empty( $input['new_blocked_ip'] ) ) {
					$new_ip = sanitize_text_field( wp_unslash( $input['new_blocked_ip'] ) );
					if ( filter_var( $new_ip, FILTER_VALIDATE_IP ) && ! in_array( $new_ip, $blocked_ips, true ) ) {
						$blocked_ips[] = $new_ip;
					}
				}

				return array(
					'enabled'             => ! empty( $input['enabled'] ),
					'ad_client_id'        => isset( $input['ad_client_id'] ) ? sanitize_text_field( wp_unslash( $input['ad_client_id'] ) ) : '',
					'max_clicks'          => max( 1, min( $max_clicks, 100 ) ),
					'detection_hours'     => max( 1, min( $detection_hours, 168 ) ), // 최대 7일(168시간)까지 허용.
					'block_duration_days' => max( 1, min( $block_duration_days, 365 ) ),
					'blocked_countries'   => $blocked_countries,
					'blocked_ips'         => $blocked_ips,
				);

			case 'popup':
				// 개별 팝업 목록(popups)은 AJAX(ajax_save_popup 등)로만 관리되며,
				// 이 폼 저장 경로에서는 기존 popups 배열을 그대로 보존하고 enabled만 갱신합니다.
				$existing_popup = Zorlinq32_Settings::get_group( 'popup' );
				return array(
					'enabled' => ! empty( $input['enabled'] ),
					'popups'  => isset( $existing_popup['popups'] ) && is_array( $existing_popup['popups'] ) ? $existing_popup['popups'] : array(),
				);

			case 'performance':
				return array(
					'enabled'                      => ! empty( $input['enabled'] ),
					'limit_revisions'              => ! empty( $input['limit_revisions'] ),
					'revisions_limit'              => isset( $input['revisions_limit'] ) ? max( 1, (int) $input['revisions_limit'] ) : 5,
					'extend_autosave_interval'     => ! empty( $input['extend_autosave_interval'] ),
					'autosave_interval_seconds'    => isset( $input['autosave_interval_seconds'] ) ? max( 60, (int) $input['autosave_interval_seconds'] ) : 120,
					'limit_heartbeat'              => ! empty( $input['limit_heartbeat'] ),
					'heartbeat_interval_seconds'   => isset( $input['heartbeat_interval_seconds'] ) ? max( 30, (int) $input['heartbeat_interval_seconds'] ) : 60,
					'lazy_load_images'             => ! empty( $input['lazy_load_images'] ),
					'disable_emojis'               => ! empty( $input['disable_emojis'] ),
					'disable_embeds'               => ! empty( $input['disable_embeds'] ),
					'remove_version_query_strings' => ! empty( $input['remove_version_query_strings'] ),
					'auto_empty_trash_days'        => ! empty( $input['auto_empty_trash_days'] ),
				);

			case 'security':
				return array(
					'enabled'                => ! empty( $input['enabled'] ),
					'disable_xmlrpc'         => ! empty( $input['disable_xmlrpc'] ),
					'limit_login_attempts'   => ! empty( $input['limit_login_attempts'] ),
					'max_login_attempts'     => isset( $input['max_login_attempts'] ) ? max( 1, (int) $input['max_login_attempts'] ) : 5,
					'lockout_minutes'        => isset( $input['lockout_minutes'] ) ? max( 1, (int) $input['lockout_minutes'] ) : 15,
					'disable_file_editor'    => ! empty( $input['disable_file_editor'] ),
					'hide_wp_version'        => ! empty( $input['hide_wp_version'] ),
					'security_headers'       => ! empty( $input['security_headers'] ),
					'prevent_username_enum'  => ! empty( $input['prevent_username_enum'] ),
				);

			case 'admin_experience':
				return array(
					'enabled'                  => ! empty( $input['enabled'] ),
					'clean_dashboard_widgets'  => ! empty( $input['clean_dashboard_widgets'] ),
					'clean_admin_bar'          => ! empty( $input['clean_admin_bar'] ),
					'custom_login_logo_url'    => isset( $input['custom_login_logo_url'] ) ? esc_url_raw( wp_unslash( $input['custom_login_logo_url'] ) ) : '',
					'featured_image_column'    => ! empty( $input['featured_image_column'] ),
				);

			case 'content_optimizer':
				return array(
					'enabled'                     => ! empty( $input['enabled'] ),
					'comment_spam_protection'     => ! empty( $input['comment_spam_protection'] ),
					'disable_extra_image_sizes'   => ! empty( $input['disable_extra_image_sizes'] ),
					'disabled_image_sizes'        => isset( $input['disabled_image_sizes'] ) && is_array( $input['disabled_image_sizes'] )
						? array_map( 'sanitize_key', wp_unslash( $input['disabled_image_sizes'] ) )
						: array(),
					'limit_search_post_types'     => ! empty( $input['limit_search_post_types'] ),
				);

			case 'cron_guardian':
				return array(
					'enabled'                     => ! empty( $input['enabled'] ),
					'monitor_missed_jobs'         => ! empty( $input['monitor_missed_jobs'] ),
					'notify_on_delay'             => ! empty( $input['notify_on_delay'] ),
					'auto_retry_missed'           => ! empty( $input['auto_retry_missed'] ),
					'enable_self_ping'            => ! empty( $input['enable_self_ping'] ),
					// 최소 간격(5분) 미만은 절대 저장되지 않도록 서버 측에서 강제합니다.
					'self_ping_interval_minutes'  => isset( $input['self_ping_interval_minutes'] ) ? max( 5, (int) $input['self_ping_interval_minutes'] ) : 5,
					// [v2 신규] 예약 발행 워치독 사용 여부.
					'publish_watchdog'            => ! empty( $input['publish_watchdog'] ),
				);

			default:
				return array();
		}
	}

	/**
	 * 콘텐츠 허브(경로 분류/관련글/이전-다음글) 설정 입력값을 검증/정제합니다.
	 * sanitize_group_input()과 동일한 화이트리스트 원칙을 따르되, 옵션 저장소가
	 * 다르므로(Zorlinq32_Content_Hub 전용 옵션) 별도 메서드로 분리했습니다.
	 */
	private function sanitize_content_hub_input( $input ) {
		$rows    = isset( $input['related_rows'] ) ? (int) $input['related_rows'] : 2;
		$rows    = max( 1, min( $rows, 6 ) );

		$columns = isset( $input['related_columns'] ) ? (int) $input['related_columns'] : 4;
		$columns = max( 1, min( $columns, 6 ) );

		$allowed_order = array( 'date', 'rand', 'title' );
		$order_by      = isset( $input['related_order_by'] ) && in_array( $input['related_order_by'], $allowed_order, true )
			? $input['related_order_by']
			: 'date';

		return array(
			'enabled'          => ! empty( $input['enabled'] ),
			'path_taxonomy'    => ! empty( $input['path_taxonomy'] ),
			'related_posts'    => ! empty( $input['related_posts'] ),
			'related_rows'     => $rows,
			'related_columns'  => $columns,
			'related_order_by' => $order_by,
			'prev_next_nav'    => ! empty( $input['prev_next_nav'] ),
		);
	}

	/**
	 * 공통 AJAX 검증.
	 */
	private function verify_ajax_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '권한이 없습니다.', 'zorlinq32' ) ), 403 );
		}
		check_ajax_referer( 'zorlinq32_admin_nonce', 'nonce' );
	}

	public function ajax_clear_cache() {
		$this->verify_ajax_request();

		try {
			$cache = Zorlinq32_Cache::instance();
			$cache->clear_all_cache();
			wp_send_json_success( array( 'message' => __( '캐시를 모두 삭제했습니다.', 'zorlinq32' ) ) );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '캐시 삭제 AJAX 오류: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( '캐시 삭제 중 오류가 발생했습니다.', 'zorlinq32' ) ) );
		}
	}

	/**
	 * [요청 기능: 통계 초기화] 애널리틱스 방문 기록을 전부 삭제합니다.
	 * 파괴적인 작업이므로 별도 확인 nonce와 함께, 프론트엔드에서 반드시 확인 대화상자를
	 * 거치도록 하고(JS 측 처리), 서버에서도 관리자 권한을 재확인합니다.
	 */
	public function ajax_reset_analytics() {
		$this->verify_ajax_request();

		try {
			if ( ! class_exists( 'Zorlinq32_Analytics_DB' ) ) {
				wp_send_json_error( array( 'message' => __( '애널리틱스 모듈을 찾을 수 없습니다.', 'zorlinq32' ) ) );
			}
			$result = Zorlinq32_Analytics_DB::truncate_table();
			if ( false === $result ) {
				// [애널리틱스 초기화 오류 수정] 쿼리가 실제로 실패했는데도 항상 성공 메시지를
				// 보여주던 문제를 수정했습니다. 실패 시 DB 오류를 로그에 남기고 사용자에게도 알립니다.
				global $wpdb;
				Zorlinq32_Logger::log( '애널리틱스 초기화 쿼리 실패: ' . $wpdb->last_error );
				wp_send_json_error( array( 'message' => __( '통계 초기화 중 데이터베이스 오류가 발생했습니다.', 'zorlinq32' ) ) );
			}
			// 초기화 직후에도 대시보드/알림판 위젯이나 상세 통계 화면이 예전 캐시된 값을
			// 잠깐 보여주지 않도록, 모든 애널리틱스 쿼리 캐시를 함께 지웁니다.
			if ( class_exists( 'Zorlinq32_Analytics_Query' ) ) {
				Zorlinq32_Analytics_Query::flush_all_query_cache();
			}
			wp_send_json_success( array( 'message' => __( '애널리틱스 통계를 모두 초기화했습니다.', 'zorlinq32' ) ) );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '애널리틱스 초기화 AJAX 오류: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( '통계 초기화 중 오류가 발생했습니다.', 'zorlinq32' ) ) );
		}
	}

	public function ajax_refresh_storage() {
		$this->verify_ajax_request();

		try {
			$monitor = Zorlinq32_Storage_Monitor::instance();
			// 관리자가 명시적으로 "새로고침" 버튼을 눌렀을 때는 캐시를 우회해 항상 최신 값을 가져옵니다.
			$usage   = $monitor->get_disk_usage( true );
			wp_send_json_success( $usage );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '스토리지 새로고침 AJAX 오류: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( '스토리지 정보를 가져올 수 없습니다.', 'zorlinq32' ) ) );
		}
	}

	/**
	 * 관리자가 "지금 정리" 버튼을 눌렀을 때 스토리지 정리를 즉시 실행합니다.
	 * 만료 여부와 무관하게 캐시성 파일을 전량 정리(aggressive)해 즉시 용량을 확보하며,
	 * 삭제한 파일 수와 확보한 용량을 응답으로 돌려줘 관리자가 결과를 바로 확인할 수 있습니다.
	 */
	public function ajax_cleanup_storage() {
		$this->verify_ajax_request();

		try {
			$monitor = Zorlinq32_Storage_Monitor::instance();
			$result  = $monitor->run_light_cleanup( true );
			$usage   = $monitor->get_disk_usage( true );

			wp_send_json_success(
				array(
					'files_removed'     => $result['files_removed'],
					'bytes_freed_human' => Zorlinq32_Storage_Monitor::format_bytes( $result['bytes_freed'] ),
					'usage'             => $usage,
				)
			);
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '스토리지 수동 정리 AJAX 오류: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( '정리 중 오류가 발생했습니다.', 'zorlinq32' ) ) );
		}
	}

	/**
	 * 자동 차단된 방문자를 관리자가 즉시 수동 해제합니다.
	 * (요구사항: 차단 해제 기간이 지나야 자동 해제되지만, 관리자가 즉시 해제할 수도 있어야 합니다.)
	 */
	public function ajax_unblock_visitor() {
		$this->verify_ajax_request();

		try {
			$ip = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';
			if ( empty( $ip ) || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				wp_send_json_error( array( 'message' => __( '유효하지 않은 IP입니다.', 'zorlinq32' ) ) );
			}

			Zorlinq32_AdSense_Protection::unblock_ip( $ip );
			wp_send_json_success( array( 'message' => __( '차단을 해제했습니다.', 'zorlinq32' ) ) );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '방문자 차단 해제 AJAX 오류: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( '처리 중 오류가 발생했습니다.', 'zorlinq32' ) ) );
		}
	}

	/**
	 * 관리자가 수동으로 등록한 차단 IP 목록에서 특정 IP를 제거합니다.
	 */
	public function ajax_remove_blocked_ip() {
		$this->verify_ajax_request();

		try {
			$ip = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';
			if ( empty( $ip ) ) {
				wp_send_json_error( array( 'message' => __( '유효하지 않은 IP입니다.', 'zorlinq32' ) ) );
			}

			$settings = Zorlinq32_Settings::get_group( 'adsense_protection' );
			$settings['blocked_ips'] = isset( $settings['blocked_ips'] ) ? array_values( array_diff( $settings['blocked_ips'], array( $ip ) ) ) : array();
			Zorlinq32_Settings::update_group( 'adsense_protection', $settings );

			wp_send_json_success( array( 'message' => __( '목록에서 제거했습니다.', 'zorlinq32' ) ) );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '차단 IP 제거 AJAX 오류: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( '처리 중 오류가 발생했습니다.', 'zorlinq32' ) ) );
		}
	}

	/**
	 * 팝업을 추가하거나(popup_id가 비어있으면) 기존 팝업을 수정합니다.
	 */
	public function ajax_save_popup() {
		$this->verify_ajax_request();

		try {
			$popup_settings = Zorlinq32_Settings::get_group( 'popup' );
			$popups         = isset( $popup_settings['popups'] ) && is_array( $popup_settings['popups'] ) ? $popup_settings['popups'] : array();

			$popup_id = isset( $_POST['popup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['popup_id'] ) ) : '';
			$type     = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'text';
			if ( ! in_array( $type, array( 'image', 'html', 'text' ), true ) ) {
				$type = 'text';
			}

			$frequency = isset( $_POST['frequency'] ) ? sanitize_key( wp_unslash( $_POST['frequency'] ) ) : 'always';
			if ( ! in_array( $frequency, array( 'always', 'once_per_session', 'once_per_day', 'once_per_week' ), true ) ) {
				$frequency = 'always';
			}

			$new_popup = array(
				'id'            => ! empty( $popup_id ) ? $popup_id : 'popup_' . uniqid(),
				'type'          => $type,
				'image_id'      => isset( $_POST['image_id'] ) ? (int) $_POST['image_id'] : 0,
				// HTML 코드는 관리자(manage_options)만 저장할 수 있으며(verify_ajax_request에서 이미 검증됨),
				// 코드 삽입 기능과 동일하게 의도적으로 태그를 제거하지 않습니다.
				'html_code'     => isset( $_POST['html_code'] ) ? wp_unslash( $_POST['html_code'] ) : '',
				'text_content'  => isset( $_POST['text_content'] ) ? wp_kses_post( wp_unslash( $_POST['text_content'] ) ) : '',
				'link_url'      => isset( $_POST['link_url'] ) ? esc_url_raw( wp_unslash( $_POST['link_url'] ) ) : '',
				'frequency'     => $frequency,
				'delay_seconds' => isset( $_POST['delay_seconds'] ) ? max( 0, min( 300, (int) $_POST['delay_seconds'] ) ) : 0,
				'active'        => ! empty( $_POST['active'] ),
			);

			$found = false;
			foreach ( $popups as $index => $existing ) {
				if ( isset( $existing['id'] ) && $existing['id'] === $new_popup['id'] ) {
					$popups[ $index ] = $new_popup;
					$found            = true;
					break;
				}
			}
			if ( ! $found ) {
				$popups[] = $new_popup;
			}

			$popup_settings['popups'] = $popups;
			Zorlinq32_Settings::update_group( 'popup', $popup_settings );

			wp_send_json_success( array( 'message' => __( '팝업을 저장했습니다.', 'zorlinq32' ), 'popup' => $new_popup ) );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '팝업 저장 AJAX 오류: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( '저장 중 오류가 발생했습니다.', 'zorlinq32' ) ) );
		}
	}

	public function ajax_delete_popup() {
		$this->verify_ajax_request();

		try {
			$popup_id = isset( $_POST['popup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['popup_id'] ) ) : '';
			if ( empty( $popup_id ) ) {
				wp_send_json_error( array( 'message' => __( '잘못된 요청입니다.', 'zorlinq32' ) ) );
			}

			$popup_settings = Zorlinq32_Settings::get_group( 'popup' );
			$popups         = isset( $popup_settings['popups'] ) && is_array( $popup_settings['popups'] ) ? $popup_settings['popups'] : array();

			$popups = array_values(
				array_filter(
					$popups,
					function ( $popup ) use ( $popup_id ) {
						return ! isset( $popup['id'] ) || $popup['id'] !== $popup_id;
					}
				)
			);

			$popup_settings['popups'] = $popups;
			Zorlinq32_Settings::update_group( 'popup', $popup_settings );

			wp_send_json_success( array( 'message' => __( '팝업을 삭제했습니다.', 'zorlinq32' ) ) );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '팝업 삭제 AJAX 오류: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( '삭제 중 오류가 발생했습니다.', 'zorlinq32' ) ) );
		}
	}

	public function ajax_toggle_popup() {
		$this->verify_ajax_request();

		try {
			$popup_id = isset( $_POST['popup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['popup_id'] ) ) : '';
			if ( empty( $popup_id ) ) {
				wp_send_json_error( array( 'message' => __( '잘못된 요청입니다.', 'zorlinq32' ) ) );
			}

			$popup_settings = Zorlinq32_Settings::get_group( 'popup' );
			$popups         = isset( $popup_settings['popups'] ) && is_array( $popup_settings['popups'] ) ? $popup_settings['popups'] : array();

			foreach ( $popups as $index => $popup ) {
				if ( isset( $popup['id'] ) && $popup['id'] === $popup_id ) {
					$popups[ $index ]['active'] = empty( $popup['active'] );
					break;
				}
			}

			$popup_settings['popups'] = $popups;
			Zorlinq32_Settings::update_group( 'popup', $popup_settings );

			wp_send_json_success();
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '팝업 토글 AJAX 오류: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( '처리 중 오류가 발생했습니다.', 'zorlinq32' ) ) );
		}
	}

	/**
	 * Op 템플릿(패턴, wp_block 글) 삭제.
	 */
	public function ajax_delete_template() {
		$this->verify_ajax_request();

		try {
			$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
			if ( ! $post_id ) {
				wp_send_json_error( array( 'message' => __( '잘못된 요청입니다.', 'zorlinq32' ) ) );
			}

			$deleted = Zorlinq32_Templates::delete_pattern( $post_id );
			if ( ! $deleted ) {
				wp_send_json_error( array( 'message' => __( '삭제할 수 없습니다.', 'zorlinq32' ) ) );
			}

			wp_send_json_success( array( 'message' => __( '템플릿을 삭제했습니다.', 'zorlinq32' ) ) );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( 'Op 템플릿 삭제 AJAX 오류: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( '처리 중 오류가 발생했습니다.', 'zorlinq32' ) ) );
		}
	}

	/**
	 * [기능 정리] 과거 SEO 메타박스(SEO 점수/OG 이미지 선택) 전용 스크립트를 로드하던 지점이었으나,
	 * 해당 SEO 기능 자체가 제거되어 더 이상 아무 것도 로드하지 않습니다.
	 */
	public function enqueue_post_editor_assets( $hook ) {
		// 의도적으로 비워둡니다. (하위 호환을 위해 훅 등록/메서드 자체는 유지)
	}

	/* ---------------- 렌더링 ---------------- */

	public function render_dashboard_page() {
		$settings = Zorlinq32_Settings::get_all();
		$monitor  = Zorlinq32_Storage_Monitor::instance();
		$usage    = $monitor->get_disk_usage();

		// 대시보드 카드에서 보여줄 최근 7일 방문 추이(미니 그래프)와 오늘/이번주 요약.
		$analytics_summary = array(
			'available' => false,
		);
		if ( ! empty( $settings['analytics']['enabled'] ) && class_exists( 'Zorlinq32_Analytics_Query' ) ) {
			list( $zorlinq32_dash_start, $zorlinq32_dash_end ) = Zorlinq32_Analytics_Query::resolve_date_range( '7days' );
			$analytics_summary = array(
				'available' => true,
				'trend'     => Zorlinq32_Analytics_Query::get_daily_trend( $zorlinq32_dash_start, $zorlinq32_dash_end ),
				'quick'     => Zorlinq32_Analytics_Query::get_quick_summary(),
			);
		}

		/* ⚠️ 2026-08 추가: "AI 글쓰기"/"AI 썸네일 템플릿"은 다른 모듈과 달리
		 * Zorlinq32_Settings 그룹이 아니라 개별 get_option()으로 관리되므로,
		 * 대시보드 모듈 리스트에서 사용 중/미사용 배지를 표시하기 위해
		 * 여기서 별도로 "설정 완료 여부"를 계산해 템플릿에 넘긴다.
		 * (Gemini API 키가 최소 1개 등록되어 있으면 "사용 중"으로 간주) */
		$ai_gemini_keys        = get_option( 'zorlinq32_ai_gemini_api_keys', array() );
		$ai_writer_configured  = is_array( $ai_gemini_keys ) && ! empty( array_filter( $ai_gemini_keys ) );
		// 2026-08 개편: 썸네일 이미지 생성이 Google Flow(브라우저 사용)로
		// 전환되면서, Worker URL 등록 여부와 무관하게 항상 사용 가능하다.
		$ai_thumb_configured   = true;

		include ZORLINQ32_DIR . 'templates/page-dashboard.php';
	}

	public function render_analytics_page() {
		$settings = Zorlinq32_Settings::get_group( 'analytics' );

		$range = isset( $_GET['range'] ) ? sanitize_text_field( wp_unslash( $_GET['range'] ) ) : '7days'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 페이지 조회 시 필터 파라미터를 읽는 것으로, 상태를 변경하지 않는 GET 요청입니다.
		$allowed_ranges = array( 'today', '7days', '30days', '12months' );
		if ( ! in_array( $range, $allowed_ranges, true ) ) {
			$range = '7days';
		}

		$table_ready = false;
		$trend       = array();
		$channel     = array(
			'organic'  => array(),
			'referral' => array(),
			'direct'   => 0,
			'total'    => 0,
		);
		$keywords  = array();
		$top_posts = array();
		$total     = array(
			'pageviews' => 0,
			'visitors'  => 0,
		);

		if ( ! empty( $settings['enabled'] ) && class_exists( 'Zorlinq32_Analytics_Query' ) ) {
			list( $start_date, $end_date ) = Zorlinq32_Analytics_Query::resolve_date_range( $range );
			$trend       = Zorlinq32_Analytics_Query::get_daily_trend( $start_date, $end_date );
			$channel     = Zorlinq32_Analytics_Query::get_channel_breakdown( $start_date, $end_date );
			$keywords    = Zorlinq32_Analytics_Query::get_top_keywords( $start_date, $end_date );
			$top_posts   = Zorlinq32_Analytics_Query::get_top_posts( $start_date, $end_date );
			$total       = Zorlinq32_Analytics_Query::get_total_count( $start_date, $end_date );
			$table_ready = true;
		}

		include ZORLINQ32_DIR . 'templates/page-analytics.php';
	}

	public function render_adsense_protection_page() {
		$settings       = Zorlinq32_Settings::get_group( 'adsense_protection' );
		$country_options = class_exists( 'Zorlinq32_GeoIP' ) ? Zorlinq32_GeoIP::get_country_options() : array();
		$active_blocks   = class_exists( 'Zorlinq32_AdSense_Protection' ) ? Zorlinq32_AdSense_Protection::get_active_blocks() : array();
		// [애드센스 보호: 엣지 신호 활용] Cloudflare/AWS는 오직 국가·IP 판별 정확도를 높여
		// "광고 소거" 판단에만 사용됩니다. 접속 차단 규칙 생성 기능은 없습니다.
		$using_cloudflare_header   = class_exists( 'Zorlinq32_Edge_Protection' ) && Zorlinq32_Edge_Protection::is_behind_cloudflare();
		$aws_environment_detected = class_exists( 'Zorlinq32_Edge_Protection' ) && Zorlinq32_Edge_Protection::is_aws_environment_detected();

		include ZORLINQ32_DIR . 'templates/page-adsense-protection.php';
	}

	public function render_popup_page() {
		$settings = Zorlinq32_Settings::get_group( 'popup' );
		$popups   = isset( $settings['popups'] ) && is_array( $settings['popups'] ) ? $settings['popups'] : array();
		include ZORLINQ32_DIR . 'templates/page-popup.php';
	}

	public function render_templates_page() {
		$patterns = class_exists( 'Zorlinq32_Templates' ) ? Zorlinq32_Templates::get_user_patterns() : array();
		include ZORLINQ32_DIR . 'templates/page-templates.php';
	}

	public function render_login_page() {
		$settings = Zorlinq32_Settings::get_group( 'login_security' );
		include ZORLINQ32_DIR . 'templates/page-login-security.php';
	}

	public function render_cache_page() {
		$settings = Zorlinq32_Settings::get_group( 'cache' );
		$cache    = Zorlinq32_Cache::instance();
		$cache_size = $cache->get_cache_size_bytes();
		include ZORLINQ32_DIR . 'templates/page-cache.php';
	}

	public function render_storage_page() {
		$settings = Zorlinq32_Settings::get_group( 'storage_monitor' );
		$monitor  = Zorlinq32_Storage_Monitor::instance();
		$usage    = $monitor->get_disk_usage();
		include ZORLINQ32_DIR . 'templates/page-storage.php';
	}

	public function render_indexing_page() {
		$settings = Zorlinq32_Settings::get_group( 'indexing' );
		$indexnow_key = class_exists( 'Zorlinq32_Indexing' ) ? Zorlinq32_Indexing::get_or_create_indexnow_key() : '';
		// [요청 기능] 자동 색인 페이지에서 RSS 피드와 사이트맵 주소를 함께 확인/제출할 수 있도록 전달합니다.
		$rss_feed_url = get_bloginfo( 'rss2_url' );
		include ZORLINQ32_DIR . 'templates/page-indexing.php';
	}

	public function render_code_injection_page() {
		$settings = Zorlinq32_Settings::get_group( 'code_injection' );
		include ZORLINQ32_DIR . 'templates/page-code-injection.php';
	}

	public function render_performance_page() {
		$settings = Zorlinq32_Settings::get_group( 'performance' );
		include ZORLINQ32_DIR . 'templates/page-performance.php';
	}

	public function render_security_page() {
		$settings = Zorlinq32_Settings::get_group( 'security' );
		include ZORLINQ32_DIR . 'templates/page-security.php';
	}

	public function render_admin_experience_page() {
		$settings = Zorlinq32_Settings::get_group( 'admin_experience' );
		include ZORLINQ32_DIR . 'templates/page-admin-experience.php';
	}

	public function render_content_optimizer_page() {
		$settings = Zorlinq32_Settings::get_group( 'content_optimizer' );
		include ZORLINQ32_DIR . 'templates/page-content-optimizer.php';
	}

	public function render_cron_guardian_page() {
		if ( isset( $_POST['zorlinq32_clear_cron_log'] ) && check_admin_referer( 'zorlinq32_clear_cron_log' ) ) {
			Zorlinq32_Cron_Guardian::clear_missed_log();
		}
		$settings     = Zorlinq32_Settings::get_group( 'cron_guardian' );
		$missed_log   = Zorlinq32_Cron_Guardian::get_missed_log();
		$health       = Zorlinq32_Cron_Guardian::get_health_summary();
		$server_cron_configured = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		// [v2 신규] 예약 발행 워치독이 실제로 개입해 대신 발행 처리한 이력과, 가장 최근 self-ping 시도 결과.
		$publish_watchdog_log = class_exists( 'Zorlinq32_Cron_Guardian' ) ? Zorlinq32_Cron_Guardian::get_publish_watchdog_log() : array();
		$last_ping_result     = class_exists( 'Zorlinq32_Cron_Guardian' ) ? Zorlinq32_Cron_Guardian::get_last_self_ping_result() : array( 'time' => 0, 'success' => null, 'message' => '' );
		include ZORLINQ32_DIR . 'templates/page-cron-guardian.php';
	}

	public function render_logs_page() {
		if ( isset( $_POST['zorlinq32_clear_logs'] ) && check_admin_referer( 'zorlinq32_clear_logs' ) ) {
			Zorlinq32_Logger::clear_logs();
		}
		$logs = Zorlinq32_Logger::get_logs();
		include ZORLINQ32_DIR . 'templates/page-logs.php';
	}

	/**
	 * 콘텐츠 허브(경로 분류 / 관련글 / 이전-다음글) 설정 페이지.
	 */
	public function render_content_hub_page() {
		if ( ! class_exists( 'Zorlinq32_Content_Hub' ) ) {
			echo '<div class="wrap"><p>' . esc_html__( '콘텐츠 허브 모듈을 불러올 수 없습니다.', 'zorlinq32' ) . '</p></div>';
			return;
		}
		$settings = Zorlinq32_Content_Hub::get_settings();
		$paths    = get_terms( array(
			'taxonomy'   => Zorlinq32_Content_Hub::TAXONOMY,
			'hide_empty' => false,
		) );
		if ( is_wp_error( $paths ) ) {
			$paths = array();
		}
		include ZORLINQ32_DIR . 'templates/page-content-hub.php';
	}
}
