<?php
/**
 * 블록 에디터(구텐베르크) 확장 부트스트랩.
 *
 * 퀵 버튼 블록을 등록합니다. 이 스크립트는 별도 빌드 과정(webpack 등) 없이
 * 워드프레스에 기본 내장된 wp.blocks / wp.element / wp.blockEditor / wp.components
 * 전역 객체만으로 작성되어, 빌드 도구가 없는 환경에서도 그대로 동작합니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Blocks {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_quick_button_block' ) );
		// [디자인 개선] 퀵 버튼은 반드시 중앙 배치되어야 합니다. 블록 자체의 인라인
		// style="text-align:center"로 대부분 해결되지만, 일부 테마가 자체 CSS에서
		// 문단/블록 정렬을 더 높은 우선순위로 덮어쓰는 경우를 대비해, !important를 포함한
		// 별도 스타일을 프론트엔드에 추가로 출력해 항상 중앙 정렬이 유지되도록 보장합니다.
		add_action( 'wp_head', array( $this, 'output_quick_button_alignment_css' ) );
	}

	/**
	 * 퀵 버튼 블록의 중앙 정렬을 강제하는 안전망 CSS를 출력합니다.
	 */
	public function output_quick_button_alignment_css() {
		echo '<style id="zorlinq32-quick-button-align">.wp-block-zorlinq32-quick-button{text-align:center !important;}</style>' . "\n";
	}

	public function register_quick_button_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'zorlinq32-quick-button-block',
			ZORLINQ32_URL . 'assets/js/blocks/quick-button.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			ZORLINQ32_VERSION,
			true
		);

		register_block_type(
			'zorlinq32/quick-button',
			array(
				'editor_script' => 'zorlinq32-quick-button-block',
			)
		);
	}
}
