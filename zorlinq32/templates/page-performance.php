<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap zorlinq32-wrap">
	<?php include ZORLINQ32_DIR . 'templates/partial-header.php'; ?>

	<div class="zorlinq32-settings-section">
		<h2><?php esc_html_e( '성능 최적화', 'zorlinq32' ); ?></h2>
		<p class="zorlinq32-help-text">
			<?php esc_html_e( '필요한 항목만 선택적으로 켜세요. 각 기능은 서로 독립적으로 동작하며, 사이트에 영향을 주지 않는 범위 내에서 서버 부하를 줄입니다.', 'zorlinq32' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'zorlinq32_save_settings' ); ?>
			<input type="hidden" name="action" value="zorlinq32_save_settings" />
			<input type="hidden" name="settings_group" value="performance" />
			<input type="hidden" name="redirect_page" value="zorlinq32-performance" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '모듈 사용', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							<?php esc_html_e( '성능 최적화 모듈을 사용합니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '리비전 개수 제한', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="limit_revisions" value="1" <?php checked( ! empty( $settings['limit_revisions'] ) ); ?> />
							<?php esc_html_e( '글마다 저장되는 리비전 개수를 제한해 DB 용량 증가를 줄입니다', 'zorlinq32' ); ?>
						</label>
						<br /><br />
						<input type="number" name="revisions_limit" min="1" max="50" value="<?php echo esc_attr( $settings['revisions_limit'] ); ?>" class="small-text" />
						<?php esc_html_e( '개까지 보관', 'zorlinq32' ); ?>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '자동저장 간격 연장', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="extend_autosave_interval" value="1" <?php checked( ! empty( $settings['extend_autosave_interval'] ) ); ?> />
							<?php esc_html_e( '글 작성 중 자동저장 빈도를 줄여 DB 쓰기 횟수를 줄입니다', 'zorlinq32' ); ?>
						</label>
						<br /><br />
						<input type="number" name="autosave_interval_seconds" min="60" max="600" value="<?php echo esc_attr( $settings['autosave_interval_seconds'] ); ?>" class="small-text" />
						<?php esc_html_e( '초마다 (최소 60초)', 'zorlinq32' ); ?>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '하트비트 API 제한', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="limit_heartbeat" value="1" <?php checked( ! empty( $settings['limit_heartbeat'] ) ); ?> />
							<?php esc_html_e( '관리자 화면의 백그라운드 통신 빈도를 줄이고, 프론트엔드에서는 비활성화합니다', 'zorlinq32' ); ?>
						</label>
						<br /><br />
						<input type="number" name="heartbeat_interval_seconds" min="30" max="300" value="<?php echo esc_attr( $settings['heartbeat_interval_seconds'] ); ?>" class="small-text" />
						<?php esc_html_e( '초마다 (최소 30초)', 'zorlinq32' ); ?>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '이미지 지연 로딩', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="lazy_load_images" value="1" <?php checked( ! empty( $settings['lazy_load_images'] ) ); ?> />
							<?php esc_html_e( '화면에 보이지 않는 이미지의 로딩을 지연시켜 초기 로딩 속도를 높입니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '이모지 스크립트 제거', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="disable_emojis" value="1" <?php checked( ! empty( $settings['disable_emojis'] ) ); ?> />
							<?php esc_html_e( '워드프레스 기본 이모지 호환 스크립트를 제거합니다 (최신 브라우저는 대부분 자체 지원)', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '임베드 스크립트 제거', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="disable_embeds" value="1" <?php checked( ! empty( $settings['disable_embeds'] ) ); ?> />
							<?php esc_html_e( 'oEmbed 기능을 사용하지 않는다면 관련 스크립트를 제거합니다', 'zorlinq32' ); ?>
						</label>
						<p class="zorlinq32-help-text"><?php esc_html_e( '유튜브/트위터 링크를 붙여넣어 자동 임베드하는 기능을 쓰고 있다면 켜지 마세요.', 'zorlinq32' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '버전 쿼리스트링 제거', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="remove_version_query_strings" value="1" <?php checked( ! empty( $settings['remove_version_query_strings'] ) ); ?> />
							<?php esc_html_e( 'CSS/JS 파일 URL의 ?ver= 값을 제거해 일부 CDN의 캐시 효율을 높입니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button( __( '변경사항 저장', 'zorlinq32' ) ); ?>
		</form>
	</div>
</div>
