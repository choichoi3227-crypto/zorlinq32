<?php
/**
 * 보안 강화 모듈.
 *
 * 포함 기능:
 * 1. XML-RPC 비활성화 (무차별 대입 공격의 주요 경로 차단)
 * 2. 로그인 시도 횟수 제한 (일정 횟수 실패 시 일시 잠금)
 * 3. 관리자 화면 내 테마/플러그인 파일 편집기 비활성화
 * 4. 워드프레스 버전 정보 노출 제거
 * 5. 기본 보안 헤더 추가 (X-Content-Type-Options, X-Frame-Options, Referrer-Policy)
 * 6. 존재하지 않는 사용자명으로 로그인 시도 시 사용자명 존재 여부 노출 방지
 * 7. REST API 사용자 목록(enumeration) 노출 제한
 *
 * 모든 기능은 기본 OFF이며, 사이트 정상 기능(정상 로그인, 정상 API 사용)을
 * 방해하지 않도록 예외 조건을 명확히 둡니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Security {

	private static $instance = null;
	private $settings = array();

	const LOGIN_ATTEMPTS_TRANSIENT_PREFIX = 'zorlinq32_login_attempts_';
	const LOGIN_LOCKOUT_TRANSIENT_PREFIX  = 'zorlinq32_login_lockout_';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = Zorlinq32_Settings::get_group( 'security' );

		if ( empty( $this->settings['enabled'] ) ) {
			return;
		}

		if ( ! empty( $this->settings['disable_xmlrpc'] ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'wp_headers', array( $this, 'remove_pingback_header' ) );
		}

		if ( ! empty( $this->settings['limit_login_attempts'] ) ) {
			add_filter( 'authenticate', array( $this, 'check_login_lockout' ), 30, 1 );
			add_action( 'wp_login_failed', array( $this, 'record_failed_attempt' ) );
			add_action( 'wp_login', array( $this, 'clear_attempts_on_success' ), 10, 2 );
		}

		if ( ! empty( $this->settings['disable_file_editor'] ) ) {
			if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
				// wp-config.php에 이미 상수가 정의된 경우 재정의로 인한 충돌을 방지합니다.
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- DISALLOW_FILE_EDIT는 워드프레스 코어가 인식하는 고정된 상수명이라 플러그인 프리픽스를 붙일 수 없습니다.
				define( 'DISALLOW_FILE_EDIT', true );
			}
		}

		if ( ! empty( $this->settings['hide_wp_version'] ) ) {
			add_filter( 'the_generator', '__return_empty_string' );
		}

		if ( ! empty( $this->settings['security_headers'] ) ) {
			add_action( 'send_headers', array( $this, 'send_security_headers' ) );
		}

		if ( ! empty( $this->settings['prevent_username_enum'] ) ) {
			add_filter( 'rest_endpoints', array( $this, 'restrict_users_rest_endpoint' ) );
			add_action( 'template_redirect', array( $this, 'block_author_archive_enum' ) );
		}
	}

	/**
	 * XML-RPC pingback 헤더를 응답에서 제거합니다.
	 */
	public function remove_pingback_header( $headers ) {
		if ( is_array( $headers ) && isset( $headers['X-Pingback'] ) ) {
			unset( $headers['X-Pingback'] );
		}
		return $headers;
	}

	/**
	 * 현재 요청의 클라이언트 IP를 안전하게 가져옵니다.
	 * 프록시 헤더는 스푸핑될 수 있으므로 REMOTE_ADDR을 우선 신뢰합니다.
	 */
	private function get_client_ip() {
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return '0.0.0.0';
	}

	/**
	 * 로그인 시도 전, 현재 IP가 잠금 상태인지 확인합니다.
	 * 잠금 상태라면 실제 인증 로직을 실행하지 않고 즉시 오류를 반환합니다.
	 */
	public function check_login_lockout( $user ) {
		$ip = $this->get_client_ip();
		$lockout_until = get_transient( self::LOGIN_LOCKOUT_TRANSIENT_PREFIX . md5( $ip ) );

		if ( $lockout_until && time() < $lockout_until ) {
			$remaining_minutes = ceil( ( $lockout_until - time() ) / MINUTE_IN_SECONDS );
			return new WP_Error(
				'zorlinq32_login_locked',
				sprintf(
					/* translators: %d: 남은 분 */
					__( '로그인 시도가 너무 많습니다. %d분 후 다시 시도해주세요.', 'zorlinq32' ),
					$remaining_minutes
				)
			);
		}

		return $user;
	}

	/**
	 * 로그인 실패 시 시도 횟수를 누적하고, 임계치 초과 시 일정 시간 잠급니다.
	 */
	public function record_failed_attempt() {
		try {
			$ip = $this->get_client_ip();
			$key = self::LOGIN_ATTEMPTS_TRANSIENT_PREFIX . md5( $ip );

			$attempts = (int) get_transient( $key );
			$attempts++;

			$max_attempts   = isset( $this->settings['max_login_attempts'] ) ? (int) $this->settings['max_login_attempts'] : 5;
			$lockout_minutes = isset( $this->settings['lockout_minutes'] ) ? (int) $this->settings['lockout_minutes'] : 15;

			// 시도 횟수는 15분 동안 누적합니다.
			set_transient( $key, $attempts, 15 * MINUTE_IN_SECONDS );

			if ( $attempts >= max( 1, $max_attempts ) ) {
				set_transient(
					self::LOGIN_LOCKOUT_TRANSIENT_PREFIX . md5( $ip ),
					time() + ( max( 1, $lockout_minutes ) * MINUTE_IN_SECONDS ),
					max( 1, $lockout_minutes ) * MINUTE_IN_SECONDS
				);
			}
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '로그인 시도 기록 중 오류: ' . $e->getMessage() );
		}
	}

	/**
	 * 로그인 성공 시 해당 IP의 실패 기록을 초기화합니다.
	 */
	public function clear_attempts_on_success( $user_login, $user ) {
		$ip = $this->get_client_ip();
		delete_transient( self::LOGIN_ATTEMPTS_TRANSIENT_PREFIX . md5( $ip ) );
		delete_transient( self::LOGIN_LOCKOUT_TRANSIENT_PREFIX . md5( $ip ) );
	}

	/**
	 * 기본 보안 헤더를 추가합니다. 이미 다른 플러그인/서버 설정이 헤더를 지정했다면
	 * 워드프레스가 중복 헤더를 자동으로 덮어쓰므로 충돌 위험이 낮습니다.
	 */
	public function send_security_headers() {
		if ( headers_sent() ) {
			return;
		}
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		// X-Frame-Options은 관리자 화면에서 일부 플러그인(미리보기 등)과 충돌할 수 있어 프론트엔드에만 적용합니다.
		if ( ! is_admin() ) {
			header( 'X-Frame-Options: SAMEORIGIN' );
		}
	}

	/**
	 * REST API의 /wp/v2/users 엔드포인트를 관리자에게만 노출합니다.
	 * 로그인 사용자 목록 자체 조회 기능은 그대로 유지해 코어 기능을 해치지 않습니다.
	 */
	public function restrict_users_rest_endpoint( $endpoints ) {
		if ( isset( $endpoints['/wp/v2/users'] ) && ! current_user_can( 'list_users' ) ) {
			unset( $endpoints['/wp/v2/users'] );
		}
		return $endpoints;
	}

	/**
	 * ?author=N 형태의 URL로 사용자명을 유추하는 공격을 차단합니다.
	 * 정상적인 저자 아카이브 페이지 기능(퍼머링크를 통한 접근)은 그대로 둡니다.
	 */
	public function block_author_archive_enum() {
		if ( is_admin() ) {
			return;
		}
		if ( isset( $_GET['author'] ) && is_numeric( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}
}
