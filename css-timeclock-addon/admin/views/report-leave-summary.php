<?php
/**
 * PTO summary by employee (Reports).
 *
 * @package CssTimeclockAddon
 *
 * @var bool|null $css_tc_leave_compact Shown under the pay period summary.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$css_tc_leave = css_tc_addon()->leave;
$css_tc_banks = $css_tc_leave->banks();
$css_tc_dayl  = $css_tc_leave->day_seconds();
$css_tc_hd    = static function ( $seconds ) use ( $css_tc_dayl ) {
	$days = round( $seconds / $css_tc_dayl, 2 );
	return Css_Tc_Leave::hours( $seconds ) . ' · ' . rtrim( rtrim( number_format( $days, 2, '.', '' ), '0' ), '.' ) . 'd';
};
$css_tc_emps = css_tc_addon()->employees->list_for_admin();
usort(
	$css_tc_emps,
	static function ( $a, $b ) {
		return strcasecmp( css_tc_addon()->employees->display_name( (int) $a->ID ), css_tc_addon()->employees->display_name( (int) $b->ID ) );
	}
);
$css_tc_csv = wp_nonce_url( admin_url( 'admin-post.php?action=css_tc_leave_csv' ), Css_Tc_Leave_Ui::ADMIN_NONCE );
?>
<h2 id="css-tc-pto-summary" class="css-tc-report-h2">
	<?php echo esc_html__( 'PTO summary by employee', 'css-timeclock-addon' ); ?>
	<a class="button button-small" href="<?php echo esc_url( $css_tc_csv ); ?>"><?php echo esc_html__( 'Download CSV', 'css-timeclock-addon' ); ?></a>
</h2>
<p class="description"><?php echo esc_html__( 'Each employee\'s current leave year (from the hire-date anniversary). Total includes adjustments. Remaining already takes off pending requests. Click Used to see the days.', 'css-timeclock-addon' ); ?></p>
<table class="widefat striped css-tc-report-table css-tc-report-table--narrow css-tc-pto-summary">
	<thead>
		<tr>
			<th rowspan="2"><?php echo esc_html__( 'Employee', 'css-timeclock-addon' ); ?></th>
			<th rowspan="2"><?php echo esc_html__( 'Leave year', 'css-timeclock-addon' ); ?></th>
			<?php foreach ( $css_tc_banks as $css_tc_bank ) : ?>
				<th colspan="4" class="css-tc-group-head"><?php echo esc_html( 'pto' === $css_tc_bank ? ( $css_tc_leave->one_bank() ? __( 'PTO (incl. sick)', 'css-timeclock-addon' ) : __( 'PTO', 'css-timeclock-addon' ) ) : __( 'Sick', 'css-timeclock-addon' ) ); ?></th>
			<?php endforeach; ?>
		</tr>
		<tr>
			<?php foreach ( $css_tc_banks as $css_tc_bank ) : ?>
				<th class="num"><?php echo esc_html__( 'Total', 'css-timeclock-addon' ); ?></th>
				<th class="num"><?php echo esc_html__( 'Used', 'css-timeclock-addon' ); ?></th>
				<th class="num"><?php echo esc_html__( 'Pending', 'css-timeclock-addon' ); ?></th>
				<th class="num"><?php echo esc_html__( 'Remaining', 'css-timeclock-addon' ); ?></th>
			<?php endforeach; ?>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $css_tc_emps as $css_tc_u ) : ?>
			<?php $css_tc_b = $css_tc_leave->balances( (int) $css_tc_u->ID ); ?>
			<tr>
				<td><a href="<?php echo esc_url( Css_Tc_Reports::leave_used_url( (int) $css_tc_u->ID ) ); ?>"><?php echo esc_html( css_tc_addon()->employees->display_name( (int) $css_tc_u->ID ) ); ?></a></td>
				<?php if ( ! $css_tc_b['cycle'] ) : ?>
					<td colspan="<?php echo esc_attr( (string) ( 1 + 4 * count( $css_tc_banks ) ) ); ?>" class="description">
						<a href="<?php echo esc_url( get_edit_user_link( (int) $css_tc_u->ID ) . '#css-tc-employment' ); ?>"><?php echo esc_html__( 'Set a hire date to start PTO', 'css-timeclock-addon' ); ?></a>
					</td>
				<?php else : ?>
					<td>
						<?php echo esc_html( css_tc_leave_date_label( $css_tc_b['cycle']['start'] ) . ' – ' . css_tc_leave_date_label( $css_tc_b['cycle']['end'] ) ); ?>
						<?php if ( '' !== $css_tc_b['usable_from'] && $css_tc_b['usable_from'] > $css_tc_leave->today() ) : ?>
							<div class="description"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'usable from %s', 'css-timeclock-addon' ), css_tc_leave_date_label( $css_tc_b['usable_from'] ) ) ); ?></div>
						<?php endif; ?>
					</td>
					<?php foreach ( $css_tc_banks as $css_tc_bank ) : ?>
						<?php $css_tc_t = $css_tc_b['banks'][ $css_tc_bank ]; ?>
						<td class="num"><?php echo esc_html( $css_tc_hd( $css_tc_t['allowance'] + $css_tc_t['adjust'] ) ); ?></td>
						<td class="num"><a href="<?php echo esc_url( Css_Tc_Reports::leave_used_url( (int) $css_tc_u->ID ) ); ?>"><?php echo esc_html( Css_Tc_Leave::hours( $css_tc_t['used'] ) ); ?></a></td>
						<td class="num"><?php echo esc_html( $css_tc_t['pending'] > 0 ? Css_Tc_Leave::hours( $css_tc_t['pending'] ) : '—' ); ?></td>
						<td class="num<?php echo $css_tc_t['left'] < 0 ? ' is-attention' : ''; ?>"><strong><?php echo esc_html( $css_tc_hd( $css_tc_t['left'] ) ); ?></strong></td>
					<?php endforeach; ?>
				<?php endif; ?>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
