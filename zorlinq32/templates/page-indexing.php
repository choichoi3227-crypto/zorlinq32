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
		<h2><?php esc_html_e( '자동 색인 요청', 'zorlinq32' ); ?></h2>
		<p class="zorlinq32-help-text">
			<?php esc_html_e( '글을 발행하거나 수정하면 검색엔진에 새 콘텐츠가 있다는 사실을 자동으로 알립니다. 검색엔진마다 지원하는 방식이 달라 아래처럼 구성됩니다.', 'zorlinq32' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'zorlinq32_save_settings' ); ?>
			<input type="hidden" name="action" value="zorlinq32_save_settings" />
			<input type="hidden" name="settings_group" value="indexing" />
			<input type="hidden" name="redirect_page" value="zorlinq32-indexing" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '기능 사용', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							<?php esc_html_e( '자동 색인 요청 기능을 사용합니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'IndexNow (빙 · 네이버 · Yandex 등)', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="indexnow_enabled" value="1" <?php checked( ! empty( $settings['indexnow_enabled'] ) ); ?> />
							<?php esc_html_e( 'IndexNow 프로토콜로 URL을 제출합니다', 'zorlinq32' ); ?>
						</label>
						<p class="zorlinq32-help-text">
							<?php esc_html_e( 'IndexNow는 마이크로소프트가 주도하고 여러 검색엔진이 채택한 공개 표준입니다. 네이버는 2023년 7월부터 서치어드바이저를 통해 이 프로토콜을 공식 지원하므로, 이 기능 하나로 빙과 네이버에 동시에 색인을 요청합니다. 사이트별 고유 키를 자동 생성해 사용하며 별도 API 키 발급 절차가 없습니다.', 'zorlinq32' ); ?>
						</p>
						<?php if ( ! empty( $indexnow_key ) ) : ?>
							<p class="zorlinq32-help-text">
								<?php esc_html_e( '현재 사이트 키 파일:', 'zorlinq32' ); ?>
								<a href="<?php echo esc_url( home_url( '/' . $indexnow_key . '.txt' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( home_url( '/' . $indexnow_key . '.txt' ) ); ?></a>
								<?php esc_html_e( '(저장 즉시 자동으로 적용되며, 별도로 고정링크 설정을 다시 저장할 필요가 없습니다)', 'zorlinq32' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '요청 시점', 'zorlinq32' ); ?></th>
					<td>
						<label style="display:block;margin-bottom:6px;">
							<input type="checkbox" name="auto_submit_on_publish" value="1" <?php checked( ! empty( $settings['auto_submit_on_publish'] ) ); ?> />
							<?php esc_html_e( '글/페이지를 새로 발행할 때', 'zorlinq32' ); ?>
						</label>
						<label style="display:block;">
							<input type="checkbox" name="auto_submit_on_update" value="1" <?php checked( ! empty( $settings['auto_submit_on_update'] ) ); ?> />
							<?php esc_html_e( '이미 발행된 글/페이지를 수정할 때', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '검색결과 노출 제외', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="noindex_archives" value="1" <?php checked( ! empty( $settings['noindex_archives'] ) ); ?> />
							<?php esc_html_e( '카테고리/태그/날짜 아카이브를 검색결과에서 제외(noindex)합니다', 'zorlinq32' ); ?>
						</label>
						<p class="zorlinq32-help-text">
							<?php esc_html_e( '중복 콘텐츠로 분류되기 쉬운 아카이브 페이지를 검색결과에서 제외해 원본 글이 우선 노출되도록 돕습니다. 글/페이지 본문에는 적용되지 않습니다.', 'zorlinq32' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="custom_robots_rules"><?php esc_html_e( 'robots.txt 추가 규칙', 'zorlinq32' ); ?></label></th>
					<td>
						<textarea id="custom_robots_rules" name="custom_robots_rules" rows="4" class="large-text code"><?php echo esc_textarea( $settings['custom_robots_rules'] ); ?></textarea>
						<p class="zorlinq32-help-text">
							<?php esc_html_e( '기본 규칙(관리자 영역 차단) 뒤에 그대로 추가됩니다. 잘 모르시면 비워두세요.', 'zorlinq32' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( '변경사항 저장', 'zorlinq32' ) ); ?>
		</form>
	</div>

	<div class="zorlinq32-settings-section">
		<h2><?php esc_html_e( 'RSS 피드 주소', 'zorlinq32' ); ?></h2>
		<p class="zorlinq32-help-text">
			<?php esc_html_e( '검색엔진 웹마스터 도구에 등록하거나, 다른 서비스와 연동할 때 바로 사용할 수 있는 주소입니다.', 'zorlinq32' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'RSS 피드', 'zorlinq32' ); ?></th>
				<td>
					<code><?php echo esc_html( $rss_feed_url ); ?></code>
					<a href="<?php echo esc_url( $rss_feed_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-small" style="margin-left:8px;"><?php esc_html_e( '열기', 'zorlinq32' ); ?></a>
					<p class="zorlinq32-help-text"><?php esc_html_e( '워드프레스 코어가 기본 제공하는 RSS 피드로, 최신 글 목록을 구독형으로 제공합니다.', 'zorlinq32' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'robots.txt', 'zorlinq32' ); ?></th>
				<td>
					<code><?php echo esc_html( home_url( '/robots.txt' ) ); ?></code>
					<a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener noreferrer" class="button button-small" style="margin-left:8px;"><?php esc_html_e( '열기', 'zorlinq32' ); ?></a>
				</td>
			</tr>
		</table>
	</div>

	<div class="zorlinq32-settings-section">
		<h2><?php esc_html_e( '참고: 검색엔진 웹마스터 도구 등록', 'zorlinq32' ); ?></h2>
		<p class="zorlinq32-help-text">
			<?php esc_html_e( 'IndexNow와 별개로, 아래 웹마스터 도구에 사이트를 등록해두면 색인 안정성이 높아집니다.', 'zorlinq32' ); ?>
		</p>
		<ul style="list-style:disc;padding-left:20px;">
			<li><?php esc_html_e( '구글 서치콘솔: search.google.com/search-console', 'zorlinq32' ); ?></li>
			<li><?php esc_html_e( '네이버 서치어드바이저: searchadvisor.naver.com', 'zorlinq32' ); ?></li>
			<li><?php esc_html_e( '빙 웹마스터 도구: www.bing.com/webmasters', 'zorlinq32' ); ?></li>
		</ul>
	</div>
</div>
