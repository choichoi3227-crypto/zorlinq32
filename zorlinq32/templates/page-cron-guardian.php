<?php
/**
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- 이 파일은 관리자 클래스 메서드 안에서 include 되는 템플릿이며, 여기서 정의되는 변수는 해당 메서드의 지역 스코프에 한정됩니다.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$site_url_escaped = esc_html( site_url( 'wp-cron.php' ) );
$php_path_hint     = esc_html( ABSPATH . 'wp-cron.php' );
?>
<div class="wrap zorlinq32-wrap">
	<?php include ZORLINQ32_DIR . 'templates/partial-header.php'; ?>

	<div class="zorlinq32-grid">
		<div class="zorlinq32-card">
			<h3><?php esc_html_e( '서버 실제 크론', 'zorlinq32' ); ?></h3>
			<span class="zorlinq32-status-pill <?php echo $server_cron_configured ? 'is-on' : 'is-off'; ?>">
				<span class="dot"></span>
				<?php echo $server_cron_configured ? esc_html__( '감지됨 (권장 방식 사용 중)', 'zorlinq32' ) : esc_html__( '미설정 (기본 WP-Cron 사용 중)', 'zorlinq32' ); ?>
			</span>
		</div>
		<div class="zorlinq32-card">
			<h3><?php esc_html_e( '최근 24시간 지연 감지', 'zorlinq32' ); ?></h3>
			<div class="zorlinq32-stat"><?php echo esc_html( isset( $health['missed_count'] ) ? $health['missed_count'] : 0 ); ?></div>
			<div class="zorlinq32-stat-sub">
				<?php
				if ( ! empty( $health['last_checked'] ) ) {
					printf(
						/* translators: %s: 마지막 점검 시각 */
						esc_html__( '마지막 점검: %s', 'zorlinq32' ),
						esc_html( date_i18n( 'Y-m-d H:i', $health['last_checked'] ) )
					);
				} else {
					esc_html_e( '아직 점검 기록이 없습니다.', 'zorlinq32' );
				}
				?>
			</div>
		</div>
		<div class="zorlinq32-card">
			<h3><?php esc_html_e( '예약 발행 워치독', 'zorlinq32' ); ?></h3>
			<span class="zorlinq32-status-pill <?php echo ! empty( $settings['publish_watchdog'] ) ? 'is-on' : 'is-off'; ?>">
				<span class="dot"></span>
				<?php echo ! empty( $settings['publish_watchdog'] ) ? esc_html__( '사용 중 (예약 발행 최후 안전망)', 'zorlinq32' ) : esc_html__( '미사용', 'zorlinq32' ); ?>
			</span>
			<div class="zorlinq32-stat-sub" style="margin-top:8px;">
				<?php
				if ( ! empty( $publish_watchdog_log ) ) {
					printf(
						/* translators: %d: 워치독이 대신 발행 처리한 누적 건수 */
						esc_html__( '지금까지 %d건의 예약 발행을 대신 처리했습니다 (아래 로그 참고)', 'zorlinq32' ),
						count( $publish_watchdog_log )
					);
				} else {
					esc_html_e( '개입한 이력이 없습니다 (정상)', 'zorlinq32' );
				}
				?>
			</div>
		</div>
		<div class="zorlinq32-card">
			<h3><?php esc_html_e( '최근 Self-ping 결과', 'zorlinq32' ); ?></h3>
			<?php if ( empty( $last_ping_result['time'] ) ) : ?>
				<div class="zorlinq32-stat-sub"><?php esc_html_e( '아직 시도 기록이 없습니다.', 'zorlinq32' ); ?></div>
			<?php else : ?>
				<span class="zorlinq32-status-pill <?php echo ! empty( $last_ping_result['success'] ) ? 'is-on' : 'is-off'; ?>">
					<span class="dot"></span>
					<?php echo ! empty( $last_ping_result['success'] ) ? esc_html__( '정상 전송됨', 'zorlinq32' ) : esc_html__( '전송 실패', 'zorlinq32' ); ?>
				</span>
				<div class="zorlinq32-stat-sub" style="margin-top:8px;">
					<?php
					printf(
						/* translators: %s: 마지막 시도 시각 */
						esc_html__( '마지막 시도: %s', 'zorlinq32' ),
						esc_html( date_i18n( 'Y-m-d H:i:s', $last_ping_result['time'] ) )
					);
					?>
					<?php if ( empty( $last_ping_result['success'] ) && ! empty( $last_ping_result['message'] ) ) : ?>
						<br /><span style="color:#d63638;"><?php echo esc_html( $last_ping_result['message'] ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( ! $server_cron_configured ) : ?>
	<div class="zorlinq32-notice-saved" style="border-left-color:#dba617;background:#fff8e5;">
		<strong><?php esc_html_e( '가장 정확한 방법: 서버 실제 크론 연동', 'zorlinq32' ); ?></strong>
		<p style="margin:8px 0 0;">
			<?php esc_html_e( '워드프레스 기본 WP-Cron은 방문자가 있어야만 작동합니다. 예약 작업이 정확한 시각에 실행되길 원하신다면, 호스팅 관리 화면(cPanel, plesk 등) 또는 서버 SSH에서 아래와 같이 실제 크론 작업을 등록하고, wp-config.php에 define(\'DISABLE_WP_CRON\', true); 를 추가하는 것을 권장합니다.', 'zorlinq32' ); ?>
		</p>
		<p style="margin:10px 0 0;">
			<code style="display:block;padding:10px;background:#1d2327;color:#7cd6c8;border-radius:4px;">*/5 * * * * php <?php echo esc_html( $php_path_hint ); ?> > /dev/null 2>&1</code>
		</p>
		<p class="zorlinq32-help-text" style="margin-top:8px;">
			<?php esc_html_e( '위 예시는 5분마다 실행하는 설정입니다. 정확한 등록 방법은 호스팅사 문서를 참고해주세요. 이 방식이 설정되면 아래 self-ping 기능은 자동으로 동작하지 않습니다(중복 방지).', 'zorlinq32' ); ?>
		</p>
	</div>
	<?php endif; ?>

	<div class="zorlinq32-settings-section">
		<h2><?php esc_html_e( 'Cron 안정화 설정', 'zorlinq32' ); ?></h2>
		<p class="zorlinq32-help-text">
			<?php esc_html_e( '워드프레스 아키텍처 특성상 100% 정시 실행을 코드만으로 보장할 수는 없지만, 아래 기능들로 지연/누락을 최소화하고 즉시 감지할 수 있습니다.', 'zorlinq32' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'zorlinq32_save_settings' ); ?>
			<input type="hidden" name="action" value="zorlinq32_save_settings" />
			<input type="hidden" name="settings_group" value="cron_guardian" />
			<input type="hidden" name="redirect_page" value="zorlinq32-cron" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '모듈 사용', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							<?php esc_html_e( 'Cron 안정화 모듈을 사용합니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '지연/누락 모니터링', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="monitor_missed_jobs" value="1" <?php checked( ! empty( $settings['monitor_missed_jobs'] ) ); ?> />
							<?php esc_html_e( '예정 시각보다 15분 이상 지연된 예약 작업을 감지해 기록합니다', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '이메일 알림', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="notify_on_delay" value="1" <?php checked( ! empty( $settings['notify_on_delay'] ) ); ?> />
							<?php esc_html_e( '지연이 감지되면 관리자 이메일로 알립니다 (하루 최대 1회)', 'zorlinq32' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '자동 재시도', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="auto_retry_missed" value="1" <?php checked( ! empty( $settings['auto_retry_missed'] ) ); ?> />
							<?php esc_html_e( '이 플러그인이 등록한 작업(스토리지 점검, 캐시 정리)이 30분 이상 지연되면 즉시 재실행합니다', 'zorlinq32' ); ?>
						</label>
					<p class="zorlinq32-help-text"><?php esc_html_e( '안전을 위해 다른 플러그인의 예약 작업은 강제로 재실행하지 않습니다.', 'zorlinq32' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '예약 발행 워치독 (권장)', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="publish_watchdog" value="1" <?php checked( ! empty( $settings['publish_watchdog'] ) ); ?> />
							<?php esc_html_e( '예약(발행 예정) 글이 정시에 자동 발행되지 않으면 즉시 감지해 직접 발행 처리합니다', 'zorlinq32' ); ?>
						</label>
						<p class="zorlinq32-help-text"><?php esc_html_e( 'self-ping이나 서버 크론 상태와 무관하게 항상 동작하는 최후 안전망입니다. "예약 발행이 되지 않는다"는 문제를 해결하는 핵심 기능이므로 사용을 강력히 권장합니다.', 'zorlinq32' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Self-ping (방문자 없어도 Cron 깨우기)', 'zorlinq32' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_self_ping" value="1" <?php checked( ! empty( $settings['enable_self_ping'] ) ); ?> <?php disabled( $server_cron_configured ); ?> />
							<?php esc_html_e( '방문자가 뜸한 시간에도 정기적으로 스스로 요청을 보내 예약 작업이 실행되도록 합니다', 'zorlinq32' ); ?>
						</label>
						<br /><br />
						<input type="number" name="self_ping_interval_minutes" min="5" max="1440" value="<?php echo esc_attr( $settings['self_ping_interval_minutes'] ); ?>" class="small-text" <?php disabled( $server_cron_configured ); ?> />
						<?php esc_html_e( '분마다 (최소 5분, 서버 부하 방지를 위한 하한선입니다)', 'zorlinq32' ); ?>
						<?php if ( $server_cron_configured ) : ?>
							<p class="zorlinq32-help-text"><?php esc_html_e( '서버 실제 크론이 감지되어 self-ping은 자동으로 비활성화됩니다 (중복 부하 방지).', 'zorlinq32' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php submit_button( __( '변경사항 저장', 'zorlinq32' ) ); ?>
		</form>
	</div>

	<div class="zorlinq32-settings-section" style="margin-top:20px;">
		<h2><?php esc_html_e( '지연 감지 로그', 'zorlinq32' ); ?></h2>
		<?php if ( empty( $missed_log ) ) : ?>
			<div class="zorlinq32-empty-state">
				<span class="dashicons dashicons-yes-alt"></span>
				<p><?php esc_html_e( '감지된 지연 작업이 없습니다.', 'zorlinq32' ); ?></p>
			</div>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( '감지 시각', 'zorlinq32' ); ?></th>
						<th><?php esc_html_e( '작업(훅)', 'zorlinq32' ); ?></th>
						<th><?php esc_html_e( '지연 시간', 'zorlinq32' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $missed_log as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( $entry['detected_at'] ); ?></td>
							<td><code><?php echo esc_html( $entry['hook'] ); ?></code></td>
							<td><?php echo esc_html( gmdate( 'i', $entry['delay_seconds'] ) ); ?><?php esc_html_e( '분', 'zorlinq32' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" style="margin-top:16px;">
				<?php wp_nonce_field( 'zorlinq32_clear_cron_log' ); ?>
				<button type="submit" name="zorlinq32_clear_cron_log" value="1" class="button"><?php esc_html_e( '로그 지우기', 'zorlinq32' ); ?></button>
			</form>
		<?php endif; ?>
	</div>

	<div class="zorlinq32-settings-section" style="margin-top:20px;">
		<h2><?php esc_html_e( '예약 발행 워치독 - 대신 발행 처리한 글', 'zorlinq32' ); ?></h2>
		<p class="zorlinq32-help-text">
			<?php esc_html_e( '아래 목록에 글이 있다면, 해당 글은 예정 시각에 WP-Cron이 트리거되지 않아 이 플러그인이 대신 발행 처리한 것입니다. 목록이 자주 쌓인다면 self-ping 간격을 줄이거나 서버 실제 크론 연동을 권장합니다.', 'zorlinq32' ); ?>
		</p>
		<?php if ( empty( $publish_watchdog_log ) ) : ?>
			<div class="zorlinq32-empty-state">
				<span class="dashicons dashicons-yes-alt"></span>
				<p><?php esc_html_e( '워치독이 개입한 이력이 없습니다 (WP-Cron이 정상적으로 예약 발행을 처리하고 있습니다).', 'zorlinq32' ); ?></p>
			</div>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( '복구 시각', 'zorlinq32' ); ?></th>
						<th><?php esc_html_e( '글 제목', 'zorlinq32' ); ?></th>
						<th><?php esc_html_e( '지연 시간', 'zorlinq32' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $publish_watchdog_log as $zorlinq32_wd_entry ) : ?>
						<tr>
							<td><?php echo esc_html( $zorlinq32_wd_entry['recovered_at'] ); ?></td>
							<td>
								<?php if ( ! empty( $zorlinq32_wd_entry['post_id'] ) ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $zorlinq32_wd_entry['post_id'] ) ); ?>"><?php echo esc_html( $zorlinq32_wd_entry['title'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $zorlinq32_wd_entry['title'] ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (int) round( $zorlinq32_wd_entry['delay_seconds'] / 60 ) ); ?><?php esc_html_e( '분', 'zorlinq32' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>
