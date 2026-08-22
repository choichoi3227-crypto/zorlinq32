<?php
/**
 * robots.txt 모듈.
 *
 * [기능 정리] 메타 설명/OG 태그 등 순수 SEO 기능과 XML 사이트맵 자체 생성 기능은
 * 제거되었습니다(구글 사이트맵 핑/IndexNow 등 실제 색인 요청 기능은 "자동 색인" 모듈에서
 * 계속 제공됩니다). 이 모듈은 검색엔진 노출에 필요한 최소 기능만 담당합니다.
 *
 * 포함 기능:
 * 1. robots.txt 자동 생성 (관리자/코어 경로 차단)
 * 2. 브레드크럼(이동 경로) 표시용 함수 제공 (테마에서 호출)
 * 3. 카테고리/태그 아카이브의 noindex 여부 설정
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_SEO_Extended {

	private static $instance = null;
	private $settings = array();

	/**
	 * 검색엔진이 절대 색인해서는 안 되는 경로 목록.
	 * 워드프레스 관리자 영역, 로그인 화면, 코어 시스템 파일, AJAX 엔드포인트 등이 대상입니다.
	 * 로그인 보안 모듈이 커스텀 로그인 슬러그를 쓰는 경우에도 wp-login.php 자체는
	 * 항상 차단 대상에 포함합니다 (실제 접근 여부와 무관하게 크롤러에게는 노출하지 않는 것이 안전).
	 */
	const DISALLOWED_PATHS = array(
		'/wp-admin/',
		'/wp-includes/',
		'/wp-content/plugins/',
		'/wp-content/uploads/*.php',
		'/wp-login.php',
		'/wp-cron.php',
		'/xmlrpc.php',
		'/?s=',
		'/*?replytocom=',
		'/trackback/',
		'/feed/',
		'/comments/feed/',
	);

	/**
	 * 관리자 영역은 크롤링뿐 아니라 완전히 예외 없이 차단하되,
	 * 관리자가 로그인 후 사용하는 admin-ajax.php는 프론트엔드 기능(장바구니, 검색 자동완성 등)이
	 * 이 엔드포인트를 통해 동작하는 경우가 많아 차단하면 사이트 기능이 깨질 수 있으므로 예외로 둡니다.
	 */
	const AJAX_EXCEPTION = '/wp-admin/admin-ajax.php';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// [기능 정리] 사이트맵/robots.txt는 더 이상 별도의 'seo' 설정 그룹을 쓰지 않고,
		// "자동 색인" 메뉴 하나로 통합된 'indexing' 그룹의 설정을 그대로 사용합니다.
		$this->settings = Zorlinq32_Settings::get_group( 'indexing' );

		if ( empty( $this->settings['enabled'] ) ) {
			return;
		}

		// robots.txt는 항상 안전한 기본값을 제공합니다.
		add_filter( 'robots_txt', array( $this, 'build_robots_txt' ), 10, 2 );

		if ( ! empty( $this->settings['noindex_archives'] ) ) {
			add_action( 'wp_head', array( $this, 'maybe_output_noindex' ), 2 );
		}
	}


	/**
	 * robots.txt를 완전히 새로 구성합니다.
	 * - 워드프레스 관리자/코어/로그인 경로는 항상 차단 목록에 포함합니다.
	 * - admin-ajax.php는 프론트엔드 기능이 의존하는 경우가 많아 예외로 허용합니다.
	 * - 사용자가 입력한 커스텀 규칙을 이어 붙입니다(중복 검증은 하지 않고 있는 그대로 반영합니다).
	 * - 사이트가 검색엔진 노출을 차단(설정 > 읽기 > 검색엔진이 이 사이트를 색인하지 않도록 요청)한 경우,
	 *   워드프레스 코어가 이미 전체 차단 robots.txt를 내려주므로 이 필터는 개입하지 않습니다.
	 */
	public function build_robots_txt( $output, $public ) {
		if ( '1' !== (string) $public ) {
			return $output;
		}

		$lines   = array();
		$lines[] = 'User-agent: *';

		foreach ( self::DISALLOWED_PATHS as $path ) {
			$lines[] = 'Disallow: ' . $path;
		}
		$lines[] = 'Allow: ' . self::AJAX_EXCEPTION;

		if ( ! empty( $this->settings['custom_robots_rules'] ) ) {
			$lines[] = '';
			$lines[] = trim( $this->settings['custom_robots_rules'] );
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * 카테고리/태그/날짜 아카이브에 noindex 메타를 출력합니다 (검색 결과 중복 방지 목적).
	 * 글/페이지 본문(is_singular)에는 절대 적용하지 않습니다.
	 */
	public function maybe_output_noindex() {
		if ( is_category() || is_tag() || is_date() ) {
			echo '<meta name="robots" content="noindex,follow" />' . "\n";
		}
	}

	/**
	 * 테마에서 호출할 수 있는 간단한 브레드크럼 출력 함수.
	 * 함수 호출 실패 시에도 빈 문자열만 반환해 테마 화면이 깨지지 않도록 합니다.
	 */
	public static function render_breadcrumb() {
		try {
			if ( is_front_page() ) {
				return '';
			}

			$items = array(
				'<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( '홈', 'zorlinq32' ) . '</a>',
			);

			if ( is_singular() ) {
				$items[] = esc_html( get_the_title() );
			} elseif ( is_category() || is_tag() ) {
				$items[] = esc_html( single_term_title( '', false ) );
			} elseif ( is_search() ) {
				$items[] = esc_html__( '검색 결과', 'zorlinq32' );
			}

			return '<nav class="zorlinq32-breadcrumb">' . implode( ' &raquo; ', $items ) . '</nav>';
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}
