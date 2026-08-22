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

	<p class="zorlinq32-help-text">
		<?php esc_html_e( '플러그인 내부에서 발생한 문제를 안전하게 기록한 로그입니다. 이 로그가 쌓여도 사이트 동작에는 영향을 주지 않습니다.', 'zorlinq32' ); ?>
	</p>

	<?php if ( empty( $logs ) ) : ?>
		<div class="zorlinq32-log-table">
			<div class="zorlinq32-empty-state">
				<span class="dashicons dashicons-yes-alt"></span>
				<p><?php esc_html_e( '기록된 오류가 없습니다.', 'zorlinq32' ); ?></p>
			</div>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:200px;"><?php esc_html_e( '시간', 'zorlinq32' ); ?></th>
					<th><?php esc_html_e( '메시지', 'zorlinq32' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( $entry['time'] ); ?></td>
						<td><?php echo esc_html( $entry['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" style="margin-top:16px;">
			<?php wp_nonce_field( 'zorlinq32_clear_logs' ); ?>
			<button type="submit" name="zorlinq32_clear_logs" value="1" class="button"><?php esc_html_e( '로그 지우기', 'zorlinq32' ); ?></button>
		</form>
	<?php endif; ?>
</div>
