<?php
/**
 * 리퍼러(유입 경로) 분류 엔진.
 *
 * 방문자의 Referer 헤더를 분석해 세 가지로 분류합니다:
 * 1. organic (자연유입) - 알려진 검색엔진에서 유입 (네이버/구글/빙 등)
 * 2. referral (외부유입) - 검색엔진은 아니지만 알려진 외부 도메인에서 유입 (SNS, 커뮤니티 등)
 * 3. direct (기타/직접 유입) - Referer가 없거나, 자사 도메인이거나, 알 수 없는 출처
 *
 * 검색 키워드 추출에 대한 중요한 한계:
 * 구글은 2013년부터 모든 검색에 대해 쿼리를 암호화하여 Referer에 키워드를 포함하지 않습니다
 * ("(not provided)" 현상). 빙 역시 대부분 HTTPS 환경에서 키워드를 노출하지 않습니다.
 * 네이버는 검색 결과 클릭 시 일부 환경에서 쿼리 파라미터가 Referer에 남아있어 제한적으로
 * 키워드 확인이 가능하지만, 이 또한 브라우저/네이버 정책에 따라 달라질 수 있습니다.
 * 이 모듈은 확인 가능한 경우에만 키워드를 채우고, 확인 불가능한 경우 정직하게 빈 값으로 남깁니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Referrer_Classifier {

	/**
	 * 자연유입(검색엔진)으로 인식할 도메인과, 관리자 화면에서 표시할 색상/키워드 파라미터.
	 * 색상은 요구사항대로 네이버(연두색) / 구글(빨간색) / 빙(파란색)을 사용합니다.
	 */
	const SEARCH_ENGINES = array(
		'naver' => array(
			'domains'        => array( 'search.naver.com', 'm.search.naver.com', 'naver.com' ),
			'label'          => '네이버',
			'color'          => '#03c75a', // 네이버 브랜드 연두색.
			'keyword_params' => array( 'query' ),
		),
		'google' => array(
			'domains'        => array( 'google.com', 'google.co.kr', 'google.co.jp' ),
			'label'          => '구글',
			'color'          => '#ea4335', // 구글 브랜드 레드.
			'keyword_params' => array( 'q' ),
		),
		'bing' => array(
			'domains'        => array( 'bing.com' ),
			'label'          => '빙',
			'color'          => '#0078d4', // 빙 브랜드 블루.
			'keyword_params' => array( 'q' ),
		),
	);

	/**
	 * 자연유입은 아니지만 "알려진 외부 도메인"으로 인식할 대표적인 서비스 목록.
	 * 여기 없는 도메인이라고 해서 무시되는 것은 아니며, referral(외부유입)로 분류되되
	 * 목록에 있는 경우 더 친절한 서비스명을 표시하는 용도로 사용합니다.
	 */
	const KNOWN_REFERRAL_DOMAINS = array(
		'facebook.com'    => 'Facebook',
		'instagram.com'   => 'Instagram',
		'twitter.com'     => 'Twitter/X',
		'x.com'           => 'Twitter/X',
		't.co'            => 'Twitter/X',
		'youtube.com'     => 'YouTube',
		'kakao.com'       => '카카오',
		'story.kakao.com' => '카카오스토리',
		'band.us'         => '밴드',
		'tistory.com'     => '티스토리',
		'blog.naver.com'  => '네이버 블로그',
		'cafe.naver.com'  => '네이버 카페',
		'pinterest.com'   => 'Pinterest',
		'reddit.com'      => 'Reddit',
		'linkedin.com'    => 'LinkedIn',
		'daum.net'        => '다음',
		'threads.net'     => 'Threads',
	);

	/**
	 * Referer 헤더 값을 분석해 분류 결과를 반환합니다.
	 *
	 * @param string $referrer_url 방문자의 Referer URL (없으면 빈 문자열).
	 * @param string $site_host    자사 사이트의 호스트명 (자기 자신으로부터의 이동은 direct로 처리).
	 * @return array{type:string, source:string|null, domain:string|null, keyword:string|null}
	 */
	public static function classify( $referrer_url, $site_host ) {
		try {
			if ( empty( $referrer_url ) ) {
				return self::direct_result();
			}

			$referrer_host = wp_parse_url( $referrer_url, PHP_URL_HOST );
			if ( empty( $referrer_host ) ) {
				return self::direct_result();
			}

			$referrer_host         = strtolower( preg_replace( '/^www\./', '', $referrer_host ) );
			$site_host_normalized  = strtolower( preg_replace( '/^www\./', '', (string) $site_host ) );

			// 자기 사이트 내 이동(글 목록 -> 글 상세 등)은 유입으로 집계하지 않습니다.
			if ( $referrer_host === $site_host_normalized ) {
				return self::direct_result();
			}

			// 1. 검색엔진(자연유입) 판별
			foreach ( self::SEARCH_ENGINES as $engine_key => $engine ) {
				foreach ( $engine['domains'] as $domain ) {
					if ( $referrer_host === $domain || self::is_subdomain_of( $referrer_host, $domain ) ) {
						$keyword = self::extract_keyword( $referrer_url, $engine['keyword_params'] );
						return array(
							'type'    => 'organic',
							'source'  => $engine_key,
							'domain'  => $referrer_host,
							'keyword' => $keyword,
						);
					}
				}
			}

			$path = wp_parse_url( $referrer_url, PHP_URL_PATH );
			if ( ! empty( $path ) ) {
				$path = trim( $path, '/' );
				if ( '' !== $path ) {
					$path_label = $path;
					if ( preg_match( '/^(?:[a-z0-9._-]+\/)?(.*)$/i', $path, $m ) ) {
						$path_label = $m[1];
					}
					return array(
						'type'    => 'referral',
						'source'  => $referrer_host . '|' . $path_label,
						'domain'  => $referrer_host,
						'keyword' => null,
					);
				}
			}

			// 2. 알려진 외부 도메인(외부유입) 판별
			foreach ( self::KNOWN_REFERRAL_DOMAINS as $domain => $label ) {
				if ( $referrer_host === $domain || self::is_subdomain_of( $referrer_host, $domain ) ) {
					return array(
						'type'    => 'referral',
						'source'  => $domain,
						'domain'  => $referrer_host,
						'keyword' => null,
					);
				}
			}

			// 3. 목록에 없는 그 외 외부 도메인도 "외부유입"으로 분류합니다
			// (요구사항: 자연유입 아니지만 "알려진 도메인"에서의 유입 = 외부유입).
			// 다만 알려진 서비스명이 없으므로 도메인 자체를 표시용 소스로 사용합니다.
			return array(
				'type'    => 'referral',
				'source'  => $referrer_host,
				'domain'  => $referrer_host,
				'keyword' => null,
			);
		} catch ( \Throwable $e ) {
			if ( class_exists( 'Zorlinq32_Logger' ) ) {
				Zorlinq32_Logger::log( '리퍼러 분류 중 오류: ' . $e->getMessage() );
			}
			return self::direct_result();
		}
	}

	private static function direct_result() {
		return array(
			'type'    => 'direct',
			'source'  => null,
			'domain'  => null,
			'keyword' => null,
		);
	}

	/**
	 * $host가 $parent_domain의 서브도메인인지 확인합니다 (예: m.search.naver.com vs naver.com).
	 */
	private static function is_subdomain_of( $host, $parent_domain ) {
		return ( strlen( $host ) > strlen( $parent_domain ) && substr( $host, -1 * ( strlen( $parent_domain ) + 1 ) ) === '.' . $parent_domain );
	}

	/**
	 * 검색엔진 URL의 쿼리 파라미터에서 검색어를 추출합니다.
	 * 위 클래스 docblock에서 설명한 대로, 구글/빙은 HTTPS 암호화로 인해 대부분 실패하며
	 * 이는 이 함수의 결함이 아니라 검색엔진들의 정책에 의한 구조적 한계입니다.
	 */
	private static function extract_keyword( $referrer_url, $keyword_params ) {
		$query = wp_parse_url( $referrer_url, PHP_URL_QUERY );
		if ( empty( $query ) ) {
			return null;
		}

		parse_str( $query, $params );
		foreach ( $keyword_params as $param ) {
			if ( ! empty( $params[ $param ] ) ) {
				$keyword = sanitize_text_field( wp_unslash( $params[ $param ] ) );
				// 비정상적으로 긴 값(스팸/오염된 리퍼러)은 저장하지 않습니다.
				if ( mb_strlen( $keyword ) > 0 && mb_strlen( $keyword ) <= 255 ) {
					return $keyword;
				}
			}
		}
		return null;
	}

	/**
	 * 검색엔진 키(google/naver/bing)에 대한 표시 정보(라벨, 색상)를 반환합니다.
	 */
	public static function get_engine_display( $engine_key ) {
		if ( isset( self::SEARCH_ENGINES[ $engine_key ] ) ) {
			return array(
				'label' => self::SEARCH_ENGINES[ $engine_key ]['label'],
				'color' => self::SEARCH_ENGINES[ $engine_key ]['color'],
			);
		}
		return array(
			'label' => $engine_key,
			'color' => '#8c8f94',
		);
	}
}
