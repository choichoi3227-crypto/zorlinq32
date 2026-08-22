<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap zorlinq32-wrap">
	<?php include ZORLINQ32_DIR . 'templates/partial-header.php'; ?>

	<div class="zorlinq32-settings-section">
		<h2><?php esc_html_e( '관리자 로그인 링크 변경', 'zorlinq32' ); ?></h2>
		<p class="zorlinq32-help-text">
			<?php esc_html_e( '기본 wp-login.php 접근을 차단하고, 지정한 링크로만 로그인할 수 있도록 합니다. 관리자로 로그인된 상태에서는 항상 wp-admin에 접근할 수 있어 스스로 잠길 위험이 없습니다.', 'zorlinq32' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'zorlinq32_save_settings' ); ?>
			<input type="hidden" name="action" value="zorlinq32_save_settings" />
			<input type="hidden" name="settings_group" value="login_security" />
			<input type="hidden" name="redirect_page" value="zorlinq32-login" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '기능 사용', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							<?php esc_html_e( '로그인 링크 변경 기능을 사용합니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="custom_slug"><?php esc_html_e( '새 로그인 경로', 'zorlinq32' ); ?></label></th>
					<td>
						<code><?php echo esc_html( home_url( '/' ) ); ?></code>
						<input type="text" id="custom_slug" name="custom_slug" value="<?php echo esc_attr( $settings['custom_slug'] ); ?>" class="regular-text" />
						<p class="zorlinq32-help-text"><?php esc_html_e( '영문, 숫자, 하이픈만 사용할 수 있습니다. "admin", "login" 등 예약어는 사용할 수 없습니다.', 'zorlinq32' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( '변경사항 저장', 'zorlinq32' ) ); ?>
		</form>
	</div>
</div>
