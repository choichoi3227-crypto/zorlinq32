<?php
/**
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- 이 파일은 관리자 클래스 메서드 안에서 include 되는 템플릿이며, 여기서 정의되는 변수는 해당 메서드의 지역 스코프에 한정됩니다.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$zorlinq32_range_labels = array(
	'today'    => __( '오늘', 'zorlinq32' ),
	'7days'    => __( '주간', 'zorlinq32' ),
	'30days'   => __( '월간', 'zorlinq32' ),
	'12months' => __( '연간', 'zorlinq32' ),
);
?>
<div class="wrap zorlinq32-wrap">
	<?php include ZORLINQ32_DIR . 'templates/partial-header.php'; ?>

	<?php if ( empty( $settings['enabled'] ) ) : ?>
		<div class="zorlinq32-settings-section">
			<h2><?php esc_html_e( '애널리틱스', 'zorlinq32' ); ?></h2>
			<p class="zorlinq32-help-text">
				<?php esc_html_e( '이 기능은 외부 서비스 없이, 워드프레스 표준 방식(자체 DB 테이블)으로 방문 통계를 수집합니다. 아래에서 기능을 켜주세요.', 'zorlinq32' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'zorlinq32_save_settings' ); ?>
				<input type="hidden" name="action" value="zorlinq32_save_settings" />
				<input type="hidden" name="settings_group" value="analytics" />
				<input type="hidden" name="redirect_page" value="zorlinq32-analytics" />
				<input type="hidden" name="enabled" value="1" />
				<?php submit_button( __( '애널리틱스 사용 시작', 'zorlinq32' ) ); ?>
			</form>
		</div>
	<?php else : ?>

		<div class="zorlinq32-settings-section">
			<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
				<h2 style="margin:0;"><?php esc_html_e( '방문 추이', 'zorlinq32' ); ?></h2>
				<div class="zorlinq32-range-filter">
					<?php foreach ( $zorlinq32_range_labels as $zorlinq32_range_key => $zorlinq32_range_label ) : ?>
						<a
							href="<?php echo esc_url( admin_url( 'admin.php?page=zorlinq32-analytics&range=' . $zorlinq32_range_key ) ); ?>"
							class="button <?php echo ( $range === $zorlinq32_range_key ) ? 'button-primary' : ''; ?>"
						><?php echo esc_html( $zorlinq32_range_label ); ?></a>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="zorlinq32-analytics-summary" style="display:flex;gap:32px;flex-wrap:wrap;margin:16px 0 4px;">
				<div>
					<p style="font-size:32px;font-weight:700;margin:0;line-height:1.1;"><?php echo esc_html( number_format_i18n( $total['visitors'] ) ); ?></p>
					<p class="zorlinq32-help-text" style="margin-top:2px;"><?php esc_html_e( '방문자수 (동일 방문자 중복 제거)', 'zorlinq32' ); ?></p>
				</div>
				<div>
					<p style="font-size:32px;font-weight:700;margin:0;line-height:1.1;color:#646970;"><?php echo esc_html( number_format_i18n( $total['pageviews'] ) ); ?></p>
					<p class="zorlinq32-help-text" style="margin-top:2px;"><?php esc_html_e( '조회수 (페이지뷰)', 'zorlinq32' ); ?></p>
				</div>
			</div>
			<p class="zorlinq32-help-text" style="margin-top:0;"><?php esc_html_e( '선택한 기간 집계 (관리자·로그인 사용자·봇 추정 트래픽·미리보기 요청 제외)', 'zorlinq32' ); ?></p>

			<div id="zorlinq32-trend-chart" class="zorlinq32-trend-chart" style="margin-top:16px;min-height:220px;position:relative;"></div>
			<script type="application/json" id="zorlinq32-trend-data"><?php echo wp_json_encode( $trend ); ?></script>
		</div>

		<div class="zorlinq32-settings-section">
			<h2><?php esc_html_e( '관리자 트래픽 제외', 'zorlinq32' ); ?></h2>
			<p class="zorlinq32-help-text">
				<?php esc_html_e( '로그인 상태의 관리자는 항상 제외됩니다. 아래를 켜두면 이 브라우저로 관리자 계정에 로그인한 적이 있는지(워드프레스 식별 쿠키 기준)를 함께 확인해, 로그아웃 상태로 둘러보거나 모바일 데이터로 IP가 바뀌어도 안정적으로 관리자 본인의 방문을 통계에서 제외합니다.', 'zorlinq32' ); ?>
			</p>
			<?php if ( ! empty( $settings['exclude_admin_ips'] ) && ! empty( $settings['excluded_ip_list'] ) ) : ?>
				<p class="zorlinq32-help-text">
					<?php
					printf(
						/* translators: %s: 제외 중인 IP 개수 */
						esc_html__( '참고로 이전 방식으로 기억해둔 IP도 %s개 함께 제외 목록에 남아있습니다.', 'zorlinq32' ),
						esc_html( number_format_i18n( count( $settings['excluded_ip_list'] ) ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>

		<div class="zorlinq32-settings-section">
			<h2><?php esc_html_e( '유입 채널', 'zorlinq32' ); ?></h2>

			<?php if ( 0 === (int) $channel['total'] ) : ?>
				<p class="zorlinq32-help-text"><?php esc_html_e( '선택한 기간에 수집된 방문 데이터가 없습니다.', 'zorlinq32' ); ?></p>
			<?php else : ?>
				<h3 style="margin-bottom:8px;"><?php esc_html_e( '자연유입 (검색엔진)', 'zorlinq32' ); ?></h3>
				<?php if ( empty( $channel['organic'] ) ) : ?>
					<p class="zorlinq32-help-text"><?php esc_html_e( '자연유입 기록이 없습니다.', 'zorlinq32' ); ?></p>
				<?php else : ?>
					<ul style="list-style:none;padding-left:0;margin:0 0 20px;">
						<?php foreach ( $channel['organic'] as $zorlinq32_engine_key => $zorlinq32_engine_count ) : ?>
							<?php
							$zorlinq32_engine_display = Zorlinq32_Referrer_Classifier::get_engine_display( $zorlinq32_engine_key );
							$zorlinq32_engine_percent = $channel['total'] > 0 ? round( ( $zorlinq32_engine_count / $channel['total'] ) * 100, 1 ) : 0;
							?>
							<li style="padding:6px 0;">
								<div style="display:flex;align-items:center;gap:8px;margin-bottom:3px;">
									<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr( $zorlinq32_engine_display['color'] ); ?>;flex-shrink:0;"></span>
									<span style="min-width:80px;"><?php echo esc_html( $zorlinq32_engine_display['label'] ); ?></span>
									<strong style="margin-left:auto;"><?php echo esc_html( number_format_i18n( $zorlinq32_engine_count ) ); ?></strong>
									<span class="zorlinq32-stat-sub" style="min-width:42px;text-align:right;"><?php echo esc_html( $zorlinq32_engine_percent ); ?>%</span>
								</div>
								<div class="zorlinq32-progress" style="margin-top:0;">
									<div class="zorlinq32-progress-bar" style="width:<?php echo esc_attr( $zorlinq32_engine_percent ); ?>%;background:<?php echo esc_attr( $zorlinq32_engine_display['color'] ); ?>;"></div>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<h3 style="margin-bottom:8px;"><?php esc_html_e( '외부유입 (알려진 사이트)', 'zorlinq32' ); ?></h3>
				<?php if ( empty( $channel['referral'] ) ) : ?>
					<p class="zorlinq32-help-text"><?php esc_html_e( '외부유입 기록이 없습니다.', 'zorlinq32' ); ?></p>
				<?php else : ?>
					<ul style="list-style:none;padding-left:0;margin:0 0 20px;">
						<?php foreach ( $channel['referral'] as $zorlinq32_source => $zorlinq32_source_count ) : ?>
							<?php $zorlinq32_source_percent = $channel['total'] > 0 ? round( ( $zorlinq32_source_count / $channel['total'] ) * 100, 1 ) : 0; ?>
							<li style="padding:6px 0;">
								<div style="display:flex;align-items:center;gap:8px;margin-bottom:3px;">
									<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#8c8f94;flex-shrink:0;"></span>
									<span style="min-width:140px;"><?php echo esc_html( $zorlinq32_source ); ?></span>
									<strong style="margin-left:auto;"><?php echo esc_html( number_format_i18n( $zorlinq32_source_count ) ); ?></strong>
									<span class="zorlinq32-stat-sub" style="min-width:42px;text-align:right;"><?php echo esc_html( $zorlinq32_source_percent ); ?>%</span>
								</div>
								<div class="zorlinq32-progress" style="margin-top:0;">
									<div class="zorlinq32-progress-bar" style="width:<?php echo esc_attr( $zorlinq32_source_percent ); ?>%;background:#8c8f94;"></div>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<h3 style="margin-bottom:8px;"><?php esc_html_e( '기타 (직접 접속 등)', 'zorlinq32' ); ?></h3>
				<?php $zorlinq32_direct_percent = $channel['total'] > 0 ? round( ( $channel['direct'] / $channel['total'] ) * 100, 1 ) : 0; ?>
				<div style="display:flex;align-items:center;gap:8px;margin-bottom:3px;">
					<strong><?php echo esc_html( number_format_i18n( $channel['direct'] ) ); ?></strong>
					<span class="zorlinq32-stat-sub"><?php echo esc_html( $zorlinq32_direct_percent ); ?>%</span>
				</div>
				<div class="zorlinq32-progress" style="margin-top:0;max-width:260px;">
					<div class="zorlinq32-progress-bar" style="width:<?php echo esc_attr( $zorlinq32_direct_percent ); ?>%;background:#4f46e5;"></div>
				</div>
			<?php endif; ?>
		</div>

		<div class="zorlinq32-settings-section">
			<h2><?php esc_html_e( '유입 검색 키워드', 'zorlinq32' ); ?></h2>
			<p class="zorlinq32-help-text">
				<?php esc_html_e( '구글과 빙은 2013년경부터 보안 정책상 검색 키워드를 사이트에 전달하지 않습니다. 아래 목록은 일부 조건에서 확인 가능했던 키워드만 표시하며, 전체 자연유입 중 극히 일부에 불과할 수 있습니다.', 'zorlinq32' ); ?>
			</p>
			<?php if ( empty( $keywords ) ) : ?>
				<p class="zorlinq32-help-text"><?php esc_html_e( '확인 가능한 검색 키워드가 없습니다.', 'zorlinq32' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( '키워드', 'zorlinq32' ); ?></th>
							<th><?php esc_html_e( '검색엔진', 'zorlinq32' ); ?></th>
							<th><?php esc_html_e( '유입수', 'zorlinq32' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $keywords as $zorlinq32_keyword_row ) : ?>
							<?php $zorlinq32_kw_engine = Zorlinq32_Referrer_Classifier::get_engine_display( $zorlinq32_keyword_row['referrer_source'] ); ?>
							<tr>
								<td><?php echo esc_html( $zorlinq32_keyword_row['keyword'] ); ?></td>
								<td>
									<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr( $zorlinq32_kw_engine['color'] ); ?>;margin-right:6px;"></span>
									<?php echo esc_html( $zorlinq32_kw_engine['label'] ); ?>
								</td>
								<td><?php echo esc_html( number_format_i18n( (int) $zorlinq32_keyword_row['count'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="zorlinq32-settings-section">
			<h2><?php esc_html_e( '인기글', 'zorlinq32' ); ?></h2>
			<?php if ( empty( $top_posts ) ) : ?>
				<p class="zorlinq32-help-text"><?php esc_html_e( '선택한 기간에 방문 기록이 있는 글이 없습니다.', 'zorlinq32' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( '순위', 'zorlinq32' ); ?></th>
							<th><?php esc_html_e( '제목', 'zorlinq32' ); ?></th>
							<th><?php esc_html_e( '방문수', 'zorlinq32' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $top_posts as $zorlinq32_rank => $zorlinq32_post_row ) : ?>
							<tr>
								<td><?php echo esc_html( $zorlinq32_rank + 1 ); ?></td>
								<td><a href="<?php echo esc_url( $zorlinq32_post_row['link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $zorlinq32_post_row['title'] ); ?></a></td>
								<td><?php echo esc_html( number_format_i18n( $zorlinq32_post_row['count'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="zorlinq32-settings-section">
			<h2><?php esc_html_e( '설정', 'zorlinq32' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'zorlinq32_save_settings' ); ?>
				<input type="hidden" name="action" value="zorlinq32_save_settings" />
				<input type="hidden" name="settings_group" value="analytics" />
				<input type="hidden" name="redirect_page" value="zorlinq32-analytics" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( '기능 사용', 'zorlinq32' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
								<?php esc_html_e( '애널리틱스 수집을 사용합니다', 'zorlinq32' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '관리자 트래픽 제외', 'zorlinq32' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="exclude_admin_ips" value="1" <?php checked( ! empty( $settings['exclude_admin_ips'] ) ); ?> />
								<?php esc_html_e( '관리자 계정으로 로그인한 적이 있는 브라우저의 방문을 통계에서 제외합니다', 'zorlinq32' ); ?>
							</label>
							<p class="zorlinq32-help-text"><?php esc_html_e( '워드프레스가 로그인 시 남기는 식별 쿠키를 기준으로 판단하므로, IP가 자주 바뀌는 모바일 데이터 환경에서도 안정적으로 관리자 본인의 방문을 걸러냅니다.', 'zorlinq32' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="retention_days"><?php esc_html_e( '데이터 보관 기간 (일)', 'zorlinq32' ); ?></label></th>
						<td>
							<input type="number" id="retention_days" name="retention_days" min="30" max="1825" value="<?php echo esc_attr( $settings['retention_days'] ); ?>" class="small-text" />
							<p class="zorlinq32-help-text"><?php esc_html_e( '이 기간보다 오래된 방문 기록은 매일 자동으로 삭제됩니다 (30~1825일).', 'zorlinq32' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cache_minutes"><?php esc_html_e( '통계 캐시 시간', 'zorlinq32' ); ?></label></th>
						<td>
							<select id="cache_minutes" name="cache_minutes">
								<?php
								$zorlinq32_cache_options = array(
									1  => __( '1분 (거의 실시간, 서버 부하 가장 큼)', 'zorlinq32' ),
									5  => __( '5분 (권장)', 'zorlinq32' ),
									10 => __( '10분', 'zorlinq32' ),
									30 => __( '30분', 'zorlinq32' ),
									60 => __( '60분 (서버 부하 가장 적음)', 'zorlinq32' ),
								);
								$zorlinq32_current_cache_minutes = isset( $settings['cache_minutes'] ) ? (int) $settings['cache_minutes'] : 5;
								foreach ( $zorlinq32_cache_options as $zorlinq32_minutes => $zorlinq32_label ) :
									?>
									<option value="<?php echo esc_attr( $zorlinq32_minutes ); ?>" <?php selected( $zorlinq32_current_cache_minutes, $zorlinq32_minutes ); ?>><?php echo esc_html( $zorlinq32_label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="zorlinq32-help-text"><?php esc_html_e( '통계 화면의 그래프·순위 데이터를 얼마나 자주 새로 계산할지 정합니다. 시간이 짧을수록 통계가 더 실시간에 가깝지만 데이터베이스 조회가 잦아져 서버 부하가 커지고, 길수록 부하는 줄지만 최신 방문이 반영되기까지 지연이 생깁니다.', 'zorlinq32' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( '변경사항 저장', 'zorlinq32' ) ); ?>
			</form>
		</div>

		<div class="zorlinq32-settings-section">
			<h2><?php esc_html_e( '통계 초기화', 'zorlinq32' ); ?></h2>
			<p class="zorlinq32-help-text">
				<?php esc_html_e( '지금까지 수집된 모든 방문 기록을 영구적으로 삭제합니다. 이 작업은 되돌릴 수 없으며, 플러그인 기능 자체는 계속 사용할 수 있습니다(설정은 유지되고 데이터만 비워집니다).', 'zorlinq32' ); ?>
			</p>
			<button type="button" class="button" id="zorlinq32-reset-analytics-btn" style="color:#d63638;border-color:#d63638;">
				<?php esc_html_e( '통계 초기화', 'zorlinq32' ); ?>
			</button>
			<span id="zorlinq32-reset-analytics-result" style="margin-left:10px;font-size:12px;"></span>
		</div>

	<?php endif; ?>
</div>
