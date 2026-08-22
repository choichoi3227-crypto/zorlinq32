<?php
/**
 * 안전한 내부 로거.
 *
 * 이 플러그인의 모든 모듈은 예외를 던지는 대신 이 로거를 통해
 * 문제를 기록만 하고 사이트 정상 동작을 우선시합니다.
 * ("워드프레스 에러/장애 절대 금지" 요구사항 대응)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Logger {

	const OPTION_KEY = 'zorlinq32_error_log';
	const MAX_ENTRIES = 50;

	/**
	 * 오류/경고 메시지를 DB 옵션에 최대 개수를 유지하며 기록합니다.
	 * WP_DEBUG_LOG가 켜져 있으면 error_log에도 함께 남깁니다.
	 */
	public static function log( $message ) {
		try {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[Zorlinq32] ' . $message );
			}

			$logs = get_option( self::OPTION_KEY, array() );
			if ( ! is_array( $logs ) ) {
				$logs = array();
			}

			$logs[] = array(
				'time'    => current_time( 'mysql' ),
				'message' => is_string( $message ) ? $message : wp_json_encode( $message ),
			);

			// 오래된 로그부터 잘라내어 옵션 테이블 비대화를 방지합니다.
			if ( count( $logs ) > self::MAX_ENTRIES ) {
				$logs = array_slice( $logs, -1 * self::MAX_ENTRIES );
			}

			update_option( self::OPTION_KEY, $logs, false );
		} catch ( \Throwable $e ) {
			// 로거 자체가 실패해도 절대 밖으로 예외를 전파하지 않습니다.
		}
	}

	/**
	 * 저장된 로그 목록을 반환합니다 (관리자 화면 표시용).
	 */
	public static function get_logs() {
		$logs = get_option( self::OPTION_KEY, array() );
		return is_array( $logs ) ? array_reverse( $logs ) : array();
	}

	/**
	 * 로그를 초기화합니다.
	 */
	public static function clear_logs() {
		delete_option( self::OPTION_KEY );
	}
}
