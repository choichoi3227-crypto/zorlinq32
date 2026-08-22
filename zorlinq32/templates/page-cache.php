<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap zorlinq32-wrap">
	<?php include ZORLINQ32_DIR . 'templates/partial-header.php'; ?>

	<div class="zorlinq32-grid">
		<div class="zorlinq32-card">
			<h3><?php esc_html_e( '현재 캐시 용량', 'zorlinq32' ); ?></h3>
			<div class="zorlinq32-stat"><?php echo esc_html( Zorlinq32_Storage_Monitor::format_bytes( $cache_size ) ); ?></div>
			<div class="zorlinq32-inline-actions">
				<button type="button" id="zorlinq32-clear-cache" class="button"><?php esc_html_e( '캐시 전체 삭제', 'zorlinq32' ); ?></button>
				<span id="zorlinq32-cache-result" class="zorlinq32-inline-result"></span>
			</div>
		</div>
	</div>

	<div class="zorlinq32-settings-section">
		<h2><?php esc_html_e( '페이지 캐싱 설정', 'zorlinq32' ); ?></h2>
		<p class="zorlinq32-help-text">
			<?php esc_html_e( '로그인하지 않은 방문자에게 보여지는 페이지를 파일로 저장해 재사용합니다. 글/댓글이 등록되면 캐시가 자동으로 초기화됩니다.', 'zorlinq32' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'zorlinq32_save_settings' ); ?>
			<input type="hidden" name="action" value="zorlinq32_save_settings" />
			<input type="hidden" name="settings_group" value="cache" />
			<input type="hidden" name="redirect_page" value="zorlinq32-cache" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '기능 사용', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							<?php esc_html_e( '페이지 캐싱을 사용합니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cache_lifetime"><?php esc_html_e( '캐시 유효 시간', 'zorlinq32' ); ?></label></th>
					<td>
						<input type="number" id="cache_lifetime" name="cache_lifetime" min="1" max="720" value="<?php echo esc_attr( $settings['cache_lifetime'] ); ?>" class="small-text" />
						<?php esc_html_e( '시간', 'zorlinq32' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '로그인 사용자 제외', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="exclude_logged_in" value="1" <?php checked( ! empty( $settings['exclude_logged_in'] ) ); ?> />
							<?php esc_html_e( '로그인한 사용자에게는 캐시를 제공하지 않습니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button( __( '변경사항 저장', 'zorlinq32' ) ); ?>
		</form>
	</div>
</div>
