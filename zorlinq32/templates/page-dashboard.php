<?php
/**
 * 대시보드 페이지.
 * $settings, $usage 변수는 Zorlinq32_Admin::render_dashboard_page() 에서 전달됩니다.
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- 이 파일은 관리자 클래스 메서드 안에서 include 되는 템플릿이며, 여기서 정의되는 변수는 해당 메서드의 지역 스코프에 한정됩니다.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [인터페이스 개선] 모듈 목록에서 각 항목의 켜짐/꺼짐 상태를 한눈에 보여주는 배지를 렌더링합니다.
 * 기존에는 "설정" 버튼만 있어 실제로 그 기능이 켜져 있는지 클릭해서 들어가봐야만 알 수 있었습니다.
 */
function zorlinq32_render_status_badge( $settings, $group_key ) {
	$is_on = ! empty( $settings[ $group_key ]['enabled'] );
	printf(
		'<span class="zorlinq32-status-pill %s" style="margin-right:10px;"><span class="dot"></span>%s</span>',
		esc_attr( $is_on ? 'is-on' : 'is-off' ),
		esc_html( $is_on ? __( '사용 중', 'zorlinq32' ) : __( '미사용', 'zorlinq32' ) )
	);
}
?>
<div class="wrap zorlinq32-wrap">
	<?php include ZORLINQ32_DIR . 'templates/partial-header.php'; ?>

	<div class="zorlinq32-grid">
		<div class="zorlinq32-card zorlinq32-card-wide">
			<h3><?php esc_html_e( '방문 통계 (최근 7일)', 'zorlinq32' ); ?></h3>
			<?php if ( ! empty( $analytics_summary['available'] ) ) : ?>
				<div style="display:flex;gap:28px;flex-wrap:wrap;margin-bottom:10px;">
					<div>
						<div class="zorlinq32-stat" style="font-size:26px;"><?php echo esc_html( number_format_i18n( $analytics_summary['quick']['today_visitors'] ) ); ?></div>
						<div class="zorlinq32-stat-sub"><?php esc_html_e( '오늘 방문자수', 'zorlinq32' ); ?></div>
					</div>
					<div>
						<div class="zorlinq32-stat" style="font-size:26px;color:#646970;"><?php echo esc_html( number_format_i18n( $analytics_summary['quick']['week_visitors'] ) ); ?></div>
						<div class="zorlinq32-stat-sub"><?php esc_html_e( '최근 7일 방문자수', 'zorlinq32' ); ?></div>
					</div>
				</div>
				<div id="zorlinq32-dashboard-mini-chart" class="zorlinq32-trend-chart" style="min-height:110px;position:relative;"></div>
				<script type="application/json" id="zorlinq32-dashboard-mini-data"><?php echo wp_json_encode( $analytics_summary['trend'] ); ?></script>
				<div class="zorlinq32-stat-sub" style="margin-top:8px;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=zorlinq32-analytics' ) ); ?>" class="button button-small"><?php esc_html_e( '전체 애널리틱스 보기', 'zorlinq32' ); ?></a>
				</div>
			<?php else : ?>
				<div class="zorlinq32-stat-sub">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=zorlinq32-analytics' ) ); ?>" class="button button-primary button-small"><?php esc_html_e( '애널리틱스 기능 켜기', 'zorlinq32' ); ?></a>
				</div>
			<?php endif; ?>
		</div>

		<div class="zorlinq32-card">
			<h3><?php esc_html_e( '스토리지 사용률', 'zorlinq32' ); ?></h3>
			<?php if ( $usage['available'] ) : ?>
				<div class="zorlinq32-stat"><?php echo esc_html( $usage['used_percent'] ); ?>%</div>
				<div class="zorlinq32-stat-sub">
					<?php
					printf(
						/* translators: 1: 사용중 용량, 2: 전체 용량 */
						esc_html__( '%1$s 사용 중 / 전체 %2$s', 'zorlinq32' ),
						esc_html( Zorlinq32_Storage_Monitor::format_bytes( $usage['used_bytes'] ) ),
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
				<div class="zorlinq32-stat-sub"><?php esc_html_e( '현재 호스팅 환경에서는 스토리지 정보를 가져올 수 없습니다.', 'zorlinq32' ); ?></div>
			<?php endif; ?>
		</div>

		<div class="zorlinq32-card">
			<h3><?php esc_html_e( '캐싱', 'zorlinq32' ); ?></h3>
			<span class="zorlinq32-status-pill <?php echo ! empty( $settings['cache']['enabled'] ) ? 'is-on' : 'is-off'; ?>">
				<span class="dot"></span>
				<?php echo ! empty( $settings['cache']['enabled'] ) ? esc_html__( '사용 중', 'zorlinq32' ) : esc_html__( '미사용', 'zorlinq32' ); ?>
			</span>
		</div>

		<div class="zorlinq32-card">
			<h3><?php esc_html_e( '로그인 보안', 'zorlinq32' ); ?></h3>
			<span class="zorlinq32-status-pill <?php echo ! empty( $settings['login_security']['enabled'] ) ? 'is-on' : 'is-off'; ?>">
				<span class="dot"></span>
				<?php echo ! empty( $settings['login_security']['enabled'] ) ? esc_html__( '사용 중', 'zorlinq32' ) : esc_html__( '미사용', 'zorlinq32' ); ?>
			</span>
		</div>
	</div>

	<div class="zorlinq32-module-list">
		<div class="zorlinq32-module-row">
			<div>
				<div class="name"><?php esc_html_e( '애널리틱스', 'zorlinq32' ); ?></div>
				<div class="desc"><?php esc_html_e( '자연유입/외부유입/기타 방문 통계, 인기글, 유입 키워드를 확인합니다.', 'zorlinq32' ); ?></div>
			</div>
			<div style="display:flex;align-items:center;">
			<?php zorlinq32_render_status_badge( $settings, 'analytics' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=zorlinq32-analytics' ) ); ?>" class="button"><?php esc_html_e( '설정', 'zorlinq32' ); ?></a>
			</div>
		</div>
		<div class="zorlinq32-module-row">
			<div>
				<div class="name"><?php esc_html_e( '관리자 로그인 링크 변경', 'zorlinq32' ); ?></div>
				<div class="desc"><?php esc_html_e( 'wp-login.php 접근을 차단하고 별도의 링크로 로그인합니다.', 'zorlinq32' ); ?></div>
			</div>
			<div style="display:flex;align-items:center;">
			<?php zorlinq32_render_status_badge( $settings, 'login_security' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=zorlinq32-login' ) ); ?>" class="button"><?php esc_html_e( '설정', 'zorlinq32' ); ?></a>
			</div>
		</div>
		<div class="zorlinq32-module-row">
			<div>
				<div class="name"><?php esc_html_e( '캐싱', 'zorlinq32' ); ?></div>
				<div class="desc"><?php esc_html_e( '방문자 페이지를 파일로 캐싱해 서버 부하를 줄입니다.', 'zorlinq32' ); ?></div>
			</div>
			<div style="display:flex;align-items:center;">
			<?php zorlinq32_render_status_badge( $settings, 'cache' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=zorlinq32-cache' ) ); ?>" class="button"><?php esc_html_e( '설정', 'zorlinq32' ); ?></a>
			</div>
		</div>
		<div class="zorlinq32-module-row">
			<div>
				<div class="name"><?php esc_html_e( '스토리지 모니터링', 'zorlinq32' ); ?></div>
				<div class="desc"><?php esc_html_e( '용량 초과를 예방하고 임계치 도달 시 알림을 보냅니다.', 'zorlinq32' ); ?></div>
			</div>
			<div style="display:flex;align-items:center;">
			<?php zorlinq32_render_status_badge( $settings, 'storage_monitor' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=zorlinq32-storage' ) ); ?>" class="button"><?php esc_html_e( '설정', 'zorlinq32' ); ?></a>
			</div>
		</div>
		<div class="zorlinq32-module-row">
			<div>
				<div class="name"><?php esc_html_e( '자동 색인 · 사이트맵', 'zorlinq32' ); ?></div>
				<div class="desc"><?php esc_html_e( 'IndexNow/구글 핑으로 검색엔진에 새 글을 알리고, XML 사이트맵과 robots.txt를 자동 생성합니다.', 'zorlinq32' ); ?></div>
			</div>
			<div style="display:flex;align-items:center;">
			<?php zorlinq32_render_status_badge( $settings, 'indexing' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=zorlinq32-indexing' ) ); ?>" class="button"><?php esc_html_e( '설정', 'zorlinq32' ); ?></a>
			</div>
		</div>
		<div class="zorlinq32-module-row">
			<div>
				<div class="name"><?php esc_html_e( '애드센스 부정클릭 방지', 'zorlinq32' ); ?></div>
				<div class="desc"><?php esc_html_e( '비정상적인 클릭 패턴을 관찰해 해당 방문자에게만 광고 노출을 제한합니다.', 'zorlinq32' ); ?></div>
			</div>
			<div style="display:flex;align-items:center;">
			<?php zorlinq32_render_status_badge( $settings, 'adsense_protection' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=zorlinq32-adsense-protection' ) ); ?>" class="button"><?php esc_html_e( '설정', 'zorlinq32' ); ?></a>
			</div>
		</div>
		<div class="zorlinq32-module-row">
			<div>
				<div class="name"><?php esc_html_e( '팝업', 'zorlinq32' ); ?></div>
				<div class="desc"><?php esc_html_e( '이미지, HTML/iframe, 문구 팝업을 노출 주기와 함께 관리합니다.', 'zorlinq32' ); ?></div>
			</div>
			<div style="display:flex;align-items:center;">
			<?php zorlinq32_render_status_badge( $settings, 'popup' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=zorlinq32-popup' ) ); ?>" class="button"><?php esc_html_e( '설정', 'zorlinq32' ); ?></a>
			</div>
		</div>
		<div class="zorlinq32-module-row">
			<div>
				<div class="name"><?php esc_html_e( '퀵 버튼 블록', 'zorlinq32' ); ?></div>
				<div class="desc"><?php esc_html_e( '글/페이지 편집 화면의 블록 삽입(+) 메뉴에서 "퀵 버튼"을 검색해 바로 사용할 수 있습니다.', 'zorlinq32' ); ?></div>
			</div>
		</div>
		<div class="zorlinq32-module-row">
			<div>
				<div class="name"><?php esc_html_e( 'Op 템플릿', 'zorlinq32' ); ?></div>
				<div class="desc"><?php esc_html_e( '자주 쓰는 블록 조합을 템플릿으로 저장하고 관리합니다.', 'zorlinq32' ); ?></div>
			</div>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=zorlinq32-templates' ) ); ?>" class="button"><?php esc_html_e( '설정', 'zorlinq32' ); ?></a>
		</div>
		<div class="zorlinq32-module-row">
			<div>
				<div class="name"><?php esc_html_e( '성능 최적화', 'zorlinq32' ); ?></div>
				<div class="desc"><?php esc_html_e( '리비전, 하트비트, 이모지 스크립트 등 세부 성능 옵션을 관리합니다.', 'zorlinq32' ); ?></div>
			</div>
			<div style="display:flex;align-items:center;">
			<?php zorlinq32_render_status_badge( $settings, 'performance' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=zorlinq32-performance' ) ); ?>" class="button"><?php esc_html_e( '설정', 'zorlinq32' ); ?></a>
			</div>
		</div>
		<div class="zorlinq32-module-row">
			<div>
				<div class="name"><?php esc_html_e( '보안 강화', 'zorlinq32' ); ?></div>
				<div class="desc"><?php esc_html_e( 'XML-RPC 차단, 로그인 시도 제한 등 기본 보안 기능을 관리합니다.', 'zorlinq32' ); ?></div>
			</div>
			<div style="display:flex;align-items:center;">
			<?php zorlinq32_render_status_badge( $settings, 'security' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=zorlinq32-security' ) ); ?>" class="button"><?php esc_html_e( '설정', 'zorlinq32' ); ?></a>
			</div>
		</div>
		<div class="zorlinq32-module-row">
			<div>
				<div class="name"><?php esc_html_e( '관리 편의', 'zorlinq32' ); ?></div>
				<div class="desc"><?php esc_html_e( '대시보드/관리자바 정리, 로그인 화면 커스텀을 관리합니다.', 'zorlinq32' ); ?></div>
			</div>
			<div style="display:flex;align-items:center;">
			<?php zorlinq32_render_status_badge( $settings, 'admin_experience' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=zorlinq32-admin-experience' ) ); ?>" class="button"><?php esc_html_e( '설정', 'zorlinq32' ); ?></a>
			</div>
		</div>
		<div class="zorlinq32-module-row">
			<div>
				<div class="name"><?php esc_html_e( '콘텐츠 최적화', 'zorlinq32' ); ?></div>
				<div class="desc"><?php esc_html_e( '댓글 스팸 방지, 미디어/검색 최적화를 관리합니다.', 'zorlinq32' ); ?></div>
			</div>
			<div style="display:flex;align-items:center;">
			<?php zorlinq32_render_status_badge( $settings, 'content_optimizer' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=zorlinq32-content' ) ); ?>" class="button"><?php esc_html_e( '설정', 'zorlinq32' ); ?></a>
			</div>
		</div>
		<div class="zorlinq32-module-row">
			<div>
				<div class="name"><?php esc_html_e( 'Cron 안정화', 'zorlinq32' ); ?></div>
				<div class="desc"><?php esc_html_e( '예약 발행 워치독 + self-ping으로 예약 작업 지연/누락을 감지하고 즉시 복구합니다.', 'zorlinq32' ); ?></div>
			</div>
			<div style="display:flex;align-items:center;">
			<?php zorlinq32_render_status_badge( $settings, 'cron_guardian' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=zorlinq32-cron' ) ); ?>" class="button"><?php esc_html_e( '설정', 'zorlinq32' ); ?></a>
			</div>
		</div>
		<div class="zorlinq32-module-row">
			<div>
				<div class="name"><?php esc_html_e( 'AI 글쓰기', 'zorlinq32' ); ?></div>
				<div class="desc"><?php esc_html_e( 'Gemini API로 주제 조사부터 본문·썸네일 이미지까지 자동 생성합니다. Gemini API 키를 설정합니다(검색 그라운딩 Worker는 글쓰기 전용).', 'zorlinq32' ); ?></div>
			</div>
			<div style="display:flex;align-items:center;">
			<span class="zorlinq32-status-pill <?php echo $ai_writer_configured ? 'is-on' : 'is-off'; ?>" style="margin-right:10px;">
				<span class="dot"></span>
				<?php echo $ai_writer_configured ? esc_html__( '사용 중', 'zorlinq32' ) : esc_html__( '미사용', 'zorlinq32' ); ?>
			</span>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=zorlinq32-ai-writer' ) ); ?>" class="button"><?php esc_html_e( '설정', 'zorlinq32' ); ?></a>
			</div>
		</div>
		<div class="zorlinq32-module-row">
			<div>
				<div class="name"><?php esc_html_e( 'AI 썸네일 템플릿', 'zorlinq32' ); ?></div>
				<div class="desc"><?php esc_html_e( '포스터/미니멀/사실적 사진/타이포그래피/브랜딩 등 이미지 스타일과 Google Flow 브라우저 기반 이미지 생성을 관리합니다.', 'zorlinq32' ); ?></div>
			</div>
			<div style="display:flex;align-items:center;">
			<span class="zorlinq32-status-pill <?php echo $ai_thumb_configured ? 'is-on' : 'is-off'; ?>" style="margin-right:10px;">
				<span class="dot"></span>
				<?php echo $ai_thumb_configured ? esc_html__( '사용 중', 'zorlinq32' ) : esc_html__( '미사용', 'zorlinq32' ); ?>
			</span>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=zorlinq32-ai-thumb-templates' ) ); ?>" class="button"><?php esc_html_e( '설정', 'zorlinq32' ); ?></a>
			</div>
		</div>
	</div>

	<p class="zorlinq32-help-text" style="margin-top:16px;">
		<?php esc_html_e( '필수 환경: PHP 7.4 이상, 워드프레스 5.8 이상, MySQL 5.6 이상.', 'zorlinq32' ); ?>
	</p>
</div>
