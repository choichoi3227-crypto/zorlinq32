/**
 * Zorlinq32 Analytics — 프론트엔드 방문 추적 스크립트
 *
 * 서버사이드 자동 추적 대신, 페이지가 브라우저에 로드될 때 이 스크립트가
 * AJAX로 방문 사실을 알리는 방식입니다. 방문자 식별은 브라우저 localStorage에
 * 저장되는 고유 ID를 기준으로 하며, 페이지가 캐시(전체 페이지 캐시/CDN)되어
 * 있어도 이 스크립트는 매 방문마다 새로 실행되므로 집계가 정확합니다.
 */
(function ($) {
	'use strict';

	function getVisitorId() {
		var visitorId = localStorage.getItem('zorlinq32_visitor_id');
		if (!visitorId) {
			if (window.crypto && window.crypto.getRandomValues) {
				var array = new Uint32Array(4);
				window.crypto.getRandomValues(array);
				visitorId = Array.from(array, function (x) {
					return x.toString(36).substr(2, 8);
				}).join('');
			} else {
				visitorId = Math.random().toString(36).substring(2, 15) +
					Math.random().toString(36).substring(2, 15);
			}
			localStorage.setItem('zorlinq32_visitor_id', visitorId);
		}
		return visitorId;
	}

	function getReferrer() {
		if (!document.referrer) {
			return '';
		}
		var currentHost = window.location.hostname;
		try {
			var referrerUrl;
			try {
				referrerUrl = new URL(document.referrer);
			} catch (e) {
				return '';
			}
			// 같은 사이트 내 이동(예: 다른 글 → 이 글)은 외부 유입 리퍼러가 아니므로 제외합니다.
			if (referrerUrl.hostname === currentHost) {
				return '';
			}
			return document.referrer;
		} catch (e) {
			return '';
		}
	}

	function trackPageview() {
		if (window.location.href.indexOf('/wp-admin/') !== -1) {
			return;
		}
		if (typeof zorlinq32Analytics === 'undefined') {
			return;
		}

		var data = {
			action: 'zorlinq32_track_pageview',
			visitor_id: getVisitorId(),
			url: window.location.href,
			title: document.title,
			referrer: getReferrer(),
			nonce: zorlinq32Analytics.nonce
		};

		$.ajax({
			url: zorlinq32Analytics.ajaxurl,
			type: 'POST',
			data: data,
			// 방문 추적은 방문자 경험에 어떤 영향도 주면 안 되므로 성공/실패
			// 콜백에서 아무것도 하지 않습니다(콘솔 로그조차 남기지 않음).
			success: function () {},
			error: function () {}
		});
	}

	$(document).ready(function () {
		try {
			trackPageview();
		} catch (e) {
			// 추적 스크립트 오류가 페이지의 다른 기능에 영향을 주지 않도록 조용히 무시합니다.
		}
	});
})(jQuery);
