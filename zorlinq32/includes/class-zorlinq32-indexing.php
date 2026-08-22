<?php
/**
 * 자동 인덱싱(색인 요청) 모듈.
 *
 * - IndexNow: 마이크로소프트가 주도하고 Bing, Naver, Yandex, Seznam 등이 채택한
 *   공개 표준 프로토콜입니다. 네이버는 2023년 7월부터 서치어드바이저를 통해 이 프로토콜을
 *   공식 지원합니다. API 키 파일을 사이트 루트에 노출하고, 글이 발행/수정될 때마다
 *   해당 URL을 핑(ping)하면 지원 검색엔진(빙 + 네이버 포함)이 더 빠르게 크롤링합니다.
 *
 * [기능 정리] 구글 사이트맵 핑은 제거되었습니다. 구글은 일반 콘텐츠에 대한 개별 URL 직접
 * 제출을 공식 지원하지 않고 사이트맵 형식만 받는데, 사이트맵 자체 생성 기능이 플러그인에서
 * 제거되어 더 이상 유효한 사이트맵 URL을 제공할 수 없기 때문입니다. IndexNow는 개별 URL
 * 제출을 공식 지원하므로 계속 사용합니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Indexing {

	private static $instance = null;
	private $settings = array();

	const INDEXNOW_KEY_OPTION = 'zorlinq32_indexnow_key';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = Zorlinq32_Settings::get_group( 'indexing' );

		if ( empty( $this->settings['enabled'] ) ) {
			return;
		}

		// IndexNow 키 파일 서빙을 위한 rewrite 규칙 등록 (예: /a1b2c3....txt)
		if ( ! empty( $this->settings['indexnow_enabled'] ) ) {
			add_action( 'init', array( $this, 'register_key_file_rewrite' ) );
			add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
			add_action( 'template_redirect', array( $this, 'maybe_serve_key_file' ) );
		}

		if ( ! empty( $this->settings['auto_submit_on_publish'] ) ) {
			add_action( 'transition_post_status', array( $this, 'handle_status_transition' ), 10, 3 );
		}

		if ( ! empty( $this->settings['auto_submit_on_update'] ) ) {
			add_action( 'post_updated', array( $this, 'handle_post_updated' ), 10, 3 );
		}
	}

	/**
	 * IndexNow 키를 반환합니다. 설정에 저장된 값이 없으면 즉시 생성하여 저장합니다.
	 * 키는 검색엔진이 소유권을 확인하는 용도이므로 32자 영숫자 규격을 따릅니다.
	 */
	public static function get_or_create_indexnow_key() {
		$settings = Zorlinq32_Settings::get_group( 'indexing' );
		if ( ! empty( $settings['indexnow_key'] ) ) {
			return $settings['indexnow_key'];
		}

		$key = wp_generate_password( 32, false, false );
		$settings['indexnow_key'] = $key;
		Zorlinq32_Settings::update_group( 'indexing', $settings );

		return $key;
	}

	/**
	 * IndexNow 키 파일 경로(/{키}.txt)에 대한 rewrite 규칙을 등록합니다.
	 * IndexNow 프로토콜은 사이트 루트에 "키값.txt" 파일이 존재하고, 그 안에 같은 키값이
	 * 텍스트로 들어있어야 소유권이 확인됩니다.
	 */
	public function register_key_file_rewrite() {
		$key = self::get_or_create_indexnow_key();
		if ( empty( $key ) ) {
			return;
		}
		add_rewrite_rule( '^' . preg_quote( $key, '/' ) . '\.txt$', 'index.php?zorlinq32_indexnow_key_file=1', 'top' );
	}

	public function register_query_vars( $vars ) {
		$vars[] = 'zorlinq32_indexnow_key_file';
		return $vars;
	}

	public function maybe_serve_key_file() {
		if ( ! get_query_var( 'zorlinq32_indexnow_key_file' ) ) {
			return;
		}

		$key = self::get_or_create_indexnow_key();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo esc_html( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html()로 이미 이스케이프됨.
		exit;
	}

	/**
	 * 글이 새로 발행(publish)될 때 색인 요청을 트리거합니다.
	 */
	public function handle_status_transition( $new_status, $old_status, $post ) {
		try {
			if ( 'publish' !== $new_status || 'publish' === $old_status ) {
				return;
			}
			if ( ! $this->is_indexable_post_type( $post->post_type ) ) {
				return;
			}
			$this->submit_url( get_permalink( $post ) );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '색인 요청(발행) 중 오류: ' . $e->getMessage() );
		}
	}

	/**
	 * 이미 발행된 글이 수정될 때 색인 갱신 요청을 트리거합니다.
	 */
	public function handle_post_updated( $post_id, $post_after, $post_before ) {
		try {
			if ( 'publish' !== $post_after->post_status ) {
				return;
			}
			if ( ! $this->is_indexable_post_type( $post_after->post_type ) ) {
				return;
			}
			// wp_insert_post()로 인한 리비전 저장 등 불필요한 중복 요청을 피합니다.
			if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
				return;
			}
			$this->submit_url( get_permalink( $post_id ) );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '색인 요청(수정) 중 오류: ' . $e->getMessage() );
		}
	}

	private function is_indexable_post_type( $post_type ) {
		return in_array( $post_type, array( 'post', 'page' ), true );
	}

	/**
	 * 설정된 검색엔진들에 URL 색인/갱신을 요청합니다.
	 * 이 요청들은 사용자 화면 응답을 지연시키지 않도록 비동기(non-blocking) 방식으로 전송됩니다.
	 * 실패해도 글 저장 자체에는 절대 영향을 주지 않습니다.
	 *
	 * [기능 정리] 구글 사이트맵 핑은 제거되었습니다. 구글은 개별 URL 직접 제출을 공식
	 * 지원하지 않고 사이트맵 형식만 받는데, 사이트맵 자체 생성 기능이 제거되어 더 이상
	 * 유효한 사이트맵 URL이 없기 때문입니다. Bing/네이버가 지원하는 IndexNow는 개별 URL
	 * 제출을 공식 지원하므로 계속 사용합니다.
	 */
	public function submit_url( $url ) {
		if ( empty( $url ) ) {
			return;
		}

		if ( ! empty( $this->settings['indexnow_enabled'] ) ) {
			$this->submit_to_indexnow( $url );
		}
	}

	/**
	 * IndexNow로 URL을 제출할 엔드포인트 목록.
	 *
	 * - api.indexnow.org : Bing이 운영하는 공용 허브. IndexNow를 지원하는 검색엔진들이
	 *   서로 데이터를 공유하므로 이론상 이 한 곳만 호출해도 전파되지만, 네이버는 자체
	 *   엔드포인트로 직접 받은 요청을 더 안정적으로 처리합니다.
	 * - searchadvisor.naver.com/indexnow : 네이버 서치어드바이저가 공식 제공하는 전용
	 *   엔드포인트입니다 (참고: https://searchadvisor.naver.com/guide/indexnow-request).
	 *   요청 형식(POST + JSON body: host/key/keyLocation/urlList)은 표준 IndexNow와 동일하며,
	 *   네이버에는 이 경로로 "정확하게" 직접 제출합니다.
	 */
	const INDEXNOW_ENDPOINTS = array(
		'https://api.indexnow.org/indexnow',
		'https://searchadvisor.naver.com/indexnow',
	);

	/**
	 * IndexNow 프로토콜로 URL 제출. Bing이 운영하는 공용 허브와 네이버 전용 엔드포인트
	 * 양쪽에 동일한 payload를 각각 직접 전송합니다. 두 요청 모두 비동기(non-blocking)라
	 * 글 저장 응답 속도에는 영향이 없으며, 한쪽이 실패해도 다른 쪽 제출에는 영향을 주지 않습니다.
	 */
	private function submit_to_indexnow( $url ) {
		$key = self::get_or_create_indexnow_key();
		if ( empty( $key ) ) {
			return;
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$body = wp_json_encode(
			array(
				'host'        => $host,
				'key'         => $key,
				'keyLocation' => home_url( '/' . $key . '.txt' ),
				'urlList'     => array( $url ),
			)
		);

		foreach ( self::INDEXNOW_ENDPOINTS as $endpoint ) {
			try {
				wp_remote_post(
					$endpoint,
					array(
						'timeout'  => 5,
						'blocking' => false, // 응답을 기다리지 않아 글 저장 속도에 영향을 주지 않습니다.
						'headers'  => array( 'Content-Type' => 'application/json; charset=utf-8' ),
						'body'     => $body,
					)
				);
			} catch ( \Throwable $e ) {
				Zorlinq32_Logger::log( 'IndexNow 제출 중 오류(' . $endpoint . '): ' . $e->getMessage() );
			}
		}
	}
}
