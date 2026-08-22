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
		<strong><?php esc_html_e( '작동 방식 안내', 'zorlinq32' ); ?></strong>
		<p style="margin:8px 0 0;">
			<?php esc_html_e( '이 기능은 광고 클릭을 절대 막거나 조작하지 않습니다. 클릭은 항상 정상적으로 애드센스에 전달됩니다. 설정한 기준을 초과하는 클릭이 관찰되면, 해당 방문자에게만 이후 방문 시 광고 슬롯을 비워서 보여주는 방식으로 동작합니다. 이는 게시자가 자신의 사이트에서 콘텐츠 노출 범위를 결정하는 정상적인 권한이며, 클릭을 유도·조작하는 행위가 아닙니다.', 'zorlinq32' ); ?>
		</p>
	</div>

	<div class="zorlinq32-settings-section">
		<h2><?php esc_html_e( '차단 기준 설정', 'zorlinq32' ); ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'zorlinq32_save_settings' ); ?>
			<input type="hidden" name="action" value="zorlinq32_save_settings" />
			<input type="hidden" name="settings_group" value="adsense_protection" />
			<input type="hidden" name="redirect_page" value="zorlinq32-adsense-protection" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '기능 사용', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							<?php esc_html_e( '부정클릭 방지 기능을 사용합니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ad_client_id"><?php esc_html_e( '애드센스 게시자 ID (참고용)', 'zorlinq32' ); ?></label></th>
					<td>
						<input type="text" id="ad_client_id" name="ad_client_id" class="regular-text" value="<?php echo esc_attr( $settings['ad_client_id'] ); ?>" placeholder="<?php echo esc_attr( 'ca-pub-XXXXXXXXXXXXXXXX' ); ?>" />
						<p class="zorlinq32-help-text"><?php esc_html_e( '기록용으로만 사용되며, 광고 코드 자체를 수정하지 않습니다.', 'zorlinq32' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="max_clicks"><?php esc_html_e( '최대 클릭 수', 'zorlinq32' ); ?></label></th>
					<td>
						<input type="number" id="max_clicks" name="max_clicks" min="1" max="100" value="<?php echo esc_attr( $settings['max_clicks'] ); ?>" class="small-text" />
						<p class="zorlinq32-help-text"><?php esc_html_e( '탐지 시간 내 이 횟수를 초과하는 클릭이 관찰되면 자동 차단됩니다.', 'zorlinq32' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="detection_hours"><?php esc_html_e( '탐지 시간 (시간 단위)', 'zorlinq32' ); ?></label></th>
					<td>
						<input type="number" id="detection_hours" name="detection_hours" min="1" max="168" value="<?php echo esc_attr( $settings['detection_hours'] ); ?>" class="small-text" />
						<p class="zorlinq32-help-text"><?php esc_html_e( '이 시간(최대 168시간=7일) 이내의 클릭만 집계하여 최대 클릭 수 초과 여부를 판단합니다.', 'zorlinq32' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="block_duration_days"><?php esc_html_e( '차단 해제 기간 (일)', 'zorlinq32' ); ?></label></th>
					<td>
						<input type="number" id="block_duration_days" name="block_duration_days" min="1" max="365" value="<?php echo esc_attr( $settings['block_duration_days'] ); ?>" class="small-text" />
						<p class="zorlinq32-help-text"><?php esc_html_e( '자동 차단된 방문자는 이 기간이 지나면 자동으로 차단이 해제됩니다.', 'zorlinq32' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '차단 국가', 'zorlinq32' ); ?></th>
					<td>
						<?php if ( empty( $using_cloudflare_header ) ) : ?>
							<p class="zorlinq32-help-text" style="margin-top:0;color:#8a5300;">
								<?php esc_html_e( '현재 Cloudflare를 통해 접속하는 요청이 감지되지 않았습니다. 국가 판별은 사이트에 내장된 주요 국가의 대표 IP 대역을 기준으로 한 근사치이며, 정확도가 제한적일 수 있습니다. Cloudflare(무료 플랜 포함)를 리버스 프록시로 사용하면 훨씬 정확한 국가 판별이 가능합니다.', 'zorlinq32' ); ?>
							</p>
						<?php else : ?>
							<p class="zorlinq32-help-text" style="margin-top:0;color:#00a32a;">
								<?php esc_html_e( 'Cloudflare의 국가 판별 정보가 감지되어, 이를 우선적으로 사용합니다 (높은 정확도).', 'zorlinq32' ); ?>
							</p>
						<?php endif; ?>
						<div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(140px, 1fr));gap:6px;margin-top:10px;max-width:600px;">
							<?php foreach ( $country_options as $zorlinq32_code => $zorlinq32_label ) : ?>
								<label>
									<input type="checkbox" name="blocked_countries[]" value="<?php echo esc_attr( $zorlinq32_code ); ?>" <?php checked( in_array( $zorlinq32_code, $settings['blocked_countries'], true ) ); ?> />
									<?php echo esc_html( $zorlinq32_label ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="new_blocked_ip"><?php esc_html_e( '차단 IP 추가', 'zorlinq32' ); ?></label></th>
					<td>
						<input type="text" id="new_blocked_ip" name="new_blocked_ip" class="regular-text" placeholder="<?php echo esc_attr( '예: 203.0.113.10' ); ?>" />
						<p class="zorlinq32-help-text"><?php esc_html_e( '저장 시 목록에 추가됩니다. 기존에 추가한 IP는 아래 목록에서 개별적으로 해제할 수 있습니다.', 'zorlinq32' ); ?></p>
						<?php if ( ! empty( $settings['blocked_ips'] ) ) : ?>
							<table class="widefat striped" style="max-width:500px;margin-top:8px;">
								<tbody>
									<?php foreach ( $settings['blocked_ips'] as $zorlinq32_blocked_ip ) : ?>
										<tr>
											<td><?php echo esc_html( $zorlinq32_blocked_ip ); ?></td>
											<td style="width:100px;">
												<button type="button" class="button zorlinq32-remove-blocked-ip" data-ip="<?php echo esc_attr( $zorlinq32_blocked_ip ); ?>"><?php esc_html_e( '해제', 'zorlinq32' ); ?></button>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php submit_button( __( '변경사항 저장', 'zorlinq32' ) ); ?>
		</form>
	</div>

	<div class="zorlinq32-settings-section">
		<h2><?php esc_html_e( '현재 자동 차단된 방문자', 'zorlinq32' ); ?></h2>
		<p class="zorlinq32-help-text">
			<?php esc_html_e( '개인정보 보호를 위해 IP 원문은 저장하지 않으며, 해시값만 표시됩니다. 특정 방문자를 즉시 차단 해제하려면 아래 버튼을 사용하되, IP 원문을 알고 있어야 합니다.', 'zorlinq32' ); ?>
		</p>
		<?php if ( empty( $active_blocks ) ) : ?>
			<p class="zorlinq32-help-text"><?php esc_html_e( '현재 자동 차단된 방문자가 없습니다.', 'zorlinq32' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( '식별자 (해시)', 'zorlinq32' ); ?></th>
						<th><?php esc_html_e( '차단 시각', 'zorlinq32' ); ?></th>
						<th><?php esc_html_e( '해제 예정', 'zorlinq32' ); ?></th>
						<th><?php esc_html_e( '수동 해제', 'zorlinq32' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $active_blocks as $zorlinq32_block ) : ?>
						<tr>
							<td><code><?php echo esc_html( substr( $zorlinq32_block['ip_hash'], 0, 16 ) . '...' ); ?></code></td>
							<td><?php echo esc_html( $zorlinq32_block['blocked_at'] ); ?></td>
							<td><?php echo esc_html( $zorlinq32_block['expires_at'] ? $zorlinq32_block['expires_at'] : __( '수동 차단(자동 해제 없음)', 'zorlinq32' ) ); ?></td>
							<td>
								<p class="zorlinq32-help-text" style="margin:0;"><?php esc_html_e( 'IP 원문을 알고 있다면 위 "차단 IP" 목록 대신 여기 입력해 즉시 해제할 수 있습니다.', 'zorlinq32' ); ?></p>
								<input type="text" class="zorlinq32-unblock-ip-input" placeholder="<?php echo esc_attr( 'IP 입력' ); ?>" style="width:140px;" />
								<button type="button" class="button zorlinq32-unblock-visitor-btn"><?php esc_html_e( '해제', 'zorlinq32' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<div class="zorlinq32-settings-section">
		<h2><?php esc_html_e( 'Cloudflare / AWS 연동 — 광고 소거 정확도 향상 전용', 'zorlinq32' ); ?></h2>
		<p class="zorlinq32-help-text">
			<strong><?php esc_html_e( '이 기능은 사이트 접속을 차단하지 않습니다.', 'zorlinq32' ); ?></strong>
			<?php esc_html_e( 'Cloudflare 또는 AWS(CloudFront)가 검증해 알려주는 접속 국가·IP 정보를 활용해, 부정클릭이 의심되는 방문자에게 "광고 영역만" 정확하게 숨기기 위한 판단 근거로만 사용됩니다. 어떤 경우에도 방문자는 사이트 본문과 페이지 전체를 정상적으로 열람할 수 있으며, 서버 방화벽이나 .htaccess 등에 접속 차단 규칙을 만들지 않습니다.', 'zorlinq32' ); ?>
		</p>
		<p class="zorlinq32-help-text">
			<?php esc_html_e( '대부분의 워드프레스 호스팅은 이용자에게 Cloudflare나 AWS의 API 키를 발급해주지 않으므로, 이 기능은 API 키 없이 동작합니다. 실제로 연결된 경로가 자동으로 감지됩니다.', 'zorlinq32' ); ?>
		</p>

		<?php
		// [애드센스 보호] 두 경로 중 실제로 "지금 사용 중인" 것이 무엇인지 하나의 상태로 명확히 보여줍니다.
		$zorlinq32_active_edge = $using_cloudflare_header ? 'cloudflare' : ( $aws_environment_detected ? 'aws' : 'none' );
		?>

		<div class="zorlinq32-edge-status-card is-<?php echo esc_attr( $zorlinq32_active_edge ); ?>">
			<?php if ( 'cloudflare' === $zorlinq32_active_edge ) : ?>
				<span class="dashicons dashicons-yes-alt"></span>
				<div>
					<strong><?php esc_html_e( '현재 Cloudflare의 정보로 국가·IP 판별 정확도가 향상되고 있습니다.', 'zorlinq32' ); ?></strong>
					<p><?php esc_html_e( '광고 소거 판단(국가 차단 목록 대조, 동일 방문자 식별)에 Cloudflare의 검증된 정보를 사용합니다. 별도 설정이 필요 없습니다.', 'zorlinq32' ); ?></p>
				</div>
			<?php elseif ( 'aws' === $zorlinq32_active_edge ) : ?>
				<span class="dashicons dashicons-yes-alt"></span>
				<div>
					<strong><?php esc_html_e( '현재 AWS(CloudFront)의 정보로 IP 판별 정확도가 향상되고 있습니다.', 'zorlinq32' ); ?></strong>
					<p><?php esc_html_e( '국가 판별까지 Cloudflare와 동일한 수준으로 사용하려면, AWS 콘솔의 CloudFront 배포 설정에서 Origin Request Policy에 "CloudFront-Viewer-Country" 헤더를 포함하도록 켜주세요(무료, 코드 작성 불필요).', 'zorlinq32' ); ?></p>
				</div>
			<?php else : ?>
				<span class="dashicons dashicons-info-outline"></span>
				<div>
					<strong><?php esc_html_e( 'Cloudflare 또는 AWS 연결이 감지되지 않았습니다.', 'zorlinq32' ); ?></strong>
					<p><?php esc_html_e( '서버가 직접 관측한 접속 정보만으로 광고 소거 판단이 이루어지며, 국가·IP 판별의 정확도가 다소 낮아질 수 있습니다. 이 사이트가 이미 Cloudflare 또는 AWS CloudFront 뒤에 있다면, 캐시나 프록시 설정에 따라 헤더가 오리진까지 전달되지 않고 있을 수 있습니다.', 'zorlinq32' ); ?></p>
				</div>
			<?php endif; ?>
		</div>

		<table class="zorlinq32-edge-compare">
			<tr>
				<th></th>
				<th><?php esc_html_e( 'Cloudflare', 'zorlinq32' ); ?></th>
				<th><?php esc_html_e( 'AWS (CloudFront)', 'zorlinq32' ); ?></th>
			</tr>
			<tr>
				<td><?php esc_html_e( '연결 상태', 'zorlinq32' ); ?></td>
				<td><?php echo $using_cloudflare_header ? '<span class="zorlinq32-status-pill is-on"><span class="dot"></span>' . esc_html__( '감지됨', 'zorlinq32' ) . '</span>' : '<span class="zorlinq32-status-pill is-off"><span class="dot"></span>' . esc_html__( '미감지', 'zorlinq32' ) . '</span>'; ?></td>
				<td><?php echo $aws_environment_detected ? '<span class="zorlinq32-status-pill is-on"><span class="dot"></span>' . esc_html__( '감지됨', 'zorlinq32' ) . '</span>' : '<span class="zorlinq32-status-pill is-off"><span class="dot"></span>' . esc_html__( '미감지', 'zorlinq32' ) . '</span>'; ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( '용도', 'zorlinq32' ); ?></td>
				<td colspan="2"><?php esc_html_e( '광고 소거 판단 근거로만 사용 (접속 차단 없음)', 'zorlinq32' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( '설정 필요 여부', 'zorlinq32' ); ?></td>
				<td><?php esc_html_e( '없음 (자동)', 'zorlinq32' ); ?></td>
				<td><?php esc_html_e( '국가 판별은 CloudFront 헤더 설정 1회 필요', 'zorlinq32' ); ?></td>
			</tr>
		</table>
	</div>
</div>
