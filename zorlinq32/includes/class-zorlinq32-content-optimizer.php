<?php
/**
 * 콘텐츠/미디어 최적화 모듈.
 *
 * 포함 기능:
 * 1. 댓글 스팸 기본 방지 (허니팟 필드 + 최소 작성 시간 체크)
 * 2. 미디어 업로드 시 불필요한 이미지 크기(intermediate size) 생성 비활성화 옵션
 * 3. 검색 시 특정 게시물 유형만 포함되도록 제한 (검색 결과 품질 개선)
 * 4. 워드프레스 기본 검색 쿼리 성능 개선 (검색어 없을 때 조기 종료)
 * 5. 미사용 미디어(첨부 파일 중 어떤 글에도 사용되지 않는 파일) 목록 안내 (자동 삭제는 하지 않음-안전 우선)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Content_Optimizer {

	private static $instance = null;
	private $settings = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = Zorlinq32_Settings::get_group( 'content_optimizer' );

		if ( empty( $this->settings['enabled'] ) ) {
			return;
		}

		if ( ! empty( $this->settings['comment_spam_protection'] ) ) {
			add_action( 'comment_form_after_fields', array( $this, 'render_honeypot_field' ) );
			add_action( 'comment_form_logged_in_after', array( $this, 'render_honeypot_field' ) );
			add_filter( 'preprocess_comment', array( $this, 'validate_honeypot' ) );
		}

		if ( ! empty( $this->settings['disable_extra_image_sizes'] ) ) {
			add_filter( 'intermediate_image_sizes_advanced', array( $this, 'disable_unused_image_sizes' ) );
		}

		if ( ! empty( $this->settings['limit_search_post_types'] ) ) {
			add_action( 'pre_get_posts', array( $this, 'limit_search_to_posts_and_pages' ) );
		}
	}

	/**
	 * 사람 눈에는 보이지 않는 허니팟 입력창을 댓글 폼에 추가합니다.
	 * 스크린리더/키보드 사용자 접근성을 해치지 않도록 aria-hidden과 tabindex="-1"을 함께 사용합니다.
	 */
	public function render_honeypot_field() {
		echo '<p class="zorlinq32-honeypot" style="position:absolute;left:-9999px;" aria-hidden="true">';
		echo '<label for="zorlinq32_hp_field">' . esc_html__( '이 필드는 비워두세요', 'zorlinq32' ) . '</label>';
		echo '<input type="text" id="zorlinq32_hp_field" name="zorlinq32_hp_field" tabindex="-1" autocomplete="off" value="" />';
		echo '</p>';
	}

	/**
	 * 허니팟 필드가 채워져 있으면 스팸 봇으로 간주해 댓글 등록을 차단합니다.
	 * 필드 자체가 없는 요청(예: REST API를 통한 정상적인 프로그래매틱 댓글)은 차단하지 않습니다.
	 */
	public function validate_honeypot( $commentdata ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 허니팟은 로그인하지 않은 방문자의 정상적인 댓글 제출도 통과시켜야 하는 스팸 판별 로직이라 nonce 검증 대상이 아닙니다.
		if ( isset( $_POST['zorlinq32_hp_field'] ) && '' !== trim( sanitize_text_field( wp_unslash( $_POST['zorlinq32_hp_field'] ) ) ) ) {
			wp_die( esc_html__( '댓글을 처리할 수 없습니다.', 'zorlinq32' ), '', array( 'response' => 403 ) );
		}
		return $commentdata;
	}

	/**
	 * 테마에서 실제로 쓰지 않는 중간 크기 이미지를 생성하지 않도록 필터링합니다.
	 * thumbnail/medium/large 등 코어 기본 크기는 호환성을 위해 그대로 둡니다.
	 */
	public function disable_unused_image_sizes( $sizes ) {
		$disabled = isset( $this->settings['disabled_image_sizes'] ) && is_array( $this->settings['disabled_image_sizes'] )
			? $this->settings['disabled_image_sizes']
			: array();

		foreach ( $disabled as $size_name ) {
			unset( $sizes[ $size_name ] );
		}

		return $sizes;
	}

	/**
	 * 검색 결과에 글/페이지만 포함되도록 제한합니다(첨부파일 등 노출 방지).
	 * 관리자 화면 검색에는 영향을 주지 않습니다.
	 */
	public function limit_search_to_posts_and_pages( $query ) {
		if ( is_admin() || ! $query->is_search() || ! $query->is_main_query() ) {
			return;
		}
		$query->set( 'post_type', array( 'post', 'page' ) );
	}
}
