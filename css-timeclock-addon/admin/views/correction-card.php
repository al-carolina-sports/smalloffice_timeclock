<?php
/**
 * One correction card in the admin queue.
 *
 * @package CssTimeclockAddon
 *
 * @var array<string,mixed> $item
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status = isset( $item['status'] ) ? (string) $item['status'] : 'pending';
?>
<article class="css-tc-correction" data-correction-id="<?php echo esc_attr( (string) (int) $item['id'] ); ?>" data-status="<?php echo esc_attr( $status ); ?>">
	<header>
		<strong><?php echo esc_html( isset( $item['employee'] ) ? $item['employee'] : '' ); ?></strong>
		<span><?php echo esc_html( isset( $item['work_date'] ) ? $item['work_date'] : '' ); ?></span>
		<span class="css-tc-pill css-tc-pill-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span>
	</header>
	<p>
		<?php echo esc_html__( 'Current:', 'css-timeclock-addon' ); ?>
		<?php echo esc_html( ( isset( $item['original_in'] ) && $item['original_in'] ) ? $item['original_in'] : '—' ); ?>
		→
		<?php echo esc_html( ( isset( $item['original_out'] ) && $item['original_out'] ) ? $item['original_out'] : '—' ); ?>
	</p>
	<p>
		<?php echo esc_html__( 'Proposed:', 'css-timeclock-addon' ); ?>
		<?php echo esc_html( ( isset( $item['proposed_in'] ) && $item['proposed_in'] ) ? $item['proposed_in'] : '—' ); ?>
		→
		<?php echo esc_html( ( isset( $item['proposed_out'] ) && $item['proposed_out'] ) ? $item['proposed_out'] : '—' ); ?>
		<?php if ( ! empty( $item['missing_punch'] ) ) : ?>
			<span class="description"><?php echo esc_html__( '(missing punch)', 'css-timeclock-addon' ); ?></span>
		<?php endif; ?>
	</p>
	<p>
		<?php echo esc_html__( 'Reason:', 'css-timeclock-addon' ); ?>
		<?php
		$css_tc_reason = isset( $item['reason'] ) ? trim( (string) $item['reason'] ) : '';
		echo esc_html( '' !== $css_tc_reason ? $css_tc_reason : __( 'No reason given', 'css-timeclock-addon' ) );
		?>
	</p>
	<?php if ( ! empty( $item['review_note'] ) || ! empty( $item['reviewer'] ) ) : ?>
		<p class="description">
			<?php
			if ( ! empty( $item['reviewer'] ) ) {
				echo esc_html( sprintf( /* translators: reviewer name */ __( 'Reviewed by %s', 'css-timeclock-addon' ), $item['reviewer'] ) );
			}
			if ( ! empty( $item['reviewed_at'] ) ) {
				echo ' · ' . esc_html( $item['reviewed_at'] );
			}
			if ( ! empty( $item['review_note'] ) ) {
				echo ' — ' . esc_html( $item['review_note'] );
			}
			?>
		</p>
	<?php endif; ?>
	<?php if ( 'pending' === $status ) : ?>
		<form class="css-tc-review-form">
			<label>
				<span class="screen-reader-text"><?php echo esc_html__( 'Supervisor note', 'css-timeclock-addon' ); ?></span>
				<input type="text" name="review_note" class="regular-text" maxlength="500" placeholder="<?php echo esc_attr__( 'Optional note', 'css-timeclock-addon' ); ?>" />
			</label>
			<button type="button" class="button button-primary" data-decision="approve"><?php echo esc_html__( 'Approve', 'css-timeclock-addon' ); ?></button>
			<button type="button" class="button" data-decision="reject"><?php echo esc_html__( 'Reject', 'css-timeclock-addon' ); ?></button>
		</form>
	<?php endif; ?>
</article>
