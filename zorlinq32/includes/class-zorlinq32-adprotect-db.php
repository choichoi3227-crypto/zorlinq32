<?php
/**
 * 애드센스 부정클릭 방지 데이터베이스 스키마.
 *
 * 두 개의 커스텀 테이블을 사용합니다:
 * 1. zorlinq32_ad_clicks : 광고 슬롯에서 관찰된 클릭 원본 기록 (탐지 시간 윈도우 계산용)
 * 2. zorlinq32_ad_blocks : 자동/수동으로 차단된 방문자(IP + 기기 핑거프린트) 목록
 *
 * 이 모듈은 클릭을 절대 가로채거나 막지 않고 "관찰"만 합니다. 실제 차단은
 * 이후 방문 시 광고 슬롯을 비워서 렌더링하는 방식으로만 이루어집니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_AdProtect_DB {

	const DB_VERSION_OPTION = 'zorlinq32_adprotect_db_version';
	const DB_VERSION        = '1.0';

	public static function clicks_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'zorlinq32_ad_clicks';
	}

	public static function blocks_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'zorlinq32_ad_blocks';
	}

	public static function maybe_create_tables() {
		try {
			$installed_version = get_option( self::DB_VERSION_OPTION );
			if ( self::DB_VERSION === $installed_version ) {
				return;
			}

			global $wpdb;
			$charset_collate = $wpdb->get_charset_collate();

			$clicks_table = self::clicks_table_name();
			$blocks_table = self::blocks_table_name();

			$sql_clicks = "CREATE TABLE {$clicks_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				visitor_key VARCHAR(64) NOT NULL,
				ip_hash VARCHAR(64) NOT NULL,
				country_code VARCHAR(2) NULL,
				clicked_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY visitor_key (visitor_key),
				KEY clicked_at (clicked_at)
			) {$charset_collate};";

			$sql_blocks = "CREATE TABLE {$blocks_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				visitor_key VARCHAR(64) NOT NULL,
				ip_hash VARCHAR(64) NOT NULL,
				reason VARCHAR(20) NOT NULL DEFAULT 'auto_click',
				blocked_at DATETIME NOT NULL,
				expires_at DATETIME NULL,
				manual BOOLEAN NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY visitor_key (visitor_key),
				KEY expires_at (expires_at)
			) {$charset_collate};";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql_clicks );
			dbDelta( $sql_blocks );

			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'Zorlinq32_Logger' ) ) {
				Zorlinq32_Logger::log( '애드센스 보호 테이블 생성 중 오류: ' . $e->getMessage() );
			}
		}
	}

	public static function drop_tables() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 플러그인 삭제 시 커스텀 테이블 자체를 제거하는 것이라 코어 API로 대체할 수 없습니다.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::clicks_table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::blocks_table_name() );
		delete_option( self::DB_VERSION_OPTION );
	}
}
