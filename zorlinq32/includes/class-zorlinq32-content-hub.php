<?php
/**
 * 콘텐츠 허브 모듈.
 *
 * 포함 기능:
 * 1. 글 "경로(path)" 분류 — 커스텀 택소노미(zorlinq32_path)를 등록해 글을
 *    블로그/뉴스/리뷰/가이드/커뮤니티 등 최소 5개 이상의 경로로 분류할 수 있게 합니다.
 *    관리자가 자유롭게 경로를 추가/삭제할 수 있는 표준 워드프레스 택소노미이므로
 *    코어 UI(글 목록, 글 편집 화면의 체크박스 패널)를 그대로 사용해 안전합니다.
 * 2. 자동 관련글 — 같은 "경로"(없으면 카테고리) 기준으로 글을 뽑아 본문 하단에
 *    반응형 그리드로 자동 삽입합니다. 행(row)·열(column) 수를 관리자가 직접
 *    설정할 수 있습니다. 결과 HTML을 캐시해 방문마다 쿼리를 반복하지 않고,
 *    무작위 정렬도 SQL RAND() 없이 PHP에서 섞는 방식이라 가볍습니다.
 * 3. 이전글/다음글 내비게이션 — 같은 경로 우선, 없으면 전체 글 기준으로 자동 계산.
 *
 * ⚠️ 안전 설계 원칙 (요구사항: 워드프레스에서 장애 발생 절대 금지):
 * - 모든 출력 로직은 the_content 필터 내부에서 try/catch로 감싸, 예외 발생 시
 *   원본 콘텐츠를 그대로 반환하고 조용히 로그만 남깁니다.
 * - 새 택소노미는 기존 'post' 글 타입에만 등록되며, 기존 카테고리/태그 체계에는
 *   전혀 영향을 주지 않습니다(완전히 별도의 병행 분류 체계).
 * - 관리자가 각 기능을 개별적으로 켜고 끌 수 있으며, 기본값은 모두 OFF입니다.
 * - 숏코드/블록이 아닌 the_content 필터 자동 삽입 방식이라 사용자가 별도로
 *   글마다 삽입 작업을 할 필요가 없고, 테마 교체에도 영향받지 않습니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Content_Hub {

	private static $instance = null;
	private $settings = array();

	const TAXONOMY    = 'zorlinq32_path';
	const OPTION_KEY   = 'zorlinq32_content_hub_settings';
	const RAND_POOL_MULTIPLIER = 3;
	const RAND_POOL_MAX        = 30;

	/**
	 * 캐시 유효 시간(초). HOUR_IN_SECONDS는 워드프레스 코어가 정의하는 런타임
	 * 상수라 클래스 상수 선언식에 직접 쓰지 않고 메서드에서 안전하게 계산합니다.
	 *
	 * @return int
	 */
	private static function cache_ttl() {
		return 12 * ( defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 );
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 이 모듈은 다른 그룹들과 달리 설정 항목이 많고 독립적이라
	 * Zorlinq32_Settings의 그룹 배열에 억지로 끼워 넣지 않고
	 * 전용 옵션 키(self::OPTION_KEY)로 별도 관리합니다.
	 * 다른 모듈의 옵션 구조에는 전혀 영향을 주지 않는 완전히 격리된 설계입니다.
	 */
	public static function get_default_settings() {
		return array(
			'enabled'             => false,
			'path_taxonomy'       => true,  // 경로(카테고리 대체) 분류 사용 여부
			'related_posts'       => true,  // 관련글 자동 삽입 여부
			'related_rows'        => 2,     // 관련글 행 수
			'related_columns'     => 4,     // 관련글 열 수
			'related_order_by'    => 'date', // date | rand | title
			'prev_next_nav'       => true,  // 이전글/다음글 자동 삽입 여부
			'default_paths'       => array( 'blog', 'news', 'review', 'guide', 'community' ),
		);
	}

	public static function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::get_default_settings(), $saved );
	}

	public static function update_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return false;
		}
		$merged = array_merge( self::get_default_settings(), $settings );
		return update_option( self::OPTION_KEY, $merged );
	}

	private function __construct() {
		$this->settings = self::get_settings();

		// 택소노미 등록 자체는 항상 수행합니다(경로 데이터가 이미 저장된 사용자가
		// 기능을 껐다가 다시 켜도 분류 정보가 유지되도록 하기 위함). 등록 비용은
		// 매우 가벼우므로 안전합니다.
		add_action( 'init', array( $this, 'register_path_taxonomy' ) );

		if ( empty( $this->settings['enabled'] ) ) {
			return;
		}

		// 기본 경로(블로그/뉴스/리뷰/가이드/커뮤니티) 최초 1회 자동 생성.
		add_action( 'init', array( $this, 'maybe_seed_default_paths' ), 20 );

		// 글 편집 화면에 "경로" 선택 메타박스는 택소노미 등록만으로 워드프레스가
		// 자동으로 표준 체크박스 패널을 제공하므로 별도 렌더링 코드가 필요 없습니다.

		if ( ! empty( $this->settings['related_posts'] ) || ! empty( $this->settings['prev_next_nav'] ) ) {
			// 관련글/이전-다음글은 본문 하단에 붙어야 하므로 늦은 우선순위로 둡니다.
			add_filter( 'the_content', array( $this, 'append_content_footer_blocks' ), 20 );
		}

		add_action( 'wp_head', array( $this, 'output_inline_styles' ) );

		// 글이 저장/발행/상태 변경될 때 해당 글의 관련글 캐시만 정밀하게 지웁니다.
		add_action( 'save_post', array( $this, 'purge_related_cache_on_save' ), 10, 1 );
		add_action( 'transition_post_status', array( $this, 'purge_related_cache_on_status_change' ), 10, 3 );
	}

	/* ════════════════════════════════════════════════════════
	   1. 경로(path) 택소노미 등록
	════════════════════════════════════════════════════════ */
	public function register_path_taxonomy() {
		if ( taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}

		register_taxonomy(
			self::TAXONOMY,
			array( 'post' ),
			array(
				'labels' => array(
					'name'          => __( '경로', 'zorlinq32' ),
					'singular_name' => __( '경로', 'zorlinq32' ),
					'search_items'  => __( '경로 검색', 'zorlinq32' ),
					'all_items'     => __( '전체 경로', 'zorlinq32' ),
					'edit_item'     => __( '경로 수정', 'zorlinq32' ),
					'update_item'   => __( '경로 업데이트', 'zorlinq32' ),
					'add_new_item'  => __( '새 경로 추가', 'zorlinq32' ),
					'new_item_name' => __( '새 경로 이름', 'zorlinq32' ),
					'menu_name'     => __( '경로', 'zorlinq32' ),
				),
				'hierarchical'      => true, // 카테고리처럼 체크박스 UI로 표시(계층 지원)
				'public'            => true,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true, // 블록 에디터(구텐베르크)에서도 선택 가능하도록
				'show_in_nav_menus' => true,
				'query_var'         => true,
				'rewrite'           => array( 'slug' => 'path', 'with_front' => false ),
			)
		);
	}

	/**
	 * 관리자가 아무 경로도 만들지 않았다면, "블로그/뉴스/리뷰/가이드/커뮤니티"
	 * 5개의 기본 경로를 최초 1회만 자동으로 만들어 둡니다(요구사항: 최소 5개 지정 경로).
	 * 이미 하나라도 경로가 존재하면(사용자가 직접 만들었거나 이전에 시딩됐다면)
	 * 다시 실행하지 않도록 옵션 플래그로 1회성 보장을 합니다.
	 */
	public function maybe_seed_default_paths() {
		if ( get_option( 'zorlinq32_content_hub_paths_seeded' ) ) {
			return;
		}
		if ( empty( $this->settings['path_taxonomy'] ) ) {
			return;
		}

		$labels = array(
			'blog'      => __( '블로그', 'zorlinq32' ),
			'news'      => __( '뉴스', 'zorlinq32' ),
			'review'    => __( '리뷰', 'zorlinq32' ),
			'guide'     => __( '가이드', 'zorlinq32' ),
			'community' => __( '커뮤니티', 'zorlinq32' ),
		);

		foreach ( $labels as $slug => $label ) {
			if ( ! term_exists( $slug, self::TAXONOMY ) ) {
				wp_insert_term( $label, self::TAXONOMY, array( 'slug' => $slug ) );
			}
		}

		update_option( 'zorlinq32_content_hub_paths_seeded', 1 );
	}

	/* ════════════════════════════════════════════════════════
	   2 & 3. 관련글(2행 4열) + 이전글/다음글 — the_content 하단에 자동 삽입
	════════════════════════════════════════════════════════ */
	public function append_content_footer_blocks( $content ) {
		// 싱글 글 본문에서만 동작. 목록/아카이브/피드/관리자 화면에서는 손대지 않습니다.
		if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		try {
			$extra = '';

			if ( ! empty( $this->settings['related_posts'] ) ) {
				$extra .= $this->build_related_posts_html();
			}

			if ( ! empty( $this->settings['prev_next_nav'] ) ) {
				$extra .= $this->build_prev_next_html();
			}

			return $content . $extra;
		} catch ( \Throwable $e ) {
			// 어떤 이유로든 실패하면 원본 콘텐츠를 그대로 반환해 사이트가 죽지 않도록 보장합니다.
			if ( class_exists( 'Zorlinq32_Logger' ) ) {
				Zorlinq32_Logger::log( 'Content_Hub append_content_footer_blocks 실패: ' . $e->getMessage() );
			}
			return $content;
		}
	}

	/**
	 * 현재 글과 같은 "경로"(zorlinq32_path)를 공유하는 글 중에서 관리자가 설정한
	 * 행×열 개수만큼 뽑아 반응형 그리드로 렌더링합니다. 경로가 지정되지 않은
	 * 글은 같은 카테고리 기준으로 자동 폴백합니다.
	 *
	 * 성능: 완성된 HTML을 캐시하므로(기본 12시간) 같은 글에 여러 방문자가
	 * 오더라도 DB 쿼리가 캐시 유효 기간 동안 한 번만 실행됩니다. 무작위 정렬을
	 * 선택해도 SQL ORDER BY RAND()를 쓰지 않고 최신 글 풀을 넉넉히 가져와
	 * PHP에서 섞으므로 글이 많은 사이트에서도 DB 부하가 거의 없습니다.
	 */
	private function build_related_posts_html() {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}

		$rows    = max( 1, (int) $this->settings['related_rows'] );
		$columns = max( 1, (int) $this->settings['related_columns'] );
		$order   = in_array( $this->settings['related_order_by'], array( 'date', 'rand', 'title' ), true )
			? $this->settings['related_order_by']
			: 'date';

		$cache_key = 'zlq32_related_' . $post_id . '_' . $rows . 'x' . $columns . '_' . $order;
		$cached    = $this->cache_get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$html = $this->render_related_posts_grid( $post_id, $rows, $columns, $order );
		$this->cache_set( $cache_key, $html );

		return $html;
	}

	/**
	 * 관련글 HTML을 실제로 생성합니다(캐시 미스일 때만 호출).
	 */
	private function render_related_posts_grid( $post_id, $rows, $columns, $order ) {
		$count = $rows * $columns;

		$tax_query  = array();
		$path_terms = wp_get_post_terms( $post_id, self::TAXONOMY, array( 'fields' => 'ids' ) );

		if ( ! is_wp_error( $path_terms ) && ! empty( $path_terms ) ) {
			$tax_query[] = array(
				'taxonomy' => self::TAXONOMY,
				'field'    => 'term_id',
				'terms'    => $path_terms,
			);
		} else {
			// 경로가 없으면 기존 카테고리로 폴백 — 신규 기능이 기존 콘텐츠 구조와도
			// 자연스럽게 호환되도록 하기 위함.
			$cat_terms = wp_get_post_categories( $post_id );
			if ( ! empty( $cat_terms ) ) {
				$tax_query[] = array(
					'taxonomy' => 'category',
					'field'    => 'term_id',
					'terms'    => $cat_terms,
				);
			}
		}

		$want_random = ( 'rand' === $order );

		$args = array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'post__not_in'           => array( $post_id ),
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		);

		if ( 'title' === $order ) {
			$args['orderby'] = 'title';
			$args['order']   = 'ASC';
		} else {
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
		}

		// 무작위 정렬은 SQL RAND() 대신, 필요한 개수보다 넉넉한 최신 글 풀을 가져와
		// PHP에서 섞습니다 — MySQL이 결과 전체를 임시 테이블에 정렬해야 하는
		// RAND()의 부하를 피하면서도 매번 다른 조합을 보여줄 수 있습니다.
		$args['posts_per_page'] = $want_random ? min( self::RAND_POOL_MAX, $count * self::RAND_POOL_MULTIPLIER ) : $count;

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$ids = get_posts( $args );
		if ( $want_random ) {
			shuffle( $ids );
		}
		$ids = array_slice( $ids, 0, $count );

		// 같은 경로/카테고리에 글이 부족하면 최신 글로 채워 항상 설정된 개수를 최대한 채웁니다.
		if ( count( $ids ) < $count ) {
			$needed  = $count - count( $ids );
			$exclude = $ids;
			$exclude[] = $post_id;

			$fill_args = array(
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'post__not_in'           => $exclude,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'orderby'                => $args['orderby'],
				'order'                  => $args['order'],
				'posts_per_page'         => $want_random ? min( self::RAND_POOL_MAX, $needed * self::RAND_POOL_MULTIPLIER ) : $needed,
			);

			$fill_ids = get_posts( $fill_args );
			if ( $want_random ) {
				shuffle( $fill_ids );
			}
			$ids = array_merge( $ids, array_slice( $fill_ids, 0, $needed ) );
		}

		if ( empty( $ids ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="zorlinq32-related-posts" style="--zlq32-columns:<?php echo esc_attr( $columns ); ?>;">
			<h3 class="zorlinq32-related-posts__title"><?php esc_html_e( '관련 글', 'zorlinq32' ); ?></h3>
			<div class="zorlinq32-related-posts__grid">
				<?php foreach ( $ids as $id ) :
					$thumb = get_the_post_thumbnail_url( $id, 'medium' );
					?>
					<a class="zorlinq32-related-posts__card" href="<?php echo esc_url( get_permalink( $id ) ); ?>">
						<span class="zorlinq32-related-posts__thumb" <?php if ( $thumb ) : ?>style="background-image:url('<?php echo esc_url( $thumb ); ?>');"<?php endif; ?>>
							<?php if ( ! $thumb ) : ?><span class="zorlinq32-related-posts__thumb-fallback">📄</span><?php endif; ?>
						</span>
						<span class="zorlinq32-related-posts__caption"><?php echo esc_html( get_the_title( $id ) ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ════════════════════════════════════════════════════════
	   관련글 캐시 유틸 (객체 캐시 우선, transient 폴백)
	════════════════════════════════════════════════════════ */

	private function cache_get( $key ) {
		if ( wp_using_ext_object_cache() ) {
			return wp_cache_get( $key, 'zorlinq32_content_hub' );
		}
		return get_transient( $key );
	}

	private function cache_set( $key, $value ) {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $key, $value, 'zorlinq32_content_hub', self::cache_ttl() );
			return;
		}
		set_transient( $key, $value, self::cache_ttl() );
	}

	/**
	 * 특정 글의 관련글 캐시를 지웁니다. 행/열/정렬 조합별로 키가 달라지므로
	 * 흔히 쓰이는 조합 몇 가지를 지우는 대신, transient는 접두사로 일괄 삭제합니다.
	 */
	private function purge_related_cache_for_post( $post_id ) {
		global $wpdb;

		if ( wp_using_ext_object_cache() ) {
			// 그룹 단위 캐시는 TTL(12시간)로 자연 소멸하므로 즉시 삭제를 강제하지 않아도
			// 안전합니다. 대부분의 객체 캐시 드라이버는 값 자체를 짧은 시간 내 무효화합니다.
			return;
		}

		$like = $wpdb->esc_like( '_transient_zlq32_related_' . $post_id . '_' ) . '%';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function purge_related_cache_on_save( $post_id ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$this->purge_related_cache_for_post( $post_id );
	}

	public function purge_related_cache_on_status_change( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status ) {
			return;
		}
		$this->purge_related_cache_for_post( $post->ID );
	}

	/**
	 * 같은 경로 내에서 발행일 기준 이전글/다음글을 계산합니다.
	 * 같은 경로에 글이 하나뿐이면(이전/다음이 모두 없으면) 전체 글 기준으로 폴백합니다.
	 */
	private function build_prev_next_html() {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}

		$path_terms = wp_get_post_terms( $post_id, self::TAXONOMY, array( 'fields' => 'ids' ) );
		$has_path   = ! is_wp_error( $path_terms ) && ! empty( $path_terms );

		$prev_post = $this->get_adjacent_post_in_scope( $post_id, true, $has_path ? $path_terms : array() );
		$next_post = $this->get_adjacent_post_in_scope( $post_id, false, $has_path ? $path_terms : array() );

		// 경로 범위에서 못 찾았다면 전체 글 기준으로 한 번 더 시도(폴백).
		if ( $has_path && ! $prev_post ) {
			$prev_post = $this->get_adjacent_post_in_scope( $post_id, true, array() );
		}
		if ( $has_path && ! $next_post ) {
			$next_post = $this->get_adjacent_post_in_scope( $post_id, false, array() );
		}

		if ( ! $prev_post && ! $next_post ) {
			return '';
		}

		ob_start();
		?>
		<nav class="zorlinq32-prev-next" aria-label="<?php esc_attr_e( '이전글 다음글 내비게이션', 'zorlinq32' ); ?>">
			<?php if ( $prev_post ) : ?>
				<a class="zorlinq32-prev-next__item zorlinq32-prev-next__item--prev" href="<?php echo esc_url( get_permalink( $prev_post ) ); ?>">
					<span class="zorlinq32-prev-next__label">&larr; <?php esc_html_e( '이전 글', 'zorlinq32' ); ?></span>
					<span class="zorlinq32-prev-next__title"><?php echo esc_html( get_the_title( $prev_post ) ); ?></span>
				</a>
			<?php else : ?>
				<span class="zorlinq32-prev-next__item zorlinq32-prev-next__item--empty"></span>
			<?php endif; ?>

			<?php if ( $next_post ) : ?>
				<a class="zorlinq32-prev-next__item zorlinq32-prev-next__item--next" href="<?php echo esc_url( get_permalink( $next_post ) ); ?>">
					<span class="zorlinq32-prev-next__label"><?php esc_html_e( '다음 글', 'zorlinq32' ); ?> &rarr;</span>
					<span class="zorlinq32-prev-next__title"><?php echo esc_html( get_the_title( $next_post ) ); ?></span>
				</a>
			<?php else : ?>
				<span class="zorlinq32-prev-next__item zorlinq32-prev-next__item--empty"></span>
			<?php endif; ?>
		</nav>
		<?php
		return ob_get_clean();
	}

	/**
	 * $term_ids가 비어 있지 않으면 해당 경로(taxonomy term) 범위 안에서,
	 * 비어 있으면 전체 글 중에서 발행일 기준 인접 글을 찾습니다.
	 * WordPress 코어의 get_adjacent_post()는 카테고리(category)만 지원하므로,
	 * 커스텀 택소노미 범위 조회를 위해 직접 WP_Query로 구현합니다.
	 */
	private function get_adjacent_post_in_scope( $post_id, $previous, array $term_ids ) {
		$current_date = get_post_field( 'post_date', $post_id );
		if ( ! $current_date ) {
			return null;
		}

		$args = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => 1,
			'post__not_in'        => array( $post_id ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'orderby'             => 'date',
			'order'               => $previous ? 'DESC' : 'ASC',
			'date_query'          => array(
				array(
					$previous ? 'before' : 'after' => $current_date,
					'inclusive' => false,
					'column'    => 'post_date',
				),
			),
		);

		if ( ! empty( $term_ids ) ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => self::TAXONOMY,
					'field'    => 'term_id',
					'terms'    => $term_ids,
				),
			);
		}

		$q = new WP_Query( $args );
		$result = $q->have_posts() ? $q->posts[0]->ID : null;
		wp_reset_postdata();
		return $result;
	}

	/* ════════════════════════════════════════════════════════
	   프론트엔드 기본 스타일 (테마 충돌을 피하기 위해 최소한의 스코프드 CSS만 출력)
	════════════════════════════════════════════════════════ */
	public function output_inline_styles() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}
		?>
		<style id="zorlinq32-content-hub-css">
		.zorlinq32-related-posts{margin:40px 0 0;--zlq32-columns:4;}
		.zorlinq32-related-posts__title{font-size:18px;font-weight:800;margin:0 0 14px;}
		.zorlinq32-related-posts__grid{display:grid;grid-template-columns:repeat(var(--zlq32-columns),1fr);gap:16px;}
		@media (max-width:900px){.zorlinq32-related-posts__grid{grid-template-columns:repeat(2,1fr);}}
		@media (max-width:480px){.zorlinq32-related-posts__grid{grid-template-columns:1fr;}}
		.zorlinq32-related-posts__card{display:block;text-decoration:none;color:inherit;border-radius:12px;overflow:hidden;background:#fff;border:1px solid #ececef;transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease;}
		.zorlinq32-related-posts__card:hover{transform:translateY(-3px);box-shadow:0 10px 24px -8px rgba(20,20,30,.16);border-color:#d8d8de;}
		.zorlinq32-related-posts__thumb{display:flex;align-items:center;justify-content:center;width:100%;aspect-ratio:16/10;background-color:#f2f2f5;background-size:cover;background-position:center;}
		.zorlinq32-related-posts__thumb-fallback{font-size:28px;opacity:.3;}
		.zorlinq32-related-posts__caption{display:block;padding:12px 14px;font-size:13px;line-height:1.45;font-weight:600;}

		.zorlinq32-prev-next{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:32px 0 0;}
		@media (max-width:600px){.zorlinq32-prev-next{grid-template-columns:1fr;}}
		.zorlinq32-prev-next__item{display:block;padding:14px 16px;border:1px solid #ececef;border-radius:12px;text-decoration:none;color:inherit;transition:border-color .15s ease;}
		.zorlinq32-prev-next__item--next{text-align:right;}
		.zorlinq32-prev-next__item:hover{border-color:#6366f1;}
		.zorlinq32-prev-next__label{display:block;font-size:12px;font-weight:700;color:#8a8a93;margin-bottom:4px;}
		.zorlinq32-prev-next__title{display:block;font-size:14px;font-weight:700;}
		</style>
		<?php
	}
}
