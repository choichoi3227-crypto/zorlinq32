<?php
/**
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- 이 파일은 관리자 클래스 메서드 안에서 include 되는 템플릿이며, 여기서 정의되는 변수는 해당 메서드의 지역 스코프에 한정됩니다.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap zorlinq32-wrap">
	<?php include ZORLINQ32_DIR . 'templates/partial-header.php'; ?>

	<div class="zorlinq32-notice-saved" style="border-left-color:#dba617;background:#fff8e5;">
		<strong><?php esc_html_e( '주의', 'zorlinq32' ); ?></strong>
		<p style="margin:8px 0 0;">
			<?php esc_html_e( '이 기능은 입력한 코드를 검증 없이 그대로 사이트에 출력합니다. 신뢰할 수 없는 출처의 코드를 붙여넣지 마세요. 최고 관리자(admin) 권한을 가진 사용자만 이 설정을 변경할 수 있습니다.', 'zorlinq32' ); ?>
		</p>
	</div>

	<div class="zorlinq32-settings-section">
		<h2><?php esc_html_e( '헤더/바디/푸터 코드 삽입', 'zorlinq32' ); ?></h2>
		<p class="zorlinq32-help-text">
			<?php esc_html_e( 'Google Analytics, 네이버 서치어드바이저 소유 확인 태그, 광고 코드, 커스텀 스타일 등을 지정한 위치에 삽입할 수 있습니다.', 'zorlinq32' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'zorlinq32_save_settings' ); ?>
			<input type="hidden" name="action" value="zorlinq32_save_settings" />
			<input type="hidden" name="settings_group" value="code_injection" />
			<input type="hidden" name="redirect_page" value="zorlinq32-code-injection" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '기능 사용', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							<?php esc_html_e( '코드 삽입 기능을 사용합니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="header_code"><?php esc_html_e( '헤더 코드 (<head> 내부)', 'zorlinq32' ); ?></label></th>
					<td>
						<textarea id="header_code" name="header_code" rows="8" class="large-text code" spellcheck="false"><?php echo esc_textarea( $settings['header_code'] ); ?></textarea>
						<p class="zorlinq32-help-text"><?php esc_html_e( '</head> 태그 직전에 삽입됩니다. 메타 태그, 소유권 확인 태그, 폰트/스타일 로딩 등에 적합합니다.', 'zorlinq32' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="body_code"><?php esc_html_e( '바디 코드 (<body> 시작 직후)', 'zorlinq32' ); ?></label></th>
					<td>
						<textarea id="body_code" name="body_code" rows="8" class="large-text code" spellcheck="false"><?php echo esc_textarea( $settings['body_code'] ); ?></textarea>
						<p class="zorlinq32-help-text"><?php esc_html_e( 'Google Tag Manager의 noscript 태그처럼 <body> 시작 직후 위치가 필요한 코드에 사용하세요. 사용 중인 테마가 wp_body_open() 훅을 지원해야 정상 출력됩니다(대부분의 최신 테마는 지원합니다).', 'zorlinq32' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="footer_code"><?php esc_html_e( '푸터 코드 (</body> 직전)', 'zorlinq32' ); ?></label></th>
					<td>
						<textarea id="footer_code" name="footer_code" rows="8" class="large-text code" spellcheck="false"><?php echo esc_textarea( $settings['footer_code'] ); ?></textarea>
						<p class="zorlinq32-help-text"><?php esc_html_e( '분석 스크립트, 채팅 위젯 등 페이지 로딩 속도에 영향을 최소화하고 싶은 코드에 적합합니다.', 'zorlinq32' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( '변경사항 저장', 'zorlinq32' ) ); ?>
		</form>
	</div>
</div>
