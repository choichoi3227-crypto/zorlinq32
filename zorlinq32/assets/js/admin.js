/**
 * Zorlinq32 - 관리자 화면 스크립트
 * 모든 AJAX 요청은 실패해도 사용자에게 명확한 메시지만 보여주고
 * 페이지 전체 동작에는 영향을 주지 않도록 처리합니다.
 */
( function ( $ ) {
	'use strict';

	$( document ).ready( function () {

		// [애드센스 보호: 엣지 연동] 자동 생성된 Cloudflare/서버 차단 규칙 스니펫을
		// 클릭 한 번으로 전체 선택 + 클립보드 복사까지 처리합니다(API 키 불필요, 순수 클라이언트 동작).
		$( '.zorlinq32-copy-snippet' ).on( 'click', function () {
			var $textarea = $( this );
			$textarea.select();
			try {
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( $textarea.val() );
				} else {
					document.execCommand( 'copy' );
				}
				var original = $textarea.data( 'original-border' ) || $textarea.css( 'border-color' );
				$textarea.data( 'original-border', original );
				$textarea.css( 'border-color', '#00a32a' );
				window.setTimeout( function () {
					$textarea.css( 'border-color', original );
				}, 600 );
			} catch ( e ) {
				// 클립보드 API를 사용할 수 없는 환경(권한 없음 등)이면 전체 선택 상태로만 남겨,
				// 사용자가 직접 Ctrl/Cmd+C로 복사할 수 있게 합니다.
			}
		} );

		// 캐시 전체 삭제
		$( '#zorlinq32-clear-cache' ).on( 'click', function ( e ) {
			e.preventDefault();
			var $btn = $( this );
			var $result = $( '#zorlinq32-cache-result' );

			$btn.prop( 'disabled', true );
			$result.removeClass( 'success error' ).text( zorlinq32Admin.i18n.clearing );

			$.post( zorlinq32Admin.ajaxUrl, {
				action: 'zorlinq32_clear_cache',
				nonce: zorlinq32Admin.nonce
			} ).done( function ( response ) {
				if ( response && response.success ) {
					$result.addClass( 'success' ).text( response.data.message );
				} else {
					$result.addClass( 'error' ).text( '캐시 삭제에 실패했습니다.' );
				}
			} ).fail( function () {
				$result.addClass( 'error' ).text( '요청 중 오류가 발생했습니다.' );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		// 스토리지 정보 새로고침
		$( '#zorlinq32-refresh-storage' ).on( 'click', function ( e ) {
			e.preventDefault();
			var $btn = $( this );

			$btn.prop( 'disabled', true ).text( zorlinq32Admin.i18n.refreshing );

			$.post( zorlinq32Admin.ajaxUrl, {
				action: 'zorlinq32_refresh_storage',
				nonce: zorlinq32Admin.nonce
			} ).done( function ( response ) {
				if ( response && response.success && response.data && response.data.available ) {
					location.reload();
				} else {
					$( '#zorlinq32-storage-unavailable' ).show();
				}
			} ).fail( function () {
				$( '#zorlinq32-storage-unavailable' ).show();
			} ).always( function () {
				$btn.prop( 'disabled', false ).text( '새로고침' );
			} );
		} );

		// 스토리지 지금 정리: 만료 여부와 무관하게 캐시성 파일을 전량 정리
		$( '#zorlinq32-cleanup-storage' ).on( 'click', function ( e ) {
			e.preventDefault();
			var $btn = $( this );
			var $result = $( '#zorlinq32-cleanup-result' );

			$btn.prop( 'disabled', true ).text( zorlinq32Admin.i18n.cleaning );
			$result.hide().removeClass( 'success error' );

			$.post( zorlinq32Admin.ajaxUrl, {
				action: 'zorlinq32_cleanup_storage',
				nonce: zorlinq32Admin.nonce
			} ).done( function ( response ) {
				if ( response && response.success && response.data ) {
					var msg = zorlinq32Admin.i18n.cleanupResult
						.replace( '%1$d', response.data.files_removed )
						.replace( '%2$s', response.data.bytes_freed_human );
					$result.addClass( 'success' ).text( msg ).show();
					// 정리 후 최신 사용량을 보여주기 위해 잠시 후 새로고침합니다.
					setTimeout( function () {
						location.reload();
					}, 1200 );
				} else {
					var errMsg = ( response && response.data && response.data.message ) || zorlinq32Admin.i18n.cleanupFailed;
					$result.addClass( 'error' ).text( errMsg ).show();
				}
			} ).fail( function () {
				$result.addClass( 'error' ).text( zorlinq32Admin.i18n.cleanupFailed ).show();
			} ).always( function () {
				$btn.prop( 'disabled', false ).text( '지금 정리' );
			} );
		} );

		// 애드센스 보호: 자동 차단된 방문자를 IP 입력으로 즉시 해제
		$( document ).on( 'click', '.zorlinq32-unblock-visitor-btn', function ( e ) {
			e.preventDefault();
			var $btn = $( this );
			var $row = $btn.closest( 'tr' );
			var ip = $row.find( '.zorlinq32-unblock-ip-input' ).val();

			if ( ! ip ) {
				return;
			}

			$btn.prop( 'disabled', true );

			$.post( zorlinq32Admin.ajaxUrl, {
				action: 'zorlinq32_unblock_visitor',
				nonce: zorlinq32Admin.nonce,
				ip: ip
			} ).done( function ( response ) {
				if ( response && response.success ) {
					$row.fadeOut( 200, function () { $row.remove(); } );
				} else {
					$btn.prop( 'disabled', false );
				}
			} ).fail( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		// 애드센스 보호: 수동 등록한 차단 IP를 목록에서 제거
		$( document ).on( 'click', '.zorlinq32-remove-blocked-ip', function ( e ) {
			e.preventDefault();
			var $btn = $( this );
			var ip = $btn.data( 'ip' );

			$btn.prop( 'disabled', true );

			$.post( zorlinq32Admin.ajaxUrl, {
				action: 'zorlinq32_remove_blocked_ip',
				nonce: zorlinq32Admin.nonce,
				ip: ip
			} ).done( function ( response ) {
				if ( response && response.success ) {
					$btn.closest( 'tr' ).fadeOut( 200, function () { $( this ).remove(); } );
				} else {
					$btn.prop( 'disabled', false );
				}
			} ).fail( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		// Op 템플릿 삭제
		$( document ).on( 'click', '.zorlinq32-template-delete', function ( e ) {
			e.preventDefault();
			var $btn = $( this );

			if ( ! window.confirm( '이 템플릿을 삭제하시겠습니까? 이 템플릿을 사용 중인 글에는 영향을 주지 않습니다.' ) ) {
				return;
			}

			$btn.prop( 'disabled', true );

			$.post( zorlinq32Admin.ajaxUrl, {
				action: 'zorlinq32_delete_template',
				nonce: zorlinq32Admin.nonce,
				post_id: $btn.data( 'id' )
			} ).done( function ( response ) {
				if ( response && response.success ) {
					$btn.closest( 'tr' ).fadeOut( 200, function () { $( this ).remove(); } );
				} else {
					$btn.prop( 'disabled', false );
				}
			} ).fail( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		// [요청 기능: 통계 초기화] 파괴적인 작업이므로 반드시 확인 대화상자를 거칩니다.
		$( '#zorlinq32-reset-analytics-btn' ).on( 'click', function ( e ) {
			e.preventDefault();
			var $btn = $( this );
			var $result = $( '#zorlinq32-reset-analytics-result' );

			var confirmed = window.confirm( zorlinq32Admin.i18n.confirmReset || '정말로 모든 통계를 삭제하시겠습니까? 이 작업은 되돌릴 수 없습니다.' );
			if ( ! confirmed ) {
				return;
			}

			$btn.prop( 'disabled', true );
			$result.removeClass( 'success error' ).text( zorlinq32Admin.i18n.resetting || '초기화 중...' );

			$.post( zorlinq32Admin.ajaxUrl, {
				action: 'zorlinq32_reset_analytics',
				nonce: zorlinq32Admin.nonce
			} ).done( function ( response ) {
				if ( response && response.success ) {
					$result.addClass( 'success' ).text( response.data.message );
					// 화면에 남아있는 그래프/숫자가 이전 값 그대로 보이지 않도록 새로고침합니다.
					window.setTimeout( function () {
						window.location.reload();
					}, 800 );
				} else {
					$result.addClass( 'error' ).text( ( response && response.data && response.data.message ) || '초기화에 실패했습니다.' );
				}
			} ).fail( function () {
				$result.addClass( 'error' ).text( '요청 중 오류가 발생했습니다.' );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

	} );
} )( jQuery );
