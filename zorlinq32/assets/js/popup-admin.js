/**
 * Zorlinq32 - 팝업 관리 페이지 스크립트
 */
( function ( $ ) {
	'use strict';

	$( document ).ready( function () {
		var frame;

		function switchTypeRows( type ) {
			$( '#zorlinq32-popup-row-image' ).toggle( 'image' === type );
			$( '#zorlinq32-popup-row-html' ).toggle( 'html' === type );
			$( '#zorlinq32-popup-row-text' ).toggle( 'text' === type );
		}

		switchTypeRows( $( '#zorlinq32-popup-type' ).val() );

		$( '#zorlinq32-popup-type' ).on( 'change', function () {
			switchTypeRows( $( this ).val() );
		} );

		$( '#zorlinq32-popup-select-image' ).on( 'click', function ( e ) {
			e.preventDefault();

			if ( frame ) {
				frame.open();
				return;
			}

			frame = wp.media( {
				title: '이미지 선택',
				button: { text: '이 이미지 사용' },
				multiple: false,
				library: { type: 'image' }
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				$( '#zorlinq32-popup-image-id' ).val( attachment.id );

				var previewUrl = ( attachment.sizes && attachment.sizes.medium ) ? attachment.sizes.medium.url : attachment.url;
				$( '#zorlinq32-popup-image-preview' ).html(
					$( '<img>' ).attr( 'src', previewUrl ).css( { maxWidth: '200px', height: 'auto', display: 'block', border: '1px solid #dcdcde', borderRadius: '4px' } )
				);
			} );

			frame.open();
		} );

		function resetForm() {
			$( '#zorlinq32-popup-editing-id' ).val( '' );
			$( '#zorlinq32-popup-type' ).val( 'image' );
			$( '#zorlinq32-popup-image-id' ).val( '0' );
			$( '#zorlinq32-popup-image-preview' ).empty();
			$( '#zorlinq32-popup-html-code' ).val( '' );
			$( '#zorlinq32-popup-text-content' ).val( '' );
			$( '#zorlinq32-popup-link-url' ).val( '' );
			$( '#zorlinq32-popup-frequency' ).val( 'always' );
			$( '#zorlinq32-popup-delay' ).val( '0' );
			$( '#zorlinq32-popup-active' ).prop( 'checked', true );
			switchTypeRows( 'image' );
			$( '#zorlinq32-popup-form-title' ).text( '새 팝업 추가' );
			$( '#zorlinq32-popup-cancel-edit' ).hide();
		}

		$( '#zorlinq32-popup-cancel-edit' ).on( 'click', function () {
			resetForm();
		} );

		// 편집 버튼: 기존 팝업 데이터를 폼에 채워넣습니다.
		$( document ).on( 'click', '.zorlinq32-popup-edit', function () {
			var $btn = $( this );

			$( '#zorlinq32-popup-editing-id' ).val( $btn.data( 'id' ) );
			$( '#zorlinq32-popup-type' ).val( $btn.data( 'type' ) );
			$( '#zorlinq32-popup-image-id' ).val( $btn.data( 'image-id' ) || '0' );
			$( '#zorlinq32-popup-html-code' ).val( $btn.data( 'html-code' ) || '' );
			$( '#zorlinq32-popup-text-content' ).val( $btn.data( 'text-content' ) || '' );
			$( '#zorlinq32-popup-link-url' ).val( $btn.data( 'link-url' ) || '' );
			$( '#zorlinq32-popup-frequency' ).val( $btn.data( 'frequency' ) || 'always' );
			$( '#zorlinq32-popup-delay' ).val( $btn.data( 'delay-seconds' ) || 0 );

			switchTypeRows( $btn.data( 'type' ) );
			$( '#zorlinq32-popup-form-title' ).text( '팝업 편집' );
			$( '#zorlinq32-popup-cancel-edit' ).show();

			$( 'html, body' ).animate( { scrollTop: $( '#zorlinq32-popup-form-title' ).offset().top - 40 }, 300 );
		} );

		$( '#zorlinq32-popup-save' ).on( 'click', function () {
			var $btn = $( this );
			var $result = $( '#zorlinq32-popup-save-result' );

			$btn.prop( 'disabled', true );
			$result.removeClass( 'success error' ).text( '저장 중...' );

			$.post( zorlinq32Admin.ajaxUrl, {
				action: 'zorlinq32_save_popup',
				nonce: zorlinq32Admin.nonce,
				popup_id: $( '#zorlinq32-popup-editing-id' ).val(),
				type: $( '#zorlinq32-popup-type' ).val(),
				image_id: $( '#zorlinq32-popup-image-id' ).val(),
				html_code: $( '#zorlinq32-popup-html-code' ).val(),
				text_content: $( '#zorlinq32-popup-text-content' ).val(),
				link_url: $( '#zorlinq32-popup-link-url' ).val(),
				frequency: $( '#zorlinq32-popup-frequency' ).val(),
				delay_seconds: $( '#zorlinq32-popup-delay' ).val(),
				active: $( '#zorlinq32-popup-active' ).is( ':checked' ) ? 1 : 0
			} ).done( function ( response ) {
				if ( response && response.success ) {
					location.reload();
				} else {
					var msg = ( response && response.data && response.data.message ) ? response.data.message : '저장에 실패했습니다.';
					$result.addClass( 'error' ).text( msg );
				}
			} ).fail( function () {
				$result.addClass( 'error' ).text( '요청 중 오류가 발생했습니다.' );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		$( document ).on( 'click', '.zorlinq32-popup-delete', function () {
			var $btn = $( this );
			if ( ! window.confirm( '이 팝업을 삭제하시겠습니까?' ) ) {
				return;
			}

			$btn.prop( 'disabled', true );

			$.post( zorlinq32Admin.ajaxUrl, {
				action: 'zorlinq32_delete_popup',
				nonce: zorlinq32Admin.nonce,
				popup_id: $btn.data( 'id' )
			} ).done( function ( response ) {
				if ( response && response.success ) {
					location.reload();
				} else {
					$btn.prop( 'disabled', false );
				}
			} ).fail( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		$( document ).on( 'click', '.zorlinq32-popup-toggle', function () {
			var $btn = $( this );
			$btn.prop( 'disabled', true );

			$.post( zorlinq32Admin.ajaxUrl, {
				action: 'zorlinq32_toggle_popup',
				nonce: zorlinq32Admin.nonce,
				popup_id: $btn.data( 'id' )
			} ).done( function ( response ) {
				if ( response && response.success ) {
					location.reload();
				} else {
					$btn.prop( 'disabled', false );
				}
			} ).fail( function () {
				$btn.prop( 'disabled', false );
			} );
		} );
	} );
} )( jQuery );
