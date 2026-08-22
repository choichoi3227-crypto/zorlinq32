<?php
/**
 * IP 기반 국가 판별 헬퍼.
 *
 * 1순위: Cloudflare를 리버스 프록시로 사용 중인 사이트는 CF-IPCountry 헤더를 그대로 신뢰합니다.
 *        (Cloudflare가 자체 GeoIP DB로 검증한 값이라 정확도가 높고, 외부 API 호출이 없습니다.)
 * 2순위: Cloudflare를 쓰지 않는 사이트를 위해, 주요 국가의 대표적인 IP 대역(RIR 할당 기준
 *        대형 블록)을 최소한으로 내장하여 대략적인 판별을 시도합니다.
 *
 * 중요한 한계: 2순위 방식은 전체 IP 공간을 다루지 못하는 근사치이며, 상업용 GeoIP DB
 * (MaxMind 등) 수준의 정확도를 제공하지 않습니다. 이 한계는 관리자 화면에 명시됩니다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_GeoIP {

	/**
	 * 자주 차단 대상이 되는 국가들의 대표 IPv4 대역(CIDR) 일부.
	 * 전체 대역이 아닌 각 국가에서 비중이 큰 주요 블록 일부만 포함한 근사 데이터이며,
	 * 지속적으로 정확하게 유지되지 않을 수 있습니다(RIR 재할당 등으로 시간에 따라 변합니다).
	 * 정확한 차단이 필요하다면 Cloudflare(무료 플랜 포함)를 리버스 프록시로 사용하는 것을 권장합니다.
	 */
	const APPROXIMATE_RANGES = array(
		'KR' => array( '1.11.0.0/16', '1.16.0.0/12', '14.32.0.0/12', '39.0.0.0/12', '58.72.0.0/13', '61.32.0.0/11', '106.240.0.0/12', '110.11.0.0/16', '112.144.0.0/12', '175.192.0.0/10', '210.94.0.0/16', '211.32.0.0/12', '223.32.0.0/12' ),
		'CN' => array( '1.0.1.0/24', '1.0.2.0/23', '14.0.0.0/8', '27.0.0.0/8', '36.0.0.0/8', '39.128.0.0/10', '42.0.0.0/8', '58.14.0.0/15', '61.128.0.0/10', '101.0.0.0/8', '106.0.0.0/8', '110.0.0.0/8', '111.0.0.0/8', '112.0.0.0/8', '113.0.0.0/8', '114.0.0.0/8', '115.0.0.0/8', '116.0.0.0/8', '117.0.0.0/8', '118.0.0.0/8', '119.0.0.0/8', '120.0.0.0/8', '121.0.0.0/8', '122.0.0.0/8', '123.0.0.0/8', '124.0.0.0/8', '125.0.0.0/8', '175.0.0.0/8', '182.0.0.0/8', '183.0.0.0/8', '202.96.0.0/11', '210.0.0.0/8', '211.0.0.0/8', '218.0.0.0/8', '219.128.0.0/10', '220.160.0.0/11', '221.0.0.0/8', '222.0.0.0/8', '223.64.0.0/11' ),
		'RU' => array( '5.44.0.0/15', '31.13.0.0/16', '37.9.0.0/16', '46.0.0.0/8', '77.88.0.0/16', '78.24.0.0/13', '79.104.0.0/13', '81.176.0.0/12', '82.144.0.0/12', '85.140.0.0/14', '87.240.0.0/16', '91.108.0.0/16', '92.36.0.0/14', '93.158.0.0/15', '95.24.0.0/13', '109.194.0.0/15', '178.154.0.0/16', '188.128.0.0/10', '213.180.0.0/16' ),
		'KP' => array( '175.45.176.0/22' ),
		'US' => array( '3.0.0.0/8', '4.0.0.0/8', '6.0.0.0/8', '8.0.0.0/7', '12.0.0.0/6', '13.0.0.0/8', '17.0.0.0/8', '18.0.0.0/8', '20.0.0.0/8', '23.0.0.0/8', '24.0.0.0/8', '34.0.0.0/8', '35.0.0.0/8', '38.0.0.0/8', '40.0.0.0/8', '44.0.0.0/8', '50.0.0.0/8', '52.0.0.0/8', '54.0.0.0/8', '63.0.0.0/8', '64.0.0.0/6', '68.0.0.0/6', '72.0.0.0/5', '96.0.0.0/6', '98.0.0.0/7', '104.0.0.0/8', '107.0.0.0/8', '108.0.0.0/6', '155.0.0.0/8', '162.0.0.0/8', '166.0.0.0/8', '172.0.0.0/8', '173.0.0.0/8', '174.0.0.0/8', '184.0.0.0/8', '198.0.0.0/8', '199.0.0.0/8', '204.0.0.0/6', '208.0.0.0/6', '216.0.0.0/6' ),
	);

	/**
	 * 방문자의 국가 코드(ISO 3166-1 alpha-2, 대문자)를 판별합니다. 알 수 없으면 빈 문자열을 반환합니다.
	 */
	public static function detect_country_code( $ip ) {
		try {
			// 1순위: Cloudflare가 검증한 헤더 (가장 신뢰도 높음, 외부 호출 없음).
			// [보안 강화] 헤더 존재 여부만으로 신뢰하지 않고, 요청이 실제로 Cloudflare 공식
			// IP 대역을 거쳐 왔는지까지 함께 검증합니다. 그렇지 않으면 Cloudflare를 쓰지 않는
			// 사이트에서 공격자가 이 헤더를 위조해 차단 국가를 임의로 우회할 수 있습니다.
			$cf_verified = class_exists( 'Zorlinq32_Edge_Protection' ) && Zorlinq32_Edge_Protection::is_behind_cloudflare();
			if ( $cf_verified && ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
				$cf_country = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
				// Cloudflare는 알 수 없는 경우 'XX', 봇으로 판단되면 'T1' 등을 반환하기도 하므로
				// 2자리 알파벳 코드만 유효한 것으로 취급합니다.
				if ( preg_match( '/^[A-Z]{2}$/', $cf_country ) ) {
					return $cf_country;
				}
			}

			// [애드센스 보호: AWS 동등 지원] AWS CloudFront도 Origin Request Policy에
			// "CloudFront-Viewer-Country" 헤더를 포함하도록 설정해두면(AWS 콘솔에서 체크박스
			// 하나로 켤 수 있는 AWS 공식 기능, 별도 Lambda 불필요) Cloudflare와 동일한 방식으로
			// 검증된 국가 코드를 오리진에 전달합니다. 이 헤더가 감지되면 Cloudflare가 없는
			// AWS 단독 환경에서도 국가 기반 애드센스 보호가 Cloudflare와 동등하게 작동합니다.
			$cf_front_verified = class_exists( 'Zorlinq32_Edge_Protection' ) && Zorlinq32_Edge_Protection::is_behind_cloudfront();
			if ( $cf_front_verified && ! empty( $_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] ) ) {
				$cloudfront_country = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] ) ) );
				if ( preg_match( '/^[A-Z]{2}$/', $cloudfront_country ) ) {
					return $cloudfront_country;
				}
			}

			// 2순위: 내장된 근사 IP 대역과 대조합니다.
			if ( empty( $ip ) || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return ''; // IPv6나 유효하지 않은 IP는 이 근사 방식으로 판별하지 않습니다.
			}

			foreach ( self::APPROXIMATE_RANGES as $country_code => $ranges ) {
				foreach ( $ranges as $cidr ) {
					if ( self::ip_in_cidr( $ip, $cidr ) ) {
						return $country_code;
					}
				}
			}

			return '';
		} catch ( \Throwable $e ) {
			if ( class_exists( 'Zorlinq32_Logger' ) ) {
				Zorlinq32_Logger::log( '국가 판별 중 오류: ' . $e->getMessage() );
			}
			return '';
		}
	}

	/**
	 * IPv4 주소가 CIDR 대역에 포함되는지 확인합니다.
	 */
	private static function ip_in_cidr( $ip, $cidr ) {
		$parts = explode( '/', $cidr );
		if ( count( $parts ) !== 2 ) {
			return false;
		}
		list( $subnet, $bits ) = $parts;
		$bits = (int) $bits;
		if ( $bits < 0 || $bits > 32 ) {
			return false;
		}

		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );
		if ( false === $ip_long || false === $subnet_long ) {
			return false;
		}

		$mask = ( 0 === $bits ) ? 0 : ( ~0 << ( 32 - $bits ) );
		return ( $ip_long & $mask ) === ( $subnet_long & $mask );
	}

	/**
	 * 관리자 화면에서 국가 선택 UI에 사용할 대표 국가 목록 (코드 => 한글명).
	 * 요구사항인 "국가명을 입력하면 알아서 차단"을 만족하도록, 자유 텍스트 대신
	 * 드롭다운으로 제공해 오타로 인한 오차단/미차단을 방지합니다.
	 */
	public static function get_country_options() {
		return array(
			'KR' => __( '대한민국', 'zorlinq32' ),
			'US' => __( '미국', 'zorlinq32' ),
			'CN' => __( '중국', 'zorlinq32' ),
			'RU' => __( '러시아', 'zorlinq32' ),
			'KP' => __( '북한', 'zorlinq32' ),
			'JP' => __( '일본', 'zorlinq32' ),
			'VN' => __( '베트남', 'zorlinq32' ),
			'IN' => __( '인도', 'zorlinq32' ),
			'ID' => __( '인도네시아', 'zorlinq32' ),
			'BR' => __( '브라질', 'zorlinq32' ),
			'NG' => __( '나이지리아', 'zorlinq32' ),
			'PK' => __( '파키스탄', 'zorlinq32' ),
			'BD' => __( '방글라데시', 'zorlinq32' ),
			'PH' => __( '필리핀', 'zorlinq32' ),
			'DE' => __( '독일', 'zorlinq32' ),
			'GB' => __( '영국', 'zorlinq32' ),
			'FR' => __( '프랑스', 'zorlinq32' ),
		);
	}
}
