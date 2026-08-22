<?php
/**
 * 애널리틱스 데이터베이스 스키마 관리.
 *
 * 워드프레스 표준 방식(dbDelta를 이용한 커스텀 테이블)으로 방문 기록을 저장합니다.
 * 외부 서비스(D1, 서드파티 분석 SaaS 등)를 전혀 사용하지 않고 사이트 자체 DB에만 저장합니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Analytics_DB {

	const DB_VERSION_OPTION = 'zorlinq32_analytics_db_version';
	const DB_VERSION        = '1.1';

	/**
	 * 방문 기록 테이블 이름을 반환합니다 (멀티사이트 환경의 사이트별 프리픽스를 자동 반영).
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'zorlinq32_visits';
	}

	/**
	 * 테이블이 없거나 스키마 버전이 다르면 생성/갱신합니다.
	 * 활성화 시점, 그리고 만약을 대비해 관리자 화면 로드 시점에도 안전하게 재확인합니다.
	 */
	public static function maybe_create_table() {
		try {
			$installed_version = get_option( self::DB_VERSION_OPTION );
			if ( self::DB_VERSION === $installed_version ) {
				return;
			}

			global $wpdb;
			$table_name      = self::table_name();
			$charset_collate = $wpdb->get_charset_collate();

			// dbDelta는 정해진 SQL 형식(각 컬럼 정의 사이 줄바꿈, 기본 키는 KEY로 표기 등)을 요구합니다.
			$sql = "CREATE TABLE {$table_name} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id BIGINT UNSIGNED NULL,
				referrer_type VARCHAR(20) NOT NULL DEFAULT 'direct',
				referrer_source VARCHAR(50) NULL,
				referrer_domain VARCHAR(255) NULL,
				keyword VARCHAR(255) NULL,
				visitor_hash VARCHAR(64) NOT NULL,
				visited_date DATE NOT NULL,
				visited_at DATETIME NOT NULL,
				bot_name VARCHAR(100) NULL,
				bot_details VARCHAR(255) NULL,
				PRIMARY KEY  (id),
				KEY post_id (post_id),
				KEY visited_date (visited_date),
				KEY referrer_type (referrer_type),
				KEY visitor_hash (visitor_hash)
			) {$charset_collate};";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
			self::maybe_add_missing_columns();

			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'Zorlinq32_Logger' ) ) {
				Zorlinq32_Logger::log( '애널리틱스 테이블 생성 중 오류: ' . $e->getMessage() );
			}
		}
	}

	private static function maybe_add_missing_columns() {
		global $wpdb;
		$table_name = self::table_name();
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table_name}", ARRAY_A );
		if ( ! is_array( $columns ) ) {
			return;
		}
		$existing = array();
		foreach ( $columns as $column ) {
			$existing[] = strtolower( $column['Field'] );
		}

		if ( ! in_array( 'bot_name', $existing, true ) ) {
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN bot_name VARCHAR(100) NULL" );
		}
		if ( ! in_array( 'bot_details', $existing, true ) ) {
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN bot_details VARCHAR(255) NULL" );
		}
	}

	/**
	 * [요청 기능: 통계 초기화] 테이블 구조는 유지한 채 모든 방문 기록만 삭제합니다.
	 * 플러그인 삭제(drop_table)와 달리, 기능은 계속 사용하면서 누적된 데이터만 비우고
	 * 싶을 때(예: 잘못 집계된 과거 데이터를 정리하고 새로 시작하고 싶을 때) 사용합니다.
	 */
	public static function truncate_table() {
		global $wpdb;
		// [애널리틱스 초기화 오류 수정] 애널리틱스 기능을 켠 적이 없거나, 활성화 훅이 아직
		// 실행되지 않은 등의 이유로 테이블 자체가 없는 상태에서 TRUNCATE를 실행하면 SQL 오류가
		// 발생해 초기화 요청이 실패했습니다. TRUNCATE 직전에 테이블 존재를 보장합니다.
		self::maybe_create_table();
		$table_name = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 커스텀 통계 테이블 전체 초기화이며 코어 API가 없습니다.
		return $wpdb->query( "TRUNCATE TABLE {$table_name}" );
	}

	/**
	 * 플러그인 삭제 시 테이블을 완전히 제거합니다. (uninstall.php에서 호출)
	 */
	public static function drop_table() {
		global $wpdb;
		$table_name = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 플러그인 삭제 시 커스텀 테이블 자체를 제거하는 것이라 코어 API로 대체할 수 없습니다.
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
		delete_option( self::DB_VERSION_OPTION );
	}
}
