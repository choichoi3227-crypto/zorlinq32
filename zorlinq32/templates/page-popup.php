<?php
/**
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- 이 파일은 관리자 클래스 메서드 안에서 include 되는 템플릿이며, 여기서 정의되는 변수는 해당 메서드의 지역 스코프에 한정됩니다.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$zorlinq32_frequency_labels = array(
	'always'           => __( '항상', 'zorlinq32' ),
	'once_per_session' => __( '세션당 1회', 'zorlinq32' ),
	'once_per_day'     => __( '하루 1회', 'zorlinq32' ),
	'once_per_week'    => __( '주 1회', 'zorlinq32' ),
);
?>
<div class="wrap zorlinq32-wrap">
	<?php include ZORLINQ32_DIR . 'templates/partial-header.php'; ?>

	<div class="zorlinq32-settings-section">
		<h2><?php esc_html_e( '팝업 기능 사용', 'zorlinq32' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'zorlinq32_save_settings' ); ?>
			<input type="hidden" name="action" value="zorlinq32_save_settings" />
			<input type="hidden" name="settings_group" value="popup" />
			<input type="hidden" name="redirect_page" value="zorlinq32-popup" />
			<label>
				<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
				<?php esc_html_e( '사이트에 등록된 팝업을 노출합니다', 'zorlinq32' ); ?>
			</label>
			<?php submit_button( __( '저장', 'zorlinq32' ), 'secondary' ); ?>
		</form>
	</div>

	<div class="zorlinq32-settings-section">
		<h2><?php esc_html_e( '팝업 목록', 'zorlinq32' ); ?></h2>
		<div id="zorlinq32-popup-list">
			<?php if ( empty( $popups ) ) : ?>
				<p class="zorlinq32-help-text" id="zorlinq32-popup-empty"><?php esc_html_e( '등록된 팝업이 없습니다.', 'zorlinq32' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( '종류', 'zorlinq32' ); ?></th>
							<th><?php esc_html_e( '노출 주기', 'zorlinq32' ); ?></th>
							<th><?php esc_html_e( '상태', 'zorlinq32' ); ?></th>
							<th><?php esc_html_e( '관리', 'zorlinq32' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $popups as $zorlinq32_popup ) : ?>
							<tr data-popup-id="<?php echo esc_attr( $zorlinq32_popup['id'] ); ?>">
								<td>
									<?php
									$zorlinq32_type_labels = array(
										'image' => __( '이미지', 'zorlinq32' ),
										'html'  => __( 'HTML/iframe/스크립트', 'zorlinq32' ),
										'text'  => __( '문구', 'zorlinq32' ),
									);
									echo esc_html( isset( $zorlinq32_type_labels[ $zorlinq32_popup['type'] ] ) ? $zorlinq32_type_labels[ $zorlinq32_popup['type'] ] : $zorlinq32_popup['type'] );
									?>
								</td>
								<td><?php echo esc_html( isset( $zorlinq32_frequency_labels[ $zorlinq32_popup['frequency'] ] ) ? $zorlinq32_frequency_labels[ $zorlinq32_popup['frequency'] ] : $zorlinq32_popup['frequency'] ); ?></td>
								<td>
									<span class="zorlinq32-status-pill <?php echo ! empty( $zorlinq32_popup['active'] ) ? 'is-on' : 'is-off'; ?>">
										<span class="dot"></span>
										<?php echo ! empty( $zorlinq32_popup['active'] ) ? esc_html__( '사용 중', 'zorlinq32' ) : esc_html__( '미사용', 'zorlinq32' ); ?>
									</span>
								</td>
								<td>
									<button type="button" class="button zorlinq32-popup-toggle" data-id="<?php echo esc_attr( $zorlinq32_popup['id'] ); ?>"><?php esc_html_e( '켜기/끄기', 'zorlinq32' ); ?></button>
									<button type="button" class="button zorlinq32-popup-edit"
										data-id="<?php echo esc_attr( $zorlinq32_popup['id'] ); ?>"
										data-type="<?php echo esc_attr( $zorlinq32_popup['type'] ); ?>"
										data-image-id="<?php echo esc_attr( $zorlinq32_popup['image_id'] ); ?>"
										data-html-code="<?php echo esc_attr( $zorlinq32_popup['html_code'] ); ?>"
										data-text-content="<?php echo esc_attr( $zorlinq32_popup['text_content'] ); ?>"
										data-link-url="<?php echo esc_attr( $zorlinq32_popup['link_url'] ); ?>"
										data-frequency="<?php echo esc_attr( $zorlinq32_popup['frequency'] ); ?>"
										data-delay-seconds="<?php echo esc_attr( $zorlinq32_popup['delay_seconds'] ); ?>"
									><?php esc_html_e( '편집', 'zorlinq32' ); ?></button>
									<button type="button" class="button zorlinq32-popup-delete" data-id="<?php echo esc_attr( $zorlinq32_popup['id'] ); ?>"><?php esc_html_e( '삭제', 'zorlinq32' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>

	<div class="zorlinq32-settings-section">
		<h2 id="zorlinq32-popup-form-title"><?php esc_html_e( '새 팝업 추가', 'zorlinq32' ); ?></h2>
		<input type="hidden" id="zorlinq32-popup-editing-id" value="" />

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="zorlinq32-popup-type"><?php esc_html_e( '종류', 'zorlinq32' ); ?></label></th>
				<td>
					<select id="zorlinq32-popup-type">
						<option value="image"><?php esc_html_e( '이미지', 'zorlinq32' ); ?></option>
						<option value="html"><?php esc_html_e( 'HTML / iframe / 스크립트 코드', 'zorlinq32' ); ?></option>
						<option value="text"><?php esc_html_e( '문구', 'zorlinq32' ); ?></option>
					</select>
				</td>
			</tr>
			<tr id="zorlinq32-popup-row-image">
				<th scope="row"><?php esc_html_e( '이미지', 'zorlinq32' ); ?></th>
				<td>
					<input type="hidden" id="zorlinq32-popup-image-id" value="0" />
					<div id="zorlinq32-popup-image-preview" style="margin-bottom:8px;"></div>
					<button type="button" class="button" id="zorlinq32-popup-select-image"><?php esc_html_e( '이미지 선택', 'zorlinq32' ); ?></button>
				</td>
			</tr>
			<tr id="zorlinq32-popup-row-html" style="display:none;">
				<th scope="row"><label for="zorlinq32-popup-html-code"><?php esc_html_e( 'HTML / iframe / 스크립트', 'zorlinq32' ); ?></label></th>
				<td>
					<textarea id="zorlinq32-popup-html-code" rows="8" class="large-text code" spellcheck="false"></textarea>
					<p class="zorlinq32-help-text"><?php esc_html_e( '입력한 코드가 그대로 팝업 안에 출력됩니다. 신뢰할 수 있는 코드만 입력하세요.', 'zorlinq32' ); ?></p>
				</td>
			</tr>
			<tr id="zorlinq32-popup-row-text" style="display:none;">
				<th scope="row"><label for="zorlinq32-popup-text-content"><?php esc_html_e( '문구', 'zorlinq32' ); ?></label></th>
				<td>
					<textarea id="zorlinq32-popup-text-content" rows="5" class="large-text"></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="zorlinq32-popup-link-url"><?php esc_html_e( '이동 링크 (선택)', 'zorlinq32' ); ?></label></th>
				<td>
					<input type="url" id="zorlinq32-popup-link-url" class="regular-text" placeholder="<?php echo esc_attr( 'https://example.com' ); ?>" />
					<p class="zorlinq32-help-text"><?php esc_html_e( '팝업 클릭 시 이동할 주소입니다. HTML 종류에는 적용되지 않습니다(코드 안에서 직접 링크를 구성하세요).', 'zorlinq32' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="zorlinq32-popup-frequency"><?php esc_html_e( '노출 주기', 'zorlinq32' ); ?></label></th>
				<td>
					<select id="zorlinq32-popup-frequency">
						<?php foreach ( $zorlinq32_frequency_labels as $zorlinq32_freq_key => $zorlinq32_freq_label ) : ?>
							<option value="<?php echo esc_attr( $zorlinq32_freq_key ); ?>"><?php echo esc_html( $zorlinq32_freq_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="zorlinq32-popup-delay"><?php esc_html_e( '노출 지연 시간 (초)', 'zorlinq32' ); ?></label></th>
				<td>
					<input type="number" id="zorlinq32-popup-delay" min="0" max="300" value="0" class="small-text" />
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( '활성화', 'zorlinq32' ); ?></th>
				<td>
					<label>
						<input type="checkbox" id="zorlinq32-popup-active" checked />
						<?php esc_html_e( '저장 즉시 노출 시작', 'zorlinq32' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<button type="button" class="button button-primary" id="zorlinq32-popup-save"><?php esc_html_e( '팝업 저장', 'zorlinq32' ); ?></button>
		<button type="button" class="button" id="zorlinq32-popup-cancel-edit" style="display:none;"><?php esc_html_e( '편집 취소', 'zorlinq32' ); ?></button>
		<span id="zorlinq32-popup-save-result" class="zorlinq32-ajax-result"></span>
	</div>
</div>
