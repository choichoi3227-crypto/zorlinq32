<?php
/**
 * 페이지 캐싱 모듈 (파일 기반).
 *
 * 로그인하지 않은 방문자의 GET 요청에 한해 렌더링된 HTML을
 * 파일로 저장하고, 다음 요청부터는 워드프레스 부트스트랩 이후
 * 캐시 파일을 즉시 반환하여 서버 부하(CPU/DB 쿼리)를 줄입니다.
 *
 * 안전장치:
 * - 관리자/로그인 사용자, POST 요청, 검색/장바구니 등 동적 페이지는 캐시하지 않습니다.
 * - 캐시 디렉토리 생성 실패, 쓰기 실패 시 조용히 캐싱을 건너뛰고 정상 렌더링으로 폴백합니다.
 * - 콘텐츠(글/댓글) 변경 시 관련 캐시를 자동 무효화합니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Cache {

	private static $instance = null;
	private $settings = array();
	private $cache_dir = '';
	// [캐싱 버그 수정] 이번 요청에서 우리가 직접 ob_start()를 호출했는지 여부를 기억합니다.
	// shutdown 시점의 안전망(maybe_store_cache)이 "우리가 연 적 없는" 다른 버퍼까지
	// 실수로 flush하지 않도록 이 플래그로 범위를 한정합니다.
	private $buffering_started = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings  = Zorlinq32_Settings::get_group( 'cache' );
		$upload_dir       = wp_upload_dir();
		$this->cache_dir  = trailingslashit( $upload_dir['basedir'] ) . 'zorlinq32-cache/';

		if ( empty( $this->settings['enabled'] ) ) {
			return;
		}

		// 캐시 출력은 최대한 이른 시점(템플릿 로드 이전)에 시도합니다.
		add_action( 'template_redirect', array( $this, 'maybe_serve_cache' ), 0 );
		// [캐싱 버그 수정] 캐시로 응답하지 않는(=새로 렌더링해야 하는) 요청에 한해,
		// 이 시점부터 출력 버퍼링을 직접 시작합니다. 이전 버전에는 이 ob_start() 호출이
		// 아예 없어서 shutdown 시점의 ob_get_contents()가 사실상 빈 값을 읽거나, 다른
		// 플러그인/테마가 우연히 열어둔 버퍼에 의존해야만 동작하는 상태였습니다(=캐시가
		// 사실상 거의 저장되지 않는 근본 원인). 이제는 이 클래스가 직접 버퍼 수명을
		// 관리하므로, 캐시가 켜져 있고 저장 대상 요청이면 항상 안정적으로 저장됩니다.
		add_action( 'template_redirect', array( $this, 'maybe_start_output_buffer' ), 1 );
		add_action( 'shutdown', array( $this, 'maybe_store_cache' ), 0 );

		// 콘텐츠 변경 시 캐시 무효화.
		// [서버 자원 최적화] 기존에는 글 하나만 저장/댓글이 하나만 달려도 사이트 전체의
		// 모든 캐시 파일을 삭제했습니다. 트래픽이 많고 콘텐츠가 자주 갱신되는 사이트에서는
		// 이 때문에 캐시 적중률이 계속 낮게 유지되어, 캐싱 기능이 있어도 서버 부하 절감
		// 효과가 거의 없는 역설적인 상황이 발생할 수 있습니다. 이제는 변경된 글의 캐시
		// 파일과 홈/글목록 페이지만 선택적으로 지우고, 전체 삭제는 테마 변경처럼
		// 사이트 전역에 실제로 영향을 주는 드문 이벤트에만 사용합니다.
		add_action( 'save_post', array( $this, 'smart_invalidate_on_save' ) );
		add_action( 'comment_post', array( $this, 'smart_invalidate_on_comment' ) );
		add_action( 'switch_theme', array( $this, 'clear_all_cache' ) );

		// 정기 캐시 정리 (cron)
		add_action( 'zorlinq32_daily_cache_cleanup', array( $this, 'cleanup_expired_cache' ) );
	}

	/**
	 * 현재 요청이 캐시 가능한 요청인지 판단합니다.
	 */
	/**
	 * 현재 요청의 경로(쿼리스트링 제외)를 안전하게 반환합니다.
	 */
	private function get_current_request_path() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );
		return $path ? $path : '/';
	}

	private function is_cacheable_request() {
		if ( is_admin() ) {
			return false;
		}
		if ( is_user_logged_in() && ! empty( $this->settings['exclude_logged_in'] ) ) {
			return false;
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return false;
		}
		if ( is_search() || is_404() || ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
			return false;
		}
		// [SEO 개선] robots.txt는 검색엔진이 참고하는 "지금 이 순간의 사실"을 반영해야
		// 하므로 파일 캐시 대상에서 항상 제외합니다.
		if ( 'robots.txt' === trim( $this->get_current_request_path(), '/' ) ) {
			return false;
		}
		if ( ! empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// 쿼리 파라미터가 있는 요청(예: ?s=, ?add-to-cart=)은 캐시하지 않습니다.
			return false;
		}

		// 애드센스 부정클릭 방지 기능이 켜져 있고 현재 방문자가 차단 대상이면 캐시를 우회합니다.
		// 캐시된 HTML은 광고 제거 필터(the_content)를 거치지 않은 상태로 저장되므로,
		// 캐시를 그대로 서빙하면 차단된 방문자에게도 광고가 계속 노출되는 불일치가 생깁니다.
		if ( class_exists( 'Zorlinq32_AdSense_Protection' ) ) {
			$adsense_settings = Zorlinq32_Settings::get_group( 'adsense_protection' );
			if ( ! empty( $adsense_settings['enabled'] ) && Zorlinq32_AdSense_Protection::instance()->is_current_visitor_blocked() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 요청 URL 기준으로 캐시 파일 경로를 생성합니다.
	 */
	private function get_cache_file_path() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return $this->get_cache_file_path_for_uri( $request_uri );
	}

	/**
	 * 임의의 URI(현재 요청이 아닌, 예를 들어 특정 글의 URL)에 대한 캐시 파일 경로를 계산합니다.
	 * 이 메서드가 있어야 "이 글만" 또는 "홈페이지만"처럼 선택적 캐시 무효화가 가능합니다.
	 */
	private function get_cache_file_path_for_uri( $uri ) {
		$key = md5( $uri );
		return $this->cache_dir . $key . '.html';
	}

	/**
	 * 주어진 절대 URL을 요청 경로(REQUEST_URI 형태)로 변환한 뒤 캐시 파일을 삭제합니다.
	 * 파일이 없으면 조용히 무시합니다(이미 캐시되지 않은 페이지일 수 있음).
	 */
	private function delete_cache_for_url( $url ) {
		if ( empty( $url ) ) {
			return;
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( empty( $path ) ) {
			$path = '/';
		}
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		$uri   = $path . ( $query ? '?' . $query : '' );

		$file = $this->get_cache_file_path_for_uri( $uri );
		if ( file_exists( $file ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * 글이 저장될 때, 사이트 전체가 아니라 "이 글의 캐시"와 "홈/블로그 목록 페이지 캐시"만
	 * 선택적으로 무효화합니다. 자동저장·리비전·비공개 상태 변경은 방문자가 보는 캐시된
	 * 페이지와 무관하므로 건너뛰어 불필요한 파일 삭제를 방지합니다.
	 */
	public function smart_invalidate_on_save( $post_id ) {
		try {
			if ( ! is_dir( $this->cache_dir ) ) {
				return;
			}
			if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
				return;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				return;
			}

			// 실제로 방문자에게 노출되는(또는 노출되었을 수 있는) 상태 변화일 때만 무효화합니다.
			$relevant_statuses = array( 'publish', 'trash' );
			if ( ! in_array( $post->post_status, $relevant_statuses, true ) ) {
				return;
			}

			$this->delete_cache_for_url( get_permalink( $post_id ) );
			$this->delete_cache_for_url( home_url( '/' ) );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '선택적 캐시 무효화(글 저장) 중 오류: ' . $e->getMessage() );
		}
	}

	/**
	 * 댓글이 등록되면 해당 글의 캐시만 무효화합니다(댓글은 그 글 페이지에만 표시되므로
	 * 사이트 전역 캐시를 지울 이유가 없습니다).
	 */
	public function smart_invalidate_on_comment( $comment_id ) {
		try {
			if ( ! is_dir( $this->cache_dir ) ) {
				return;
			}
			$comment = get_comment( $comment_id );
			if ( ! $comment || empty( $comment->comment_post_ID ) ) {
				return;
			}
			$this->delete_cache_for_url( get_permalink( (int) $comment->comment_post_ID ) );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '선택적 캐시 무효화(댓글) 중 오류: ' . $e->getMessage() );
		}
	}

	/**
	 * 캐시 디렉토리가 없으면 생성합니다. 실패 시 false를 반환하고 계속 진행하지 않습니다.
	 */
	private function ensure_cache_dir() {
		if ( file_exists( $this->cache_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- 캐싱 경로는 매 요청마다 실행되어 WP_Filesystem 초기화 비용을 피해야 하며, 단순 boolean 권한 체크만 필요하므로 네이티브 함수를 사용합니다.
			return is_writable( $this->cache_dir );
		}

		try {
			return wp_mkdir_p( $this->cache_dir );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '캐시 디렉토리 생성 실패: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * [캐싱 버그 수정] 이 요청이 캐시 저장 대상이면, 여기서부터 페이지 끝까지의 모든 출력을
	 * 가로챌 수 있도록 우리 전용 출력 버퍼를 시작합니다. maybe_serve_cache()가 이미 캐시를
	 * 반환하고 exit한 경우(캐시 HIT)에는 이 코드가 실행되지 않으므로 이중 버퍼링 걱정이 없습니다.
	 *
	 * ob_start()에 콜백을 등록해두면, 이후 어떤 방식으로 출력이 끝나든(die/wp_die 포함)
	 * PHP가 버퍼를 flush하는 시점에 우리 콜백이 그 내용을 가로채 캐시 파일에 먼저 저장한 뒤
	 * 그대로 화면에 흘려보냅니다. shutdown 훅 하나에만 의존하는 것보다 훨씬 안전합니다.
	 */
	public function maybe_start_output_buffer() {
		try {
			if ( ! $this->is_cacheable_request() ) {
				return;
			}
			// 이미 다른 코드가 버퍼링 콜백을 우리 목적과 무관하게 열어둔 상태일 수 있으므로,
			// 우리가 시작한 버퍼인지 스스로 알 수 있도록 별도 플래그로 추적합니다.
			ob_start( array( $this, 'output_buffer_callback' ) );
			$this->buffering_started = true;
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '캐시 출력 버퍼 시작 중 오류: ' . $e->getMessage() );
		}
	}

	/**
	 * 우리가 시작한 출력 버퍼가 flush될 때 호출되는 콜백입니다. 최종 HTML을 캐시 파일에
	 * 저장한 뒤, 원래 출력되었어야 할 내용을 그대로 반환해 방문자 화면에는 아무 차이가
	 * 없도록 합니다(캐시 저장은 부수효과일 뿐, 이 요청의 응답 자체를 바꾸지 않습니다).
	 */
	public function output_buffer_callback( $content ) {
		try {
			if ( ! empty( $content ) && $this->ensure_cache_dir() ) {
				$file = $this->get_cache_file_path();
				@file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '캐시 저장(출력 버퍼 콜백) 중 오류: ' . $e->getMessage() );
		}
		// 콜백은 반드시 최종 출력할 내용을 반환해야 합니다. 실패하더라도 원본 콘텐츠를
		// 그대로 반환해 방문자에게는 절대 빈 화면/오류가 노출되지 않도록 합니다.
		return $content;
	}

	/**
	 * 저장된 캐시가 있고 유효기간 내라면 즉시 출력 후 종료합니다.
	 */
	public function maybe_serve_cache() {
		try {
			if ( ! $this->is_cacheable_request() ) {
				return;
			}

			$file = $this->get_cache_file_path();
			if ( ! file_exists( $file ) ) {
				return;
			}

			$lifetime_hours = isset( $this->settings['cache_lifetime'] ) ? (int) $this->settings['cache_lifetime'] : 24;
			$max_age        = max( 1, $lifetime_hours ) * HOUR_IN_SECONDS;

			if ( ( time() - filemtime( $file ) ) > $max_age ) {
				return; // 만료됨 -> 새로 렌더링하고 저장.
			}

			$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents
			if ( false === $content || '' === $content ) {
				return;
			}

			header( 'X-Zorlinq32-Cache: HIT' );
			echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '캐시 제공 중 오류: ' . $e->getMessage() );
			// 오류 발생 시 캐시를 건너뛰고 정상 렌더링을 계속 진행합니다.
		}
	}

	/**
	 * [캐싱 버그 수정] 실제 저장은 output_buffer_callback()에서 출력 버퍼가 flush될 때
	 * 이미 처리됩니다(정상적인 페이지 렌더링 흐름이라면 shutdown 이전에 워드프레스 코어가
	 * 모든 출력 버퍼를 flush합니다). 다만 아주 드물게(치명적 에러로 PHP가 비정상 종료되는 등)
	 * 우리 콜백이 실행되지 못한 채 shutdown까지 버퍼가 남아있는 경우를 대비한 안전망으로,
	 * 아직 열려 있는 우리 버퍼가 있다면 여기서 마지막으로 한 번 더 정리합니다.
	 */
	public function maybe_store_cache() {
		try {
			if ( empty( $this->buffering_started ) ) {
				return;
			}
			if ( ob_get_level() > 0 ) {
				// 이 시점까지 버퍼가 남아있다면 아직 flush되지 않은 것이므로,
				// 강제로 flush하여 우리 콜백(output_buffer_callback)이 저장을 마무리하게 합니다.
				ob_end_flush();
			}
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '캐시 저장(안전망) 중 오류: ' . $e->getMessage() );
		}
	}

	/**
	 * 전체 캐시를 삭제합니다.
	 */
	public function clear_all_cache() {
		try {
			if ( ! is_dir( $this->cache_dir ) ) {
				return;
			}
			$files = glob( $this->cache_dir . '*.html' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						@unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					}
				}
			}
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '캐시 삭제 중 오류: ' . $e->getMessage() );
		}
	}

	/**
	 * 만료된 캐시 파일만 정리합니다 (정기 cron 작업).
	 */
	public function cleanup_expired_cache() {
		try {
			if ( ! is_dir( $this->cache_dir ) ) {
				return;
			}

			$lifetime_hours = isset( $this->settings['cache_lifetime'] ) ? (int) $this->settings['cache_lifetime'] : 24;
			$max_age        = max( 1, $lifetime_hours ) * HOUR_IN_SECONDS;

			$files = glob( $this->cache_dir . '*.html' );
			if ( ! is_array( $files ) ) {
				return;
			}

			foreach ( $files as $file ) {
				if ( is_file( $file ) && ( time() - filemtime( $file ) ) > $max_age ) {
					@unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			}
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '캐시 정기 정리 중 오류: ' . $e->getMessage() );
		}
	}

	/**
	 * 현재 캐시 디렉토리의 총 용량(바이트)을 반환합니다. (관리자 화면 표시용)
	 */
	public function get_cache_size_bytes() {
		try {
			if ( ! is_dir( $this->cache_dir ) ) {
				return 0;
			}
			$files = glob( $this->cache_dir . '*.html' );
			if ( ! is_array( $files ) ) {
				return 0;
			}
			$total = 0;
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					$total += filesize( $file );
				}
			}
			return $total;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}
}
