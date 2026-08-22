/**
 * Zorlinq32 - 퀵 버튼 블록
 * 빌드 도구(webpack 등) 없이 wp.blocks / wp.element / wp.blockEditor 전역 객체만으로 작성되었습니다.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var RichText = blockEditor.RichText;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var RangeControl = components.RangeControl;
	var SelectControl = components.SelectControl;
	var ColorPalette = components.ColorPalette;
	var Button = components.Button;

	/**
	 * 애니메이션 종류별로 "적당한 변동율"을 가진 CSS를 문자열로 만듭니다.
	 * 과하지 않은 수치(짧은 지속시간, 작은 변위)로 고정해 사용자가 프리셋을 골라도
	 * 항상 무난한 결과가 나오도록 합니다.
	 */
	function buildAnimationCss( uniqueClass, animation ) {
		switch ( animation ) {
			case 'pulse':
				return '.' + uniqueClass + '{animation:zorlinq32-qb-pulse 1.6s ease-in-out infinite;}' +
					'@keyframes zorlinq32-qb-pulse{0%,100%{transform:scale(1);}50%{transform:scale(1.04);}}';
			case 'zoom':
				return '.' + uniqueClass + '{transition:transform .2s ease;}' +
					'.' + uniqueClass + ':hover{transform:scale(1.06);}';
			case 'fade':
				return '.' + uniqueClass + '{transition:opacity .2s ease;}' +
					'.' + uniqueClass + ':hover{opacity:0.85;}';
			case 'shake':
				return '.' + uniqueClass + ':hover{animation:zorlinq32-qb-shake .4s ease;}' +
					'@keyframes zorlinq32-qb-shake{0%,100%{transform:translateX(0);}25%{transform:translateX(-3px);}75%{transform:translateX(3px);}}';
			case 'none':
			default:
				return '';
		}
	}

	blocks.registerBlockType( 'zorlinq32/quick-button', {
		title: __( '퀵 버튼', 'zorlinq32' ),
		description: __( '배경색, 텍스트색, 애니메이션, 크기를 자유롭게 설정할 수 있는 버튼입니다.', 'zorlinq32' ),
		icon: 'button',
		category: 'widgets',
		keywords: [ 'button', '버튼', 'quick button', '퀵버튼' ],
		supports: { html: false },
		attributes: {
			text: { type: 'string', default: __( '버튼 텍스트', 'zorlinq32' ) },
			url: { type: 'string', default: '' },
			backgroundColor: { type: 'string', default: '#4f46e5' },
			textColor: { type: 'string', default: '#ffffff' },
			animation: { type: 'string', default: 'none' },
			paddingHorizontal: { type: 'number', default: 24 },
			paddingVertical: { type: 'number', default: 12 },
			borderRadius: { type: 'number', default: 6 },
			uid: { type: 'string', default: '' }
		},

		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			if ( ! attributes.uid ) {
				setAttributes( { uid: 'qb-' + Math.random().toString( 36 ).substr( 2, 9 ) } );
			}

			var uniqueClass = 'zorlinq32-qb-' + ( attributes.uid || 'preview' );
			// [디자인 개선] 퀵 버튼은 항상 가운데 정렬되어야 합니다. useBlockProps()가 반환하는
			// 기본 className/style에 텍스트 정렬이 포함되어 있지 않아, 버튼이 부모 컨테이너의
			// 정렬(대개 왼쪽)을 그대로 따르는 문제가 있었습니다. 감싸는 div에 text-align:center를
			// 강제해, 테마나 다른 블록의 정렬 설정과 무관하게 항상 중앙에 표시되도록 합니다.
			var blockProps = useBlockProps( { style: { textAlign: 'center' } } );

			var buttonStyle = {
				display: 'inline-block',
				backgroundColor: attributes.backgroundColor,
				color: attributes.textColor,
				padding: attributes.paddingVertical + 'px ' + attributes.paddingHorizontal + 'px',
				borderRadius: attributes.borderRadius + 'px',
				textDecoration: 'none',
				border: 'none',
				cursor: 'pointer',
				fontWeight: '600'
			};

			return el(
				element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( '버튼 설정', 'zorlinq32' ), initialOpen: true },
						el( TextControl, {
							label: __( '이동 링크', 'zorlinq32' ),
							value: attributes.url,
							onChange: function ( value ) { setAttributes( { url: value } ); },
							placeholder: 'https://example.com'
						} ),
						el( SelectControl, {
							label: __( '동작(애니메이션)', 'zorlinq32' ),
							value: attributes.animation,
							options: [
								{ label: __( '없음', 'zorlinq32' ), value: 'none' },
								{ label: __( '펄스', 'zorlinq32' ), value: 'pulse' },
								{ label: __( '줌', 'zorlinq32' ), value: 'zoom' },
								{ label: __( '페이드', 'zorlinq32' ), value: 'fade' },
								{ label: __( '떨림', 'zorlinq32' ), value: 'shake' }
							],
							onChange: function ( value ) { setAttributes( { animation: value } ); }
						} ),
						el( RangeControl, {
							label: __( '좌우 여백', 'zorlinq32' ),
							value: attributes.paddingHorizontal,
							min: 4,
							max: 80,
							onChange: function ( value ) { setAttributes( { paddingHorizontal: value } ); }
						} ),
						el( RangeControl, {
							label: __( '상하 여백', 'zorlinq32' ),
							value: attributes.paddingVertical,
							min: 2,
							max: 60,
							onChange: function ( value ) { setAttributes( { paddingVertical: value } ); }
						} ),
						el( RangeControl, {
							label: __( '모서리 둥글기', 'zorlinq32' ),
							value: attributes.borderRadius,
							min: 0,
							max: 50,
							onChange: function ( value ) { setAttributes( { borderRadius: value } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( '배경 색', 'zorlinq32' ), initialOpen: false },
						el( ColorPalette, {
							value: attributes.backgroundColor,
							onChange: function ( value ) { setAttributes( { backgroundColor: value || '#4f46e5' } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( '텍스트 색', 'zorlinq32' ), initialOpen: false },
						el( ColorPalette, {
							value: attributes.textColor,
							onChange: function ( value ) { setAttributes( { textColor: value || '#ffffff' } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( '프리셋', 'zorlinq32' ), initialOpen: false },
						el( Button, {
							isSecondary: true,
							style: { marginBottom: '8px', width: '100%' },
							onClick: function () {
								setAttributes( { backgroundColor: '#4f46e5', textColor: '#ffffff', animation: 'pulse', paddingHorizontal: 24, paddingVertical: 12, borderRadius: 6 } );
							}
						}, __( '기본형 (인디고 + 펄스)', 'zorlinq32' ) ),
						el( Button, {
							isSecondary: true,
							style: { marginBottom: '8px', width: '100%' },
							onClick: function () {
								setAttributes( { backgroundColor: '#16a34a', textColor: '#ffffff', animation: 'zoom', paddingHorizontal: 28, paddingVertical: 14, borderRadius: 999 } );
							}
						}, __( '강조형 (그린 + 줌 + 필)', 'zorlinq32' ) ),
						el( Button, {
							isSecondary: true,
							style: { width: '100%' },
							onClick: function () {
								setAttributes( { backgroundColor: '#111827', textColor: '#ffffff', animation: 'none', paddingHorizontal: 20, paddingVertical: 10, borderRadius: 4 } );
							}
						}, __( '미니멀형', 'zorlinq32' ) )
					)
				),
				el(
					'div',
					blockProps,
					el( 'style', null, buildAnimationCss( uniqueClass, attributes.animation ) ),
					el(
						RichText,
						{
							tagName: 'span',
							className: uniqueClass,
							style: buttonStyle,
							value: attributes.text,
							onChange: function ( value ) { setAttributes( { text: value } ); },
							placeholder: __( '버튼 텍스트', 'zorlinq32' )
						}
					)
				)
			);
		},

		save: function ( props ) {
			var attributes = props.attributes;
			// [디자인 개선] 프론트엔드에 저장되는 마크업에도 동일하게 중앙 정렬을 강제합니다.
			var blockProps = blockEditor.useBlockProps.save( { style: { textAlign: 'center' } } );
			var uniqueClass = 'zorlinq32-qb-' + ( attributes.uid || 'x' );

			var buttonStyle = {
				display: 'inline-block',
				backgroundColor: attributes.backgroundColor,
				color: attributes.textColor,
				padding: attributes.paddingVertical + 'px ' + attributes.paddingHorizontal + 'px',
				borderRadius: attributes.borderRadius + 'px',
				textDecoration: 'none',
				border: 'none',
				fontWeight: '600'
			};

			var tag = attributes.url ? 'a' : 'span';
			var linkProps = attributes.url ? { href: attributes.url, className: uniqueClass, style: buttonStyle } : { className: uniqueClass, style: buttonStyle };

			return el(
				'div',
				blockProps,
				el( 'style', null, buildAnimationCss( uniqueClass, attributes.animation ) ),
				el( tag, linkProps, el( RichText.Content, { value: attributes.text } ) )
			);
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
