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
		<h2><?php esc_html_e( 'Op 템플릿 만드는 방법', 'zorlinq32' ); ?></h2>
		<p class="zorlinq32-help-text">
			<?php esc_html_e( '글이나 페이지 편집 화면에서 자주 쓰는 블록 조합을 선택한 뒤, 블록 툴바의 옵션(⋮) 메뉴에서 "패턴 생성"을 누르고 이름만 입력해 저장하세요. "Op 템플릿" 카테고리는 자동으로 지정되므로 별도로 선택할 필요가 없습니다. 이후에는 블록 삽입 화면(+)에서 "Op 템플릿" 탭을 열면 언제든 다시 불러와 사용할 수 있습니다.', 'zorlinq32' ); ?>
		</p>
		<p class="zorlinq32-help-text">
			<?php esc_html_e( '이 기능은 새로운 저장 시스템을 만드는 대신 워드프레스에 내장된 "패턴" 기능을 그대로 활용합니다. 아래 목록에서 저장된 템플릿을 확인하고 필요 없는 것을 삭제할 수 있습니다.', 'zorlinq32' ); ?>
		</p>
	</div>

	<div class="zorlinq32-settings-section">
		<h2><?php esc_html_e( '저장된 Op 템플릿', 'zorlinq32' ); ?></h2>
		<?php if ( empty( $patterns ) ) : ?>
			<p class="zorlinq32-help-text"><?php esc_html_e( '아직 저장된 템플릿이 없습니다.', 'zorlinq32' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( '이름', 'zorlinq32' ); ?></th>
						<th><?php esc_html_e( '마지막 수정', 'zorlinq32' ); ?></th>
						<th><?php esc_html_e( '관리', 'zorlinq32' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $patterns as $zorlinq32_pattern ) : ?>
						<tr data-template-id="<?php echo esc_attr( $zorlinq32_pattern['id'] ); ?>">
							<td><?php echo esc_html( $zorlinq32_pattern['title'] ); ?></td>
							<td><?php echo esc_html( $zorlinq32_pattern['modified'] ); ?></td>
							<td>
								<?php if ( ! empty( $zorlinq32_pattern['edit_url'] ) ) : ?>
									<a href="<?php echo esc_url( $zorlinq32_pattern['edit_url'] ); ?>" class="button"><?php esc_html_e( '편집', 'zorlinq32' ); ?></a>
								<?php endif; ?>
								<button type="button" class="button zorlinq32-template-delete" data-id="<?php echo esc_attr( $zorlinq32_pattern['id'] ); ?>"><?php esc_html_e( '삭제', 'zorlinq32' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>
