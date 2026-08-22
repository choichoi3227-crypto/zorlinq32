<?php
/**
 * 애널리틱스 통계 집계 쿼리.
 *
 * 관리자 대시보드 및 글 편집 화면에서 사용하는 모든 집계 쿼리를 담당합니다.
 * 모든 쿼리는 커스텀 테이블(wp_zorlinq32_visits)을 대상으로 하며,
 * 워드프레스 코어에 대응하는 API가 없어 $wpdb를 직접 사용합니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Analytics_Query {

	/**
	 * [요청 기능: 애널리틱스 캐시 시간 설정] 관리자가 설정 화면에서 1/5/10/30/60분 중
	 * 선택한 캐시 유효 시간(분)을 반환합니다. 짧을수록 통계가 더 실시간에 가깝지만
	 * DB 조회가 잦아져 서버 부하가 커지고, 길수록 부하는 줄지만 최신 방문이 반영되기까지
	 * 지연이 생깁니다(관리자 화면 안내 문구에도 이 트레이드오프를 명시했습니다).
	 */
	private static function get_cache_ttl_seconds() {
		$settings = class_exists( 'Zorlinq32_Settings' ) ? Zorlinq32_Settings::get_group( 'analytics' ) : array();
		$minutes  = isset( $settings['cache_minutes'] ) ? (int) $settings['cache_minutes'] : 5;
		$allowed  = array( 1, 5, 10, 30, 60 );
		if ( ! in_array( $minutes, $allowed, true ) ) {
			$minutes = 5;
		}
		return $minutes * MINUTE_IN_SECONDS;
	}

	/**
	 * 조회 결과를 설정된 시간만큼 트랜지언트에 캐시하는 공통 래퍼입니다.
	 * 캐시 키는 메서드 이름과 모든 인자를 조합해 만들어, 서로 다른 기간/글 조합의
	 * 결과가 섞이지 않도록 합니다.
	 */
	private static function remember( $cache_key_parts, $callback ) {
		$cache_key = 'zorlinq32_aq_' . md5( implode( '|', $cache_key_parts ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
		$result = call_user_func( $callback );
		set_transient( $cache_key, $result, self::get_cache_ttl_seconds() );
		return $result;
	}

	/**
	 * [요청 기능: 통계 초기화 연동] 초기화 버튼을 눌렀을 때, 방금 지운 데이터가 캐시에
	 * 남아 있어 초기화 직후에도 잠시 예전 숫자가 보이는 것을 막기 위해 모든 애널리틱스
	 * 쿼리 캐시를 즉시 무효화합니다. 트랜지언트 키에 매번 다른 접두어(md5)가 붙어
	 * 개별 키를 일일이 알 수 없으므로, 옵션 테이블에서 해당 접두어로 시작하는 항목을
	 * 한 번에 삭제합니다.
	 */
	public static function flush_all_query_cache() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 트랜지언트 일괄 삭제로, delete_transient()는 키를 미리 알아야 하는데 여기서는 접두어 기반 일괄 삭제가 필요해 코어 API로 대체할 수 없습니다.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_zorlinq32\_aq\_%' OR option_name LIKE '\_transient\_timeout\_zorlinq32\_aq\_%'" );
		delete_transient( 'zorlinq32_analytics_quick_summary' );
	}

	/**
	 * 필터 종류(today/daily/weekly/monthly/yearly)에 따른 시작 날짜를 계산합니다.
	 *
	 * @param string $range one of: today, 7days, 30days, 12months, custom.
	 */
	public static function resolve_date_range( $range ) {
		$today = current_time( 'Y-m-d' );
		switch ( $range ) {
			case 'today':
				return array( $today, $today );
			case '7days':
				return array( gmdate( 'Y-m-d', strtotime( '-6 days', strtotime( $today ) ) ), $today );
			case '30days':
				return array( gmdate( 'Y-m-d', strtotime( '-29 days', strtotime( $today ) ) ), $today );
			case '12months':
				return array( gmdate( 'Y-m-d', strtotime( '-11 months', strtotime( $today ) ) ), $today );
			default:
				return array( gmdate( 'Y-m-d', strtotime( '-6 days', strtotime( $today ) ) ), $today );
		}
	}

	/**
	 * 지정된 기간의 일자별 방문 추이를 반환합니다. 그래프 렌더링에 사용됩니다.
	 *
	 * [애널리틱스 정확도 개선] 기존에는 페이지뷰(COUNT(*))만 반환해, 같은 방문자가
	 * 새로고침하거나 여러 글을 연달아 읽을 때마다 늘어나는 "조회수"를 마치 "방문자 수"처럼
	 * 보여주고 있었습니다. 이는 실제 유입 규모보다 수치를 크게 부풀려 보이게 하는 주된
	 * 원인이었습니다. 이제는 visitor_hash 기준 고유 방문자 수(pv 대비 dedupe된 값)를
	 * 함께 반환하여, 대시보드에서 "방문자 수"와 "조회수"를 명확히 구분해 보여줄 수 있습니다.
	 *
	 * @return array [ ['date' => 'YYYY-MM-DD', 'count' => 조회수, 'visitors' => 순방문자수], ... ]
	 */
	public static function get_daily_trend( $start_date, $end_date, $post_id = 0 ) {
		return self::remember(
			array( 'get_daily_trend', $start_date, $end_date, $post_id ),
			function () use ( $start_date, $end_date, $post_id ) {
				return self::query_daily_trend( $start_date, $end_date, $post_id );
			}
		);
	}

	/**
	 * get_daily_trend()의 실제 DB 조회 로직입니다(캐시 래퍼와 분리).
	 */
	private static function query_daily_trend( $start_date, $end_date, $post_id ) {
		try {
			global $wpdb;
			$table_name = Zorlinq32_Analytics_DB::table_name();

			if ( $post_id > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 커스텀 통계 테이블 집계 쿼리로 코어 API가 존재하지 않습니다.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT visited_date AS date, COUNT(*) AS count, COUNT(DISTINCT visitor_hash) AS visitors FROM {$table_name} WHERE visited_date BETWEEN %s AND %s AND post_id = %d GROUP BY visited_date ORDER BY visited_date ASC",
						$start_date,
						$end_date,
						$post_id
					),
					ARRAY_A
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT visited_date AS date, COUNT(*) AS count, COUNT(DISTINCT visitor_hash) AS visitors FROM {$table_name} WHERE visited_date BETWEEN %s AND %s GROUP BY visited_date ORDER BY visited_date ASC",
						$start_date,
						$end_date
					),
					ARRAY_A
				);
			}

			return self::fill_missing_dates( is_array( $rows ) ? $rows : array(), $start_date, $end_date );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '일자별 추이 조회 중 오류: ' . $e->getMessage() );
			return array();
		}
	}

	/**
	 * 쿼리 결과에 없는 날짜(방문 0건)를 0으로 채워 그래프가 끊기지 않도록 합니다.
	 */
	private static function fill_missing_dates( $rows, $start_date, $end_date ) {
		$by_date = array();
		foreach ( $rows as $row ) {
			$by_date[ $row['date'] ] = array(
				'count'    => (int) $row['count'],
				'visitors' => isset( $row['visitors'] ) ? (int) $row['visitors'] : (int) $row['count'],
			);
		}

		$result  = array();
		$current = strtotime( $start_date );
		$end     = strtotime( $end_date );

		// 너무 넓은 범위(예: 몇 년치)로 무한 루프에 가까운 연산이 되는 것을 방지하는 안전장치.
		$max_iterations = 400;
		$i              = 0;

		while ( $current <= $end && $i < $max_iterations ) {
			$date_str = gmdate( 'Y-m-d', $current );
			$found    = isset( $by_date[ $date_str ] ) ? $by_date[ $date_str ] : array(
				'count'    => 0,
				'visitors' => 0,
			);
			$result[] = array(
				'date'     => $date_str,
				'count'    => $found['count'],
				'visitors' => $found['visitors'],
			);
			$current  = strtotime( '+1 day', $current );
			++$i;
		}

		return $result;
	}

	/**
	 * 지정된 기간의 유입 채널별(자연유입 검색엔진별 / 외부유입 / 기타) 집계를 반환합니다.
	 */
	public static function get_channel_breakdown( $start_date, $end_date, $post_id = 0 ) {
		return self::remember(
			array( 'get_channel_breakdown', $start_date, $end_date, $post_id ),
			function () use ( $start_date, $end_date, $post_id ) {
				return self::query_channel_breakdown( $start_date, $end_date, $post_id );
			}
		);
	}

	private static function query_channel_breakdown( $start_date, $end_date, $post_id ) {
		try {
			global $wpdb;
			$table_name = Zorlinq32_Analytics_DB::table_name();

			$post_clause = $post_id > 0 ? $wpdb->prepare( ' AND post_id = %d', $post_id ) : '';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 커스텀 통계 테이블 집계 쿼리로 코어 API가 존재하지 않습니다.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT referrer_type, referrer_source, COUNT(*) AS count FROM {$table_name} WHERE visited_date BETWEEN %s AND %s" . $post_clause . ' GROUP BY referrer_type, referrer_source ORDER BY count DESC',
					$start_date,
					$end_date
				),
				ARRAY_A
			);

			$breakdown = array(
				'organic'  => array(),
				'referral' => array(),
				'direct'   => 0,
				'total'    => 0,
			);

			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$count = (int) $row['count'];
					$breakdown['total'] += $count;

					if ( 'organic' === $row['referrer_type'] ) {
						$engine = $row['referrer_source'] ? $row['referrer_source'] : 'unknown';
						if ( ! isset( $breakdown['organic'][ $engine ] ) ) {
							$breakdown['organic'][ $engine ] = 0;
						}
						$breakdown['organic'][ $engine ] += $count;
					} elseif ( 'referral' === $row['referrer_type'] ) {
						$source = $row['referrer_source'] ? $row['referrer_source'] : __( '기타 외부 사이트', 'zorlinq32' );
						if ( ! isset( $breakdown['referral'][ $source ] ) ) {
							$breakdown['referral'][ $source ] = 0;
						}
						$breakdown['referral'][ $source ] += $count;
					} else {
						$breakdown['direct'] += $count;
					}
				}
			}

			return $breakdown;
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '유입 채널 집계 중 오류: ' . $e->getMessage() );
			return array(
				'organic'  => array(),
				'referral' => array(),
				'direct'   => 0,
				'total'    => 0,
			);
		}
	}

	/**
	 * 지정된 기간에 확인 가능했던 검색 키워드 목록(빈도순)을 반환합니다.
	 * 위 리퍼러 분류기 문서에서 설명한 대로, 실제로는 대부분의 검색이 키워드를
	 * 노출하지 않으므로(특히 구글/빙) 이 목록은 "확인 가능했던 일부"에 불과합니다.
	 */
	public static function get_top_keywords( $start_date, $end_date, $post_id = 0, $limit = 20 ) {
		return self::remember(
			array( 'get_top_keywords', $start_date, $end_date, $post_id, $limit ),
			function () use ( $start_date, $end_date, $post_id, $limit ) {
				return self::query_top_keywords( $start_date, $end_date, $post_id, $limit );
			}
		);
	}

	private static function query_top_keywords( $start_date, $end_date, $post_id, $limit ) {
		try {
			global $wpdb;
			$table_name = Zorlinq32_Analytics_DB::table_name();

			$post_clause = $post_id > 0 ? $wpdb->prepare( ' AND post_id = %d', $post_id ) : '';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 커스텀 통계 테이블 집계 쿼리로 코어 API가 존재하지 않습니다.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT keyword, referrer_source, COUNT(*) AS count FROM {$table_name} WHERE visited_date BETWEEN %s AND %s AND keyword IS NOT NULL AND keyword != ''" . $post_clause . ' GROUP BY keyword, referrer_source ORDER BY count DESC LIMIT %d',
					$start_date,
					$end_date,
					$limit
				),
				ARRAY_A
			);

			return is_array( $rows ) ? $rows : array();
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '유입 키워드 조회 중 오류: ' . $e->getMessage() );
			return array();
		}
	}

	/**
	 * 지정된 기간의 인기글(방문수 상위)을 반환합니다.
	 */
	public static function get_top_posts( $start_date, $end_date, $limit = 10 ) {
		return self::remember(
			array( 'get_top_posts', $start_date, $end_date, $limit ),
			function () use ( $start_date, $end_date, $limit ) {
				return self::query_top_posts( $start_date, $end_date, $limit );
			}
		);
	}

	private static function query_top_posts( $start_date, $end_date, $limit ) {
		try {
			global $wpdb;
			$table_name = Zorlinq32_Analytics_DB::table_name();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 커스텀 통계 테이블 집계 쿼리로 코어 API가 존재하지 않습니다.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, COUNT(*) AS count FROM {$table_name} WHERE visited_date BETWEEN %s AND %s AND post_id IS NOT NULL GROUP BY post_id ORDER BY count DESC LIMIT %d",
					$start_date,
					$end_date,
					$limit
				),
				ARRAY_A
			);

			$result = array();
			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$post = get_post( (int) $row['post_id'] );
					if ( ! $post ) {
						continue; // 삭제된 글은 목록에서 제외합니다.
					}
					$result[] = array(
						'post_id' => (int) $row['post_id'],
						'title'   => get_the_title( $post ),
						'link'    => get_permalink( $post ),
						'count'   => (int) $row['count'],
					);
				}
			}
			return $result;
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '인기글 조회 중 오류: ' . $e->getMessage() );
			return array();
		}
	}

	/**
	 * 지정된 기간의 총 방문수를 반환합니다.
	 *
	 * [애널리틱스 정확도 개선] pageviews(조회수)와 visitors(순방문자, dedupe) 두 값을
	 * 함께 반환합니다. "트래픽이 비정상적으로 높다"는 체감의 상당 부분은 조회수를
	 * 방문자 수처럼 표시했기 때문이었으므로, 대시보드/알림판에서는 기본적으로
	 * visitors 값을 "방문자 수"로 표시합니다.
	 *
	 * @return array { 'pageviews' => int, 'visitors' => int }
	 */
	public static function get_total_count( $start_date, $end_date, $post_id = 0 ) {
		return self::remember(
			array( 'get_total_count', $start_date, $end_date, $post_id ),
			function () use ( $start_date, $end_date, $post_id ) {
				return self::query_total_count( $start_date, $end_date, $post_id );
			}
		);
	}

	private static function query_total_count( $start_date, $end_date, $post_id ) {
		try {
			global $wpdb;
			$table_name = Zorlinq32_Analytics_DB::table_name();

			if ( $post_id > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT COUNT(*) AS pageviews, COUNT(DISTINCT visitor_hash) AS visitors FROM {$table_name} WHERE visited_date BETWEEN %s AND %s AND post_id = %d",
						$start_date,
						$end_date,
						$post_id
					),
					ARRAY_A
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT COUNT(*) AS pageviews, COUNT(DISTINCT visitor_hash) AS visitors FROM {$table_name} WHERE visited_date BETWEEN %s AND %s",
						$start_date,
						$end_date
					),
					ARRAY_A
				);
			}

			return array(
				'pageviews' => $row ? (int) $row['pageviews'] : 0,
				'visitors'  => $row ? (int) $row['visitors'] : 0,
			);
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '총 방문수 조회 중 오류: ' . $e->getMessage() );
			return array(
				'pageviews' => 0,
				'visitors'  => 0,
			);
		}
	}

	/**
	 * 워드프레스 코어 "알림판(대시보드)" 위젯 등 가벼운 요약이 필요한 곳에서 쓰는
	 * 짧은 헬퍼입니다. 오늘 하루의 순방문자/조회수와, 최근 7일 순방문자 합계를 반환합니다.
	 * 결과는 60초간 캐시되어, 같은 요청 내 위젯이 여러 번 렌더링되어도 쿼리가 중복되지 않습니다.
	 */
	public static function get_quick_summary() {
		$cache_key = 'zorlinq32_analytics_quick_summary';
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$today       = current_time( 'Y-m-d' );
		list( $week_start, $week_end ) = self::resolve_date_range( '7days' );

		$today_totals = self::get_total_count( $today, $today );
		$week_totals  = self::get_total_count( $week_start, $week_end );

		$summary = array(
			'today_visitors'  => $today_totals['visitors'],
			'today_pageviews' => $today_totals['pageviews'],
			'week_visitors'   => $week_totals['visitors'],
			'week_pageviews'  => $week_totals['pageviews'],
		);

		// [요청 기능: 애널리틱스 캐시 시간 설정] 이 요약 위젯도 다른 조회들과 동일한
		// 설정값(1/5/10/30/60분)을 따르도록 통일했습니다(과거에는 60초로 고정되어 있었습니다).
		set_transient( $cache_key, $summary, self::get_cache_ttl_seconds() );

		return $summary;
	}
}
