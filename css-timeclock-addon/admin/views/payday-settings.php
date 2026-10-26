<?php
/**
 * TC-Config → Pay & Payday.
 *
 * Markers stay off until this form is saved. Bands that follow the timecard
 * pay period use the same Monday bounds as hours. Saving does not change hours.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$css_tc_pay_hist = Css_Tc_Payday::load_history();
$css_tc_pay_cfg  = Css_Tc_Payday::display_config( $css_tc_pay_hist );
if ( ! $css_tc_pay_cfg ) {
	$css_tc_pay_cfg = Css_Tc_Payday::default_config();
}
$css_tc_pay_today   = css_tc_addon()->time->site_today();
$css_tc_pay_engine  = css_tc_addon()->pay_periods;
$css_tc_pay_bounds  = $css_tc_pay_engine->period_for_date( $css_tc_pay_today );
$css_tc_pay_fixed   = in_array( $css_tc_pay_cfg['frequency'], array( 'semimonthly_1_15', 'semimonthly_15_eom', 'monthly' ), true );
$css_tc_pay_follows = ( 'engine' === $css_tc_pay_cfg['frequency'] );
$css_tc_pay_differs = Css_Tc_Payday::differs_from_engine( $css_tc_pay_cfg, $css_tc_pay_today );
$css_tc_pay_rows    = array();
$css_tc_pay_ready   = $css_tc_pay_follows || Css_Tc_Payday::is_ymd( $css_tc_pay_cfg['anchor'] );
if ( 'anchor' === $css_tc_pay_cfg['payday_mode'] && ! Css_Tc_Payday::is_ymd( $css_tc_pay_cfg['payday_anchor'] ) ) {
	$css_tc_pay_ready = false;
}
if ( $css_tc_pay_ready ) {
	$css_tc_pay_rows = Css_Tc_Payday::upcoming(
		Css_Tc_Payday::with_snapshot( array(), $css_tc_pay_today, $css_tc_pay_cfg, '' ),
		$css_tc_pay_today,
		4
	);
}
$css_tc_pay_length = Css_Tc_Payday::length_blurb( $css_tc_pay_cfg, $css_tc_pay_today );
?>
<form class="css-tc-payday-form" method="post" action="">
	<?php if ( ! $css_tc_pay_hist ) : ?>
		<div class="notice notice-info inline"><p><?php echo esc_html__( 'Paydays stay off until you save. Calendars show no bands, and the dashboard does not list a payday, until this form is saved.', 'css-timeclock-addon' ); ?></p></div>
	<?php endif; ?>
	<p class="description">
		<?php echo esc_html__( 'Colored bands use the timecard pay period: Monday through Sunday, weekly or biweekly, from the anchor on TC-Config. Payday is the first Friday after that period ends, which is 5 days after a Sunday end. Saving here does not change hours, overtime, or PTO.', 'css-timeclock-addon' ); ?>
	</p>
	<p class="description">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: Weekly or Biweekly, 2: anchor date */
				__( 'Timecard pay period: %1$s from %2$s.', 'css-timeclock-addon' ),
				'weekly' === $css_tc_pay_engine->length() ? __( 'Weekly', 'css-timeclock-addon' ) : __( 'Biweekly', 'css-timeclock-addon' ),
				Css_Tc_Payday::format_long( $css_tc_pay_engine->anchor() )
			)
		);
		if ( is_array( $css_tc_pay_bounds ) && ! empty( $css_tc_pay_bounds['start'] ) ) {
			echo ' ';
			echo esc_html(
				sprintf(
					/* translators: %s: current timecard period range */
					__( 'Today is in %s.', 'css-timeclock-addon' ),
					Css_Tc_Payday::format_range( $css_tc_pay_bounds['start'], $css_tc_pay_bounds['end'] )
				)
			);
		}
		?>
	</p>
	<div id="css-tc-payday-mismatch" class="notice notice-warning inline" <?php echo $css_tc_pay_differs ? '' : 'hidden'; ?>>
		<p><?php echo esc_html__( 'These bands do not match the timecard pay period. Hours, overtime, and PTO still use that Monday period. Choose “Same as the timecard pay period” so the bands stay aligned.', 'css-timeclock-addon' ); ?></p>
	</div>

	<h2><?php echo esc_html__( 'Pay', 'css-timeclock-addon' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="payday_frequency"><?php echo esc_html__( 'Pay frequency', 'css-timeclock-addon' ); ?></label></th>
			<td>
				<select name="payday_frequency" id="payday_frequency">
					<?php foreach ( Css_Tc_Payday::frequencies() as $css_tc_key => $css_tc_label ) : ?>
						<option value="<?php echo esc_attr( $css_tc_key ); ?>" <?php selected( $css_tc_pay_cfg['frequency'], $css_tc_key ); ?>><?php echo esc_html( $css_tc_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr data-css-tc-anchor-row <?php echo $css_tc_pay_follows ? 'hidden' : ''; ?>>
			<th scope="row"><label for="payday_period_anchor"><?php echo esc_html__( 'Pay period start date (anchor)', 'css-timeclock-addon' ); ?></label></th>
			<td>
				<input name="payday_period_anchor" id="payday_period_anchor" type="date" value="<?php echo esc_attr( $css_tc_pay_cfg['anchor'] ); ?>" />
				<p class="description"><?php echo esc_html__( 'Used only when this calendar does not follow the timecard pay period. Weekly, bi-weekly, and monthly periods step from this date. Semi-monthly periods follow the calendar split you picked.', 'css-timeclock-addon' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Period length', 'css-timeclock-addon' ); ?></th>
			<td><strong id="css-tc-payday-length"><?php echo esc_html( $css_tc_pay_length ? $css_tc_pay_length : __( 'Choose a pay period start date.', 'css-timeclock-addon' ) ); ?></strong></td>
		</tr>
		<tr>
			<th scope="row"><label for="payday_week_start"><?php echo esc_html__( 'Week starts on', 'css-timeclock-addon' ); ?></label></th>
			<td>
				<select name="payday_week_start" id="payday_week_start">
					<?php for ( $css_tc_dow = 0; $css_tc_dow < 7; $css_tc_dow++ ) : ?>
						<option value="<?php echo esc_attr( (string) $css_tc_dow ); ?>" <?php selected( (int) $css_tc_pay_cfg['week_start'], $css_tc_dow ); ?>><?php echo esc_html( Css_Tc_Payday::weekday_name( $css_tc_dow ) ); ?></option>
					<?php endfor; ?>
				</select>
				<p class="description"><?php echo esc_html__( 'Sets the calendar columns and repeats the pay-period label on that weekday. It does not change the period start.', 'css-timeclock-addon' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php echo esc_html__( 'Payday', 'css-timeclock-addon' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php echo esc_html__( 'Payday rule', 'css-timeclock-addon' ); ?></th>
			<td>
				<p>
					<label>
						<input type="radio" name="payday_mode" value="weekday" <?php checked( $css_tc_pay_cfg['payday_mode'], 'weekday' ); ?> />
						<?php echo esc_html__( 'First', 'css-timeclock-addon' ); ?>
						<select name="payday_weekday" id="payday_weekday">
							<?php for ( $css_tc_dow = 0; $css_tc_dow < 7; $css_tc_dow++ ) : ?>
								<option value="<?php echo esc_attr( (string) $css_tc_dow ); ?>" <?php selected( (int) $css_tc_pay_cfg['payday_weekday'], $css_tc_dow ); ?>><?php echo esc_html( Css_Tc_Payday::weekday_name( $css_tc_dow ) ); ?></option>
							<?php endfor; ?>
						</select>
						<?php echo esc_html__( 'after period ends', 'css-timeclock-addon' ); ?>
					</label>
				</p>
				<p>
					<label>
						<input type="radio" name="payday_mode" value="days" <?php checked( $css_tc_pay_cfg['payday_mode'], 'days' ); ?> />
						<input name="payday_days" id="payday_days" type="number" min="0" max="90" class="small-text" value="<?php echo esc_attr( (string) $css_tc_pay_cfg['payday_days'] ); ?>" />
						<?php echo esc_html__( 'days after period end', 'css-timeclock-addon' ); ?>
					</label>
				</p>
				<p data-css-tc-on-end <?php echo $css_tc_pay_fixed ? '' : 'hidden'; ?>>
					<label>
						<input type="radio" name="payday_mode" value="on_end" <?php checked( $css_tc_pay_cfg['payday_mode'], 'on_end' ); ?> />
						<?php echo esc_html__( 'On the period end date', 'css-timeclock-addon' ); ?>
					</label>
					<span class="description"><?php echo esc_html__( 'Semi-monthly and monthly only.', 'css-timeclock-addon' ); ?></span>
				</p>
				<p>
					<label>
						<input type="radio" name="payday_mode" value="anchor" <?php checked( $css_tc_pay_cfg['payday_mode'], 'anchor' ); ?> />
						<?php echo esc_html__( 'Every 14 days from this payday', 'css-timeclock-addon' ); ?>
						<input name="payday_anchor" id="payday_anchor" type="date" value="<?php echo esc_attr( $css_tc_pay_cfg['payday_anchor'] ); ?>" />
					</label>
				</p>
				<p class="description"><?php echo esc_html__( 'First Friday after the period ends is 5 days after a Sunday end. The 14-day list is counted from a known payday and does not move when the timecard anchor changes. Calendars stay blank until you save.', 'css-timeclock-addon' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="payday_shift"><?php echo esc_html__( 'Weekend / holiday payday', 'css-timeclock-addon' ); ?></label></th>
			<td>
				<select name="payday_shift" id="payday_shift">
					<option value="previous" <?php selected( $css_tc_pay_cfg['shift'], 'previous' ); ?>><?php echo esc_html__( 'Move to previous business day', 'css-timeclock-addon' ); ?></option>
					<option value="next" <?php selected( $css_tc_pay_cfg['shift'], 'next' ); ?>><?php echo esc_html__( 'Move to next business day', 'css-timeclock-addon' ); ?></option>
					<option value="keep" <?php selected( $css_tc_pay_cfg['shift'], 'keep' ); ?>><?php echo esc_html__( 'Keep the date', 'css-timeclock-addon' ); ?></option>
				</select>
				<p>
					<label for="payday_holidays"><?php echo esc_html__( 'Holidays that move payday', 'css-timeclock-addon' ); ?></label><br />
					<textarea name="payday_holidays" id="payday_holidays" rows="4" class="large-text code" placeholder="<?php echo esc_attr( "YYYY-MM-DD\nMM-DD" ); ?>"><?php echo esc_textarea( (string) $css_tc_pay_cfg['holidays'] ); ?></textarea>
				</p>
				<p class="description"><?php echo esc_html__( 'One date per line. YYYY-MM-DD is one year. MM-DD repeats every year. These dates move payday only. They do not change holiday pay on the Holidays tab. Office-closed days from that tab still show on the calendar.', 'css-timeclock-addon' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php echo esc_html__( 'Calendar display', 'css-timeclock-addon' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php echo esc_html__( 'Show on employee calendars', 'css-timeclock-addon' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="payday_show_employee" value="1" <?php checked( ! empty( $css_tc_pay_cfg['show_employee'] ) ); ?> />
					<?php echo esc_html__( 'On', 'css-timeclock-addon' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Show on admin calendars', 'css-timeclock-addon' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="payday_show_admin" value="1" <?php checked( ! empty( $css_tc_pay_cfg['show_admin'] ) ); ?> />
					<?php echo esc_html__( 'On', 'css-timeclock-addon' ); ?>
				</label>
				&nbsp;
				<label>
					<input type="checkbox" name="payday_show_closes" value="1" <?php checked( ! empty( $css_tc_pay_cfg['show_closes'] ) ); ?> />
					<?php echo esc_html__( 'Also show the “Period closes” marker', 'css-timeclock-addon' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Pay period band colors', 'css-timeclock-addon' ); ?></th>
			<td>
				<input type="color" id="payday_color_a_pick" value="<?php echo esc_attr( $css_tc_pay_cfg['color_a'] ); ?>" aria-label="<?php echo esc_attr__( 'First band color', 'css-timeclock-addon' ); ?>" />
				<input name="payday_color_a" id="payday_color_a" type="text" class="small-text code" value="<?php echo esc_attr( $css_tc_pay_cfg['color_a'] ); ?>" />
				<input type="color" id="payday_color_b_pick" value="<?php echo esc_attr( $css_tc_pay_cfg['color_b'] ); ?>" aria-label="<?php echo esc_attr__( 'Second band color', 'css-timeclock-addon' ); ?>" />
				<input name="payday_color_b" id="payday_color_b" type="text" class="small-text code" value="<?php echo esc_attr( $css_tc_pay_cfg['color_b'] ); ?>" />
				<p class="description"><?php echo esc_html__( 'Alternating pay periods.', 'css-timeclock-addon' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="payday_marker"><?php echo esc_html__( 'Payday marker', 'css-timeclock-addon' ); ?></label></th>
			<td>
				<select name="payday_marker" id="payday_marker">
					<option value="badge" <?php selected( $css_tc_pay_cfg['marker'], 'badge' ); ?>><?php echo esc_html__( '$ badge', 'css-timeclock-addon' ); ?></option>
					<option value="icon" <?php selected( $css_tc_pay_cfg['marker'], 'icon' ); ?>><?php echo esc_html__( 'Money icon', 'css-timeclock-addon' ); ?></option>
					<option value="star" <?php selected( $css_tc_pay_cfg['marker'], 'star' ); ?>><?php echo esc_html__( 'Star', 'css-timeclock-addon' ); ?></option>
				</select>
				<input type="color" id="payday_marker_color_pick" value="<?php echo esc_attr( $css_tc_pay_cfg['marker_color'] ); ?>" aria-label="<?php echo esc_attr__( 'Payday color', 'css-timeclock-addon' ); ?>" />
				<input name="payday_marker_color" id="payday_marker_color" type="text" class="small-text code" value="<?php echo esc_attr( $css_tc_pay_cfg['marker_color'] ); ?>" />
				<span id="css-tc-payday-marker-preview" class="css-tc-payday-badge" style="background:<?php echo esc_attr( $css_tc_pay_cfg['marker_color'] ); ?>"><?php echo esc_html( Css_Tc_Payday::marker_text( $css_tc_pay_cfg['marker'] ) ); ?></span>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="payday_effective"><?php echo esc_html__( 'Effective date', 'css-timeclock-addon' ); ?></label></th>
			<td>
				<input name="payday_effective" id="payday_effective" type="date" value="<?php echo esc_attr( $css_tc_pay_today ); ?>" />
				<p class="description"><?php echo esc_html__( 'The first save is also used for earlier dates. A later save keeps any pay period that already started. Use the same effective date as a saved row to replace that row.', 'css-timeclock-addon' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php echo esc_html__( 'Preview — next 4 pay periods', 'css-timeclock-addon' ); ?></h2>
	<p class="description"><?php echo esc_html__( 'This preview uses the schedule in the form, from today. It is not shown on calendars until you save.', 'css-timeclock-addon' ); ?></p>
	<p id="css-tc-payday-empty" class="description" <?php echo $css_tc_pay_rows ? 'hidden' : ''; ?>><?php echo esc_html__( 'Choose a pay period start date, or follow the timecard pay period. For the every-14-days rule, also choose a known payday.', 'css-timeclock-addon' ); ?></p>
	<table class="widefat striped css-tc-payday-table" id="css-tc-payday-preview">
		<thead>
			<tr>
				<th><?php echo esc_html__( 'Period start', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'Period end', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'Payday', 'css-timeclock-addon' ); ?></th>
				<th><?php echo esc_html__( 'Length', 'css-timeclock-addon' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $css_tc_pay_rows as $css_tc_row ) : ?>
				<tr>
					<td><?php echo esc_html( Css_Tc_Payday::format_long( $css_tc_row['start'] ) ); ?></td>
					<td><?php echo esc_html( Css_Tc_Payday::format_long( $css_tc_row['end'] ) ); ?></td>
					<td><?php echo esc_html( Css_Tc_Payday::marker_glyph( $css_tc_pay_cfg['marker'] ) . ' ' . Css_Tc_Payday::format_payday( $css_tc_row['payday'] ) ); ?></td>
					<td><?php echo esc_html( sprintf( /* translators: %d: number of days in the pay period */ __( '%d days', 'css-timeclock-addon' ), (int) $css_tc_row['length'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $css_tc_pay_hist ) : ?>
		<h2><?php echo esc_html__( 'Saved schedules', 'css-timeclock-addon' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Effective', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Frequency', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Period start', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Payday rule', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Saved', 'css-timeclock-addon' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $css_tc_pay_hist as $css_tc_snap ) : ?>
					<?php
					$css_tc_sc = $css_tc_snap['config'];
					if ( 'anchor' === $css_tc_sc['payday_mode'] ) {
						$css_tc_rule = sprintf(
							/* translators: %s: known payday Y-m-d */
							__( 'Every 14 days from %s', 'css-timeclock-addon' ),
							$css_tc_sc['payday_anchor']
						);
					} elseif ( 'days' === $css_tc_sc['payday_mode'] ) {
						$css_tc_rule = sprintf(
							/* translators: %d: days after the period ends */
							__( '%d days after period end', 'css-timeclock-addon' ),
							(int) $css_tc_sc['payday_days']
						);
					} elseif ( 'on_end' === $css_tc_sc['payday_mode'] ) {
						$css_tc_rule = __( 'On the period end date', 'css-timeclock-addon' );
					} else {
						$css_tc_rule = sprintf(
							/* translators: %s: weekday name */
							__( 'First %s after period ends', 'css-timeclock-addon' ),
							Css_Tc_Payday::weekday_name( (int) $css_tc_sc['payday_weekday'] )
						);
					}
					$css_tc_freqs = Css_Tc_Payday::frequencies();
					?>
					<tr>
						<td><?php echo esc_html( $css_tc_snap['effective'] ); ?></td>
						<td><?php echo esc_html( isset( $css_tc_freqs[ $css_tc_sc['frequency'] ] ) ? $css_tc_freqs[ $css_tc_sc['frequency'] ] : $css_tc_sc['frequency'] ); ?></td>
						<td><?php echo esc_html( 'engine' === $css_tc_sc['frequency'] ? __( 'Timecard pay period', 'css-timeclock-addon' ) : $css_tc_sc['anchor'] ); ?></td>
						<td><?php echo esc_html( $css_tc_rule ); ?></td>
						<td><?php echo esc_html( $css_tc_snap['saved_at'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<p class="submit">
		<button type="submit" class="button button-primary"><?php echo esc_html__( 'Save Changes', 'css-timeclock-addon' ); ?></button>
	</p>
</form>
