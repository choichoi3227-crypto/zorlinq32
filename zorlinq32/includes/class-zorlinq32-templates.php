<?php
/**
 * Op 템플릿 (블록 패턴) 모듈.
 *
 * 워드프레스는 이미 "패턴(Pattern)"이라는 기능으로 "자주 쓰는 블록 조합을 저장했다가
 * 재사용"하는 기능을 코어에서 완전히 지원합니다(에디터의 "선택 영역을 패턴으로 저장" 메뉴).
 * 이 모듈은 그 기능을 대체하는 새 시스템을 만드는 대신, 사용자가 만든 패턴이 별도의
 * "Op 템플릿" 카테고리로 분류되어 에디터의 블록 삽입 화면에서 쉽게 찾을 수 있도록 하고,
 * 관리자 화면에서 등록된 템플릿을 한눈에 보고 관리(삭제)할 수 있는 목록을 제공합니다.
 *
 * 사용자 정의 패턴은 워드프레스 코어의 'wp_block' 글 타입(재사용 블록/패턴)으로 저장되므로,
 * 이 모듈은 그 데이터를 그대로 활용하며 별도 테이블을 만들지 않습니다.
 *
 * [버그 수정] 이전 구현은 register_block_pattern_category()로 카테고리 "이름"만 등록했는데,
 * 이 함수는 PHP 코드로 정적 등록하는 패턴(register_block_pattern())에만 적용되는 개념이라,
 * 사용자가 에디터에서 "패턴으로 저장"해 만드는 wp_block 글에는 전혀 연결되지 않았습니다.
 * 그 결과 "저장은 되지만 어느 카테고리에도 속하지 않는 상태"가 되어, 카테고리 필터가
 * 걸린 화면에서 패턴을 찾으려 할 때 목록이 비거나 삽입 시 예기치 않은 오류로 이어질 수
 * 있었습니다. 워드프레스 6.3부터 재사용 블록/패턴은 'wp_pattern_category'라는 실제
 * 택소노미로 분류됩니다. 이제는 이 택소노미에 "Op 템플릿" 텀을 등록하고, 사용자가 패턴을
 * 저장할 때 이 텀이 자동으로 함께 지정되도록 연결합니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Templates {

	private static $instance = null;

	const CATEGORY_SLUG = 'zorlinq32-templates';
	const TAXONOMY       = 'wp_pattern_category';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// wp_pattern_category 택소노미는 워드프레스 코어가 등록하므로, 이 텀을 만드는 작업은
		// 그 택소노미가 실제로 존재하는 시점(init) 이후에 수행해야 합니다.
		add_action( 'init', array( $this, 'ensure_pattern_category_term' ), 20 );
		// 사용자가 에디터에서 새 재사용 블록(wp_block)을 저장할 때, "Op 템플릿" 카테고리를
		// 자동으로 지정합니다. 이렇게 해야 저장한 패턴이 실제로 카테고리 아래에 나타납니다.
		add_action( 'save_post_wp_block', array( $this, 'assign_category_to_pattern' ), 10, 2 );
	}

	/**
	 * "Op 템플릿" 카테고리 텀이 존재하지 않으면 생성합니다. 이미 있으면 아무 것도 하지 않습니다.
	 * 매 요청마다 DB 쿼리를 피하기 위해, 한 번 확인된 이후에는 옵션에 텀 ID를 캐시합니다.
	 */
	public function ensure_pattern_category_term() {
		try {
			if ( ! taxonomy_exists( self::TAXONOMY ) ) {
				// 매우 오래된 워드프레스 버전(6.3 미만)에서는 이 택소노미가 없을 수 있습니다.
				// 이 경우 카테고리 분류 기능 없이도 재사용 블록 저장/사용 자체는 코어 기능이라
				// 정상 동작하므로, 조용히 건너뜁니다.
				return;
			}

			$cached_term_id = get_option( 'zorlinq32_pattern_category_term_id' );
			if ( $cached_term_id && term_exists( (int) $cached_term_id, self::TAXONOMY ) ) {
				return; // 이미 생성되어 있고 유효함.
			}

			$existing = term_exists( self::CATEGORY_SLUG, self::TAXONOMY );
			if ( $existing ) {
				$term_id = is_array( $existing ) ? (int) $existing['term_id'] : (int) $existing;
				update_option( 'zorlinq32_pattern_category_term_id', $term_id );
				return;
			}

			$inserted = wp_insert_term(
				__( 'Op 템플릿', 'zorlinq32' ),
				self::TAXONOMY,
				array( 'slug' => self::CATEGORY_SLUG )
			);

			if ( ! is_wp_error( $inserted ) && isset( $inserted['term_id'] ) ) {
				update_option( 'zorlinq32_pattern_category_term_id', (int) $inserted['term_id'] );
			} else {
				Zorlinq32_Logger::log( 'Op 템플릿 카테고리 생성 실패: ' . ( is_wp_error( $inserted ) ? $inserted->get_error_message() : '알 수 없는 오류' ) );
			}
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( 'Op 템플릿 카테고리 확인 중 오류: ' . $e->getMessage() );
		}
	}

	/**
	 * 재사용 블록(wp_block)이 저장될 때 "Op 템플릿" 카테고리를 자동으로 지정합니다.
	 * 이미 다른 카테고리가 지정되어 있다면 덮어쓰지 않고 추가만 합니다(사용자가 직접
	 * 다른 카테고리를 선택했을 수 있으므로).
	 */
	public function assign_category_to_pattern( $post_id, $post ) {
		try {
			if ( ! taxonomy_exists( self::TAXONOMY ) ) {
				return;
			}
			if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
				return;
			}
			if ( 'auto-draft' === $post->post_status ) {
				return;
			}

			$term_id = (int) get_option( 'zorlinq32_pattern_category_term_id' );
			if ( ! $term_id || ! term_exists( $term_id, self::TAXONOMY ) ) {
				// 아직 카테고리가 준비되지 않았다면(드문 경우) 지금 즉시 생성을 시도합니다.
				$this->ensure_pattern_category_term();
				$term_id = (int) get_option( 'zorlinq32_pattern_category_term_id' );
				if ( ! $term_id ) {
					return;
				}
			}

			$existing_terms = wp_get_object_terms( $post_id, self::TAXONOMY, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $existing_terms ) ) {
				$existing_terms = array();
			}
			if ( in_array( $term_id, $existing_terms, true ) ) {
				return; // 이미 지정되어 있음.
			}

			wp_set_object_terms( $post_id, array_merge( $existing_terms, array( $term_id ) ), self::TAXONOMY, false );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( 'Op 템플릿 카테고리 지정 중 오류: ' . $e->getMessage() );
		}
	}

	/**
	 * 사용자가 만든 패턴(wp_block 글 타입) 목록을 관리자 화면 표시용으로 반환합니다.
	 *
	 * [버그 수정: 기존 저장분 복구] 이 메서드 수정 이전에 저장된 패턴들은 "Op 템플릿"
	 * 카테고리가 전혀 지정되지 않은 상태로 남아있을 수 있습니다. 관리자가 이 목록을 볼 때마다
	 * 카테고리가 없는 패턴을 찾아 자동으로 지정해, 별도 재저장 없이도 정상적으로 카테고리
	 * 아래에 나타나도록 소급 복구합니다.
	 */
	public static function get_user_patterns() {
		try {
			$posts = get_posts(
				array(
					'post_type'      => 'wp_block',
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);

			self::backfill_missing_categories( $posts );

			$result = array();
			foreach ( $posts as $post ) {
				$result[] = array(
					'id'       => $post->ID,
					'title'    => $post->post_title ? $post->post_title : __( '(제목 없음)', 'zorlinq32' ),
					'modified' => get_the_modified_date( 'Y-m-d H:i', $post ),
					'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
				);
			}
			return $result;
		} catch ( \Throwable $e ) {
			if ( class_exists( 'Zorlinq32_Logger' ) ) {
				Zorlinq32_Logger::log( 'Op 템플릿 목록 조회 중 오류: ' . $e->getMessage() );
			}
			return array();
		}
	}

	/**
	 * 카테고리(wp_pattern_category 텀)가 지정되지 않은 패턴들에 소급으로 텀을 부여합니다.
	 * 이미 텀이 있는 패턴은 건드리지 않습니다. 관리자 목록 화면에서만(저빈도) 실행되므로
	 * 프론트엔드 성능에는 영향이 없습니다.
	 */
	private static function backfill_missing_categories( $posts ) {
		if ( ! taxonomy_exists( self::TAXONOMY ) || empty( $posts ) ) {
			return;
		}

		$term_id = (int) get_option( 'zorlinq32_pattern_category_term_id' );
		if ( ! $term_id || ! term_exists( $term_id, self::TAXONOMY ) ) {
			return; // 카테고리 자체가 아직 준비되지 않았다면(드문 경우) 다음 init 시점에 생성됩니다.
		}

		foreach ( $posts as $post ) {
			$existing_terms = wp_get_object_terms( $post->ID, self::TAXONOMY, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $existing_terms ) ) {
				continue;
			}
			if ( in_array( $term_id, $existing_terms, true ) ) {
				continue; // 이미 지정되어 있음.
			}
			wp_set_object_terms( $post->ID, array_merge( $existing_terms, array( $term_id ) ), self::TAXONOMY, false );
		}
	}

	/**
	 * 지정된 패턴(wp_block 글)을 삭제합니다.
	 */
	public static function delete_pattern( $post_id ) {
		if ( 'wp_block' !== get_post_type( $post_id ) ) {
			return false;
		}
		return wp_delete_post( $post_id, true );
	}
}
