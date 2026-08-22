<?php
/**
 * 플러그인 설정(옵션) 관리 클래스.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Settings {

	const OPTION_KEY = 'zorlinq32_settings';

	/**
	 * 기본 설정값. 모든 기능은 명시적으로 켜야 동작하도록
	 * 로그인 링크 변경을 제외하고는 기본 OFF로 두어
	 * 예상치 못한 사이트 동작 변화를 방지합니다.
	 */
	public static function get_default_settings() {
		return array(
			'login_security' => array(
				'enabled'    => false,
				'custom_slug'=> 'secure-login',
				'redirect_403' => true,
			),
			'cache' => array(
				'enabled'         => false,
				'cache_lifetime'  => 24, // 시간 단위
				'exclude_logged_in' => true,
			),
			'storage_monitor' => array(
				'enabled'          => true,
				'warning_threshold'=> 80, // %
				'critical_threshold' => 95, // %
				'notify_admin_email' => true,
			),
			// [기능 정리] 순수 SEO 기능(메타 설명, OG 태그, 사이트 인증코드 등)과 XML 사이트맵
			// 자체 생성 기능은 제거하고, 실제로 검색엔진 노출에 필요한 "robots.txt + 자동
			// 색인 요청(IndexNow)"만 이 'indexing' 그룹 하나로 통합했습니다.
			'indexing' => array(
				'enabled'                => false,
				'indexnow_enabled'       => false,
				'indexnow_key'           => '',
				'auto_submit_on_publish' => true,
				'auto_submit_on_update'  => true,
				'noindex_archives'       => false,
				'custom_robots_rules'    => '',
			),
			'code_injection' => array(
				'enabled'      => false,
				'header_code'  => '',
				'body_code'    => '',
				'footer_code'  => '',
			),
			'analytics' => array(
				'enabled'           => false,
				'retention_days'    => 400,
				// [애널리틱스 정확도 개선] 관리자 자신의 트래픽을 통계에서 제외하는 기능.
				// 기본값을 켜두어(true), 새로 활성화하는 사용자는 처음부터 정확한 수치를 봅니다.
				'exclude_admin_ips' => true,
				'excluded_ip_list'  => array(),
				// [요청 기능: 애널리틱스 캐시 시간 설정] 조회 결과를 얼마나 오래 캐시할지(분).
				// 짧을수록 실시간에 가깝지만 서버 부하가 커지고, 길수록 부하는 줄지만
				// 최신 방문이 반영되기까지 지연이 생깁니다. 기본값 5분은 대부분의 사이트에
				// 적절한 균형점입니다.
				'cache_minutes'     => 5,
			),
			'adsense_protection' => array(
				'enabled'            => false,
				'ad_client_id'       => '',
				'max_clicks'         => 3,
				'detection_hours'    => 1,
				'block_duration_days'=> 7,
				'blocked_countries'  => array(), // 국가 코드(ISO 3166-1 alpha-2) 배열, 예: array('KP', 'CN') - 광고 소거 판단에만 사용
				'blocked_ips'        => array(), // 광고를 소거할 수동 지정 IP 배열 - 사이트 접속과는 무관
			),
			'popup' => array(
				'enabled'         => false,
				'popups'          => array(), // 각 팝업: id, type(image/html/text), image_id, image_link, html_code, text_content, link_url, frequency(always/once_per_session/once_per_day/once_per_week), delay_seconds, active
			),
			'performance' => array(
				'enabled'                    => false,
				'limit_revisions'            => false,
				'revisions_limit'            => 5,
				'extend_autosave_interval'   => false,
				'autosave_interval_seconds'  => 120,
				'limit_heartbeat'            => false,
				'heartbeat_interval_seconds' => 60,
				'lazy_load_images'           => true,
				'disable_emojis'             => false,
				'disable_embeds'             => false,
				'remove_version_query_strings' => false,
				'auto_empty_trash_days'      => false,
			),
			'security' => array(
				'enabled'                => false,
				'disable_xmlrpc'         => false,
				'limit_login_attempts'   => false,
				'max_login_attempts'     => 5,
				'lockout_minutes'        => 15,
				'disable_file_editor'    => false,
				'hide_wp_version'        => false,
				'security_headers'       => false,
				'prevent_username_enum'  => false,
			),
			'admin_experience' => array(
				'enabled'                  => false,
				'clean_dashboard_widgets'  => false,
				'clean_admin_bar'          => false,
				'custom_login_logo_url'    => '',
				'featured_image_column'    => false,
			),
			'content_optimizer' => array(
				'enabled'                     => false,
				'comment_spam_protection'     => false,
				'disable_extra_image_sizes'   => false,
				'disabled_image_sizes'        => array(),
				'limit_search_post_types'     => false,
			),
			'cron_guardian' => array(
				'enabled'                     => false,
				'monitor_missed_jobs'         => true,
				'notify_on_delay'             => true,
				'auto_retry_missed'           => true,
				// [v2] self-ping 기본값을 ON으로 전환했습니다. 예전 기본값(false)이
				// "예약 발행이 실행되지 않는다"는 문제의 핵심 원인 중 하나였습니다 -
				// self-ping이 꺼져 있으면 WP-Cron은 순수하게 방문자 트래픽에만 의존하게 됩니다.
				'enable_self_ping'            => true,
				'self_ping_interval_minutes'  => 5,
				// [v2 신규] 예약 발행(포스트 예약) 전담 워치독. self-ping/서버크론 상태와
				// 무관하게 항상 동작하는 최후 안전망이므로 기본값을 ON으로 둡니다.
				'publish_watchdog'            => true,
			),
		);
	}

	/**
	 * 활성화 시 기존 설정이 없을 때만 기본값을 저장합니다.
	 */
	public static function set_defaults() {
		$existing = get_option( self::OPTION_KEY, false );
		if ( false === $existing ) {
			add_option( self::OPTION_KEY, self::get_default_settings() );
		}
	}

	/**
	 * 전체 설정을 반환합니다. 누락된 키는 기본값으로 병합해
	 * 업데이트 이후에도 안전하게 동작하도록 합니다.
	 */
	public static function get_all() {
		$defaults = self::get_default_settings();
		$saved    = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$merged = array();
		foreach ( $defaults as $group => $group_defaults ) {
			$merged[ $group ] = isset( $saved[ $group ] ) && is_array( $saved[ $group ] )
				? array_merge( $group_defaults, $saved[ $group ] )
				: $group_defaults;
		}

		return $merged;
	}

	/**
	 * 특정 그룹의 설정만 반환합니다. (예: 'cache', 'indexing')
	 */
	public static function get_group( $group ) {
		$all = self::get_all();
		return isset( $all[ $group ] ) ? $all[ $group ] : array();
	}

	/**
	 * 설정 전체를 저장합니다.
	 */
	public static function update_all( $settings ) {
		if ( ! is_array( $settings ) ) {
			return false;
		}
		return update_option( self::OPTION_KEY, $settings );
	}

	/**
	 * 특정 그룹만 갱신합니다.
	 */
	public static function update_group( $group, $group_settings ) {
		$all = self::get_all();
		$all[ $group ] = is_array( $group_settings ) ? $group_settings : array();
		return self::update_all( $all );
	}
}
