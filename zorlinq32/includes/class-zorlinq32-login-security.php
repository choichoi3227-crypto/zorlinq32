<?php
/**
 * 관리자 로그인 링크 변경 모듈.
 *
 * wp-login.php / wp-admin 직접 접근을 차단하고
 * 사용자가 지정한 커스텀 슬러그로만 로그인 화면에 접근할 수 있도록 합니다.
 *
 * 안전장치:
 * - AJAX, REST API, cron, 로그인 후 리다이렉트 등 워드프레스 핵심 동작을
 *   절대 막지 않도록 예외 목록을 둡니다.
 * - 슬러그가 비어있거나 다른 페이지와 충돌하면 기능을 자동으로 비활성화합니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Login_Security {

	private static $instance = null;
	private $settings = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = Zorlinq32_Settings::get_group( 'login_security' );

		if ( empty( $this->settings['enabled'] ) ) {
			return;
		}

		$slug = $this->get_safe_slug();
		if ( empty( $slug ) ) {
			// 슬러그가 유효하지 않으면 기능을 켜지 않고 조용히 종료 (사이트 잠금 방지).
			return;
		}

		add_action( 'init', array( $this, 'maybe_render_custom_login' ), 0 );
		add_action( 'init', array( $this, 'maybe_block_default_login' ), 1 );
		add_filter( 'site_url', array( $this, 'filter_login_url' ), 10, 4 );
		add_filter( 'network_site_url', array( $this, 'filter_login_url' ), 10, 3 );
		add_filter( 'wp_redirect', array( $this, 'filter_redirect' ), 10, 2 );
	}

	/**
	 * 현재 요청 경로가 커스텀 로그인 슬러그와 정확히 일치하는지 확인합니다.
	 * strpos 기반 부분 일치가 아닌, 경로 전체를 비교하는 엄격한 매칭을 사용합니다
	 * (예: '/my-login-page'라는 일반 페이지가 슬러그 'login'을 부분 포함해 오작동하는 것을 방지).
	 */
	private function is_custom_login_request( $custom_slug ) {
		if ( empty( $custom_slug ) ) {
			return false;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $request_uri ) {
			return false;
		}

		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( empty( $path ) ) {
			return false;
		}

		return trim( $path, '/' ) === trim( $custom_slug, '/' );
	}

	/**
	 * 커스텀 슬러그로 들어온 요청을 실제 wp-login.php 화면으로 로드합니다.
	 * 이 처리가 없으면 로그인 URL을 아무리 바꿔도 새 주소로 접근할 방법이 없어
	 * 관리자를 포함한 그 누구도 로그인할 수 없는 상태(완전 잠금)가 됩니다.
	 */
	public function maybe_render_custom_login() {
		if ( $this->is_protected_context() ) {
			return;
		}

		$custom_slug = $this->get_safe_slug();
		if ( ! $this->is_custom_login_request( $custom_slug ) ) {
			return;
		}

		$login_file = ABSPATH . 'wp-login.php';
		if ( ! file_exists( $login_file ) ) {
			// wp-login.php가 없는 비정상 상황에서도 사이트를 잠그지 않고 그대로 통과시킵니다.
			Zorlinq32_Logger::log( '커스텀 로그인 처리 실패: wp-login.php 파일을 찾을 수 없습니다.' );
			return;
		}

		global $error, $interim_login, $action, $user_login;

		// wp-login.php는 폼 action, 리다이렉트 URL 등을 site_url()/wp_login_url() 함수로
		// 생성하므로, 이미 등록된 site_url/network_site_url 필터를 통해 커스텀 슬러그가
		// 자동으로 유지됩니다.
		require $login_file;
		exit;
	}

	/**
	 * 커스텀 슬러그를 검증하여 반환합니다. 예약어나 빈 값이면 빈 문자열을 반환합니다.
	 */
	private function get_safe_slug() {
		$slug = isset( $this->settings['custom_slug'] ) ? sanitize_title( $this->settings['custom_slug'] ) : '';

		$reserved = array( 'wp-admin', 'wp-login', 'wp-login.php', 'admin', 'login', '' );
		if ( in_array( $slug, $reserved, true ) ) {
			return '';
		}

		return $slug;
	}

	/**
	 * REST API, AJAX, cron 요청, 로그인된 관리자 요청은 절대 차단하지 않습니다.
	 * 이 예외 처리가 없으면 사이트 전체가 잠길 위험이 있으므로 최우선으로 검사합니다.
	 */
	private function is_protected_context() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		return false;
	}

	/**
	 * 기본 wp-login.php 경로로 들어온, 로그인하지 않은 사용자의 요청을 404로 차단합니다.
	 * 커스텀 슬러그로 들어온 요청은 이 함수보다 먼저 실행되는 maybe_render_custom_login()이
	 * wp-login.php를 로드하고 즉시 종료하므로, 이 함수에는 절대 도달하지 않습니다.
	 */
	public function maybe_block_default_login() {
		if ( $this->is_protected_context() ) {
			return;
		}

		// 이미 로그인된 관리자는 wp-admin 접근을 허용합니다 (관리자가 스스로 잠기는 것을 방지).
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $request_uri ) {
			return;
		}

		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( empty( $path ) ) {
			return;
		}

		$is_default_login = ( false !== strpos( $path, 'wp-login.php' ) );

		if ( $is_default_login ) {
			$this->safe_send_404();
		}
	}

	/**
	 * 404 응답을 안전하게 전송합니다. 템플릿 로드에 실패하더라도
	 * 최소한의 헤더/본문으로 사이트가 죽지 않도록 처리합니다.
	 */
	private function safe_send_404() {
		try {
			status_header( 404 );
			nocache_headers();

			if ( function_exists( 'get_query_template' ) ) {
				$template = get_query_template( '404' );
				if ( $template ) {
					include $template;
					exit;
				}
			}
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '로그인 보안 404 처리 중 오류: ' . $e->getMessage() );
		}

		// 템플릿 로드 실패 시 최소한의 안전한 출력.
		echo '404 Not Found';
		exit;
	}

	/**
	 * 로그인 URL을 생성할 때 커스텀 슬러그로 치환합니다 (워드프레스 내부 링크 생성용).
	 */
	public function filter_login_url( $url, $path = '', $scheme = null, $blog_id = null ) {
		if ( is_string( $url ) && false !== strpos( $url, 'wp-login.php' ) ) {
			$slug = $this->get_safe_slug();
			if ( ! empty( $slug ) ) {
				return str_replace( 'wp-login.php', $slug, $url );
			}
		}
		return $url;
	}

	/**
	 * wp_redirect 호출 시 wp-login.php로 향하는 리다이렉트를 커스텀 슬러그로 보정합니다.
	 */
	public function filter_redirect( $location, $status ) {
		if ( is_string( $location ) && false !== strpos( $location, 'wp-login.php' ) ) {
			$slug = $this->get_safe_slug();
			if ( ! empty( $slug ) ) {
				return str_replace( 'wp-login.php', $slug, $location );
			}
		}
		return $location;
	}
}
