<?php
/**
 * SMOTC replacement for AIO Real Time Monitoring.
 *
 * @package CssTimeclockAddon
 *
 * @var array<string,mixed> $snapshot Working and missed-clock-out rows.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$working = isset( $snapshot['working'] ) ? $snapshot['working'] : array();
$missed  = isset( $snapshot['missed'] ) ? $snapshot['missed'] : array();
$max     = isset( $snapshot['max_hours'] ) ? (int) $snapshot['max_hours'] : 16;
$tz      = isset( $snapshot['timezone'] ) ? (string) $snapshot['timezone'] : '';

/**
 * @param array<string,mixed> $row Monitor row.
 * @return void
 */
$css_tc_monitor_row = static function ( $row ) {
	$edit = isset( $row['edit_url'] ) ? (string) $row['edit_url'] : '';
	?>
	<tr>
		<td><?php echo esc_html( (string) $row['name'] ); ?></td>
		<td><?php echo esc_html( '' !== (string) $row['department'] ? (string) $row['department'] : '—' ); ?></td>
		<td><?php echo esc_html( (string) $row['clock_in'] ); ?></td>
		<td><?php echo esc_html( (string) $row['elapsed'] ); ?></td>
		<td><?php echo esc_html( '' !== (string) $row['ip'] ? (string) $row['ip'] : '—' ); ?></td>
		<td>
			<?php if ( '' !== $edit ) : ?>
				<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html__( 'Edit shift', 'css-timeclock-addon' ); ?></a>
			<?php endif; ?>
		</td>
	</tr>
	<?php
};
?>
<div class="wrap css-tc-monitor">
	<h1><?php echo esc_html__( 'Real Time Monitoring', 'css-timeclock-addon' ); ?></h1>
	<p class="css-tc-monitor__lead">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: site timezone name, 2: hour limit */
				__( 'Times are shown in the site timezone (%1$s). Open shifts older than %2$d hours are missed clock-outs and are not counted as working.', 'css-timeclock-addon' ),
				$tz,
				$max
			)
		);
		?>
	</p>

	<h2><?php echo esc_html__( 'Working now', 'css-timeclock-addon' ); ?></h2>
	<table class="widefat striped css-tc-monitor__table">
		<thead>
			<tr>
				<th><?php echo esc_html__( 'Employee', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'Department', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'Clocked in', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'Elapsed', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'IP address', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'Shift', 'css-timeclock-addon' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $working ) ) : ?>
				<tr>
					<td colspan="6"><?php echo esc_html__( 'Nobody is clocked in.', 'css-timeclock-addon' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $working as $row ) : ?>
					<?php $css_tc_monitor_row( $row ); ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	<p><strong><?php echo esc_html__( 'Total clocked in', 'css-timeclock-addon' ); ?>:</strong> <?php echo esc_html( (string) count( $working ) ); ?></p>

	<h2><?php echo esc_html__( 'Missed clock-out', 'css-timeclock-addon' ); ?></h2>
	<p><?php echo esc_html__( 'These open shifts are too old to count as working. They still need a clock-out on the timecard.', 'css-timeclock-addon' ); ?></p>
	<table class="widefat striped css-tc-monitor__table">
		<thead>
			<tr>
				<th><?php echo esc_html__( 'Employee', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'Department', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'Clocked in', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'Age', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'IP address', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'Shift', 'css-timeclock-addon' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $missed ) ) : ?>
				<tr>
					<td colspan="6"><?php echo esc_html__( 'No missed clock-outs in recent shifts.', 'css-timeclock-addon' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $missed as $row ) : ?>
					<?php $css_tc_monitor_row( $row ); ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
