<?php
/**
 * Month calendar of pay periods and paydays.
 *
 * @package CssTimeclockAddon
 *
 * @var array<string,mixed>|null $css_tc_pay_cal
 * @var string                   $css_tc_pay_note
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $css_tc_pay_cal ) || ! is_array( $css_tc_pay_cal ) ) {
	return;
}

$css_tc_pay_note = isset( $css_tc_pay_note ) ? (string) $css_tc_pay_note : '';
$css_tc_sum      = isset( $css_tc_pay_cal['summary'] ) && is_array( $css_tc_pay_cal['summary'] ) ? $css_tc_pay_cal['summary'] : null;
$css_tc_prev_url = add_query_arg( 'css_tc_month', (string) $css_tc_pay_cal['prev'] );
$css_tc_next_url = add_query_arg( 'css_tc_month', (string) $css_tc_pay_cal['next'] );
?>
<section class="css-tc-payday" aria-label="<?php echo esc_attr__( 'Pay period calendar', 'css-timeclock-addon' ); ?>">
	<?php if ( $css_tc_sum ) : ?>
		<p class="css-tc-payday__header">
			<?php
			echo esc_html__( 'Current pay period:', 'css-timeclock-addon' ) . ' ' . esc_html( $css_tc_sum['range'] );
			if ( ! empty( $css_tc_pay_cal['show_closes'] ) && '' !== $css_tc_sum['closes_label'] ) {
				echo ' · ' . esc_html__( 'Closes', 'css-timeclock-addon' ) . ' ' . esc_html( $css_tc_sum['closes_label'] );
			}
			echo ' · ' . esc_html__( 'Next payday:', 'css-timeclock-addon' ) . ' ';
			?>
			<span class="css-tc-payday__badge" style="background:<?php echo esc_attr( $css_tc_pay_cal['marker_color'] ); ?>"><?php echo esc_html( $css_tc_pay_cal['marker_glyph'] ); ?></span>
			<?php echo esc_html( $css_tc_sum['payday_label'] ); ?>
		</p>
	<?php endif; ?>
	<?php if ( '' !== $css_tc_pay_note ) : ?>
		<p class="css-tc-payday__note"><?php echo esc_html( $css_tc_pay_note ); ?></p>
	<?php endif; ?>
	<div class="css-tc-payday__nav">
		<a class="css-tc-payday__nav-btn css-tc-no-print" href="<?php echo esc_url( $css_tc_prev_url ); ?>" aria-label="<?php echo esc_attr__( 'Previous month', 'css-timeclock-addon' ); ?>">&lsaquo;</a>
		<h2><?php echo esc_html( (string) $css_tc_pay_cal['title'] ); ?></h2>
		<a class="css-tc-payday__nav-btn css-tc-no-print" href="<?php echo esc_url( $css_tc_next_url ); ?>" aria-label="<?php echo esc_attr__( 'Next month', 'css-timeclock-addon' ); ?>">&rsaquo;</a>
	</div>
	<table class="css-tc-payday__grid">
		<thead>
			<tr>
				<?php foreach ( $css_tc_pay_cal['weekdays'] as $css_tc_head ) : ?>
					<th scope="col"><?php echo esc_html( (string) $css_tc_head ); ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $css_tc_pay_cal['weeks'] as $css_tc_week ) : ?>
				<tr>
					<?php foreach ( $css_tc_week as $css_tc_cell ) : ?>
						<td style="background:<?php echo esc_attr( (string) $css_tc_cell['tint'] ); ?>">
							<div class="css-tc-payday__day<?php echo empty( $css_tc_cell['in_month'] ) ? ' is-out' : ''; ?>"><?php echo esc_html( (string) $css_tc_cell['day'] ); ?></div>
							<?php if ( ! empty( $css_tc_cell['bar'] ) ) : ?>
								<div class="css-tc-payday__band<?php echo '' === $css_tc_cell['label'] ? ' is-cont' : ''; ?>" style="background:<?php echo esc_attr( (string) $css_tc_cell['color'] ); ?>"><?php echo esc_html( (string) $css_tc_cell['label'] ); ?></div>
							<?php endif; ?>
							<?php if ( '' !== $css_tc_cell['hours'] ) : ?>
								<div class="css-tc-payday__hours"><?php echo esc_html( (string) $css_tc_cell['hours'] ); ?></div>
							<?php endif; ?>
							<?php if ( ! empty( $css_tc_cell['payday'] ) ) : ?>
								<div><span class="css-tc-payday__badge" style="background:<?php echo esc_attr( (string) $css_tc_pay_cal['marker_color'] ); ?>"><?php echo esc_html( (string) $css_tc_pay_cal['marker_text'] ); ?></span></div>
							<?php endif; ?>
							<?php if ( ! empty( $css_tc_pay_cal['show_closes'] ) && ! empty( $css_tc_cell['closes'] ) ) : ?>
								<div><span class="css-tc-payday__closes"><?php echo esc_html__( 'Period closes', 'css-timeclock-addon' ); ?></span></div>
							<?php endif; ?>
							<?php if ( '' !== $css_tc_cell['holiday'] ) : ?>
								<div><span class="css-tc-payday__holiday"><?php echo esc_html( sprintf( /* translators: %s: holiday name */ __( 'Holiday (%s)', 'css-timeclock-addon' ), (string) $css_tc_cell['holiday'] ) ); ?></span></div>
							<?php endif; ?>
						</td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p class="css-tc-payday__legend">
		<strong><?php echo esc_html__( 'Legend:', 'css-timeclock-addon' ); ?></strong>
		<span><i class="css-tc-payday__swatch" style="background:<?php echo esc_attr( (string) $css_tc_pay_cal['tint_a'] ); ?>;border-color:<?php echo esc_attr( (string) $css_tc_pay_cal['color_a'] ); ?>"></i><?php echo esc_html__( 'Pay period A', 'css-timeclock-addon' ); ?></span>
		<span><i class="css-tc-payday__swatch" style="background:<?php echo esc_attr( (string) $css_tc_pay_cal['tint_b'] ); ?>;border-color:<?php echo esc_attr( (string) $css_tc_pay_cal['color_b'] ); ?>"></i><?php echo esc_html__( 'Pay period B (alternating)', 'css-timeclock-addon' ); ?></span>
		<span><span class="css-tc-payday__badge" style="background:<?php echo esc_attr( (string) $css_tc_pay_cal['marker_color'] ); ?>"><?php echo esc_html( (string) $css_tc_pay_cal['marker_glyph'] ); ?></span> <?php echo esc_html__( 'Pay date', 'css-timeclock-addon' ); ?></span>
		<?php if ( ! empty( $css_tc_pay_cal['show_closes'] ) ) : ?>
			<span><span class="css-tc-payday__closes"><?php echo esc_html__( 'Period closes', 'css-timeclock-addon' ); ?></span> <?php echo esc_html__( 'Last day of period', 'css-timeclock-addon' ); ?></span>
		<?php endif; ?>
		<?php if ( ! empty( $css_tc_pay_cal['has_holiday'] ) ) : ?>
			<span><span class="css-tc-payday__holiday"><?php echo esc_html__( 'Holiday', 'css-timeclock-addon' ); ?></span> <?php echo esc_html__( 'Office closed', 'css-timeclock-addon' ); ?></span>
		<?php endif; ?>
	</p>
</section>
