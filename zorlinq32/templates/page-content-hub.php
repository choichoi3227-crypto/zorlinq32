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

	<div class="zorlinq32-settings-section">
		<h2><span class="dashicons dashicons-networking" style="color:var(--zlq32-accent);"></span> <?php esc_html_e( '콘텐츠 허브', 'zorlinq32' ); ?></h2>
		<p class="zorlinq32-help-text">
			<?php esc_html_e( '글을 경로(카테고리 대체 분류)별로 관리하고, 관련 글·이전글/다음글을 본문에 자동으로 삽입합니다. 모든 기능은 the_content 필터로 자동 처리되므로 글마다 별도 작업이 필요 없습니다. 관련 글 결과는 캐시되어 서버 부하를 최소화합니다.', 'zorlinq32' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'zorlinq32_save_settings' ); ?>
			<input type="hidden" name="action" value="zorlinq32_save_settings" />
			<input type="hidden" name="settings_group" value="content_hub" />
			<input type="hidden" name="redirect_page" value="zorlinq32-content-hub" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '모듈 사용', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							<?php esc_html_e( '콘텐츠 허브 모듈을 사용합니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '경로 분류', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="path_taxonomy" value="1" <?php checked( ! empty( $settings['path_taxonomy'] ) ); ?> />
							<?php esc_html_e( '글을 "경로"로 분류합니다 (블로그/뉴스/리뷰/가이드/커뮤니티 등, 자유롭게 추가·삭제 가능)', 'zorlinq32' ); ?>
						</label>
						<p class="zorlinq32-help-text">
							<?php esc_html_e( '기본 5개 경로가 최초 1회 자동 생성됩니다. 글 편집 화면 우측의 "경로" 패널에서 지정하며, 관련 글/이전-다음글은 같은 경로를 우선 기준으로 삼습니다.', 'zorlinq32' ); ?>
							<?php if ( function_exists( 'admin_url' ) ) : ?>
								<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=zorlinq32_path&post_type=post' ) ); ?>" target="_blank" rel="noopener">
									<?php esc_html_e( '경로 관리 화면 열기 →', 'zorlinq32' ); ?>
								</a>
							<?php endif; ?>
						</p>
						<?php if ( ! empty( $paths ) ) : ?>
							<p class="zorlinq32-help-text">
								<strong><?php esc_html_e( '현재 등록된 경로:', 'zorlinq32' ); ?></strong>
								<?php echo esc_html( implode( ', ', wp_list_pluck( $paths, 'name' ) ) ); ?>
								(<?php echo count( $paths ); ?><?php esc_html_e( '개', 'zorlinq32' ); ?>)
							</p>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '자동 관련글', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="related_posts" value="1" <?php checked( ! empty( $settings['related_posts'] ) ); ?> />
							<?php esc_html_e( '본문 하단에 관련 글을 그리드로 자동 삽입합니다', 'zorlinq32' ); ?>
						</label>
						<p class="zorlinq32-help-text">
							<?php esc_html_e( '같은 경로(없으면 카테고리)의 글 우선, 부족하면 다른 글로 채워 항상 그리드를 최대한 채웁니다.', 'zorlinq32' ); ?>
						</p>

						<div style="display:flex; gap:20px; flex-wrap:wrap; margin-top:12px;">
							<label style="display:block;">
								<?php esc_html_e( '행 (Rows)', 'zorlinq32' ); ?><br>
								<input type="number" name="related_rows" min="1" max="6"
									value="<?php echo esc_attr( isset( $settings['related_rows'] ) ? $settings['related_rows'] : 2 ); ?>" style="width:70px; margin-top:4px;" />
							</label>
							<label style="display:block;">
								<?php esc_html_e( '열 (Columns)', 'zorlinq32' ); ?><br>
								<input type="number" name="related_columns" min="1" max="6"
									value="<?php echo esc_attr( isset( $settings['related_columns'] ) ? $settings['related_columns'] : 4 ); ?>" style="width:70px; margin-top:4px;" />
							</label>
							<label style="display:block;">
								<?php esc_html_e( '정렬', 'zorlinq32' ); ?><br>
								<?php $order = isset( $settings['related_order_by'] ) ? $settings['related_order_by'] : 'date'; ?>
								<select name="related_order_by" style="margin-top:4px;">
									<option value="date" <?php selected( 'date', $order ); ?>><?php esc_html_e( '최신순', 'zorlinq32' ); ?></option>
									<option value="rand" <?php selected( 'rand', $order ); ?>><?php esc_html_e( '무작위', 'zorlinq32' ); ?></option>
									<option value="title" <?php selected( 'title', $order ); ?>><?php esc_html_e( '제목순', 'zorlinq32' ); ?></option>
								</select>
							</label>
						</div>
						<p class="zorlinq32-help-text">
							<?php esc_html_e( '총 표시 개수 = 행 × 열 (화면이 좁아지면 자동으로 열이 줄어듭니다). 무작위 정렬도 서버 부하가 큰 SQL RAND()를 쓰지 않고 가볍게 처리됩니다.', 'zorlinq32' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '이전글 / 다음글', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="prev_next_nav" value="1" <?php checked( ! empty( $settings['prev_next_nav'] ) ); ?> />
							<?php esc_html_e( '본문 하단에 이전글/다음글 내비게이션을 자동 삽입합니다', 'zorlinq32' ); ?>
						</label>
						<p class="zorlinq32-help-text">
							<?php esc_html_e( '같은 경로 내 발행일 기준으로 계산하며, 같은 경로에 인접 글이 없으면 전체 글 기준으로 자동 대체됩니다.', 'zorlinq32' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( '변경사항 저장', 'zorlinq32' ) ); ?>
		</form>
	</div>
</div>
