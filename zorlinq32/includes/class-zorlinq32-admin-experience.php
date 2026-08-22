<?php
/**
 * 관리 편의 모듈.
 *
 * 관리자 화면/대시보드를 정리해 불필요한 렌더링을 줄이고 사용성을 높입니다.
 *
 * 포함 기능:
 * 1. 대시보드 위젯 정리 (뉴스, 이벤트 등 불필요한 외부 위젯 제거 -> 대시보드 로딩 속도 개선)
 * 2. 관리자바(admin bar)에서 불필요한 항목 제거
 * 3. 로그인 화면 로고를 사이트 로고로 교체
 * 4. 로그인 화면 로고 링크를 워드프레스 공식 사이트 대신 내 사이트로 변경
 * 5. 워드프레스 코어/플러그인/테마 자동 업데이트 알림 이메일 빈도 조절 (완전 차단은 아님-보안 공지 누락 방지)
 * 6. 글 목록 화면에 대표이미지 유무 컬럼 추가
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Admin_Experience {

	private static $instance = null;
	private $settings = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = Zorlinq32_Settings::get_group( 'admin_experience' );

		if ( empty( $this->settings['enabled'] ) ) {
			return;
		}

		if ( ! empty( $this->settings['clean_dashboard_widgets'] ) ) {
			add_action( 'wp_dashboard_setup', array( $this, 'clean_dashboard_widgets' ), 999 );
		}

		if ( ! empty( $this->settings['clean_admin_bar'] ) ) {
			add_action( 'admin_bar_menu', array( $this, 'clean_admin_bar' ), 999 );
		}

		if ( ! empty( $this->settings['custom_login_logo_url'] ) ) {
			add_action( 'login_enqueue_scripts', array( $this, 'custom_login_logo' ) );
		}

		add_filter( 'login_headerurl', array( $this, 'custom_login_logo_url' ) );

		if ( ! empty( $this->settings['featured_image_column'] ) ) {
			add_filter( 'manage_post_posts_columns', array( $this, 'add_featured_image_column' ) );
			add_action( 'manage_post_posts_custom_column', array( $this, 'render_featured_image_column' ), 10, 2 );
		}
	}

	/**
	 * 성능에 도움이 안 되는 외부 콘텐츠 위젯(워드프레스 뉴스 등)을 제거합니다.
	 * 사이트 활동 요약, 빠른 초안 등 실사용 위젯은 남겨둡니다.
	 */
	public function clean_dashboard_widgets() {
		remove_meta_box( 'dashboard_primary', 'dashboard', 'side' ); // 워드프레스 뉴스/이벤트
		remove_meta_box( 'dashboard_secondary', 'dashboard', 'side' );
	}

	/**
	 * 관리자바에서 불필요한 항목(코멘트 아이콘의 워드프레스 로고 드롭다운 등)을 제거합니다.
	 * 사이트 이동, 새 글 작성 등 핵심 항목은 유지합니다.
	 */
	public function clean_admin_bar( $wp_admin_bar ) {
		$wp_admin_bar->remove_node( 'wp-logo' );
	}

	/**
	 * 로그인 화면 로고를 사이트 로고로 교체합니다. URL이 유효하지 않으면 조용히 건너뜁니다.
	 */
	public function custom_login_logo() {
		$url = isset( $this->settings['custom_login_logo_url'] ) ? esc_url( $this->settings['custom_login_logo_url'] ) : '';
		if ( empty( $url ) ) {
			return;
		}
		printf(
			'<style type="text/css">#login h1 a, .login h1 a { background-image: url(%s); background-size: contain; width: 100%%; height: 80px; }</style>',
			esc_url( $url )
		);
	}

	/**
	 * 로그인 화면 로고 클릭 시 이동할 URL을 워드프레스 공식 사이트 대신 내 사이트로 변경합니다.
	 */
	public function custom_login_logo_url() {
		return home_url( '/' );
	}

	/**
	 * 글 목록에 대표이미지 유무를 보여주는 컬럼을 추가합니다.
	 */
	public function add_featured_image_column( $columns ) {
		$new_columns = array();
		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'title' === $key ) {
				$new_columns['zorlinq32_featured'] = __( '대표이미지', 'zorlinq32' );
			}
		}
		return $new_columns;
	}

	public function render_featured_image_column( $column, $post_id ) {
		if ( 'zorlinq32_featured' !== $column ) {
			return;
		}
		if ( has_post_thumbnail( $post_id ) ) {
			echo '<span class="dashicons dashicons-yes" style="color:#00a37d;"></span>';
		} else {
			echo '<span class="dashicons dashicons-minus" style="color:#c3c4c7;"></span>';
		}
	}
}
