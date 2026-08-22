/**
 * Zorlinq32 - 애드센스 부정클릭 방지: 프론트엔드 관찰 스크립트
 *
 * 중요: 이 스크립트는 광고 클릭을 절대 가로채거나 막지 않습니다.
 * 클릭 이벤트를 "관찰"만 하여 통계 목적으로 서버에 보고하며,
 * preventDefault()나 stopPropagation()을 호출하지 않아 정상적인 클릭 동작에는
 * 전혀 영향을 주지 않습니다. 애드센스 광고(iframe)는 브라우저 보안 정책(Same-Origin Policy)상
 * 클릭 자체를 직접 감지할 수 없으므로, 광고 컨테이너 영역에서 발생한 클릭 이벤트를
 * 캡처링 단계에서 관찰하는 방식을 사용합니다.
 */
( function () {
	'use strict';

	if ( 'undefined' === typeof zorlinq32AdProtect ) {
		return;
	}

	/**
	 * 브라우저 환경 특성을 조합해 기기 핑거프린트를 생성합니다.
	 * 개인을 식별하기 위한 목적이 아니라, 같은 IP를 공유하는 여러 기기(예: 사무실, 카페 공유 IP)를
	 * 구분해 오탐(정상 사용자 차단)을 줄이기 위한 보조 신호로만 사용됩니다.
	 */
	function collectFingerprint() {
		var parts = [];
		try {
			parts.push( navigator.userAgent || '' );
			parts.push( navigator.language || '' );
			parts.push( screen.width + 'x' + screen.height );
			parts.push( screen.colorDepth || '' );
			parts.push( new Date().getTimezoneOffset() );
			parts.push( navigator.hardwareConcurrency || '' );
			parts.push( ( navigator.platform || '' ) );

			// 캔버스 렌더링 결과는 GPU/드라이버/폰트 조합에 따라 미세하게 달라져
			// 기기 구분에 흔히 쓰이는 안정적인 보조 신호입니다.
			var canvas = document.createElement( 'canvas' );
			var ctx = canvas.getContext( '2d' );
			if ( ctx ) {
				ctx.textBaseline = 'top';
				ctx.font = '14px Arial';
				ctx.fillText( 'zorlinq32-fp', 2, 2 );
				parts.push( canvas.toDataURL() );
			}
		} catch ( e ) {
			// 핑거프린트 수집이 실패해도 사이트 동작에는 영향이 없어야 합니다.
		}
		return simpleHash( parts.join( '|' ) );
	}

	/**
	 * 암호학적 해시가 아닌, 클라이언트 측 경량 해시입니다.
	 * (서버에서 IP와 함께 다시 한 번 정식으로 해시되므로, 여기서는 단순 식별용으로 충분합니다.)
	 */
	function simpleHash( str ) {
		var hash = 0;
		for ( var i = 0; i < str.length; i++ ) {
			hash = ( ( hash << 5 ) - hash ) + str.charCodeAt( i );
			hash |= 0;
		}
		return 'fp_' + Math.abs( hash ).toString( 36 );
	}

	function reportClick( slotElement ) {
		var fingerprint = collectFingerprint();

		var data = new URLSearchParams();
		data.append( 'action', 'zorlinq32_observe_ad_click' );
		data.append( 'nonce', zorlinq32AdProtect.nonce );
		data.append( 'fingerprint', fingerprint );

		// sendBeacon은 페이지 이탈(광고 클릭 시 새 탭/페이지 이동) 중에도 안정적으로 전송되며,
		// 응답을 기다리지 않아 사용자의 클릭 흐름을 전혀 지연시키지 않습니다.
		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( zorlinq32AdProtect.ajaxUrl, data );
		} else {
			fetch( zorlinq32AdProtect.ajaxUrl, {
				method: 'POST',
				body: data,
				keepalive: true
			} ).catch( function () {} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		// 애드센스가 렌더링하는 <ins class="adsbygoogle"> 요소들을 관찰 대상으로 삼습니다.
		var adSlots = document.querySelectorAll( 'ins.adsbygoogle' );
		if ( ! adSlots.length ) {
			return;
		}

		adSlots.forEach( function ( slot ) {
			// 캡처링 단계에서 관찰만 하고, 이벤트를 막거나 전파를 중단하지 않습니다.
			slot.addEventListener( 'click', function () {
				reportClick( slot );
			}, { capture: true, passive: true } );
		} );
	} );
} )();
