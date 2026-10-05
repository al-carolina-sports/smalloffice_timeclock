<?php
/**
 * Admin settings + PIN table.
 *
 * @package CssTimeclockAddon
 *
 * @var array<string,mixed> $settings
 * @var WP_User[]           $employees
 * @var string              $tab
 * @var string              $pin_page
 * @var string              $name_page
 * @var string              $times_page
 * @var string              $base_url
 * @var array<string,mixed> $queue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap css-tc-admin">
	<h1><?php echo esc_html( Css_Tc_Branding::FULL_NAME ); ?></h1>
	<p class="css-tc-lead">
		<?php echo esc_html__( 'Shared tablet kiosks. Employees clock in and out with a PIN — no WordPress login on the tablet.', 'css-timeclock-addon' ); ?>
	</p>

	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( $base_url . '&tab=settings' ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>">
			<?php echo esc_html__( 'TC-Config', 'css-timeclock-addon' ); ?>
		</a>
		<a href="<?php echo esc_url( $base_url . '&tab=holidays' ); ?>" class="nav-tab <?php echo 'holidays' === $tab ? 'nav-tab-active' : ''; ?>">
			<?php echo esc_html__( 'Holidays', 'css-timeclock-addon' ); ?>
		</a>
		<a href="<?php echo esc_url( $base_url . '&tab=leave' ); ?>" class="nav-tab <?php echo 'leave' === $tab ? 'nav-tab-active' : ''; ?>">
			<?php echo esc_html__( 'PTO & sick', 'css-timeclock-addon' ); ?>
		</a>
		<a href="<?php echo esc_url( $base_url . '&tab=pins' ); ?>" class="nav-tab <?php echo 'pins' === $tab ? 'nav-tab-active' : ''; ?>">
			<?php echo esc_html__( 'Employee', 'css-timeclock-addon' ); ?>
		</a>
		<a href="<?php echo esc_url( $base_url . '&tab=import' ); ?>" class="nav-tab <?php echo 'import' === $tab ? 'nav-tab-active' : ''; ?>">
			<?php echo esc_html__( 'Import employees', 'css-timeclock-addon' ); ?>
		</a>
		<a href="<?php echo esc_url( $base_url . '&tab=locations' ); ?>" class="nav-tab <?php echo 'locations' === $tab ? 'nav-tab-active' : ''; ?>">
			<?php echo esc_html__( 'Locations & departments', 'css-timeclock-addon' ); ?>
		</a>
		<a href="<?php echo esc_url( $base_url . '&tab=corrections' ); ?>" class="nav-tab <?php echo 'corrections' === $tab ? 'nav-tab-active' : ''; ?>">
			<?php echo esc_html__( 'Corrections', 'css-timeclock-addon' ); ?>
			<?php if ( ! empty( $queue['pending_count'] ) ) : ?>
				<span class="css-tc-tab-count"><?php echo esc_html( (string) (int) $queue['pending_count'] ); ?></span>
			<?php endif; ?>
		</a>
	</nav>

	<div class="css-tc-notice" hidden></div>

	<?php if ( 'holidays' === $tab ) : ?>
		<?php include CSS_TC_ADDON_DIR . 'admin/views/holiday-settings.php'; ?>
	<?php elseif ( 'leave' === $tab ) : ?>
		<?php include CSS_TC_ADDON_DIR . 'admin/views/leave-settings.php'; ?>
	<?php elseif ( 'locations' === $tab ) : ?>
		<?php include CSS_TC_ADDON_DIR . 'admin/views/organization-tab.php'; ?>
	<?php elseif ( 'import' === $tab ) : ?>
		<?php include CSS_TC_ADDON_DIR . 'admin/views/import-tab.php'; ?>
	<?php endif; ?>

	<?php if ( 'settings' === $tab ) : ?>
		<form class="css-tc-settings-form" method="post" action="">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Time clock kiosk', 'css-timeclock-addon' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="pin_kiosk_enabled" value="1" <?php checked( ! empty( $settings['pin_kiosk_enabled'] ) ); ?> />
							<?php echo esc_html__( 'Enable the time clock kiosk', 'css-timeclock-addon' ); ?>
						</label>
						<p class="description"><?php echo esc_html__( 'The main time clock page. Employees type their PIN, or tap their name in the Who\'s working list and then enter their PIN. This replaces the separate name-list kiosk; its old page now opens this one.', 'css-timeclock-addon' ); ?></p>
						<p class="description">
							<?php echo esc_html__( 'Shortcode:', 'css-timeclock-addon' ); ?>
							<code>[css_tc_pin_kiosk]</code>
							<?php if ( $pin_page ) : ?>
								— <a href="<?php echo esc_url( $pin_page ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open the time clock', 'css-timeclock-addon' ); ?></a>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pin_min_length"><?php echo esc_html__( 'PIN length', 'css-timeclock-addon' ); ?></label></th>
					<td>
						<input name="pin_min_length" id="pin_min_length" type="number" min="4" max="8" value="<?php echo esc_attr( (string) $settings['pin_min_length'] ); ?>" class="small-text" />
						<?php echo esc_html__( 'to', 'css-timeclock-addon' ); ?>
						<input name="pin_max_length" id="pin_max_length" type="number" min="4" max="12" value="<?php echo esc_attr( (string) $settings['pin_max_length'] ); ?>" class="small-text" />
						<?php echo esc_html__( 'digits', 'css-timeclock-addon' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rate_limit_max"><?php echo esc_html__( 'Failed PIN limit', 'css-timeclock-addon' ); ?></label></th>
					<td>
						<input name="rate_limit_max" id="rate_limit_max" type="number" min="3" max="20" value="<?php echo esc_attr( (string) $settings['rate_limit_max'] ); ?>" class="small-text" />
						<?php echo esc_html__( 'incorrect attempts per tablet IP, then lock for', 'css-timeclock-addon' ); ?>
						<input name="rate_limit_window" id="rate_limit_window" type="number" min="60" max="3600" value="<?php echo esc_attr( (string) $settings['rate_limit_window'] ); ?>" class="small-text" />
						<?php echo esc_html__( 'seconds.', 'css-timeclock-addon' ); ?>
					</td>
				</tr>
				<?php
				$office_ip       = css_tc_addon()->pins->client_ip();
				$office_raw      = isset( $settings['ip_allowlist'] ) ? (string) $settings['ip_allowlist'] : '';
				$office_parsed   = css_tc_addon()->pins->parse_allowlist( $office_raw );
				$office_enforcing = ! empty( $settings['ip_allowlist_enabled'] ) && ! empty( $office_parsed['entries'] );
				$office_here     = css_tc_addon()->pins->is_client_allowed();
				?>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Office IP allowlist', 'css-timeclock-addon' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ip_allowlist_enabled" value="1" <?php checked( ! empty( $settings['ip_allowlist_enabled'] ) ); ?> />
							<?php echo esc_html__( 'Only allow kiosk punches from these networks', 'css-timeclock-addon' ); ?>
						</label>
						<p class="description">
							<?php echo esc_html__( 'Off, or on with an empty list, allows every network. Comments and blank lines do not count as addresses, so turning this on with an empty box does not lock the sandbox out. WordPress admin, PIN management, and corrections stay available from any IP.', 'css-timeclock-addon' ); ?>
						</p>
						<label for="ip_allowlist" class="screen-reader-text"><?php echo esc_html__( 'Allowed IPs and CIDR ranges', 'css-timeclock-addon' ); ?></label>
						<textarea name="ip_allowlist" id="ip_allowlist" rows="6" class="large-text code css-tc-allowlist" placeholder="<?php echo esc_attr__( '203.0.113.10', 'css-timeclock-addon' ); ?>"><?php echo esc_textarea( $office_raw ); ?></textarea>
						<p class="description">
							<?php echo esc_html__( 'One per line: an IPv4 or IPv6 address, a CIDR range (203.0.113.0/24), or a hostname for an office on a changing address (for example csswilson.ddns.net). Hostnames are looked up every few minutes; if a lookup fails the last address that worked is kept. Lines starting with # are comments.', 'css-timeclock-addon' ); ?>
						</p>
						<?php Css_Tc_Admin::render_host_status( $office_raw ); ?>
						<p class="description">
							<?php echo esc_html__( 'Uses the same client address as the failed-PIN limit and office detection. Forwarded headers (X-Forwarded-For and similar) are only believed when they come from the hosting network or a trusted proxy listed below, so a visitor cannot pretend to be at the office.', 'css-timeclock-addon' ); ?>
						</p>
						<?php if ( '' !== $office_ip ) : ?>
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: IP address seen by this admin browser */
										__( 'This browser’s address for the allowlist is %s.', 'css-timeclock-addon' ),
										$office_ip
									)
								);
								?>
							</p>
						<?php else : ?>
							<p class="description"><?php echo esc_html__( 'This browser’s address could not be read. Kiosk checks use the same lookup.', 'css-timeclock-addon' ); ?></p>
						<?php endif; ?>
						<?php if ( $office_enforcing && ! $office_here ) : ?>
							<p class="description css-tc-allowlist-warn">
								<?php echo esc_html__( 'Kiosk punches from this browser will be refused until this address is listed. This admin screen is not blocked.', 'css-timeclock-addon' ); ?>
							</p>
						<?php elseif ( ! empty( $settings['ip_allowlist_enabled'] ) && empty( $office_parsed['entries'] ) ) : ?>
							<p class="description">
								<?php echo esc_html__( 'The checkbox is on, but there are no addresses yet, so kiosks still allow every network.', 'css-timeclock-addon' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Refused kiosk requests', 'css-timeclock-addon' ); ?></th>
					<td>
						<?php $css_tc_refused = array_slice( array_reverse( css_tc_addon()->pins->refused_kiosk_log() ), 0, 50 ); ?>
						<?php if ( empty( $css_tc_refused ) ) : ?>
							<p class="description"><?php echo esc_html__( 'No refused kiosk requests.', 'css-timeclock-addon' ); ?></p>
						<?php else : ?>
							<p class="description"><?php echo esc_html__( 'Kiosk requests turned away by the office IP allowlist (newest first, at most one per address per minute). PINs are never recorded.', 'css-timeclock-addon' ); ?></p>
							<table class="widefat striped css-tc-refused-log">
								<thead>
									<tr>
										<th><?php echo esc_html__( 'Time', 'css-timeclock-addon' ); ?></th>
										<th><?php echo esc_html__( 'IP', 'css-timeclock-addon' ); ?></th>
										<th><?php echo esc_html__( 'Action', 'css-timeclock-addon' ); ?></th>
										<th><?php echo esc_html__( 'Employee', 'css-timeclock-addon' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $css_tc_refused as $css_tc_row ) : ?>
										<tr>
											<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $css_tc_row['time'] ) ); ?></td>
											<td><code><?php echo esc_html( $css_tc_row['ip'] ); ?></code></td>
											<td><code><?php echo esc_html( $css_tc_row['action'] ); ?></code></td>
											<td><?php echo esc_html( '' !== $css_tc_row['name'] ? $css_tc_row['name'] : '—' ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</td>
				</tr>
				<?php $css_tc_diag = css_tc_addon()->pins->ip_diagnostics(); ?>
				<tr>
					<th scope="row"><label for="trusted_proxies"><?php echo esc_html__( 'Trusted proxies', 'css-timeclock-addon' ); ?></label></th>
					<td>
						<textarea name="trusted_proxies" id="trusted_proxies" rows="3" class="large-text code" placeholder="<?php echo esc_attr__( '# usually leave empty', 'css-timeclock-addon' ); ?>"><?php echo esc_textarea( (string) ( $settings['trusted_proxies'] ?? '' ) ); ?></textarea>
						<p class="description">
							<?php echo esc_html__( 'Usually leave empty. Private and hosting-internal addresses are already trusted. Only list the public addresses of a CDN or load balancer you put in front of the site, if the detected address below shows that service instead of your office.', 'css-timeclock-addon' ); ?>
						</p>
						<details class="css-tc-ip-diag">
							<summary><?php echo esc_html__( 'What this request looks like', 'css-timeclock-addon' ); ?></summary>
							<table class="widefat striped" style="max-width:640px">
								<tbody>
									<?php foreach ( $css_tc_diag as $css_tc_k => $css_tc_v ) : ?>
										<tr>
											<th scope="row" style="width:160px"><?php echo esc_html( $css_tc_k ); ?></th>
											<td><code><?php echo esc_html( '' !== $css_tc_v ? $css_tc_v : '—' ); ?></code></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</details>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Employee times', 'css-timeclock-addon' ); ?></th>
					<td>
						<p class="description">
							<?php echo esc_html__( 'Shortcode:', 'css-timeclock-addon' ); ?>
							<code>[css_tc_my_times]</code>
							<?php if ( $times_page ) : ?>
								— <a href="<?php echo esc_url( $times_page ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open employee times page', 'css-timeclock-addon' ); ?></a>
							<?php endif; ?>
						</p>
						<p class="description"><?php echo esc_html__( 'Logged-in employees see their own timecard here. Supervisors open USOTC → Timecards for any employee. Corrections for the current pay period are approved on the Corrections tab. Past pay periods are display-only.', 'css-timeclock-addon' ); ?></p>
						<label>
							<input type="checkbox" name="wide_layout" value="1" <?php checked( ! isset( $settings['wide_layout'] ) || ! empty( $settings['wide_layout'] ) ); ?> />
							<?php echo esc_html__( 'Wide layout', 'css-timeclock-addon' ); ?>
						</label>
						<p class="description"><?php echo esc_html__( 'My Time Clock, its corrections view, and the kiosks use a full-width page so the week grid can use the screen. Turn this off to leave those pages inside the theme’s content column. A genuinely narrow window still stacks the days.', 'css-timeclock-addon' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pay_period_length"><?php echo esc_html__( 'Pay period', 'css-timeclock-addon' ); ?></label></th>
					<td>
						<select name="pay_period_length" id="pay_period_length">
							<option value="weekly" <?php selected( isset( $settings['pay_period_length'] ) ? $settings['pay_period_length'] : 'biweekly', 'weekly' ); ?>><?php echo esc_html__( 'Weekly', 'css-timeclock-addon' ); ?></option>
							<option value="biweekly" <?php selected( isset( $settings['pay_period_length'] ) ? $settings['pay_period_length'] : 'biweekly', 'biweekly' ); ?>><?php echo esc_html__( 'Biweekly', 'css-timeclock-addon' ); ?></option>
						</select>
						<p class="description"><?php echo esc_html__( 'Weeks run Monday through Sunday. Biweekly is two of those weeks. The current period is the only one employees can correct.', 'css-timeclock-addon' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pay_period_anchor"><?php echo esc_html__( 'Pay period anchor', 'css-timeclock-addon' ); ?></label></th>
					<td>
						<input name="pay_period_anchor" id="pay_period_anchor" type="date" value="<?php echo esc_attr( isset( $settings['pay_period_anchor'] ) ? (string) $settings['pay_period_anchor'] : '2026-09-07' ); ?>" />
						<p class="description"><?php echo esc_html__( 'A Monday that starts a pay period. Default is Monday 2026-09-07. Periods before and after this date are counted from it.', 'css-timeclock-addon' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Overtime', 'css-timeclock-addon' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="overtime_enabled" value="1" <?php checked( ! empty( $settings['overtime_enabled'] ) ); ?> />
							<?php echo esc_html__( 'Calculate overtime', 'css-timeclock-addon' ); ?>
						</label>
						<p>
							<?php echo esc_html__( 'Hours worked after', 'css-timeclock-addon' ); ?>
							<input name="overtime_hours" id="overtime_hours" type="number" min="1" max="<?php echo esc_attr( (string) Css_Tc_Overtime::max_hours( isset( $settings['overtime_weeks'] ) ? (int) $settings['overtime_weeks'] : 1 ) ); ?>" step="0.25" value="<?php echo esc_attr( (string) ( isset( $settings['overtime_hours'] ) ? $settings['overtime_hours'] : 40 ) ); ?>" class="small-text" data-hours-per-week="<?php echo esc_attr( (string) Css_Tc_Overtime::HOURS_PER_WEEK ); ?>" />
							<?php echo esc_html__( 'hours per', 'css-timeclock-addon' ); ?>
							<select name="overtime_weeks" id="overtime_weeks">
								<option value="1" <?php selected( isset( $settings['overtime_weeks'] ) ? (int) $settings['overtime_weeks'] : 1, 1 ); ?>><?php echo esc_html__( '1 week', 'css-timeclock-addon' ); ?></option>
								<option value="2" <?php selected( isset( $settings['overtime_weeks'] ) ? (int) $settings['overtime_weeks'] : 1, 2 ); ?>><?php echo esc_html__( '2 weeks', 'css-timeclock-addon' ); ?></option>
							</select>
							<?php echo esc_html__( 'are overtime.', 'css-timeclock-addon' ); ?>
						</p>
						<p>
							<label for="overtime_scope"><?php echo esc_html__( 'With more than one company:', 'css-timeclock-addon' ); ?></label>
							<select name="overtime_scope" id="overtime_scope">
								<option value="combined" <?php selected( isset( $settings['overtime_scope'] ) ? $settings['overtime_scope'] : 'combined', 'combined' ); ?>><?php echo esc_html__( 'Add up hours across all companies', 'css-timeclock-addon' ); ?></option>
								<option value="per_company" <?php selected( isset( $settings['overtime_scope'] ) ? $settings['overtime_scope'] : 'combined', 'per_company' ); ?>><?php echo esc_html__( 'Count each company separately', 'css-timeclock-addon' ); ?></option>
							</select>
						</p>
						<p class="description"><?php echo esc_html__( 'When hours are added up across companies, overtime is charged to the company whose hours crossed the limit. Companies under common ownership may have to add hours up; check with your payroll provider.', 'css-timeclock-addon' ); ?></p>
						<p class="description"><?php echo esc_html__( 'Weeks are the pay period’s Monday–Sunday weeks. US federal overtime is 40 hours per 1 week. A 2-week window needs a biweekly pay period. The timecard and reports show Overtime as its own pay code; pay rates are set in your payroll system.', 'css-timeclock-addon' ); ?></p>
					</td>
				</tr>
				<tr id="assignments_enabled">
					<th scope="row"><?php echo esc_html__( 'Departments at clock-in', 'css-timeclock-addon' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="assignments_enabled" value="1" <?php checked( ! empty( $settings['assignments_enabled'] ) ); ?> />
							<?php echo esc_html__( 'Ask which department at clock-in', 'css-timeclock-addon' ); ?>
						</label>
						<p class="description"><?php echo esc_html__( 'Uses the companies, locations and departments on the Locations & departments tab. Employees only see departments assigned on their profile. The kiosk page location (shortcode attribute location="Raleigh") or the office network decides the location; otherwise the employee picks it. With one choice, the question is skipped.', 'css-timeclock-addon' ); ?></p>
					</td>
				</tr>
				<tr id="switch_enabled">
					<th scope="row"><?php echo esc_html__( 'Switch', 'css-timeclock-addon' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="switch_enabled" value="1" <?php checked( ! isset( $settings['switch_enabled'] ) || ! empty( $settings['switch_enabled'] ) ); ?> />
							<?php echo esc_html__( 'Allow Switch: move to another department without clocking out', 'css-timeclock-addon' ); ?>
						</label>
						<p class="description"><?php echo esc_html__( 'Shows a Switch button to clocked-in employees and offers "Switch to <office>" when they enter their PIN at another office. The current shift ends and the next one starts at the same second. Turned off, employees clock out and clock back in to change departments. Needs "Ask which department at clock-in".', 'css-timeclock-addon' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="missed_clock_out_hours"><?php echo esc_html__( 'Missed clock-out', 'css-timeclock-addon' ); ?></label></th>
					<td>
						<input name="missed_clock_out_hours" id="missed_clock_out_hours" type="number" min="1" max="36" value="<?php echo esc_attr( (string) ( isset( $settings['missed_clock_out_hours'] ) ? $settings['missed_clock_out_hours'] : 16 ) ); ?>" class="small-text" />
						<?php echo esc_html__( 'hours. An open shift older than this is not “clocked in” on the kiosk, who’s-working board, or Real Time Monitoring. The timecard flags it for correction.', 'css-timeclock-addon' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="long_shift_hours"><?php echo esc_html__( 'Long shift', 'css-timeclock-addon' ); ?></label></th>
					<td>
						<input name="long_shift_hours" id="long_shift_hours" type="number" min="1" max="36" value="<?php echo esc_attr( (string) ( isset( $settings['long_shift_hours'] ) ? $settings['long_shift_hours'] : 16 ) ); ?>" class="small-text" />
						<?php echo esc_html__( 'hours. A finished shift longer than this is flagged on the timecard. Those hours still count in the totals.', 'css-timeclock-addon' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="times_lookback_days"><?php echo esc_html__( 'Times lookback', 'css-timeclock-addon' ); ?></label></th>
					<td>
						<input name="times_lookback_days" id="times_lookback_days" type="number" min="7" max="60" value="<?php echo esc_attr( (string) ( isset( $settings['times_lookback_days'] ) ? $settings['times_lookback_days'] : 21 ) ); ?>" class="small-text" />
						<?php echo esc_html__( 'days kept on the older day-by-day list. Timecards follow the pay period instead.', 'css-timeclock-addon' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="idle_reset_ms"><?php echo esc_html__( 'Return to idle', 'css-timeclock-addon' ); ?></label></th>
					<td>
						<input name="idle_reset_ms" id="idle_reset_ms" type="number" min="3000" max="30000" step="500" value="<?php echo esc_attr( (string) $settings['idle_reset_ms'] ); ?>" class="small-text" />
						<?php echo esc_html__( 'milliseconds after a successful punch (no lingering employee session).', 'css-timeclock-addon' ); ?>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo esc_html__( 'Save settings', 'css-timeclock-addon' ); ?></button>
				<button type="button" class="button css-tc-create-pages"><?php echo esc_html__( 'Create or restore kiosk and times pages', 'css-timeclock-addon' ); ?></button>
			</p>
		</form>

		<div class="css-tc-help">
			<h2><?php echo esc_html__( 'How punches reach AIO Lite', 'css-timeclock-addon' ); ?></h2>
			<p>
				<?php echo esc_html__( 'AIO Lite’s clock AJAX only runs for a logged-in WordPress user. This add-on does not edit AIO files. After a valid PIN it creates or closes the same shift custom posts AIO uses (post type shift, author = employee, meta employee_clock_in_time / employee_clock_out_time), stored as UTC. If someone still uses AIO’s clock button, that save is rewritten to UTC. The /time-clock/ page redirects to an USOTC kiosk. USOTC → Real Time Monitoring lists fresh open shifts as working and older open shifts as missed clock-outs, in the site timezone.', 'css-timeclock-addon' ); ?>
			</p>
		</div>
	<?php elseif ( 'pins' === $tab ) : ?>
		<h2><?php echo esc_html__( 'Hire date and PIN', 'css-timeclock-addon' ); ?></h2>
		<p>
			<?php echo esc_html__( 'The kiosk checks PINs against a WordPress password hash. An encrypted copy (key from this site\'s wp-config.php secret keys) lets managers reveal a PIN with the eye in the PIN column; employees can reveal their own on My Time Clock. Each reveal is logged. PINs set before this version cannot be shown until a new PIN is set. Each PIN must be unique. Employees without a PIN do not appear on the name-list kiosk.', 'css-timeclock-addon' ); ?>
		</p>

		<p>
			<label for="css-tc-pin-filter" class="screen-reader-text"><?php echo esc_html__( 'Filter employees', 'css-timeclock-addon' ); ?></label>
			<input type="search" id="css-tc-pin-filter" class="regular-text" placeholder="<?php echo esc_attr__( 'Filter by name…', 'css-timeclock-addon' ); ?>" />
			<?php
			$css_tc_inactive_count = 0;
			foreach ( $employees as $css_tc_u ) {
				if ( Css_Tc_Status::INACTIVE === css_tc_addon()->status->current( (int) $css_tc_u->ID ) ) {
					++$css_tc_inactive_count;
				}
			}
			// Active and on-leave first, inactive last.
			usort(
				$employees,
				static function ( $a, $b ) {
					$ia = Css_Tc_Status::INACTIVE === css_tc_addon()->status->current( (int) $a->ID ) ? 1 : 0;
					$ib = Css_Tc_Status::INACTIVE === css_tc_addon()->status->current( (int) $b->ID ) ? 1 : 0;
					return $ia - $ib;
				}
			);
			?>
			<?php if ( $css_tc_inactive_count > 0 ) : ?>
				<label class="css-tc-show-inactive"><input type="checkbox" data-css-tc-show-inactive /> <?php echo esc_html( sprintf( /* translators: %d: count */ _n( 'Show %d inactive employee', 'Show %d inactive employees', $css_tc_inactive_count, 'css-timeclock-addon' ), $css_tc_inactive_count ) ); ?></label>
			<?php endif; ?>
		</p>

		<table class="widefat striped css-tc-pin-table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Employee', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Role', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Hire date', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'PIN status', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'PIN', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Set PIN', 'css-timeclock-addon' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $employees ) ) : ?>
				<tr>
					<td colspan="6">
						<?php echo esc_html__( 'No time-clock employees found. Create WordPress users with the Employee, Volunteer, Manager, or Contractor role (the roles AIO Lite uses).', 'css-timeclock-addon' ); ?>
					</td>
				</tr>
			<?php else : ?>
				<?php foreach ( $employees as $user ) : ?>
					<?php
					$has_pin = Css_Tc_Pins::user_has_pin( (int) $user->ID );
					$set_at  = (int) get_user_meta( (int) $user->ID, Css_Tc_Pins::META_SET, true );
					$roles   = implode( ', ', array_map( 'sanitize_text_field', (array) $user->roles ) );
					?>
					<?php $css_tc_now = css_tc_addon()->status->current( (int) $user->ID ); ?>
					<tr class="css-tc-pin-row css-tc-status--<?php echo esc_attr( $css_tc_now ); ?>" data-name="<?php echo esc_attr( strtolower( css_tc_addon()->employees->display_name( (int) $user->ID ) ) ); ?>"<?php echo Css_Tc_Status::INACTIVE === $css_tc_now ? ' hidden data-inactive="1"' : ''; ?>>
						<td>
							<strong><?php echo esc_html( css_tc_addon()->employees->display_name( (int) $user->ID ) ); ?></strong>
							<?php if ( Css_Tc_Status::ACTIVE !== $css_tc_now || 'Active' !== css_tc_addon()->status->describe( (int) $user->ID ) ) : ?>
								<span class="css-tc-status-badge css-tc-status-badge--<?php echo esc_attr( $css_tc_now ); ?>"><?php echo esc_html( css_tc_addon()->status->describe( (int) $user->ID ) ); ?></span>
							<?php endif; ?>
							<div class="row-actions">
								<?php echo esc_html( $user->user_login ); ?>
							</div>
						</td>
						<td><?php echo esc_html( $roles ); ?></td>
						<td class="css-tc-hire">
							<?php
							$css_tc_hire  = css_tc_addon()->holidays->hire_date( (int) $user->ID );
							$css_tc_intro = css_tc_addon()->holidays->intro_days( (int) $user->ID );
							$css_tc_from  = css_tc_addon()->holidays->eligible_from( (int) $user->ID );
							?>
							<a href="<?php echo esc_url( get_edit_user_link( (int) $user->ID ) . '#css-tc-employment' ); ?>">
								<?php echo esc_html( '' !== $css_tc_hire ? wp_date( get_option( 'date_format', 'Y-m-d' ), strtotime( $css_tc_hire . ' 12:00:00' ) ) : __( 'Set', 'css-timeclock-addon' ) ); ?>
							</a>
							<?php if ( '' !== $css_tc_hire && css_tc_addon()->leave->any_enabled() ) : ?>
								<?php $css_tc_lb = css_tc_addon()->leave->balances( (int) $user->ID ); ?>
								<div class="description">
									<?php
									$css_tc_lparts = array();
									foreach ( $css_tc_lb['banks'] as $css_tc_bank => $css_tc_t ) {
										$css_tc_lparts[] = ( 'pto' === $css_tc_bank ? 'PTO' : __( 'Sick', 'css-timeclock-addon' ) ) . ' ' . Css_Tc_Leave::hours( $css_tc_t['left'] ) . ' ' . __( 'left', 'css-timeclock-addon' );
									}
									echo esc_html( implode( ' · ', $css_tc_lparts ) );
									?>
								</div>
							<?php endif; ?>
							<?php if ( $css_tc_intro > 0 ) : ?>
								<div class="description">
									<?php
									echo esc_html(
										$css_tc_from > wp_date( 'Y-m-d' )
											/* translators: 1: days, 2: date */
											? sprintf( __( '%1$d-day intro · holiday pay from %2$s', 'css-timeclock-addon' ), $css_tc_intro, wp_date( get_option( 'date_format', 'Y-m-d' ), strtotime( $css_tc_from . ' 12:00:00' ) ) )
											/* translators: %d: days */
											: sprintf( __( '%d-day intro complete', 'css-timeclock-addon' ), $css_tc_intro )
									);
									?>
								</div>
							<?php endif; ?>
						</td>
						<td class="css-tc-pin-status">
							<?php if ( $has_pin ) : ?>
								<span class="css-tc-pill css-tc-pill-set"><?php echo esc_html__( 'Set', 'css-timeclock-addon' ); ?></span>
								<?php if ( $set_at ) : ?>
									<span class="description"><?php echo esc_html( wp_date( get_option( 'date_format', 'Y-m-d' ), $set_at ) ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<span class="css-tc-pill css-tc-pill-unset"><?php echo esc_html__( 'Not set', 'css-timeclock-addon' ); ?></span>
							<?php endif; ?>
						</td>
						<?php
						$css_tc_viewable = css_tc_addon()->pins->is_viewable( (int) $user->ID );
						$css_tc_last     = css_tc_addon()->pins->last_reveal( (int) $user->ID );
						?>
						<td class="css-tc-pin-reveal">
							<span class="css-tc-pin-field css-tc-pin-field--reveal">
								<code class="css-tc-pin-mask" data-pin-mask><?php echo $has_pin ? '••••' : '—'; ?></code>
								<button type="button" class="css-tc-pin-toggle css-tc-pin-reveal-btn" data-user-id="<?php echo esc_attr( (string) (int) $user->ID ); ?>" aria-pressed="false" aria-label="<?php echo esc_attr__( 'Show PIN', 'css-timeclock-addon' ); ?>" <?php echo $css_tc_viewable ? '' : 'hidden'; ?>>
									<svg class="css-tc-pin-toggle__show" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z" fill="none" stroke="currentColor" stroke-width="2" /><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2" /></svg><svg class="css-tc-pin-toggle__hide" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M3 3l18 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" /><path d="M10.6 6.2A10.4 10.4 0 0 1 12 6c6.5 0 10 6 10 6a18.2 18.2 0 0 1-3.2 3.8M6.1 6.7C3.7 8.3 2 12 2 12s3.5 6 10 6c1.2 0 2.3-.2 3.3-.6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" /><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
								</button>
							</span>
							<span class="description css-tc-pin-note" <?php echo ( $has_pin && ! $css_tc_viewable ) ? '' : 'hidden'; ?>><?php echo esc_html__( 'Set a new PIN to view it', 'css-timeclock-addon' ); ?></span>
							<?php if ( $css_tc_last ) : ?>
								<span class="description css-tc-pin-last">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: who viewed, 2: date/time */
											__( 'Last viewed by %1$s, %2$s', 'css-timeclock-addon' ),
											css_tc_addon()->employees->display_name( (int) $css_tc_last['by'] ),
											wp_date( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'g:i a' ), (int) $css_tc_last['at'] )
										)
									);
									?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<form class="css-tc-pin-form" data-user-id="<?php echo esc_attr( (string) (int) $user->ID ); ?>">
								<?php $pin_input_id = 'css-tc-pin-' . (int) $user->ID; ?>
								<label class="screen-reader-text" for="<?php echo esc_attr( $pin_input_id ); ?>"><?php echo esc_html__( 'New PIN', 'css-timeclock-addon' ); ?></label>
								<span class="css-tc-pin-field">
									<input id="<?php echo esc_attr( $pin_input_id ); ?>" class="css-tc-pin-input is-masked" type="text" inputmode="numeric" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="12" pattern="[0-9]*" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" />
									<button type="button" class="css-tc-pin-toggle" aria-pressed="false" aria-controls="<?php echo esc_attr( $pin_input_id ); ?>" aria-label="<?php echo esc_attr__( 'Show PIN', 'css-timeclock-addon' ); ?>">
										<svg class="css-tc-pin-toggle__show" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
											<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z" fill="none" stroke="currentColor" stroke-width="2" />
											<circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2" />
										</svg>
										<svg class="css-tc-pin-toggle__hide" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
											<path d="M3 3l18 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
											<path d="M10.6 6.2A10.4 10.4 0 0 1 12 6c6.5 0 10 6 10 6a18.2 18.2 0 0 1-3.2 3.8M6.1 6.7C3.7 8.3 2 12 2 12s3.5 6 10 6c1.2 0 2.3-.2 3.3-.6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
											<path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
										</svg>
									</button>
								</span>
								<button type="submit" class="button button-primary"><?php echo esc_html__( 'Save PIN', 'css-timeclock-addon' ); ?></button>
								<button type="button" class="button css-tc-clear-pin" <?php disabled( ! $has_pin ); ?>><?php echo esc_html__( 'Clear', 'css-timeclock-addon' ); ?></button>
								<span class="css-tc-pin-row-msg" role="status" aria-live="polite" hidden></span>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<h2 class="css-tc-section-heading"><?php echo esc_html__( 'Departments, locations, company settings', 'css-timeclock-addon' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: link to the Users screen, 2: link to the Locations & departments tab */
				esc_html__( 'Which departments, locations and companies an employee can clock into is set on each employee\'s own profile, under %1$s: open the employee and use the Time clock departments section. The companies, locations and departments themselves are created on the %2$s tab.', 'css-timeclock-addon' ),
				'<a href="' . esc_url( admin_url( 'users.php' ) ) . '">' . esc_html__( 'Users in the WordPress dashboard', 'css-timeclock-addon' ) . '</a>',
				'<a href="' . esc_url( $base_url . '&tab=locations' ) . '">' . esc_html__( 'Locations & departments', 'css-timeclock-addon' ) . '</a>'
			);
			?>
		</p>
		<script>
		( function () {
			var box = document.querySelector( '[data-css-tc-show-inactive]' );
			if ( ! box ) { return; }
			box.addEventListener( 'change', function () {
				document.querySelectorAll( '.css-tc-pin-row[data-inactive]' ).forEach( function ( row ) {
					row.hidden = ! box.checked;
				} );
			} );
		} )();
		</script>
	<?php elseif ( 'corrections' === $tab ) : ?>
		<p>
			<?php echo esc_html__( 'Employees suggest clock-in or clock-out corrections for the current pay period from My Time Clock. Approving writes the AIO-compatible shift and keeps the original times plus who suggested and who approved. A suggestion that would change a closed pay period is rejected. A manager edit from the timecard is saved immediately and listed under Recently reviewed as Edited by manager.', 'css-timeclock-addon' ); ?>
		</p>

		<h2><?php echo esc_html__( 'Pending', 'css-timeclock-addon' ); ?></h2>
		<div class="css-tc-correction-list" data-role="pending-list">
			<?php if ( empty( $queue['pending'] ) ) : ?>
				<p class="description css-tc-empty-queue"><?php echo esc_html__( 'No pending suggestions.', 'css-timeclock-addon' ); ?></p>
			<?php else : ?>
				<?php foreach ( $queue['pending'] as $item ) : ?>
					<?php
					$item_status = 'pending';
					include CSS_TC_ADDON_DIR . 'admin/views/correction-card.php';
					?>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<h2><?php echo esc_html__( 'Recently reviewed', 'css-timeclock-addon' ); ?></h2>
		<div class="css-tc-correction-list" data-role="recent-list">
			<?php if ( empty( $queue['recent'] ) ) : ?>
				<p class="description css-tc-empty-queue"><?php echo esc_html__( 'No reviewed suggestions yet.', 'css-timeclock-addon' ); ?></p>
			<?php else : ?>
				<?php foreach ( $queue['recent'] as $item ) : ?>
					<?php
					$item_status = isset( $item['status'] ) ? $item['status'] : '';
					include CSS_TC_ADDON_DIR . 'admin/views/correction-card.php';
					?>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
