<?php
/**
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- 이 파일은 관리자 클래스 메서드 안에서 include 되는 템플릿이며, 여기서 정의되는 변수는 해당 메서드의 지역 스코프에 한정됩니다.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$image_size_options = array(
	'medium_large' => __( '중간-큰 크기 (medium_large)', 'zorlinq32' ),
	'1536x1536'    => __( '1536x1536', 'zorlinq32' ),
	'2048x2048'    => __( '2048x2048', 'zorlinq32' ),
);
$selected_sizes = isset( $settings['disabled_image_sizes'] ) && is_array( $settings['disabled_image_sizes'] ) ? $settings['disabled_image_sizes'] : array();
?>
<div class="wrap zorlinq32-wrap">
	<?php include ZORLINQ32_DIR . 'templates/partial-header.php'; ?>

	<div class="zorlinq32-settings-section">
		<h2><?php esc_html_e( '콘텐츠 최적화', 'zorlinq32' ); ?></h2>
		<p class="zorlinq32-help-text">
			<?php esc_html_e( '댓글 스팸 방지, 미디어/검색 최적화 기능입니다.', 'zorlinq32' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'zorlinq32_save_settings' ); ?>
			<input type="hidden" name="action" value="zorlinq32_save_settings" />
			<input type="hidden" name="settings_group" value="content_optimizer" />
			<input type="hidden" name="redirect_page" value="zorlinq32-content" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '모듈 사용', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							<?php esc_html_e( '콘텐츠 최적화 모듈을 사용합니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '댓글 스팸 방지', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="comment_spam_protection" value="1" <?php checked( ! empty( $settings['comment_spam_protection'] ) ); ?> />
							<?php esc_html_e( '보이지 않는 허니팟 필드를 추가해 자동 스팸 봇을 걸러냅니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '불필요한 이미지 크기 생성 안함', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="disable_extra_image_sizes" value="1" <?php checked( ! empty( $settings['disable_extra_image_sizes'] ) ); ?> />
							<?php esc_html_e( '업로드 시 아래에서 선택한 크기의 이미지를 추가로 생성하지 않습니다', 'zorlinq32' ); ?>
						</label>
						<br /><br />
						<?php foreach ( $image_size_options as $key => $label ) : ?>
							<label style="display:block;margin-bottom:4px;">
								<input type="checkbox" name="disabled_image_sizes[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected_sizes, true ) ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '검색 결과 범위 제한', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="limit_search_post_types" value="1" <?php checked( ! empty( $settings['limit_search_post_types'] ) ); ?> />
							<?php esc_html_e( '검색 결과에 글/페이지만 포함되도록 제한합니다 (첨부파일 등 제외)', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button( __( '변경사항 저장', 'zorlinq32' ) ); ?>
		</form>
	</div>
</div>
