<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap zorlinq32-wrap">
	<?php include ZORLINQ32_DIR . 'templates/partial-header.php'; ?>

	<div class="zorlinq32-settings-section">
		<h2><?php esc_html_e( '관리 편의', 'zorlinq32' ); ?></h2>
		<p class="zorlinq32-help-text">
			<?php esc_html_e( '관리자 화면을 정리해 불필요한 렌더링을 줄이고 사용성을 높입니다.', 'zorlinq32' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'zorlinq32_save_settings' ); ?>
			<input type="hidden" name="action" value="zorlinq32_save_settings" />
			<input type="hidden" name="settings_group" value="admin_experience" />
			<input type="hidden" name="redirect_page" value="zorlinq32-admin-experience" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '모듈 사용', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							<?php esc_html_e( '관리 편의 모듈을 사용합니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '대시보드 위젯 정리', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="clean_dashboard_widgets" value="1" <?php checked( ! empty( $settings['clean_dashboard_widgets'] ) ); ?> />
							<?php esc_html_e( '워드프레스 뉴스/이벤트 등 외부 콘텐츠 위젯을 제거합니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '관리자바 정리', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="clean_admin_bar" value="1" <?php checked( ! empty( $settings['clean_admin_bar'] ) ); ?> />
							<?php esc_html_e( '상단 관리자바에서 워드프레스 로고 메뉴를 제거합니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="custom_login_logo_url"><?php esc_html_e( '로그인 화면 로고', 'zorlinq32' ); ?></label></th>
					<td>
						<input type="url" id="custom_login_logo_url" name="custom_login_logo_url" value="<?php echo esc_attr( $settings['custom_login_logo_url'] ); ?>" class="regular-text" placeholder="https://example.com/logo.png" />
						<p class="zorlinq32-help-text"><?php esc_html_e( '이미지 URL을 입력하면 로그인 화면의 워드프레스 로고를 대체합니다. 비워두면 기본 로고가 유지됩니다.', 'zorlinq32' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '대표이미지 컬럼', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="featured_image_column" value="1" <?php checked( ! empty( $settings['featured_image_column'] ) ); ?> />
							<?php esc_html_e( '글 목록 화면에 대표이미지 유무를 보여주는 컬럼을 추가합니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button( __( '변경사항 저장', 'zorlinq32' ) ); ?>
		</form>
	</div>
</div>
