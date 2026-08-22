/**
 * Zorlinq32 - 팝업 노출 로직
 * 노출 주기(once_per_session/once_per_day/once_per_week)는 방문자의 브라우저
 * localStorage에만 기록됩니다. 서버는 어떤 방문자가 팝업을 봤는지 저장하지 않습니다.
 */
( function () {
	'use strict';

	if ( 'undefined' === typeof zorlinq32PopupData || ! Array.isArray( zorlinq32PopupData ) ) {
		return;
	}

	var STORAGE_PREFIX = 'zorlinq32_popup_seen_';

	function shouldShow( popup ) {
		if ( 'always' === popup.frequency ) {
			return true;
		}

		var key = STORAGE_PREFIX + popup.id;
		var lastSeenRaw;
		try {
			lastSeenRaw = window.localStorage.getItem( key );
		} catch ( e ) {
			// localStorage를 쓸 수 없는 환경(프라이빗 브라우징 등)에서는 매번 노출로 폴백합니다.
			return true;
		}

		if ( ! lastSeenRaw ) {
			return true;
		}

		var lastSeen = parseInt( lastSeenRaw, 10 );
		if ( isNaN( lastSeen ) ) {
			return true;
		}

		var now = Date.now();
		var elapsedMs = now - lastSeen;

		switch ( popup.frequency ) {
			case 'once_per_session':
				// 세션 기준은 sessionStorage로 별도 확인합니다 (탭/브라우저 종료 시 초기화).
				try {
					return ! window.sessionStorage.getItem( key );
				} catch ( e ) {
					return true;
				}
			case 'once_per_day':
				return elapsedMs >= ( 24 * 60 * 60 * 1000 );
			case 'once_per_week':
				return elapsedMs >= ( 7 * 24 * 60 * 60 * 1000 );
			default:
				return true;
		}
	}

	function markSeen( popup ) {
		var key = STORAGE_PREFIX + popup.id;
		var now = String( Date.now() );
		try {
			window.localStorage.setItem( key, now );
		} catch ( e ) {}
		if ( 'once_per_session' === popup.frequency ) {
			try {
				window.sessionStorage.setItem( key, now );
			} catch ( e ) {}
		}
	}

	function showPopup( popup ) {
		var el = document.getElementById( 'zorlinq32-popup-' + popup.id );
		if ( ! el ) {
			return;
		}

		el.style.display = 'flex';
		// display 변경과 별도의 프레임에서 클래스를 추가해 CSS 트랜지션/애니메이션이 걸리도록 합니다.
		window.requestAnimationFrame( function () {
			el.classList.add( 'is-visible' );
		} );

		markSeen( popup );

		var closeBtn = el.querySelector( '.zorlinq32-popup-close' );
		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', function () {
				el.classList.remove( 'is-visible' );
				el.style.display = 'none';
			} );
		}

		el.addEventListener( 'click', function ( event ) {
			if ( event.target === el ) {
				el.classList.remove( 'is-visible' );
				el.style.display = 'none';
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		zorlinq32PopupData.forEach( function ( popup ) {
			if ( ! shouldShow( popup ) ) {
				return;
			}

			var delayMs = ( popup.delaySeconds || 0 ) * 1000;
			window.setTimeout( function () {
				showPopup( popup );
			}, delayMs );
		} );
	} );
} )();
