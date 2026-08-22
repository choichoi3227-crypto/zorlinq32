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

	<div class="zorlinq32-grid">
		<div class="zorlinq32-card">
			<h3><?php esc_html_e( '스토리지 현황', 'zorlinq32' ); ?></h3>
			<?php if ( $usage['available'] ) : ?>
				<div class="zorlinq32-stat"><?php echo esc_html( $usage['used_percent'] ); ?>%</div>
				<div class="zorlinq32-stat-sub">
					<?php
					printf(
						/* translators: 1: 사용중 용량, 2: 잔여 용량, 3: 전체 용량 */
						esc_html__( '사용 중 %1$s · 잔여 %2$s · 전체 %3$s', 'zorlinq32' ),
						esc_html( Zorlinq32_Storage_Monitor::format_bytes( $usage['used_bytes'] ) ),
						esc_html( Zorlinq32_Storage_Monitor::format_bytes( $usage['free_bytes'] ) ),
						esc_html( Zorlinq32_Storage_Monitor::format_bytes( $usage['total_bytes'] ) )
					);
					?>
				</div>
				<div class="zorlinq32-progress">
					<?php
					$bar_class = '';
					if ( $usage['used_percent'] >= 95 ) {
						$bar_class = 'is-critical';
					} elseif ( $usage['used_percent'] >= 80 ) {
						$bar_class = 'is-warning';
					}
					?>
					<div class="zorlinq32-progress-bar <?php echo esc_attr( $bar_class ); ?>" style="width: <?php echo esc_attr( min( 100, $usage['used_percent'] ) ); ?>%;"></div>
				</div>
			<?php else : ?>
				<p><?php esc_html_e( '현재 호스팅 환경에서는 스토리지 정보를 가져올 수 없습니다. (호스팅사 설정에 따라 disk_total_space 함수가 비활성화되어 있을 수 있습니다.)', 'zorlinq32' ); ?></p>
			<?php endif; ?>
			<div class="zorlinq32-inline-actions">
				<button type="button" id="zorlinq32-refresh-storage" class="button"><?php esc_html_e( '새로고침', 'zorlinq32' ); ?></button>
				<button type="button" id="zorlinq32-cleanup-storage" class="button"><?php esc_html_e( '지금 정리', 'zorlinq32' ); ?></button>
				<span id="zorlinq32-storage-unavailable" class="zorlinq32-inline-result error" style="display:none;">
					<?php esc_html_e( '스토리지 정보를 가져올 수 없습니다.', 'zorlinq32' ); ?>
				</span>
				<span id="zorlinq32-cleanup-result" class="zorlinq32-inline-result" style="display:none;"></span>
			</div>
			<p class="zorlinq32-help-text">
				<?php esc_html_e( '"지금 정리"는 만료 여부와 관계없이 캐시 파일을 전량 삭제해 즉시 용량을 확보합니다. 캐시는 다음 방문 시 자동으로 다시 생성되므로 사이트 동작에는 영향이 없습니다.', 'zorlinq32' ); ?>
			</p>
		</div>
	</div>

	<div class="zorlinq32-settings-section">
		<h2><?php esc_html_e( '스토리지 모니터링 설정', 'zorlinq32' ); ?></h2>
		<p class="zorlinq32-help-text">
			<?php esc_html_e( '설정한 임계치를 초과하면 관리자 이메일로 알려드리고, 위험 수준에서는 캐시 파일 등 안전하게 정리 가능한 항목만 자동으로 정리합니다. 사용자가 업로드한 미디어 파일은 절대 삭제하지 않습니다.', 'zorlinq32' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'zorlinq32_save_settings' ); ?>
			<input type="hidden" name="action" value="zorlinq32_save_settings" />
			<input type="hidden" name="settings_group" value="storage_monitor" />
			<input type="hidden" name="redirect_page" value="zorlinq32-storage" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '기능 사용', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							<?php esc_html_e( '스토리지 모니터링을 사용합니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="warning_threshold"><?php esc_html_e( '경고 임계치', 'zorlinq32' ); ?></label></th>
					<td>
						<input type="number" id="warning_threshold" name="warning_threshold" min="1" max="100" value="<?php echo esc_attr( $settings['warning_threshold'] ); ?>" class="small-text" /> %
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="critical_threshold"><?php esc_html_e( '위험 임계치', 'zorlinq32' ); ?></label></th>
					<td>
						<input type="number" id="critical_threshold" name="critical_threshold" min="1" max="100" value="<?php echo esc_attr( $settings['critical_threshold'] ); ?>" class="small-text" /> %
						<p class="zorlinq32-help-text"><?php esc_html_e( '이 수준에 도달하면 안전한 항목에 한해 자동 정리를 수행합니다.', 'zorlinq32' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '이메일 알림', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="notify_admin_email" value="1" <?php checked( ! empty( $settings['notify_admin_email'] ) ); ?> />
							<?php esc_html_e( '임계치 초과 시 관리자 이메일로 알립니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button( __( '변경사항 저장', 'zorlinq32' ) ); ?>
		</form>
	</div>
</div>
