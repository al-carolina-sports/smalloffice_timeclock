<?php
/**
 * SMOTC Reports: pay period summary and shift detail.
 *
 * @package CssTimeclockAddon
 *
 * @var array<string,mixed>                 $filters
 * @var array<int,array<string,mixed>>      $periods
 * @var array<string,string>                $departments
 * @var WP_User[]                           $employees
 * @var string                              $page_slug
 * @var array<string,mixed>|null            $summary
 * @var array<int,array<string,mixed>>|null $shifts
 * @var string                              $csv_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$css_tc_time   = css_tc_addon()->time;
$css_tc_period = $filters['period'];
$css_tc_hm     = static function ( $seconds ) use ( $css_tc_time ) {
	return (int) $seconds > 0 ? $css_tc_time->format_duration( (int) $seconds ) : '0:00';
};
$css_tc_tab_url = static function ( $report ) use ( $filters, $page_slug ) {
	return add_query_arg(
		array(
			'page'       => $page_slug,
			'report'     => $report,
			'period'     => $filters['period'] ? $filters['period']['start'] : '',
			'department' => $filters['department'],
		),
		admin_url( Css_Tc_Plugin::aio_is_active() ? 'admin.php' : 'options-general.php' )
	);
};
?>
<div class="wrap css-tc-reports">
	<h1><?php echo esc_html__( 'Reports', 'css-timeclock-addon' ); ?></h1>

	<h2 class="nav-tab-wrapper">
		<a href="<?php echo esc_url( $css_tc_tab_url( 'summary' ) ); ?>" class="nav-tab <?php echo 'summary' === $filters['report'] ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Pay period summary', 'css-timeclock-addon' ); ?></a>
		<a href="<?php echo esc_url( $css_tc_tab_url( 'shifts' ) ); ?>" class="nav-tab <?php echo 'shifts' === $filters['report'] ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Shift detail', 'css-timeclock-addon' ); ?></a>
	</h2>

	<form method="get" class="css-tc-report-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>" />
		<input type="hidden" name="report" value="<?php echo esc_attr( $filters['report'] ); ?>" />
		<label>
			<?php echo esc_html__( 'Pay period', 'css-timeclock-addon' ); ?>
			<select name="period">
				<?php foreach ( $periods as $p ) : ?>
					<option value="<?php echo esc_attr( $p['start'] ); ?>" <?php selected( $css_tc_period ? $css_tc_period['start'] : '', $p['start'] ); ?>><?php echo esc_html( $p['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label>
			<?php echo esc_html__( 'Department', 'css-timeclock-addon' ); ?>
			<select name="department">
				<option value=""><?php echo esc_html__( 'All departments', 'css-timeclock-addon' ); ?></option>
				<?php foreach ( $departments as $slug => $name ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['department'], $slug ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
				<option value="<?php echo esc_attr( Css_Tc_Reports::NONE_DEPT ); ?>" <?php selected( $filters['department'], Css_Tc_Reports::NONE_DEPT ); ?>><?php echo esc_html__( 'No department', 'css-timeclock-addon' ); ?></option>
			</select>
		</label>
		<?php if ( 'shifts' === $filters['report'] ) : ?>
			<label>
				<?php echo esc_html__( 'Employee', 'css-timeclock-addon' ); ?>
				<select name="employee">
					<option value="0"><?php echo esc_html__( 'All employees', 'css-timeclock-addon' ); ?></option>
					<?php foreach ( $employees as $user ) : ?>
						<option value="<?php echo esc_attr( (string) (int) $user->ID ); ?>" <?php selected( $filters['employee'], (int) $user->ID ); ?>><?php echo esc_html( css_tc_addon()->employees->display_name( (int) $user->ID ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		<?php endif; ?>
		<button type="submit" class="button button-primary"><?php echo esc_html__( 'View', 'css-timeclock-addon' ); ?></button>
		<a class="button" href="<?php echo esc_url( $csv_url ); ?>"><?php echo esc_html__( 'Download CSV', 'css-timeclock-addon' ); ?></a>
		<button type="button" class="button" onclick="window.print()"><?php echo esc_html__( 'Print', 'css-timeclock-addon' ); ?></button>
	</form>

	<?php if ( ! $css_tc_period ) : ?>
		<p><?php echo esc_html__( 'No pay period is available. Check the pay period settings.', 'css-timeclock-addon' ); ?></p>

	<?php elseif ( $summary ) : ?>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: date range, 2: timezone, 3: overtime rule */
					__( '%1$s · Times in %2$s · %3$s', 'css-timeclock-addon' ),
					$css_tc_period['range'],
					$summary['timezone'],
					$summary['rule_note']
				)
			);
			?>
		</p>

		<table class="widefat striped css-tc-report-table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Employee', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Department', 'css-timeclock-addon' ); ?></th>
					<?php foreach ( $summary['weeks'] as $week ) : ?>
						<th class="num" title="<?php echo esc_attr( $week['range'] ); ?>"><?php echo esc_html( $week['label'] ); ?></th>
					<?php endforeach; ?>
					<?php foreach ( $summary['codes'] as $def ) : ?>
						<th class="num"><?php echo esc_html( $def['label'] ); ?></th>
					<?php endforeach; ?>
					<th class="num"><?php echo esc_html__( 'Total', 'css-timeclock-addon' ); ?></th>
					<th class="num"><?php echo esc_html__( 'Decimal', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Needs attention', 'css-timeclock-addon' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $summary['rows'] ) ) : ?>
					<tr><td colspan="<?php echo esc_attr( (string) ( 5 + count( $summary['weeks'] ) + count( $summary['codes'] ) ) ); ?>"><?php echo esc_html__( 'No employees match these filters.', 'css-timeclock-addon' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $summary['rows'] as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $row['timecard_url'] ); ?>"><?php echo esc_html( $row['name'] ); ?></a></td>
						<td><?php echo esc_html( '' !== $row['department'] ? $row['department'] : '—' ); ?></td>
						<?php foreach ( array_keys( $summary['weeks'] ) as $w ) : ?>
							<td class="num"><?php echo esc_html( $css_tc_hm( isset( $row['weeks'][ $w ] ) ? $row['weeks'][ $w ] : 0 ) ); ?></td>
						<?php endforeach; ?>
						<?php foreach ( array_keys( $summary['codes'] ) as $slug ) : ?>
							<td class="num<?php echo ( Css_Tc_Pay_Codes::OVERTIME === $slug && $row['codes'][ $slug ] > 0 ) ? ' is-overtime' : ''; ?>"><?php echo esc_html( $css_tc_hm( $row['codes'][ $slug ] ) ); ?></td>
						<?php endforeach; ?>
						<td class="num"><strong><?php echo esc_html( $css_tc_hm( $row['total'] ) ); ?></strong></td>
						<td class="num"><?php echo esc_html( Css_Tc_Reports::decimal_hours( $row['total'] ) ); ?></td>
						<td class="<?php echo empty( $row['attention'] ) ? '' : 'is-attention'; ?>"><?php echo esc_html( empty( $row['attention'] ) ? '—' : implode( ', ', $row['attention'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<?php if ( ! empty( $summary['rows'] ) ) : ?>
				<tfoot>
					<tr>
						<th colspan="2"><?php echo esc_html__( 'All shown', 'css-timeclock-addon' ); ?></th>
						<?php foreach ( array_keys( $summary['weeks'] ) as $w ) : ?>
							<th class="num"><?php echo esc_html( $css_tc_hm( isset( $summary['totals']['weeks'][ $w ] ) ? $summary['totals']['weeks'][ $w ] : 0 ) ); ?></th>
						<?php endforeach; ?>
						<?php foreach ( array_keys( $summary['codes'] ) as $slug ) : ?>
							<th class="num"><?php echo esc_html( $css_tc_hm( $summary['totals']['codes'][ $slug ] ) ); ?></th>
						<?php endforeach; ?>
						<th class="num"><?php echo esc_html( $css_tc_hm( $summary['totals']['total'] ) ); ?></th>
						<th class="num"><?php echo esc_html( Css_Tc_Reports::decimal_hours( $summary['totals']['total'] ) ); ?></th>
						<th></th>
					</tr>
				</tfoot>
			<?php endif; ?>
		</table>

		<?php if ( count( $summary['departments'] ) > 0 ) : ?>
			<h2><?php echo esc_html__( 'By department', 'css-timeclock-addon' ); ?></h2>
			<table class="widefat striped css-tc-report-table css-tc-report-table--narrow">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Department', 'css-timeclock-addon' ); ?></th>
						<th class="num"><?php echo esc_html__( 'Employees', 'css-timeclock-addon' ); ?></th>
						<?php foreach ( $summary['codes'] as $def ) : ?>
							<th class="num"><?php echo esc_html( $def['label'] ); ?></th>
						<?php endforeach; ?>
						<th class="num"><?php echo esc_html__( 'Total', 'css-timeclock-addon' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $summary['departments'] as $dept ) : ?>
						<tr>
							<td><?php echo esc_html( $dept['name'] ); ?></td>
							<td class="num"><?php echo esc_html( (string) $dept['employees'] ); ?></td>
							<?php foreach ( array_keys( $summary['codes'] ) as $slug ) : ?>
								<td class="num"><?php echo esc_html( $css_tc_hm( $dept['codes'][ $slug ] ) ); ?></td>
							<?php endforeach; ?>
							<td class="num"><strong><?php echo esc_html( $css_tc_hm( $dept['total'] ) ); ?></strong></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

	<?php elseif ( is_array( $shifts ) ) : ?>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: date range, 2: number of shifts */
					__( '%1$s · %2$d shifts · times in the site timezone', 'css-timeclock-addon' ),
					$css_tc_period['range'],
					count( $shifts )
				)
			);
			?>
		</p>
		<table class="widefat striped css-tc-report-table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Employee', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Department', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Date', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'In', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Out', 'css-timeclock-addon' ); ?></th>
					<th class="num"><?php echo esc_html__( 'Hours', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'IP in', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'IP out', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Flags', 'css-timeclock-addon' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $shifts ) ) : ?>
					<tr><td colspan="9"><?php echo esc_html__( 'No shifts in this pay period.', 'css-timeclock-addon' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $shifts as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['name'] ); ?></td>
						<td><?php echo esc_html( '' !== $row['department'] ? $row['department'] : '—' ); ?></td>
						<td><?php echo esc_html( $row['weekday'] . ' ' . $css_tc_time->format_mdy( $row['date'] ) ); ?></td>
						<td><?php echo esc_html( '' !== $row['in'] ? $row['in'] : '—' ); ?></td>
						<td><?php echo esc_html( ( '' !== $row['out'] ? $row['out'] : '—' ) . ( $row['next_day'] ? ' (+1)' : '' ) ); ?></td>
						<td class="num"><?php echo esc_html( $row['seconds'] > 0 ? $css_tc_time->format_duration( $row['seconds'] ) : '—' ); ?></td>
						<td><?php echo esc_html( '' !== $row['ip_in'] ? $row['ip_in'] : '—' ); ?></td>
						<td><?php echo esc_html( '' !== $row['ip_out'] ? $row['ip_out'] : '—' ); ?></td>
						<td class="<?php echo empty( $row['flags'] ) ? '' : 'is-attention'; ?>"><?php echo esc_html( implode( ', ', $row['flags'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
