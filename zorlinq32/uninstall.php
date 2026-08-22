<?php
/**
 * 플러그인 삭제(완전 제거) 시 실행되는 파일.
 * 워드프레스가 관리자 화면에서 "삭제"를 눌렀을 때만 로드됩니다.
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- 이 파일은 워드프레스가 격리된 단발성 컨텍스트에서 한 번만 로드하므로 변수 네임스페이스 충돌 위험이 없습니다.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 플러그인 설정 옵션 삭제
delete_option( 'zorlinq32_settings' );
delete_option( 'zorlinq32_error_log' );
delete_option( 'zorlinq32_rewrite_flush_needed' );
delete_option( 'zorlinq32_cron_missed_log' );
delete_option( 'zorlinq32_cron_health' );
delete_option( 'zorlinq32_last_visit_time' );
delete_option( 'zorlinq32_last_self_ping' );

// AI 글쓰기 · 썸네일 모듈 옵션 삭제
delete_option( 'zorlinq32_ai_gemini_api_keys' );
delete_option( 'zorlinq32_ai_search_worker_url' );
delete_option( 'zorlinq32_ai_search_worker_secret' );
delete_option( 'zorlinq32_ai_thumb_templates' );
delete_option( 'zorlinq32_ai_thumb_font_path' );
// Removed image-provider options are deleted for upgrades from older versions.
delete_option( 'zorlinq32_ai_cf_worker_url' );
delete_option( 'zorlinq32_ai_image_worker_secret' );
delete_option( 'zorlinq32_ai_horde_api_key' );

// Removed legacy image integration options are deleted for upgrades from older versions.
delete_option( 'zorlinq32_google_imagen_config' );
delete_option( 'zorlinq32_google_imagen_token' );
delete_option( 'zorlinq32_google_imagen_access_cache' );
delete_option( 'zorlinq32_google_imagen_fallback_key' );

// 2026-08 추가: 콘텐츠 허브(경로 분류/관련글/이전-다음글/자동 목차) 모듈 옵션 삭제.
delete_option( 'zorlinq32_content_hub_settings' );
delete_option( 'zorlinq32_content_hub_paths_seeded' );
// 경로(zorlinq32_path) 택소노미의 용어(term)들도 함께 정리한다. get_terms()는 대상
// 택소노미가 register_taxonomy()로 등록되어 있어야 정상 동작하는데, uninstall.php가
// 실행되는 시점은 플러그인이 이미 비활성화된 뒤라 이 택소노미가 등록되어 있지 않다.
// 따라서 삭제 직전 임시로 최소 스펙만 등록해(공개 여부 등은 중요하지 않음, 오직
// get_terms/wp_delete_term이 이 택소노미를 인식하게만 하면 된다) 안전하게 정리한다.
if ( ! taxonomy_exists( 'zorlinq32_path' ) ) {
	register_taxonomy( 'zorlinq32_path', array( 'post' ) );
}
$zorlinq32_path_terms = get_terms( array(
	'taxonomy'   => 'zorlinq32_path',
	'hide_empty' => false,
	'fields'     => 'ids',
) );
if ( ! is_wp_error( $zorlinq32_path_terms ) && ! empty( $zorlinq32_path_terms ) ) {
	foreach ( $zorlinq32_path_terms as $zorlinq32_term_id ) {
		wp_delete_term( $zorlinq32_term_id, 'zorlinq32_path' );
	}
}

// 예약된 cron 작업 정리
$timestamp = wp_next_scheduled( 'zorlinq32_storage_check' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'zorlinq32_storage_check' );
}
// 이전 버전에서 등록된 레거시 훅(하루 1회)이 남아있을 수 있어 함께 정리합니다.
$legacy_timestamp = wp_next_scheduled( 'zorlinq32_daily_storage_check' );
if ( $legacy_timestamp ) {
	wp_unschedule_event( $legacy_timestamp, 'zorlinq32_daily_storage_check' );
}
$timestamp2 = wp_next_scheduled( 'zorlinq32_daily_cache_cleanup' );
if ( $timestamp2 ) {
	wp_unschedule_event( $timestamp2, 'zorlinq32_daily_cache_cleanup' );
}
$timestamp3 = wp_next_scheduled( 'zorlinq32_cron_health_check' );
if ( $timestamp3 ) {
	wp_unschedule_event( $timestamp3, 'zorlinq32_cron_health_check' );
}
$timestamp4 = wp_next_scheduled( 'zorlinq32_analytics_daily_cleanup' );
if ( $timestamp4 ) {
	wp_unschedule_event( $timestamp4, 'zorlinq32_analytics_daily_cleanup' );
}
$timestamp5 = wp_next_scheduled( 'zorlinq32_adprotect_daily_cleanup' );
if ( $timestamp5 ) {
	wp_unschedule_event( $timestamp5, 'zorlinq32_adprotect_daily_cleanup' );
}

// 애드센스 보호 커스텀 테이블 삭제
require_once __DIR__ . '/includes/class-zorlinq32-adprotect-db.php';
if ( class_exists( 'Zorlinq32_AdProtect_DB' ) ) {
	Zorlinq32_AdProtect_DB::drop_tables();
}

// 애널리틱스 커스텀 테이블 및 관련 옵션 삭제
require_once __DIR__ . '/includes/class-zorlinq32-analytics-db.php';
if ( class_exists( 'Zorlinq32_Analytics_DB' ) ) {
	Zorlinq32_Analytics_DB::drop_table();
}
delete_option( 'zorlinq32_analytics_db_version' );
// 최근 며칠치 일별 솔트 옵션도 함께 정리합니다 (오늘부터 과거 3일).
for ( $zorlinq32_days_ago = 0; $zorlinq32_days_ago <= 3; $zorlinq32_days_ago++ ) {
	delete_option( 'zorlinq32_analytics_salt_' . gmdate( 'Y-m-d', strtotime( '-' . $zorlinq32_days_ago . ' days' ) ) );
}

// 글/페이지에 저장된 SEO 메타 데이터 삭제
delete_post_meta_by_key( '_zorlinq32_meta_description' );
delete_post_meta_by_key( '_zorlinq32_focus_keywords' );
delete_post_meta_by_key( '_zorlinq32_og_image_id' );

// AI 글쓰기 모듈이 저장한 post meta 삭제
delete_post_meta_by_key( '_ai_seo_title' );
delete_post_meta_by_key( '_ai_meta_desc' );
delete_post_meta_by_key( '_ai_slug' );
delete_post_meta_by_key( '_ai_focus_keyword' );
delete_post_meta_by_key( '_ai_blog_schema_markup' );

// 카테고리 SEO 설명 삭제 (모든 카테고리 term을 순회)
$zorlinq32_categories = get_terms(
	array(
		'taxonomy'   => 'category',
		'hide_empty' => false,
		'fields'     => 'ids',
	)
);
if ( is_array( $zorlinq32_categories ) ) {
	foreach ( $zorlinq32_categories as $zorlinq32_term_id ) {
		delete_term_meta( $zorlinq32_term_id, 'zorlinq32_category_seo_description' );
	}
}

// 작성자 SEO 설명 삭제 (모든 사용자를 순회)
$zorlinq32_users = get_users( array( 'fields' => 'ID' ) );
if ( is_array( $zorlinq32_users ) ) {
	foreach ( $zorlinq32_users as $zorlinq32_user_id ) {
		delete_user_meta( $zorlinq32_user_id, 'zorlinq32_author_seo_description' );
	}
}

// 캐시 디렉토리 삭제
$upload_dir = wp_upload_dir();
$cache_dir  = trailingslashit( $upload_dir['basedir'] ) . 'zorlinq32-cache/';
if ( is_dir( $cache_dir ) ) {
	$files = glob( $cache_dir . '*' );
	if ( is_array( $files ) ) {
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}
	@rmdir( $cache_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}
