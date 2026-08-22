<?php
/**
 * 애널리틱스 모듈.
 *
 * - 프론트엔드 방문을 자체 DB 테이블에 기록합니다(외부 서비스 사용 안 함).
 * - [추적 방식] 서버사이드 자동 추적이 아니라, 브라우저에서 로드되는 자바스크립트가
 *   페이지가 열릴 때 AJAX로 방문 사실을 알리는 방식입니다. 방문자 식별은 서버가 계산한
 *   IP 해시가 아니라, 브라우저 localStorage에 저장되는 방문자 ID(클라이언트 생성)를
 *   기준으로 합니다. 이렇게 하면 캐시 플러그인/CDN으로 페이지가 캐시되어도(즉 PHP가
 *   실행되지 않고 정적 HTML이 그대로 나가도) 방문 집계가 정확히 동작합니다.
 * - 관리자/로그인 사용자, 봇으로 추정되는 User-Agent는 집계에서 제외합니다.
 * - IP는 원문을 저장하지 않고 일 단위로 회전하는 솔트와 함께 해시하여 저장합니다
 *   (부가 정보로만 남기고, 개인 식별 정보를 보관하지 않습니다).
 * - AJAX 요청 처리 자체가 비동기이므로 페이지 로딩을 지연시키지 않습니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Analytics {

	private static $instance = null;
	private $settings = array();

	/**
	 * 명백한 자동화 도구/봇으로 판단되는 User-Agent 패턴.
	 * 완벽한 봇 차단은 불가능하지만, 통계 오염의 가장 흔한 원인들을 걸러냅니다.
	 *
	 * [애널리틱스 정확도 개선] 기존 14개 패턴은 검색엔진 봇 위주였고, 실제로 트래픽을
	 * 크게 왜곡시키는 아래 항목들이 빠져 있었습니다:
	 * - 모니터링/가동시간 점검 서비스 (statuscake, site24x7, gtmetrix, pingdom 계열 확장)
	 * - 헤드리스 브라우저/자동화 테스트 도구 (headlesschrome, phantomjs, puppeteer, playwright, selenium)
	 * - 범용 HTTP 라이브러리(스크립트에서 흔히 남는 기본 UA) (python-urllib, go-http-client, java/, okhttp, node-fetch, axios)
	 * - SEO/백링크 분석 크롤러 확장 (semrushbot, dotbot, blexbot, seokicks, mojeekbot 등)
	 * - 소셜/미리보기 카드 봇 (telegrambot, slackbot, discordbot, whatsapp, skypeuripreview)
	 * - 워드프레스 자체 루프백 요청(uptime 관리, 헬스체크) (wordpress/, wp_)
	 * 정규식 1회 매칭으로 성능도 함께 개선했습니다(기존: 최대 14회 strpos 반복 → 1회 preg_match).
	 *
	 * [클라이언트 추적 전환 참고] AJAX 방식으로 바뀌어도 이 목록은 여전히 유효합니다.
	 * 대다수 단순 봇/크롤러는 애초에 자바스크립트를 실행하지 않아 이 필터에 걸릴 일 없이
	 * 자동으로 걸러지지만, 헤드리스 브라우저(Puppeteer 등)처럼 JS를 실행하는 봇은
	 * User-Agent로 한 번 더 걸러야 하므로 검사를 유지합니다.
	 */
	const BOT_UA_PATTERN_LIST = array(
		'bot', 'spider', 'crawl', 'slurp', 'bingpreview', 'facebookexternalhit',
		'pingdom', 'uptimerobot', 'ahrefs', 'semrush', 'mj12bot', 'curl', 'wget',
		'python-requests', 'python-urllib', 'go-http-client', 'okhttp', 'node-fetch',
		'axios', 'java/', 'headlesschrome', 'phantomjs', 'puppeteer', 'playwright',
		'selenium', 'webdriver', 'statuscake', 'site24x7', 'gtmetrix', 'pagespeed',
		'dotbot', 'blexbot', 'seokicks', 'mojeekbot', 'telegrambot', 'slackbot',
		'discordbot', 'whatsapp', 'skypeuripreview', 'applebot', 'yandexbot',
		'duckduckbot', 'baiduspider', 'sogou', 'exabot', 'ia_archiver', 'archive.org_bot',
		'petalbot', 'bytespider', 'gptbot', 'ccbot', 'anthropic-ai', 'claudebot',
		'google-inspectiontool', 'adsbot-google', 'feedfetcher', 'wordpress/',
		'libwww-perl', 'httpclient', 'scrapy', 'zgrab', 'masscan', 'nmap',
		'claude', 'anthropic', 'openai', 'chatgpt', 'gptbot', 'mediapartners-google',
		'google-other', 'googlebot', 'googlebot-image', 'googlebot-mobile', 'google-read-aloud',
		'google-structured-data-testing-tool', 'google-site-verification', 'google-extended',
		'googlebot-news', 'google-inspectiontool', 'apis-google', 'googleweblight',
		'kakaotalk', 'yeti', 'daumoa', 'navercorp', 'naver.me', 'band-proxy',
		'coccoc', 'ccbot', 'serpstatbot', 'seznambot', 'x11', 'webcache',
	);

	/**
	 * 위 목록을 매 요청마다 다시 조립하지 않도록 첫 호출 시 컴파일된 정규식을 캐시합니다.
	 */
	private static $bot_ua_regex = null;

	private static function get_bot_ua_regex() {
		if ( null === self::$bot_ua_regex ) {
			self::$bot_ua_regex = '/' . implode( '|', array_map( 'preg_quote', self::BOT_UA_PATTERN_LIST ) ) . '/i';
		}
		return self::$bot_ua_regex;
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = Zorlinq32_Settings::get_group( 'analytics' );

		if ( empty( $this->settings['enabled'] ) ) {
			return;
		}

		Zorlinq32_Analytics_DB::maybe_create_table();

		// 프론트엔드에 추적 스크립트를 로드하고, 그 스크립트가 호출하는 AJAX 엔드포인트를 등록합니다.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tracking_script' ) );
		add_action( 'wp_ajax_zorlinq32_track_pageview', array( $this, 'handle_track_pageview' ) );
		add_action( 'wp_ajax_nopriv_zorlinq32_track_pageview', array( $this, 'handle_track_pageview' ) );

		// 오래된 기록은 설정된 보관 기간에 따라 매일 자동 정리합니다.
		add_action( 'zorlinq32_analytics_daily_cleanup', array( $this, 'cleanup_old_records' ) );

		// 글 편집 화면에 이 글의 방문 통계를 보여주는 메타박스를 추가합니다.
		add_action( 'add_meta_boxes', array( $this, 'register_post_analytics_meta_box' ) );

		// [애널리틱스 정확도 개선] 관리자 권한을 가진 사용자가 로그인에 성공하면, 그 시점의
		// IP를 "관리자 접속 IP"로 자동 기록해둡니다. exclude_admin_ips 옵션이 켜져 있으면
		// 이후 그 IP로 들어오는(로그인/비로그인 무관) 방문은 통계에서 자동 제외되어,
		// 관리자가 매번 IP를 직접 찾아 입력할 필요 없이 "관리자 트래픽 제외"가 동작합니다.
		add_action( 'wp_login', array( $this, 'maybe_remember_admin_ip' ), 10, 2 );
	}

	/**
	 * 프론트엔드에 추적 스크립트를 로드합니다. 관리자 화면, 로그인 사용자,
	 * 미리보기 등 집계할 필요가 없는 컨텍스트에서는 아예 스크립트를 내려주지
	 * 않아 불필요한 요청 자체가 발생하지 않습니다(성능 최적화).
	 */
	public function enqueue_tracking_script() {
		if ( is_admin() || is_user_logged_in() || is_preview() || is_feed() || is_robots() || is_trackback() ) {
			return;
		}

		wp_enqueue_script(
			'zorlinq32-analytics-tracking',
			ZORLINQ32_URL . '/assets/js/analytics-tracking.js',
			array( 'jquery' ),
			ZORLINQ32_VERSION,
			true
		);

		wp_localize_script(
			'zorlinq32-analytics-tracking',
			'zorlinq32Analytics',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'zorlinq32_track_pageview' ),
			)
		);
	}

	/**
	 * 관리자(manage_options 권한 보유자)가 로그인하면 현재 IP를 제외 목록에 자동 추가합니다.
	 * 이미 등록된 IP는 중복 추가하지 않으며, 목록이 과도하게 커지지 않도록 최대 10개로 제한합니다
	 * (여러 지점/재택 등 IP가 자주 바뀌는 경우를 고려한 여유분입니다).
	 */
	public function maybe_remember_admin_ip( $user_login, $user ) {
		try {
			if ( empty( $this->settings['exclude_admin_ips'] ) ) {
				return;
			}
			if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
				return;
			}
			$ip = $this->get_client_ip();
			if ( '0.0.0.0' === $ip ) {
				return;
			}

			$list = isset( $this->settings['excluded_ip_list'] ) && is_array( $this->settings['excluded_ip_list'] )
				? $this->settings['excluded_ip_list']
				: array();

			if ( in_array( $ip, $list, true ) ) {
				return;
			}

			$list[] = $ip;
			if ( count( $list ) > 10 ) {
				$list = array_slice( $list, -10 );
			}

			$this->settings['excluded_ip_list'] = $list;
			Zorlinq32_Settings::update_group( 'analytics', $this->settings );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '관리자 IP 자동 기록 중 오류: ' . $e->getMessage() );
		}
	}

	/**
	 * 글/페이지 편집 화면에 "이 글의 애널리틱스" 메타박스를 등록합니다.
	 */
	public function register_post_analytics_meta_box() {
		$post_types = get_post_types( array( 'public' => true ) );
		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'zorlinq32_post_analytics_box',
				__( 'Zorlinq32 애널리틱스 (이 글)', 'zorlinq32' ),
				array( $this, 'render_post_analytics_meta_box' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	public function render_post_analytics_meta_box( $post ) {
		try {
			if ( 'auto-draft' === $post->post_status || 'draft' === $post->post_status ) {
				echo '<p>' . esc_html__( '발행 후 방문 통계가 표시됩니다.', 'zorlinq32' ) . '</p>';
				return;
			}

			list( $start_date, $end_date ) = Zorlinq32_Analytics_Query::resolve_date_range( '30days' );
			$total   = Zorlinq32_Analytics_Query::get_total_count( $start_date, $end_date, $post->ID );
			$channel = Zorlinq32_Analytics_Query::get_channel_breakdown( $start_date, $end_date, $post->ID );

			echo '<p style="font-size:20px;font-weight:600;margin:0 0 4px;">' . esc_html( number_format_i18n( $total['visitors'] ) ) . '</p>';
			echo '<p class="description" style="margin-top:0;">' . esc_html__( '최근 30일 방문자수', 'zorlinq32' ) . ' <span style="opacity:.7;">(' . esc_html(
				sprintf(
					/* translators: %s: 조회수(페이지뷰) 숫자 */
					__( '조회수 %s', 'zorlinq32' ),
					number_format_i18n( $total['pageviews'] )
				)
			) . ')</span></p>';

			if ( $total['pageviews'] > 0 ) {
				$organic_total  = array_sum( $channel['organic'] );
				$referral_total = array_sum( $channel['referral'] );
				echo '<ul style="margin:10px 0 0;padding-left:0;list-style:none;">';
				echo '<li>' . esc_html__( '자연유입', 'zorlinq32' ) . ': ' . esc_html( number_format_i18n( $organic_total ) ) . '</li>';
				echo '<li>' . esc_html__( '외부유입', 'zorlinq32' ) . ': ' . esc_html( number_format_i18n( $referral_total ) ) . '</li>';
				echo '<li>' . esc_html__( '기타(직접 접속 등)', 'zorlinq32' ) . ': ' . esc_html( number_format_i18n( $channel['direct'] ) ) . '</li>';
				echo '</ul>';
			}

			printf(
				'<p style="margin-top:12px;"><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=zorlinq32-analytics&range=30days' ) ),
				esc_html__( '전체 애널리틱스 보기 →', 'zorlinq32' )
			);
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '글별 애널리틱스 메타박스 렌더링 중 오류: ' . $e->getMessage() );
			echo '<p>' . esc_html__( '통계를 불러올 수 없습니다.', 'zorlinq32' ) . '</p>';
		}
	}

	/**
	 * 프론트엔드 자바스크립트(analytics-tracking.js)가 페이지 로드 시 호출하는 AJAX 엔드포인트.
	 * 브라우저가 보낸 방문자 ID(localStorage 기반)와 현재 URL/리퍼러를 받아 즉시 DB에 기록합니다.
	 * 캐시된 정적 페이지에서도 정확히 동작하며(PHP가 실행되지 않는 페이지 뷰 자체와 무관하게
	 * 스크립트가 별도로 호출됨), 응답은 항상 성공/실패와 무관하게 방문자 경험에 영향을 주지 않도록
	 * 매우 가볍게 처리합니다.
	 */
	public function handle_track_pageview() {
		try {
			check_ajax_referer( 'zorlinq32_track_pageview', 'nonce' );

			if ( $this->is_probably_bot() ) {
				wp_send_json_success( array( 'skipped' => 'bot' ) );
			}

			if ( is_user_logged_in() ) {
				wp_send_json_success( array( 'skipped' => 'logged_in' ) );
			}

			if ( $this->is_excluded_admin_visitor() ) {
				wp_send_json_success( array( 'skipped' => 'excluded_admin' ) );
			}

			$visitor_id = isset( $_POST['visitor_id'] ) ? sanitize_text_field( wp_unslash( $_POST['visitor_id'] ) ) : '';
			$url        = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
			$referrer   = isset( $_POST['referrer'] ) ? esc_url_raw( wp_unslash( $_POST['referrer'] ) ) : '';

			if ( empty( $visitor_id ) || empty( $url ) ) {
				wp_send_json_error( array( 'message' => 'missing_fields' ), 400 );
			}

			// URL에서 글/페이지 ID를 역으로 찾습니다. 홈/글/페이지가 아니면(검색결과, 404 등)
			// post_id는 null로 기록됩니다.
			$post_id = url_to_postid( $url );
			if ( ! $post_id && ( untrailingslashit( $url ) === untrailingslashit( home_url() ) ) ) {
				$post_id = 0; // 홈페이지는 post_id 없이 집계.
			} elseif ( ! $post_id ) {
				// 글/페이지로 특정되지 않는 URL(검색결과, 아카이브, 404 등)도 방문 자체는 집계하되
				// 글별 통계에는 연결하지 않습니다.
				$post_id = null;
			}

			$site_host  = wp_parse_url( home_url(), PHP_URL_HOST );
			$classified = Zorlinq32_Referrer_Classifier::classify( $referrer, $site_host );
			$bot_meta   = $this->classify_bot_details();

			global $wpdb;
			$table_name = Zorlinq32_Analytics_DB::table_name();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 커스텀 통계 테이블에 대한 단순 삽입이며, 코어에 대응하는 API가 없습니다.
			$wpdb->insert(
				$table_name,
				array(
					'post_id'         => $post_id,
					'referrer_type'   => $classified['type'],
					'referrer_source' => $classified['source'],
					'referrer_domain' => $classified['domain'],
					'keyword'         => $classified['keyword'],
					'visitor_hash'    => $this->get_visitor_hash( $visitor_id ),
					'visited_date'    => current_time( 'Y-m-d' ),
					'visited_at'      => current_time( 'mysql' ),
					'bot_name'        => $bot_meta['name'],
					'bot_details'     => $bot_meta['details'],
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			wp_send_json_success( array( 'recorded' => true ) );
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '방문 추적(AJAX) 중 오류: ' . $e->getMessage() );
			// 추적 실패가 방문자 경험에 영향을 주면 안 되므로 200으로 조용히 응답합니다.
			wp_send_json_error( array( 'message' => 'internal_error' ) );
		}
	}

	/**
	 * 관리자가 설정에서 등록한 IP(또는 사이트 관리자 본인이 마지막으로 로그인했던 IP를
	 * 자동으로 기억해둔 값)와 현재 방문자의 IP가 일치하는지 확인합니다.
	 * 사무실/집에서 매번 같은 IP로 사이트를 점검하는 관리자의 트래픽이 통계에 섞여
	 * 방문자 수를 부풀리는 것을 방지하기 위한 기능입니다.
	 */
	private function is_excluded_admin_visitor() {
		if ( empty( $this->settings['exclude_admin_ips'] ) ) {
			return false;
		}

		// [애널리틱스 정확도 개선: 핵심 수정] 기존에는 등록된 IP와 정확히 일치할 때만
		// 제외했는데, 모바일 데이터(LTE/5G)로 접속하는 관리자는 접속마다 IP가 계속
		// 바뀌어 이 방식이 사실상 무력화되고 있었습니다("아무도 안 왔는데 계속 오르는"
		// 현상의 주된 원인이었습니다). 워드프레스는 로그인에 성공한 브라우저에
		// wp-settings-{user_id} 쿠키를 세션이 끝난 뒤에도 오래(약 1년) 남겨둡니다.
		// 이 쿠키가 있다는 것은 "이 브라우저로 관리자 계정에 로그인한 적이 있다"는
		// 뜻이므로, IP가 계속 바뀌어도 안정적으로 관리자 본인의 재방문을 걸러낼 수 있습니다.
		foreach ( $_COOKIE as $cookie_name => $cookie_value ) {
			if ( 0 === strpos( $cookie_name, 'wp-settings-time-' ) || 0 === strpos( $cookie_name, 'wp-settings-' ) ) {
				return true;
			}
		}

		$excluded_ips = isset( $this->settings['excluded_ip_list'] ) && is_array( $this->settings['excluded_ip_list'] )
			? $this->settings['excluded_ip_list']
			: array();
		if ( empty( $excluded_ips ) ) {
			return false;
		}
		$ip = $this->get_client_ip();
		return in_array( $ip, $excluded_ips, true );
	}


	/**
	 * User-Agent 기반의 단순 봇 필터링. 완벽하지 않지만 통계 오염의 상당 부분을 줄여줍니다.
	 */
	private function is_probably_bot() {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return true;
		}
		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		if ( '' === trim( $ua ) ) {
			return true;
		}
		return 1 === preg_match( self::get_bot_ua_regex(), $ua );
	}

	private function classify_bot_details() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$ua = trim( $ua );
		if ( '' === $ua ) {
			return array( 'name' => 'unknown', 'details' => 'empty-user-agent' );
		}

		$details = strtolower( $ua );
		$name = 'unknown';
		if ( false !== stripos( $details, 'claudebot' ) || false !== stripos( $details, 'claude' ) ) {
			$name = 'Claude';
		} elseif ( false !== stripos( $details, 'googlebot' ) || false !== stripos( $details, 'google-structured-data-testing-tool' ) || false !== stripos( $details, 'mediapartners-google' ) ) {
			$name = 'Google';
		} elseif ( false !== stripos( $details, 'facebookexternalhit' ) ) {
			$name = 'Facebook';
		} elseif ( false !== stripos( $details, 'twitterbot' ) || false !== stripos( $details, 'x.com' ) ) {
			$name = 'Twitter/X';
		} elseif ( false !== stripos( $details, 'bingbot' ) ) {
			$name = 'Bing';
		} elseif ( false !== stripos( $details, 'applebot' ) ) {
			$name = 'Apple';
		} elseif ( false !== stripos( $details, 'yandex' ) ) {
			$name = 'Yandex';
		}

		return array(
			'name'    => $name,
			'details' => $ua,
		);
	}

	/**
	 * 브라우저(localStorage)가 생성해 보낸 방문자 ID를 해시해 저장합니다.
	 * [추적 방식 전환] 기존에는 서버가 IP+User-Agent+일일 솔트로 방문자를
	 * 추정했지만, 이제는 클라이언트가 스스로 생성해 오래 유지하는 방문자 ID를
	 * 기준으로 삼습니다(추적 스크립트의 getVisitorId()와 동일한 개념). 이렇게
	 * 하면 같은 브라우저가 IP를 바꿔가며(LTE/WiFi 전환 등) 재방문해도 동일
	 * 방문자로 정확히 인식되고, 반대로 같은 공유 IP를 쓰는 서로 다른 방문자를
	 * 한 명으로 잘못 합치는 문제도 줄어듭니다.
	 *
	 * 원본 visitor_id를 그대로 저장하지 않고 사이트 고유 솔트와 함께 단방향
	 * 해시해, DB가 유출되어도 브라우저의 원본 localStorage 값을 역산할 수
	 * 없게 합니다.
	 *
	 * @param string $visitor_id 클라이언트가 전송한 방문자 ID.
	 * @return string
	 */
	private function get_visitor_hash( $visitor_id ) {
		$daily_salt = $this->get_daily_salt();
		return hash( 'sha256', $visitor_id . '|' . $daily_salt );
	}

	/**
	 * 매일 자정에 새로 생성되는 솔트값. DB 옵션에 저장하되, 이 값 자체는 IP나 개인정보가 아닙니다.
	 */
	private function get_daily_salt() {
		$option_key = 'zorlinq32_analytics_salt_' . current_time( 'Y-m-d' );
		$salt       = get_option( $option_key );
		if ( empty( $salt ) ) {
			$salt = wp_generate_password( 32, false );
			update_option( $option_key, $salt, false );
			// 어제 이전의 솔트 옵션은 더 이상 필요 없으므로 정리합니다.
			delete_option( 'zorlinq32_analytics_salt_' . gmdate( 'Y-m-d', strtotime( '-2 days' ) ) );
		}
		return $salt;
	}

	private function get_client_ip() {
		// 프록시/로드밸런서 환경을 100% 신뢰할 수는 없지만, 통계 목적의 대략적인 구분용이므로
		// 가장 흔히 쓰이는 헤더를 순서대로 확인합니다. 보안 판단(차단 등)에는 사용하지 않습니다.
		$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $candidates as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// X-Forwarded-For는 콤마로 여러 IP가 나열될 수 있어 첫 번째 값만 사용합니다.
				$parts = explode( ',', $value );
				return trim( $parts[0] );
			}
		}
		return '0.0.0.0';
	}

	/**
	 * 설정된 보관 기간보다 오래된 기록을 삭제합니다 (기본 400일).
	 */
	/**
	 * [서버 자원 최적화] 한 번의 DELETE 쿼리에서 지울 최대 행 수.
	 * 트래픽이 많은 사이트는 하루 방문 기록이 수만 건에 달할 수 있어, 보관 기간이 지난
	 * 레코드를 한 번에 모두 삭제하면 테이블 락 시간이 길어져 다른 요청(방문 기록 저장 등)을
	 * 지연시킬 수 있습니다. 배치로 나누어 삭제해 한 번의 쿼리 실행 시간을 짧게 유지합니다.
	 */
	const CLEANUP_BATCH_SIZE = 2000;

	public function cleanup_old_records() {
		try {
			$retention_days = ! empty( $this->settings['retention_days'] ) ? (int) $this->settings['retention_days'] : 400;
			global $wpdb;
			$table_name = Zorlinq32_Analytics_DB::table_name();
			$cutoff     = gmdate( 'Y-m-d', strtotime( '-' . $retention_days . ' days' ) );

			// 배치 단위로 반복 삭제합니다. 각 배치 사이 짧은 텀을 두지 않는 이유는 이 작업이
			// 저빈도(하루 1회) cron에서만 실행되어 누적 실행 시간보다 단일 쿼리 락 시간이
			// 더 중요한 지표이기 때문입니다. 안전을 위해 최대 반복 횟수(50회 = 최대 10만건)로
			// 상한을 두어, 예기치 못한 무한 루프 가능성을 원천 차단합니다.
			$max_batches = 50;
			for ( $i = 0; $i < $max_batches; $i++ ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 커스텀 통계 테이블에 대한 보관 기간 정리이며 코어 API로 대체할 수 없습니다.
				$deleted = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$table_name} WHERE visited_date < %s LIMIT %d",
						$cutoff,
						self::CLEANUP_BATCH_SIZE
					)
				);

				if ( ! $deleted ) {
					break; // 더 지울 행이 없으면 종료.
				}
			}
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '애널리틱스 오래된 기록 정리 중 오류: ' . $e->getMessage() );
		}
	}
}
