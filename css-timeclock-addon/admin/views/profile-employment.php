<?php
/**
 * Hire date and introductory period on the user profile (managers only).
 *
 * @package CssTimeclockAddon
 *
 * @var WP_User $user
 * @var string  $hire
 * @var int     $intro
 * @var string  $from
 * @var bool    $holidays
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h2 id="css-tc-employment"><?php echo esc_html__( 'Time clock employment', 'css-timeclock-addon' ); ?></h2>
<input type="hidden" name="css_tc_employment_present" value="1" />
<?php wp_nonce_field( Css_Tc_Holidays::PROFILE_NONCE, 'css_tc_employment_nonce' ); ?>
<?php
$css_tc_st    = css_tc_addon()->status;
$css_tc_srec  = $css_tc_st->record( (int) $user->ID );
$css_tc_slog  = array_slice( $css_tc_st->log( (int) $user->ID ), 0, 8 );
$css_tc_slabs = Css_Tc_Status::labels();
?>
<input type="hidden" name="css_tc_status_present" value="1" />
<?php wp_nonce_field( Css_Tc_Status::PROFILE_NONCE, 'css_tc_status_nonce' ); ?>
<table class="form-table" role="presentation">
	<tr class="css-tc-status-row">
		<th scope="row"><label for="css_tc_status"><?php echo esc_html__( 'Employment status', 'css-timeclock-addon' ); ?></label></th>
		<td>
			<p><strong><?php echo esc_html( $css_tc_st->describe( (int) $user->ID ) ); ?></strong></p>
			<select name="css_tc_status" id="css_tc_status" data-css-tc-status>
				<?php foreach ( $css_tc_slabs as $css_tc_k => $css_tc_l ) : ?>
					<option value="<?php echo esc_attr( $css_tc_k ); ?>" <?php selected( $css_tc_srec['status'], $css_tc_k ); ?>><?php echo esc_html( $css_tc_l ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="css-tc-status-fields" data-for="leave">
				<label><?php echo esc_html__( 'from', 'css-timeclock-addon' ); ?> <input type="date" name="css_tc_leave_from" value="<?php echo esc_attr( $css_tc_srec['leave_from'] ); ?>" /></label>
				<label><?php echo esc_html__( 'until (optional)', 'css-timeclock-addon' ); ?> <input type="date" name="css_tc_leave_to" value="<?php echo esc_attr( $css_tc_srec['leave_to'] ); ?>" /></label>
			</span>
			<span class="css-tc-status-fields" data-for="inactive">
				<label><?php echo esc_html__( 'last day worked', 'css-timeclock-addon' ); ?> <input type="date" name="css_tc_last_day" value="<?php echo esc_attr( $css_tc_srec['last_day'] ); ?>" /></label>
			</span>
			<p><input type="text" name="css_tc_status_note" class="regular-text" maxlength="200" placeholder="<?php echo esc_attr__( 'Note for the log (optional), e.g. medical leave, resigned', 'css-timeclock-addon' ); ?>" /></p>
			<p class="description">
				<?php echo esc_html__( 'On leave: cannot use the kiosk or request PTO, is hidden from the Who\'s working board, and gets no automatic holiday pay; they can still sign in to see their timecard. After the end date they are active again automatically.', 'css-timeclock-addon' ); ?><br />
				<?php echo esc_html__( 'Inactive: from the day after the last day worked, cannot use the kiosk or sign in, gets no holiday pay, and the PTO balance stops at the last day (shown for payout). Their hours stay on timecards and in reports.', 'css-timeclock-addon' ); ?><br />
				<?php echo esc_html__( 'Managers can still add PTO or a holiday for them under Time off. The WordPress role is not changed.', 'css-timeclock-addon' ); ?>
			</p>
			<?php if ( ! empty( $css_tc_slog ) ) : ?>
				<ul class="css-tc-leave-log">
					<?php foreach ( $css_tc_slog as $css_tc_e ) : ?>
						<?php $css_tc_by = get_userdata( (int) $css_tc_e['by'] ); ?>
						<li>
							<?php
							$css_tc_txt = ( $css_tc_slabs[ $css_tc_e['status'] ] ?? $css_tc_e['status'] );
							if ( ! empty( $css_tc_e['leave_from'] ) ) {
								$css_tc_txt .= ' ' . $css_tc_e['leave_from'] . ( ! empty( $css_tc_e['leave_to'] ) ? ' – ' . $css_tc_e['leave_to'] : '' );
							}
							if ( ! empty( $css_tc_e['last_day'] ) ) {
								$css_tc_txt .= ' ' . sprintf( /* translators: %s: date */ __( '(last day %s)', 'css-timeclock-addon' ), $css_tc_e['last_day'] );
							}
							echo esc_html( wp_date( get_option( 'date_format' ), (int) $css_tc_e['at'] ) . ' · ' . $css_tc_txt . ( '' !== $css_tc_e['note'] ? ' · ' . $css_tc_e['note'] : '' ) . ' · ' . ( $css_tc_by ? $css_tc_by->display_name : '?' ) );
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<script>
			( function () {
				var sel = document.querySelector( '[data-css-tc-status]' );
				if ( ! sel ) { return; }
				function sync() {
					document.querySelectorAll( '.css-tc-status-fields' ).forEach( function ( el ) {
						el.hidden = el.getAttribute( 'data-for' ) !== sel.value;
					} );
				}
				sel.addEventListener( 'change', sync );
				sync();
			} )();
			</script>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="css_tc_hire_date"><?php echo esc_html__( 'Hire date', 'css-timeclock-addon' ); ?></label></th>
		<td>
			<input type="date" name="css_tc_hire_date" id="css_tc_hire_date" value="<?php echo esc_attr( $hire ); ?>" />
		</td>
	</tr>
	<tr>
		<th scope="row"><?php echo esc_html__( 'Introductory period', 'css-timeclock-addon' ); ?></th>
		<td>
			<label>
				<input type="checkbox" name="css_tc_intro_on" value="1" <?php checked( $intro > 0 ); ?> />
				<?php echo esc_html__( 'Introductory period of', 'css-timeclock-addon' ); ?>
			</label>
			<select name="css_tc_intro_days" aria-label="<?php echo esc_attr__( 'Introductory period length', 'css-timeclock-addon' ); ?>">
				<?php foreach ( Css_Tc_Holidays::INTRO_CHOICES as $css_tc_days ) : ?>
					<option value="<?php echo esc_attr( (string) $css_tc_days ); ?>" <?php selected( $intro > 0 ? $intro : 90, $css_tc_days ); ?>><?php echo esc_html( sprintf( /* translators: %d: days */ __( '%d days', 'css-timeclock-addon' ), $css_tc_days ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php echo esc_html__( 'from the hire date', 'css-timeclock-addon' ); ?>
			<p class="description">
				<?php echo esc_html__( 'No holiday pay before the hire date or during the introductory period.', 'css-timeclock-addon' ); ?>
				<?php if ( '' !== $from ) : ?>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: date */
							__( 'Holiday pay starts %s.', 'css-timeclock-addon' ),
							css_tc_addon()->time->format_day_label( $from )
						)
					);
					?>
				<?php else : ?>
					<?php echo esc_html__( 'With no hire date, holiday pay applies to every holiday.', 'css-timeclock-addon' ); ?>
				<?php endif; ?>
				<?php if ( ! $holidays ) : ?>
					<?php echo esc_html__( 'Holiday pay is turned off in TC-Config → Holidays.', 'css-timeclock-addon' ); ?>
				<?php endif; ?>
			</p>
		</td>
	</tr>
</table>
