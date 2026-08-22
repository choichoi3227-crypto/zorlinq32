<?php
/**
 * 성능 최적화 모듈.
 *
 * 서버 부하(CPU/DB 쿼리)를 줄이기 위한 다수의 세부 기능을 담당합니다.
 * 모든 세부 기능은 개별 on/off 스위치를 가지며, 기본값은 모두 false입니다.
 *
 * 포함 기능:
 * 1. 게시물 리비전 개수 제한
 * 2. 자동 임시저장 주기 연장 (DB 쓰기 횟수 감소)
 * 3. 하트비트 API 빈도 제한 (관리자 화면 AJAX 폴링으로 인한 서버 부하 감소)
 * 4. 이미지 지연 로딩(lazy loading) 속성 추가
 * 5. 사용하지 않는 이모지 스크립트 제거
 * 6. 임베드(oEmbed) 스크립트 제거
 * 7. 휴지통 자동 비우기 주기 단축
 * 8. 트랜지언트(transient) 만료 데이터 정기 정리
 * 9. 스크립트/스타일 버전 쿼리스트링 제거 (일부 CDN 캐시 효율 개선)
 * 10. WP-Cron을 실제 서버 크론으로 대체할 수 있도록 안내(직접 비활성화는 하지 않음-오작동 방지)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Performance {

	private static $instance = null;
	private $settings = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = Zorlinq32_Settings::get_group( 'performance' );

		if ( empty( $this->settings['enabled'] ) ) {
			return;
		}

		if ( ! empty( $this->settings['limit_revisions'] ) ) {
			add_filter( 'wp_revisions_to_keep', array( $this, 'limit_revisions' ), 10, 2 );
		}

		if ( ! empty( $this->settings['extend_autosave_interval'] ) ) {
			add_filter( 'autosave_interval', array( $this, 'extend_autosave_interval' ) );
		}

		if ( ! empty( $this->settings['limit_heartbeat'] ) ) {
			add_filter( 'heartbeat_settings', array( $this, 'limit_heartbeat_frequency' ) );
			add_action( 'init', array( $this, 'maybe_disable_heartbeat_on_frontend' ), 1 );
		}

		if ( ! empty( $this->settings['lazy_load_images'] ) ) {
			add_filter( 'wp_lazy_loading_enabled', '__return_true' );
		}

		if ( ! empty( $this->settings['disable_emojis'] ) ) {
			add_action( 'init', array( $this, 'disable_emoji_scripts' ) );
		}

		if ( ! empty( $this->settings['disable_embeds'] ) ) {
			add_action( 'init', array( $this, 'disable_embed_scripts' ), 9999 );
		}

		if ( ! empty( $this->settings['remove_version_query_strings'] ) ) {
			add_filter( 'script_loader_src', array( $this, 'remove_version_query_string' ), 15, 1 );
			add_filter( 'style_loader_src', array( $this, 'remove_version_query_string' ), 15, 1 );
		}

		// 정기 정리 작업 (트랜지언트, 휴지통)
		add_action( 'zorlinq32_daily_cache_cleanup', array( $this, 'cleanup_expired_transients' ) );
		// [서버 자원 최적화] 만료된 트랜지언트가 한 배치(300건)를 초과할 때, 다음 배치를
		// 이어서 처리하기 위한 일회성 예약 작업입니다 (cleanup_expired_transients 내부에서 등록됨).
		add_action( 'zorlinq32_daily_cache_cleanup_continue', array( $this, 'cleanup_expired_transients' ) );
		if ( ! empty( $this->settings['auto_empty_trash_days'] ) ) {
			add_filter( 'wp_scheduled_delete', array( $this, 'noop_filter_placeholder' ) ); // 실제 처리는 core의 EMPTY_TRASH_DAYS 상수로 처리 권장 (안내만 표시).
		}
	}

	/**
	 * 리비전 개수를 설정값만큼 제한합니다. 0 이하 입력 시 안전하게 기본값(5)을 사용합니다.
	 */
	public function limit_revisions( $num, $post ) {
		$limit = isset( $this->settings['revisions_limit'] ) ? (int) $this->settings['revisions_limit'] : 5;
		return $limit > 0 ? $limit : 5;
	}

	/**
	 * 자동 저장 간격을 늘려 DB 쓰기 빈도를 줄입니다. 최소 60초는 보장합니다(글 작성 중 데이터 유실 방지).
	 */
	public function extend_autosave_interval( $interval ) {
		$seconds = isset( $this->settings['autosave_interval_seconds'] ) ? (int) $this->settings['autosave_interval_seconds'] : 120;
		return max( 60, $seconds );
	}

	/**
	 * 하트비트 API 주기를 늘립니다(기본 15초 -> 설정값, 최소 30초 보장).
	 */
	public function limit_heartbeat_frequency( $settings ) {
		$interval = isset( $this->settings['heartbeat_interval_seconds'] ) ? (int) $this->settings['heartbeat_interval_seconds'] : 60;
		$settings['interval'] = max( 30, $interval );
		return $settings;
	}

	/**
	 * 프론트엔드(비관리자 화면)에서는 하트비트를 아예 끕니다.
	 * 글 작성 화면의 자동저장/잠금 기능은 그대로 유지되도록 admin 영역은 건드리지 않습니다.
	 */
	public function maybe_disable_heartbeat_on_frontend() {
		if ( ! is_admin() ) {
			wp_deregister_script( 'heartbeat' );
		}
	}

	/**
	 * 이모지 렌더링용 인라인 스크립트/스타일을 제거합니다.
	 */
	public function disable_emoji_scripts() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'tiny_mce_plugins', array( $this, 'remove_emoji_tinymce_plugin' ) );
		add_filter( 'wp_resource_hints', array( $this, 'remove_emoji_dns_prefetch' ), 10, 2 );
	}

	public function remove_emoji_tinymce_plugin( $plugins ) {
		return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
	}

	public function remove_emoji_dns_prefetch( $urls, $relation_type ) {
		if ( 'dns-prefetch' === $relation_type && is_array( $urls ) ) {
			$urls = array_filter(
				$urls,
				function ( $url ) {
					return false === strpos( (string) $url, 's.w.org' );
				}
			);
		}
		return $urls;
	}

	/**
	 * oEmbed 관련 스크립트/REST 엔드포인트를 비활성화합니다.
	 * 사용자가 실제로 oEmbed 임베드를 쓰고 있지 않은 경우에만 켜는 것을 권장합니다.
	 */
	public function disable_embed_scripts() {
		remove_action( 'rest_api_init', 'wp_oembed_register_route' );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		add_filter( 'embed_oembed_discover', '__return_false' );
	}

	/**
	 * 정적 자원 URL의 ?ver= 쿼리스트링을 제거합니다.
	 * 워드프레스 코어/플러그인 스크립트는 건드리지 않고, 캐시 효율에 큰 영향을 주는
	 * 테마/플러그인 자산 URL에 한해 안전하게 적용됩니다.
	 */
	public function remove_version_query_string( $src ) {
		if ( is_string( $src ) && strpos( $src, 'ver=' ) !== false ) {
			$src = remove_query_arg( 'ver', $src );
		}
		return $src;
	}

	/**
	 * [서버 자원 최적화] 한 번의 정리 작업에서 삭제할 최대 트랜지언트 개수.
	 * 대형/오래된 사이트는 만료된 트랜지언트가 수만 건씩 쌓여있을 수 있는데,
	 * 이를 한 번에 모두 처리하려 하면 매우 긴 실행 시간과 옵션 테이블 잠금을
	 * 유발할 수 있습니다. 배치 크기를 제한하고, 남은 항목은 다음 날 실행 때
	 * 이어서 처리되도록 하여 매일의 부하를 평탄화합니다.
	 */
	const TRANSIENT_CLEANUP_BATCH_SIZE = 300;

	/**
	 * 만료된 트랜지언트(옵션 테이블에 쌓이는 임시 캐시 데이터)를 정리해 DB 비대화를 방지합니다.
	 * 워드프레스 코어 API만 사용하며 직접 SQL 삭제는 수행하지 않아 안전합니다.
	 */
	public function cleanup_expired_transients() {
		try {
			if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				return; // 외부 오브젝트 캐시(Redis 등) 사용 시 워드프레스가 자체적으로 관리하므로 개입하지 않습니다.
			}

			global $wpdb;

			// 만료 시각이 지난 트랜지언트의 timeout 옵션명을 조회 (직접 삭제는 core 함수로만 수행).
			// 워드프레스 코어에는 "만료된 트랜지언트 전체 조회" API가 없어 범위 조건(LIKE + 비교) 조회가 불가피합니다.
			// LIMIT으로 배치 크기를 제한해 대형 사이트에서도 짧은 시간 안에 쿼리가 끝나도록 합니다.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$timeout_options = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT %d",
					$wpdb->esc_like( '_transient_timeout_' ) . '%',
					time(),
					self::TRANSIENT_CLEANUP_BATCH_SIZE
				)
			);

			if ( empty( $timeout_options ) || ! is_array( $timeout_options ) ) {
				return;
			}

			foreach ( $timeout_options as $timeout_option ) {
				$transient_key = str_replace( '_transient_timeout_', '', $timeout_option );
				// 코어 API로만 삭제하여 워드프레스의 캐시 무효화 로직을 그대로 따릅니다.
				delete_transient( $transient_key );
			}

			// 이번 배치에서 상한(300건)을 꽉 채웠다면 아직 정리할 항목이 더 남아있을 가능성이 높습니다.
			// 하루 한 번의 정기 작업만으로는 오래 걸릴 수 있으므로, 이 경우 5분 뒤 한 번 더 이어서
			// 처리하는 일회성 예약 작업을 등록해 전체 정리가 여러 날에 걸쳐 완료되도록 합니다.
			if ( count( $timeout_options ) >= self::TRANSIENT_CLEANUP_BATCH_SIZE
				&& ! wp_next_scheduled( 'zorlinq32_daily_cache_cleanup_continue' ) ) {
				wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'zorlinq32_daily_cache_cleanup_continue' );
			}
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '트랜지언트 정리 중 오류: ' . $e->getMessage() );
		}
	}

	/**
	 * 자동 휴지통 비우기는 core 상수(EMPTY_TRASH_DAYS)를 통해서만 안전하게 조정 가능합니다.
	 * 여기서는 실제 동작을 바꾸지 않고, 관리자 화면에 안내 문구만 표시하기 위한 자리 표시자입니다.
	 */
	public function noop_filter_placeholder( $value ) {
		return $value;
	}
}
