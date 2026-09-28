<?php
/**
 * ADP-style timecard: period picker, summaries, Monday–Sunday day grid.
 *
 * @package CssTimeclockAddon
 *
 * @var array<string,mixed>      $sheet
 * @var string                   $mode        employee|admin
 * @var string                   $notice
 * @var string                   $form_error
 * @var WP_User[]                $employees   Admin picker. Optional.
 * @var int                      $prev_id
 * @var int                      $next_id
 * @var string                   $edit_day    Y-m-d manager editor, or empty.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mode       = isset( $mode ) ? $mode : 'employee';
$notice     = isset( $notice ) ? (string) $notice : '';
$form_error = isset( $form_error ) ? (string) $form_error : '';
$period     = $sheet['period'];
$is_open    = ! empty( $period['is_open'] );
$user_id    = (int) $sheet['user_id'];
$employees  = isset( $employees ) && is_array( $employees ) ? $employees : array();
$prev_id    = isset( $prev_id ) ? (int) $prev_id : 0;
$next_id    = isset( $next_id ) ? (int) $next_id : 0;
$edit_day   = isset( $edit_day ) ? (string) $edit_day : '';

$period_options = css_tc_addon()->pay_periods->dropdown_periods( 6 );
$monday         = css_tc_addon()->time->date_immutable( '2026-09-07' );
$dow = array();
if ( $monday ) {
	for ( $i = 0; $i < 7; $i++ ) {
		$day   = $monday->modify( '+' . $i . ' days' );
		$stamp = $day->getTimestamp();
		$dow[] = array(
			'full'  => function_exists( 'wp_date' ) ? wp_date( 'l', $stamp ) : $day->format( 'l' ),
			'short' => function_exists( 'wp_date' ) ? wp_date( 'D', $stamp ) : $day->format( 'D' ),
		);
	}
}

$pencil = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>';
$css_tc_check = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10" fill="currentColor"/><path fill="#fff" d="M10.2 15.4 7.1 12.3l-1.1 1.1 4.2 4.2 8-8-1.1-1.1z"/></svg>';
$css_tc_lock  = '<svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M10 2a4 4 0 0 0-4 4v2H5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V9a1 1 0 0 0-1-1h-1V6a4 4 0 0 0-4-4zm-2 6V6a2 2 0 1 1 4 0v2H8z"/></svg>';
$css_tc_flag  = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M5 3v18h2v-6h11l-2.2-4L18 7H7V3H5z"/></svg>';
?>
<div class="css-tc-sheet">
	<div class="css-tc-sheet__bar css-tc-no-print">
		<label class="css-tc-sheet__period">
			<span class="screen-reader-text"><?php echo esc_html__( 'Pay period', 'css-timeclock-addon' ); ?></span>
			<select data-css-tc-jump>
				<?php foreach ( $period_options as $option ) : ?>
					<?php
					$url = ( 'admin' === $mode )
						? Css_Tc_Admin::timecards_url(
							array(
								'employee' => $user_id,
								'period'   => $option['start'],
							)
						)
						: Css_Tc_Shortcodes::times_url( $option['start'] );
					?>
					<option value="<?php echo esc_url( $url ); ?>" <?php selected( $option['start'], $period['start'] ); ?>>
						<?php echo esc_html( $option['label'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<?php if ( 'admin' === $mode && ! empty( $employees ) ) : ?>
			<div class="css-tc-sheet__switcher">
				<?php if ( $prev_id ) : ?>
					<a class="css-tc-sheet__arrow" href="<?php echo esc_url( Css_Tc_Admin::timecards_url( array( 'employee' => $prev_id, 'period' => $period['start'] ) ) ); ?>" aria-label="<?php echo esc_attr__( 'Previous employee', 'css-timeclock-addon' ); ?>">&lsaquo;</a>
				<?php else : ?>
					<span class="css-tc-sheet__arrow is-disabled" aria-hidden="true">&lsaquo;</span>
				<?php endif; ?>
				<span class="css-tc-avatar" aria-hidden="true"><?php echo esc_html( $sheet['initials'] ); ?></span>
				<label class="screen-reader-text" for="css-tc-employee-jump"><?php echo esc_html__( 'Employee', 'css-timeclock-addon' ); ?></label>
				<select id="css-tc-employee-jump" data-css-tc-jump>
					<?php foreach ( $employees as $employee ) : ?>
						<?php $eid = (int) $employee->ID; ?>
						<option value="<?php echo esc_url( Css_Tc_Admin::timecards_url( array( 'employee' => $eid, 'period' => $period['start'] ) ) ); ?>" <?php selected( $eid, $user_id ); ?>>
							<?php echo esc_html( css_tc_addon()->employees->display_name( $eid ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php if ( $next_id ) : ?>
					<a class="css-tc-sheet__arrow" href="<?php echo esc_url( Css_Tc_Admin::timecards_url( array( 'employee' => $next_id, 'period' => $period['start'] ) ) ); ?>" aria-label="<?php echo esc_attr__( 'Next employee', 'css-timeclock-addon' ); ?>">&rsaquo;</a>
				<?php else : ?>
					<span class="css-tc-sheet__arrow is-disabled" aria-hidden="true">&rsaquo;</span>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<div class="css-tc-sheet__switcher css-tc-sheet__switcher--self">
				<span class="css-tc-avatar" aria-hidden="true"><?php echo esc_html( $sheet['initials'] ); ?></span>
				<strong><?php echo esc_html( $sheet['employee_name'] ); ?></strong>
			</div>
		<?php endif; ?>

		<div class="css-tc-sheet__right">
		<?php if ( 'employee' === $mode && Css_Tc_Pins::user_has_pin( get_current_user_id() ) ) : ?>
			<?php $css_tc_my_viewable = css_tc_addon()->pins->is_viewable( get_current_user_id() ); ?>
			<span class="css-tc-mypin css-tc-no-print" data-css-tc-mypin data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'css_tc_my_pin' ) ); ?>">
				<span class="css-tc-mypin__label"><?php echo esc_html__( 'Your PIN', 'css-timeclock-addon' ); ?></span>
				<code class="css-tc-mypin__value" data-mypin-value>••••</code>
				<?php if ( $css_tc_my_viewable ) : ?>
					<button type="button" class="css-tc-mypin__toggle" data-mypin-toggle aria-pressed="false" aria-label="<?php echo esc_attr__( 'Show PIN', 'css-timeclock-addon' ); ?>" data-label-show="<?php echo esc_attr__( 'Show PIN', 'css-timeclock-addon' ); ?>" data-label-hide="<?php echo esc_attr__( 'Hide PIN', 'css-timeclock-addon' ); ?>"><svg class="css-tc-mypin__show" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z" fill="none" stroke="currentColor" stroke-width="2" /><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2" /></svg><svg class="css-tc-mypin__hide" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M3 3l18 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" /><path d="M10.6 6.2A10.4 10.4 0 0 1 12 6c6.5 0 10 6 10 6a18.2 18.2 0 0 1-3.2 3.8M6.1 6.7C3.7 8.3 2 12 2 12s3.5 6 10 6c1.2 0 2.3-.2 3.3-.6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" /><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg></button>
				<?php else : ?>
					<span class="css-tc-mypin__note"><?php echo esc_html__( 'Ask a manager to set a new PIN to view it here', 'css-timeclock-addon' ); ?></span>
				<?php endif; ?>
			</span>
		<?php endif; ?>

		<button type="button" class="css-tc-print" onclick="window.print()"><?php echo esc_html__( 'Print', 'css-timeclock-addon' ); ?></button>
		</div>
	</div>

	<p class="css-tc-print-only">
		<?php echo esc_html( $sheet['employee_name'] . ' — ' . $period['label'] ); ?>
	</p>

	<?php if ( ! $is_open ) : ?>
		<p class="css-tc-banner css-tc-banner--closed" role="status">
			<?php
			if ( 'admin' === $mode ) {
				echo esc_html__( 'This pay period is closed. You can still edit a day. Saving updates the punches immediately and records the change.', 'css-timeclock-addon' );
			} else {
				echo esc_html__( 'This pay period is closed and can\'t be edited.', 'css-timeclock-addon' );
			}
			?>
		</p>
	<?php endif; ?>
	<?php if ( ! empty( $sheet['long_shift_count'] ) ) : ?>
		<p class="css-tc-banner css-tc-banner--long" role="status">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: number of long shifts, 2: hour limit */
					_n(
						'%1$d shift is longer than %2$d hours. Those hours are still included in the totals.',
						'%1$d shifts are longer than %2$d hours. Those hours are still included in the totals.',
						(int) $sheet['long_shift_count'],
						'css-timeclock-addon'
					),
					(int) $sheet['long_shift_count'],
					(int) $sheet['long_shift_max']
				)
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $notice ) : ?>
		<p class="css-tc-sheet__notice css-tc-no-print"><?php echo esc_html( $notice ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $form_error && ! ( 'admin' === $mode && '' !== $edit_day ) ) : ?>
		<p class="css-tc-sheet__error css-tc-no-print"><?php echo esc_html( $form_error ); ?></p>
	<?php endif; ?>

	<?php
	$edit_row = null;
	if ( 'admin' === $mode && '' !== $edit_day ) {
		foreach ( $sheet['weeks'] as $css_tc_week ) {
			foreach ( $css_tc_week['days'] as $css_tc_day ) {
				if ( isset( $css_tc_day['date'] ) && $css_tc_day['date'] === $edit_day ) {
					$edit_row = $css_tc_day;
				}
			}
		}
	}
	?>
	<?php if ( 'admin' === $mode && '' !== $edit_day ) : ?>
		<?php include CSS_TC_ADDON_DIR . 'admin/views/manager-day-edit.php'; ?>
	<?php endif; ?>

	<?php if ( 'employee' === $mode && $is_open ) : ?>
		<p class="css-tc-sheet__actions css-tc-no-print">
			<a class="css-tc-print" href="<?php echo esc_url( Css_Tc_Shortcodes::correct_url() ); ?>"><?php echo esc_html__( 'Correct this pay period', 'css-timeclock-addon' ); ?></a>
		</p>
	<?php endif; ?>

	<div class="css-tc-cards">
		<section class="css-tc-card">
			<h2><?php echo esc_html__( 'Pay Period Summary', 'css-timeclock-addon' ); ?></h2>
			<p class="css-tc-card__range"><?php echo esc_html( $period['range'] ); ?></p>
			<p class="css-tc-card__total">
				<span class="css-tc-card__hours"><?php echo esc_html( ! empty( $sheet['holiday_seconds'] ) ? $sheet['paid_hm'] : $sheet['total_hm'] ); ?></span>
				<span class="css-tc-card__unit"><?php echo esc_html__( 'Total Hours', 'css-timeclock-addon' ); ?></span>
			</p>
			<?php if ( ! empty( $sheet['holiday_seconds'] ) ) : ?>
				<p class="css-tc-card__range">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: worked H:MM, 2: holiday H:MM */
							__( '%1$s worked + %2$s holiday', 'css-timeclock-addon' ),
							$sheet['total_hm'],
							css_tc_addon()->time->format_duration( (int) $sheet['holiday_seconds'] )
						)
					);
					?>
				</p>
			<?php endif; ?>
		</section>
		<section class="css-tc-card">
			<h2><?php echo esc_html__( 'Pay Code Summary', 'css-timeclock-addon' ); ?></h2>
			<ul class="css-tc-code-list">
				<?php foreach ( $sheet['pay_codes'] as $code ) : ?>
					<li>
						<span><?php echo esc_html( $code['label'] ); ?></span>
						<span><?php echo esc_html( preg_match( '/^\d+:\d{2}$/', $code['hm'] ) ? $code['hm'] . ' HRS' : $code['hm'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( isset( $sheet['by_company'] ) && count( $sheet['by_company'] ) > 1 ) : ?>
				<ul class="css-tc-code-list css-tc-code-list--companies">
					<?php foreach ( $sheet['by_company'] as $css_tc_co ) : ?>
						<li>
							<span><?php echo esc_html( $css_tc_co['name'] ); ?></span>
							<span>
								<?php echo esc_html( css_tc_addon()->time->format_duration( $css_tc_co['total'] ) . ' HRS' ); ?>
								<?php if ( $css_tc_co['overtime'] > 0 ) : ?>
									<span class="css-tc-card__range"><?php echo esc_html( sprintf( /* translators: %s: overtime H:MM */ __( 'incl. %s OT', 'css-timeclock-addon' ), css_tc_addon()->time->format_duration( $css_tc_co['overtime'] ) ) ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $css_tc_co['holiday'] ) ) : ?>
									<span class="css-tc-card__range"><?php echo esc_html( sprintf( /* translators: %s: holiday H:MM */ __( '+ %s holiday', 'css-timeclock-addon' ), css_tc_addon()->time->format_duration( (int) $css_tc_co['holiday'] ) ) ); ?></span>
								<?php endif; ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $sheet['overtime_note'] ) ) : ?>
				<p class="css-tc-card__range"><?php echo esc_html( $sheet['overtime_note'] ); ?></p>
			<?php endif; ?>
		</section>
		<section class="css-tc-card">
			<h2><?php echo esc_html__( 'Weekly Summary', 'css-timeclock-addon' ); ?></h2>
			<ul class="css-tc-week-list">
				<?php foreach ( $sheet['weeks'] as $week ) : ?>
					<li>
						<span>
							<strong><?php echo esc_html( $week['label'] ); ?></strong>
							<span class="css-tc-card__range"><?php echo esc_html( $week['range'] ); ?></span>
						</span>
						<span>
							<?php echo esc_html( preg_match( '/^\d+:\d{2}$/', $week['hm'] ) ? $week['hm'] . ' HRS' : $week['hm'] ); ?>
							<?php if ( ! empty( $week['overtime_seconds'] ) ) : ?>
								<span class="css-tc-card__range">
									<?php
									/* translators: %s: overtime hours H:MM */
									echo esc_html( sprintf( __( 'incl. %s OT', 'css-timeclock-addon' ), $week['overtime_hm'] ) );
									?>
								</span>
							<?php endif; ?>
							<?php if ( ! empty( $week['holiday_seconds'] ) ) : ?>
								<span class="css-tc-card__range">
									<?php
									/* translators: %s: holiday hours H:MM */
									echo esc_html( sprintf( __( '+ %s holiday', 'css-timeclock-addon' ), $week['holiday_hm'] ) );
									?>
								</span>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	</div>

	<div class="css-tc-cal-head">
		<h2><?php echo esc_html__( 'Day Summary', 'css-timeclock-addon' ); ?></h2>
		<p class="css-tc-pencil-legend"><?php echo esc_html__( 'Blue pencil: edit · Green check: changes completed · Amber: pending request · Red: needs attention · Gray lock: pay period closed', 'css-timeclock-addon' ); ?></p>
	</div>

	<div class="css-tc-cal">
		<div class="css-tc-cal__dow">
			<?php foreach ( $dow as $name ) : ?>
				<div>
					<span class="css-tc-dow__full"><?php echo esc_html( $name['full'] ); ?></span>
					<span class="css-tc-dow__short"><?php echo esc_html( $name['short'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php foreach ( $sheet['weeks'] as $week ) : ?>
			<div class="css-tc-cal__week">
				<?php foreach ( $week['days'] as $day ) : ?>
					<?php
					$day_classes = 'css-tc-day';
					if ( empty( $day['shifts'] ) && ! $day['has_time'] && empty( $day['holiday'] ) ) {
						$day_classes .= ' is-empty';
					}
					if ( ! empty( $day['is_today'] ) ) {
						$day_classes .= ' is-today';
					}
					if ( ! empty( $day['needs_correction'] ) ) {
						$day_classes .= ' needs-correction';
					}
					if ( ! empty( $day['has_long'] ) ) {
						$day_classes .= ' is-long';
					}
					if ( ! empty( $day['holiday'] ) ) {
						$day_classes .= ' is-holiday';
					}
					if ( 'admin' === $mode && $edit_day === $day['date'] ) {
						$day_classes .= ' is-editing';
					}
					$in_period    = ! empty( $day['in_period'] );
					$is_future    = ( $day['date'] > css_tc_addon()->time->site_today() );
					$correct_href = '';
					$show_pencil  = false;
					$show_check   = false;
					$show_lock    = false;
					$css_tc_attention = ! empty( $day['has_long'] );
					if ( ! empty( $day['shifts'] ) && is_array( $day['shifts'] ) ) {
						foreach ( $day['shifts'] as $css_tc_mark_pair ) {
							if ( ! empty( $css_tc_mark_pair['is_stale'] ) || ! empty( $css_tc_mark_pair['is_out_before_in'] ) || ! empty( $css_tc_mark_pair['is_long'] ) ) {
								$css_tc_attention = true;
								break;
							}
						}
					}
					if ( $in_period && ! $is_future ) {
						if ( ! $is_open ) {
							$show_lock = true;
						} else {
							$show_pencil = true;
							$show_check  = ! empty( $day['has_approved'] );
						}
						$css_tc_can_open = ( 'admin' === $mode ) || ( 'employee' === $mode && $is_open );
						if ( $css_tc_can_open && 'employee' === $mode ) {
							$correct_href = Css_Tc_Shortcodes::correct_url( $day['date'] );
						} elseif ( $css_tc_can_open && 'admin' === $mode ) {
							$correct_href = Css_Tc_Admin::timecards_url(
								array(
									'employee' => $user_id,
									'period'   => $period['start'],
									'edit_day' => $day['date'],
								)
							);
						}
					}
					?>
					<div class="<?php echo esc_attr( $day_classes ); ?>" id="day-<?php echo esc_attr( $day['date'] ); ?>">
						<div class="css-tc-day__top">
							<span class="css-tc-day__num">
								<span class="css-tc-day__dow">
									<span class="css-tc-dow__full"><?php echo esc_html( $day['weekday'] ); ?></span>
									<span class="css-tc-dow__short"><?php echo esc_html( isset( $day['weekday_short'] ) ? $day['weekday_short'] : $day['weekday'] ); ?></span>
								</span>
								<?php echo esc_html( $day['day_num'] ); ?>
							</span>
							<span class="css-tc-day__marks">
								<?php if ( ! empty( $day['has_long'] ) ) : ?>
									<span class="css-tc-badge css-tc-badge--long"><?php echo esc_html__( 'Long shift', 'css-timeclock-addon' ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $day['pending'] ) ) : ?>
									<span class="css-tc-badge css-tc-badge--pending" title="<?php echo esc_attr__( 'Pending request', 'css-timeclock-addon' ); ?>"><?php echo esc_html__( 'Pending', 'css-timeclock-addon' ); ?></span>
								<?php endif; ?>
								<?php if ( $css_tc_attention && $in_period && ! $is_future ) : ?>
									<span class="css-tc-attention" role="img" title="<?php echo esc_attr__( 'Needs attention', 'css-timeclock-addon' ); ?>" aria-label="<?php echo esc_attr__( 'Needs attention', 'css-timeclock-addon' ); ?>">
										<?php echo $css_tc_flag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
									</span>
								<?php endif; ?>
								<?php if ( $show_check ) : ?>
									<span class="css-tc-check" role="img" title="<?php echo esc_attr__( 'Changes completed', 'css-timeclock-addon' ); ?>" aria-label="<?php echo esc_attr__( 'Changes completed', 'css-timeclock-addon' ); ?>">
										<?php echo $css_tc_check; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
									</span>
								<?php endif; ?>
								<?php if ( $show_pencil && '' !== $correct_href ) : ?>
									<a class="css-tc-edit" href="<?php echo esc_url( $correct_href ); ?>" title="<?php echo esc_attr__( 'Edit', 'css-timeclock-addon' ); ?>" aria-label="<?php echo esc_attr__( 'Edit', 'css-timeclock-addon' ); ?>">
										<?php echo $pencil; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
									</a>
								<?php endif; ?>
								<?php if ( $show_lock ) : ?>
									<?php if ( '' !== $correct_href ) : ?>
										<a class="css-tc-lock" href="<?php echo esc_url( $correct_href ); ?>" title="<?php echo esc_attr__( 'Pay period closed', 'css-timeclock-addon' ); ?>" aria-label="<?php echo esc_attr__( 'Pay period closed', 'css-timeclock-addon' ); ?>">
											<?php echo $css_tc_lock; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
										</a>
									<?php else : ?>
										<span class="css-tc-lock" role="img" title="<?php echo esc_attr__( 'Pay period closed', 'css-timeclock-addon' ); ?>" aria-label="<?php echo esc_attr__( 'Pay period closed', 'css-timeclock-addon' ); ?>">
											<?php echo $css_tc_lock; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
										</span>
									<?php endif; ?>
								<?php endif; ?>
							</span>
						</div>
						<?php if ( '' !== $day['hm'] || ! empty( $day['status_label'] ) ) : ?>
							<p class="css-tc-day__hours">
								<?php if ( '' !== $day['hm'] ) : ?>
									<?php echo esc_html( $day['hm'] ); ?>
									<?php if ( preg_match( '/^\d+:\d{2}$/', $day['hm'] ) ) : ?>
										<span><?php echo esc_html__( 'Hours', 'css-timeclock-addon' ); ?></span>
									<?php endif; ?>
								<?php else : ?>
									<span class="css-tc-day__status"><?php echo esc_html( $day['status_label'] ); ?></span>
								<?php endif; ?>
							</p>
						<?php endif; ?>
						<?php if ( ! empty( $day['holiday'] ) ) : ?>
							<p class="css-tc-day__holiday">
								<span class="css-tc-day__holiday-name"><?php echo esc_html( $day['holiday'] ); ?></span>
								<?php if ( '' !== $day['holiday_hm'] ) : ?>
									<span class="css-tc-day__holiday-hours"><?php echo esc_html( sprintf( /* translators: %s: H:MM */ __( '%s holiday pay', 'css-timeclock-addon' ), $day['holiday_hm'] ) ); ?></span>
								<?php elseif ( '' !== $day['holiday_note'] ) : ?>
									<span class="css-tc-day__holiday-note"><?php echo esc_html( $day['holiday_note'] ); ?></span>
								<?php endif; ?>
							</p>
						<?php endif; ?>
						<div class="css-tc-day__pairs">
							<?php foreach ( $day['shifts'] as $pair ) : ?>
								<div class="css-tc-pair<?php echo ! empty( $pair['is_long'] ) ? ' is-long' : ''; ?><?php echo ! empty( $pair['is_out_before_in'] ) ? ' is-order' : ''; ?>">
									<span class="css-tc-pair__times">
										<span><?php echo esc_html( '' !== $pair['in_display'] ? $pair['in_display'] : __( 'No clock-in', 'css-timeclock-addon' ) ); ?></span>
										<span>
											<?php
											if ( '' !== $pair['out_display'] ) {
												echo esc_html( $pair['out_display'] );
											} elseif ( ! empty( $pair['is_stale'] ) ) {
												echo esc_html__( 'Missed clock-out', 'css-timeclock-addon' );
											} elseif ( ! empty( $pair['is_open'] ) ) {
												echo esc_html__( 'Still clocked in', 'css-timeclock-addon' );
											} else {
												echo esc_html__( 'No clock-out', 'css-timeclock-addon' );
											}
											?>
										</span>
									</span>
									<?php if ( ! empty( $pair['assignment'] ) ) : ?>
										<span class="css-tc-pair__where"><?php echo esc_html( ( ! empty( $pair['switched'] ) ? '↳ ' : '' ) . $pair['assignment'] ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $pair['is_out_before_in'] ) ) : ?>
										<span class="css-tc-pair__flag css-tc-pair__flag--order"><?php echo esc_html__( 'Clock-out before clock-in', 'css-timeclock-addon' ); ?></span>
									<?php elseif ( ! empty( $pair['is_long'] ) ) : ?>
										<span class="css-tc-pair__flag"><?php echo esc_html__( 'Long shift', 'css-timeclock-addon' ); ?></span>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
	</div>
</div>
