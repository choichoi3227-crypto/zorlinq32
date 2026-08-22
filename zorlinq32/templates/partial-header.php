<?php
/**
 * 공통 헤더 파샬. 직접 접근을 막습니다.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="zorlinq32-header">
	<div class="zorlinq32-header-logo">
		<span class="dashicons dashicons-performance"></span>
		<div>
			<h1>Zorlinq32</h1>
			<p><?php esc_html_e( '워드프레스 서버 부하 최소화 및 성능 최적화', 'zorlinq32' ); ?></p>
		</div>
	</div>
	<span class="zorlinq32-version">v<?php echo esc_html( ZORLINQ32_VERSION ); ?></span>
</div>

<?php if ( isset( $_GET['zorlinq32_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
	<div class="zorlinq32-notice-saved">
		<span class="dashicons dashicons-yes-alt"></span>
		<?php esc_html_e( '설정이 저장되었습니다.', 'zorlinq32' ); ?>
	</div>
<?php endif; ?>
