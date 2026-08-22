<?php
/**
 * 헤더(<head>) / 바디 시작 직후 / 푸터(</body> 직전) 코드 삽입 모듈.
 *
 * 사용자가 입력한 스크립트(GA, 광고 코드, 커스텀 CSS 등)를 지정된 위치에 그대로 출력합니다.
 * 관리자 전용 기능이며(capability: manage_options로만 저장 가능), 저장 시점에는 스크립트 태그를
 * 제거하지 않습니다 - 이 기능의 목적 자체가 임의의 스크립트 삽입이기 때문입니다.
 * 대신 저장 가능한 사람을 최고 권한 관리자로 제한해 악용 경로를 최소화합니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Code_Injection {

	private static $instance = null;
	private $settings = array();
	private $body_code_rendered = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = Zorlinq32_Settings::get_group( 'code_injection' );

		if ( empty( $this->settings['enabled'] ) ) {
			return;
		}

		// 관리자 화면에는 절대 출력하지 않습니다 (관리자 UI가 사용자 스크립트로 깨지는 것을 방지).
		if ( is_admin() ) {
			return;
		}

		if ( ! empty( $this->settings['header_code'] ) ) {
			add_action( 'wp_head', array( $this, 'output_header_code' ), 99 );
		}
		if ( ! empty( $this->settings['body_code'] ) ) {
			add_action( 'wp_body_open', array( $this, 'output_body_code' ), 1 );
			// wp_body_open을 호출하지 않는 구형 테마 대비 폴백: wp_footer 시점까지
			// 바디 코드가 출력되지 않았다면 위치가 완벽하지 않더라도 최소한 유실은 막습니다.
			add_action( 'wp_footer', array( $this, 'output_body_code_fallback' ), 1 );
		}
		if ( ! empty( $this->settings['footer_code'] ) ) {
			add_action( 'wp_footer', array( $this, 'output_footer_code' ), 99 );
		}
	}

	/**
	 * <head> 안, 다른 SEO/성능 관련 태그들이 출력된 뒤(우선순위 99)에 삽입합니다.
	 */
	public function output_header_code() {
		$this->safe_echo_raw( $this->settings['header_code'], '헤더' );
	}

	/**
	 * <body> 시작 직후(wp_body_open)에 삽입합니다.
	 * wp_body_open은 워드프레스 5.2+ 코어 훅으로, 대부분의 최신 테마가 지원합니다.
	 */
	public function output_body_code() {
		$this->body_code_rendered = true;
		$this->safe_echo_raw( $this->settings['body_code'], '바디' );
	}

	/**
	 * wp_body_open이 테마에서 호출되지 않아 위 output_body_code()가 한 번도 실행되지
	 * 않은 경우에만 동작하는 폴백입니다. 정상 테마에서는 $body_code_rendered가 이미
	 * true이므로 아무 것도 출력하지 않습니다.
	 */
	public function output_body_code_fallback() {
		if ( $this->body_code_rendered ) {
			return;
		}
		$this->safe_echo_raw( $this->settings['body_code'], '바디(폴백 위치)' );
	}

	/**
	 * </body> 직전(wp_footer)에 삽입합니다.
	 */
	public function output_footer_code() {
		$this->safe_echo_raw( $this->settings['footer_code'], '푸터' );
	}

	/**
	 * 사용자가 입력한 원본 코드를 그대로 출력합니다.
	 * 이 기능의 특성상 esc_html() 등으로 이스케이프하면 스크립트 태그 자체가 무력화되므로
	 * 의도적으로 이스케이프하지 않습니다. 대신 저장 경로(관리자 화면)에서 manage_options
	 * 권한 및 nonce 검증을 거친 값만 여기 도달하도록 강제합니다.
	 */
	private function safe_echo_raw( $code, $label ) {
		try {
			if ( empty( $code ) ) {
				return;
			}
			echo "\n<!-- Zorlinq32: {$label} 코드 삽입 시작 -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 라벨은 하드코딩된 문자열만 사용되어 사용자 입력이 아닙니다.
			echo $code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 사용자가 등록한 임의 스크립트를 그대로 삽입하는 것이 이 기능의 목적이며, manage_options 권한자만 저장 가능합니다.
			echo "\n<!-- Zorlinq32: {$label} 코드 삽입 끝 -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( sprintf( '%s 코드 삽입 중 오류: %s', $label, $e->getMessage() ) );
		}
	}
}
