<?php
/**
 * 팝업 모듈.
 *
 * 관리자가 등록한 팝업(이미지, HTML/iframe/script, 텍스트 문구)을 프론트엔드에 렌더링합니다.
 * 노출 주기(항상/세션당 1회/하루 1회/주 1회)는 방문자 브라우저의 localStorage에 마지막
 * 노출 시각을 기록해 판단합니다(서버에 방문자별 상태를 저장하지 않아 개인정보 부담이 없습니다).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorlinq32_Popup {

	private static $instance = null;
	private $settings = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = Zorlinq32_Settings::get_group( 'popup' );

		if ( empty( $this->settings['enabled'] ) || empty( $this->settings['popups'] ) ) {
			return;
		}

		add_action( 'wp_footer', array( $this, 'render_popups' ) );
	}

	/**
	 * 활성화된 팝업들을 프론트엔드에 렌더링합니다. 실제 노출 여부(주기 조건 충족 여부)는
	 * 서버가 판단할 수 없는 브라우저 측 정보(localStorage)에 의존하므로, 마크업은 항상
	 * 출력하고 JS가 조건에 따라 표시 여부를 최종 결정합니다.
	 */
	public function render_popups() {
		try {
			if ( is_admin() ) {
				return;
			}

			$active_popups = array_values(
				array_filter(
					$this->settings['popups'],
					function ( $popup ) {
						return ! empty( $popup['active'] );
					}
				)
			);

			if ( empty( $active_popups ) ) {
				return;
			}

			wp_enqueue_style( 'zorlinq32-popup', ZORLINQ32_URL . 'assets/css/popup.css', array(), ZORLINQ32_VERSION );
			wp_enqueue_script( 'zorlinq32-popup', ZORLINQ32_URL . 'assets/js/popup.js', array(), ZORLINQ32_VERSION, true );

			$popup_data = array();
			foreach ( $active_popups as $popup ) {
				$popup_data[] = $this->prepare_popup_data( $popup );
			}

			wp_localize_script( 'zorlinq32-popup', 'zorlinq32PopupData', $popup_data );

			foreach ( $active_popups as $popup ) {
				$this->render_popup_markup( $popup );
			}
		} catch ( \Throwable $e ) {
			Zorlinq32_Logger::log( '팝업 렌더링 중 오류: ' . $e->getMessage() );
		}
	}

	/**
	 * JS에 전달할 팝업별 메타데이터(노출 주기, 지연 시간 등)를 준비합니다.
	 * HTML 콘텐츠 자체는 서버에서 이미 마크업으로 렌더링하므로 여기에는 포함하지 않습니다.
	 */
	private function prepare_popup_data( $popup ) {
		return array(
			'id'           => $popup['id'],
			'frequency'    => isset( $popup['frequency'] ) ? $popup['frequency'] : 'always',
			'delaySeconds' => isset( $popup['delay_seconds'] ) ? (int) $popup['delay_seconds'] : 0,
		);
	}

	/**
	 * 팝업 하나의 HTML 마크업을 출력합니다. 기본적으로 숨김 상태(CSS)로 출력되고,
	 * popup.js가 노출 조건을 확인한 뒤 표시합니다.
	 */
	private function render_popup_markup( $popup ) {
		$type = isset( $popup['type'] ) ? $popup['type'] : 'text';
		$id   = isset( $popup['id'] ) ? $popup['id'] : '';

		echo '<div class="zorlinq32-popup-overlay" id="zorlinq32-popup-' . esc_attr( $id ) . '" data-popup-id="' . esc_attr( $id ) . '" style="display:none;">';
		echo '<div class="zorlinq32-popup-box">';
		echo '<button type="button" class="zorlinq32-popup-close" aria-label="' . esc_attr__( '닫기', 'zorlinq32' ) . '">&times;</button>';

		$inner = $this->build_popup_inner_html( $popup, $type );

		if ( ! empty( $popup['link_url'] ) && 'html' !== $type ) {
			// HTML 타입은 사용자가 직접 링크/스크립트를 구성하므로 이중으로 감싸지 않습니다.
			printf( '<a href="%s" class="zorlinq32-popup-link" target="_blank" rel="noopener noreferrer">', esc_url( $popup['link_url'] ) );
			echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- build_popup_inner_html() 내부에서 콘텐츠 종류별로 이미 적절히 이스케이프/허용 처리되었습니다.
			echo '</a>';
		} else {
			echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- build_popup_inner_html() 내부에서 콘텐츠 종류별로 이미 적절히 이스케이프/허용 처리되었습니다.
		}

		echo '</div>';
		echo '</div>';
	}

	/**
	 * 팝업 종류별 내부 콘텐츠 HTML을 생성합니다.
	 */
	private function build_popup_inner_html( $popup, $type ) {
		switch ( $type ) {
			case 'image':
				$image_id = isset( $popup['image_id'] ) ? (int) $popup['image_id'] : 0;
				if ( ! $image_id ) {
					return '';
				}
				$image_url = wp_get_attachment_image_url( $image_id, 'large' );
				if ( ! $image_url ) {
					return '';
				}
				return sprintf( '<img src="%s" alt="" class="zorlinq32-popup-image" />', esc_url( $image_url ) );

			case 'html':
				// HTML/iframe/script 코드는 사용자가 직접 신뢰할 수 있는 코드를 붙여넣는
				// 용도이므로 의도적으로 이스케이프하지 않습니다. 저장은 manage_options
				// 권한자만 가능하며(코드 삽입 기능과 동일한 신뢰 모델), 이 필드는 코드 삽입
				// 기능과 마찬가지로 관리자 전용 고신뢰 입력으로 취급합니다.
				return isset( $popup['html_code'] ) ? $popup['html_code'] : '';

			case 'text':
			default:
				$text = isset( $popup['text_content'] ) ? $popup['text_content'] : '';
				return '<div class="zorlinq32-popup-text">' . wp_kses_post( $text ) . '</div>';
		}
	}
}
