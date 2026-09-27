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
	<h1><?php echo esc_html( Css_Tc_Branding::BRAND ); ?></h1>
	<p class="css-tc-lead">
		<?php echo esc_html__( 'Shared tablet kiosks. Employees clock in and out with a PIN — no WordPress login on the tablet.', 'css-timeclock-addon' ); ?>
	</p>

	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( $base_url . '&tab=settings' ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>">
			<?php echo esc_html__( 'Kiosk settings', 'css-timeclock-addon' ); ?>
		</a>
		<a href="<?php echo esc_url( $base_url . '&tab=pins' ); ?>" class="nav-tab <?php echo 'pins' === $tab ? 'nav-tab-active' : ''; ?>">
			<?php echo esc_html__( 'Employee PINs', 'css-timeclock-addon' ); ?>
		</a>
		<a href="<?php echo esc_url( $base_url . '&tab=corrections' ); ?>" class="nav-tab <?php echo 'corrections' === $tab ? 'nav-tab-active' : ''; ?>">
			<?php echo esc_html__( 'Corrections', 'css-timeclock-addon' ); ?>
			<?php if ( ! empty( $queue['pending_count'] ) ) : ?>
				<span class="css-tc-tab-count"><?php echo esc_html( (string) (int) $queue['pending_count'] ); ?></span>
			<?php endif; ?>
		</a>
	</nav>

	<div class="css-tc-notice" hidden></div>

	<?php if ( 'settings' === $tab ) : ?>
		<form class="css-tc-settings-form" method="post" action="">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'PIN kiosk', 'css-timeclock-addon' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="pin_kiosk_enabled" value="1" <?php checked( ! empty( $settings['pin_kiosk_enabled'] ) ); ?> />
							<?php echo esc_html__( 'Enable the PIN pad kiosk', 'css-timeclock-addon' ); ?>
						</label>
						<p class="description">
							<?php echo esc_html__( 'Shortcode:', 'css-timeclock-addon' ); ?>
							<code>[css_tc_pin_kiosk]</code>
							<?php if ( $pin_page ) : ?>
								— <a href="<?php echo esc_url( $pin_page ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open PIN kiosk page', 'css-timeclock-addon' ); ?></a>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Name-list kiosk', 'css-timeclock-addon' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="name_kiosk_enabled" value="1" <?php checked( ! empty( $settings['name_kiosk_enabled'] ) ); ?> />
							<?php echo esc_html__( 'Enable the name-list kiosk', 'css-timeclock-addon' ); ?>
						</label>
						<p class="description">
							<?php echo esc_html__( 'Shortcode:', 'css-timeclock-addon' ); ?>
							<code>[css_tc_name_kiosk]</code>
							<?php echo esc_html__( 'Phase 1 always asks for a PIN after a name is tapped.', 'css-timeclock-addon' ); ?>
							<?php if ( $name_page ) : ?>
								— <a href="<?php echo esc_url( $name_page ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open name kiosk page', 'css-timeclock-addon' ); ?></a>
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
							<?php echo esc_html__( 'One IPv4 or IPv6 address or CIDR per line (for example 203.0.113.10 or 203.0.113.0/24). Lines starting with # are comments.', 'css-timeclock-addon' ); ?>
						</p>
						<p class="description">
							<?php echo esc_html__( 'Uses the same client IP as the failed-PIN limit. On WP Engine that is the visitor in X-Forwarded-For (the platform proxy), not the load balancer in REMOTE_ADDR. Another proxy in front of WP Engine must forward the real client address or the tablets will not match this list.', 'css-timeclock-addon' ); ?>
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
					<th scope="row"><?php echo esc_html__( 'Employee times', 'css-timeclock-addon' ); ?></th>
					<td>
						<p class="description">
							<?php echo esc_html__( 'Shortcode:', 'css-timeclock-addon' ); ?>
							<code>[css_tc_my_times]</code>
							<?php if ( $times_page ) : ?>
								— <a href="<?php echo esc_url( $times_page ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open employee times page', 'css-timeclock-addon' ); ?></a>
							<?php endif; ?>
						</p>
						<p class="description"><?php echo esc_html__( 'Logged-in employees see their own timecard here. Supervisors open SMOTC → Timecards for any employee. Corrections for the current pay period are approved on the Corrections tab. Past pay periods are display-only.', 'css-timeclock-addon' ); ?></p>
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
							<input name="overtime_hours" id="overtime_hours" type="number" min="1" max="336" step="0.25" value="<?php echo esc_attr( (string) ( isset( $settings['overtime_hours'] ) ? $settings['overtime_hours'] : 40 ) ); ?>" class="small-text" />
							<?php echo esc_html__( 'hours per', 'css-timeclock-addon' ); ?>
							<select name="overtime_weeks" id="overtime_weeks">
								<option value="1" <?php selected( isset( $settings['overtime_weeks'] ) ? (int) $settings['overtime_weeks'] : 1, 1 ); ?>><?php echo esc_html__( '1 week', 'css-timeclock-addon' ); ?></option>
								<option value="2" <?php selected( isset( $settings['overtime_weeks'] ) ? (int) $settings['overtime_weeks'] : 1, 2 ); ?>><?php echo esc_html__( '2 weeks', 'css-timeclock-addon' ); ?></option>
							</select>
							<?php echo esc_html__( 'are overtime.', 'css-timeclock-addon' ); ?>
						</p>
						<p class="description"><?php echo esc_html__( 'Weeks are the pay period’s Monday–Sunday weeks. US federal overtime is 40 hours per 1 week. A 2-week window needs a biweekly pay period. The timecard and reports show Overtime as its own pay code; pay rates are set in your payroll system.', 'css-timeclock-addon' ); ?></p>
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
				<?php echo esc_html__( 'AIO Lite’s clock AJAX only runs for a logged-in WordPress user. This add-on does not edit AIO files. After a valid PIN it creates or closes the same shift custom posts AIO uses (post type shift, author = employee, meta employee_clock_in_time / employee_clock_out_time), stored as UTC. If someone still uses AIO’s clock button, that save is rewritten to UTC. The /time-clock/ page redirects to an SMOTC kiosk. SMOTC → Real Time Monitoring lists fresh open shifts as working and older open shifts as missed clock-outs, in the site timezone.', 'css-timeclock-addon' ); ?>
			</p>
		</div>
	<?php elseif ( 'pins' === $tab ) : ?>
		<p>
			<?php echo esc_html__( 'PINs are stored with WordPress password hashing. They are never saved in plaintext. The eye on each PIN field only reveals the digits you are typing. Each PIN must be unique. Employees without a PIN do not appear on the name-list kiosk.', 'css-timeclock-addon' ); ?>
		</p>

		<p>
			<label for="css-tc-pin-filter" class="screen-reader-text"><?php echo esc_html__( 'Filter employees', 'css-timeclock-addon' ); ?></label>
			<input type="search" id="css-tc-pin-filter" class="regular-text" placeholder="<?php echo esc_attr__( 'Filter by name…', 'css-timeclock-addon' ); ?>" />
		</p>

		<table class="widefat striped css-tc-pin-table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Employee', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Role', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'PIN status', 'css-timeclock-addon' ); ?></th>
					<th><?php echo esc_html__( 'Set PIN', 'css-timeclock-addon' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $employees ) ) : ?>
				<tr>
					<td colspan="4">
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
					<tr class="css-tc-pin-row" data-name="<?php echo esc_attr( strtolower( css_tc_addon()->employees->display_name( (int) $user->ID ) ) ); ?>">
						<td>
							<strong><?php echo esc_html( css_tc_addon()->employees->display_name( (int) $user->ID ) ); ?></strong>
							<div class="row-actions">
								<?php echo esc_html( $user->user_login ); ?>
							</div>
						</td>
						<td><?php echo esc_html( $roles ); ?></td>
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
						<td>
							<form class="css-tc-pin-form" data-user-id="<?php echo esc_attr( (string) (int) $user->ID ); ?>">
								<?php $pin_input_id = 'css-tc-pin-' . (int) $user->ID; ?>
								<label class="screen-reader-text" for="<?php echo esc_attr( $pin_input_id ); ?>"><?php echo esc_html__( 'New PIN', 'css-timeclock-addon' ); ?></label>
								<span class="css-tc-pin-field">
									<input id="<?php echo esc_attr( $pin_input_id ); ?>" class="css-tc-pin-input" type="password" inputmode="numeric" autocomplete="new-password" maxlength="12" pattern="[0-9]*" />
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
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
	<?php else : ?>
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
