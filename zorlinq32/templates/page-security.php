<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap zorlinq32-wrap">
	<?php include ZORLINQ32_DIR . 'templates/partial-header.php'; ?>

	<div class="zorlinq32-settings-section">
		<h2><?php esc_html_e( '보안 강화', 'zorlinq32' ); ?></h2>
		<p class="zorlinq32-help-text">
			<?php esc_html_e( '워드프레스의 대표적인 공격 경로를 줄이는 기본 보안 기능입니다. 정상적인 로그인/API 사용은 방해하지 않도록 설계되었습니다.', 'zorlinq32' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'zorlinq32_save_settings' ); ?>
			<input type="hidden" name="action" value="zorlinq32_save_settings" />
			<input type="hidden" name="settings_group" value="security" />
			<input type="hidden" name="redirect_page" value="zorlinq32-security" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '모듈 사용', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							<?php esc_html_e( '보안 강화 모듈을 사용합니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'XML-RPC 비활성화', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="disable_xmlrpc" value="1" <?php checked( ! empty( $settings['disable_xmlrpc'] ) ); ?> />
							<?php esc_html_e( '무차별 대입 공격에 자주 악용되는 XML-RPC를 비활성화합니다', 'zorlinq32' ); ?>
						</label>
						<p class="zorlinq32-help-text"><?php esc_html_e( '워드프레스 모바일 앱이나 일부 외부 서비스 연동을 사용 중이라면 신중하게 켜주세요.', 'zorlinq32' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '로그인 시도 제한', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="limit_login_attempts" value="1" <?php checked( ! empty( $settings['limit_login_attempts'] ) ); ?> />
							<?php esc_html_e( '일정 횟수 이상 로그인에 실패하면 해당 IP를 일시적으로 잠급니다', 'zorlinq32' ); ?>
						</label>
						<br /><br />
						<label>
							<?php esc_html_e( '최대 시도 횟수:', 'zorlinq32' ); ?>
							<input type="number" name="max_login_attempts" min="1" max="20" value="<?php echo esc_attr( $settings['max_login_attempts'] ); ?>" class="small-text" />
						</label>
						&nbsp;&nbsp;
						<label>
							<?php esc_html_e( '잠금 시간(분):', 'zorlinq32' ); ?>
							<input type="number" name="lockout_minutes" min="1" max="1440" value="<?php echo esc_attr( $settings['lockout_minutes'] ); ?>" class="small-text" />
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '파일 편집기 비활성화', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="disable_file_editor" value="1" <?php checked( ! empty( $settings['disable_file_editor'] ) ); ?> />
							<?php esc_html_e( '관리자 화면에서 테마/플러그인 파일을 직접 편집하지 못하도록 막습니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '워드프레스 버전 숨기기', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="hide_wp_version" value="1" <?php checked( ! empty( $settings['hide_wp_version'] ) ); ?> />
							<?php esc_html_e( '페이지 소스에 노출되는 워드프레스 버전 정보를 제거합니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '보안 헤더 추가', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="security_headers" value="1" <?php checked( ! empty( $settings['security_headers'] ) ); ?> />
							<?php esc_html_e( 'X-Content-Type-Options, Referrer-Policy 등 기본 보안 헤더를 추가합니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '사용자명 노출 방지', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="prevent_username_enum" value="1" <?php checked( ! empty( $settings['prevent_username_enum'] ) ); ?> />
							<?php esc_html_e( '?author=숫자 형태의 URL과 REST API를 통한 사용자명 유추를 차단합니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button( __( '변경사항 저장', 'zorlinq32' ) ); ?>
		</form>
	</div>
</div>
