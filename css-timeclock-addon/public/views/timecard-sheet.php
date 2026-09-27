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

		<button type="button" class="css-tc-print" onclick="window.print()"><?php echo esc_html__( 'Print', 'css-timeclock-addon' ); ?></button>
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
	<?php if ( '' !== $form_error ) : ?>
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
				<span class="css-tc-card__hours"><?php echo esc_html( $sheet['total_hm'] ); ?></span>
				<span class="css-tc-card__unit"><?php echo esc_html__( 'Total Hours', 'css-timeclock-addon' ); ?></span>
			</p>
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
						<span><?php echo esc_html( preg_match( '/^\d+:\d{2}$/', $week['hm'] ) ? $week['hm'] . ' HRS' : $week['hm'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	</div>

	<div class="css-tc-cal-head">
		<h2><?php echo esc_html__( 'Day Summary', 'css-timeclock-addon' ); ?></h2>
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
					if ( empty( $day['shifts'] ) && ! $day['has_time'] ) {
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
					if ( 'admin' === $mode && $edit_day === $day['date'] ) {
						$day_classes .= ' is-editing';
					}
					$in_period    = ! empty( $day['in_period'] );
					$is_future    = ( $day['date'] > css_tc_addon()->time->site_today() );
					$correct_href = '';
					$edit_label   = empty( $day['shifts'] )
						? __( 'Add a shift', 'css-timeclock-addon' )
						: __( 'Correct this day', 'css-timeclock-addon' );
					if ( $in_period && 'employee' === $mode && $is_open && ! $is_future ) {
						$correct_href = Css_Tc_Shortcodes::correct_url( $day['date'] );
					} elseif ( $in_period && 'admin' === $mode ) {
						$correct_href = Css_Tc_Admin::timecards_url(
							array(
								'employee' => $user_id,
								'period'   => $period['start'],
								'edit_day' => $day['date'],
							)
						);
						$edit_label = empty( $day['shifts'] )
							? __( 'Add a shift', 'css-timeclock-addon' )
							: __( 'Edit this day', 'css-timeclock-addon' );
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
									<span class="css-tc-badge"><?php echo esc_html__( 'Pending', 'css-timeclock-addon' ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $day['pending'] ) || ! empty( $day['has_approved'] ) ) : ?>
									<span class="css-tc-dot<?php echo empty( $day['pending'] ) ? ' css-tc-dot--approved' : ''; ?>" title="<?php echo esc_attr( ! empty( $day['pending'] ) ? __( 'Pending correction', 'css-timeclock-addon' ) : __( 'Approved correction', 'css-timeclock-addon' ) ); ?>">
										<span class="screen-reader-text">
											<?php echo esc_html( ! empty( $day['pending'] ) ? __( 'Pending correction', 'css-timeclock-addon' ) : __( 'Approved correction', 'css-timeclock-addon' ) ); ?>
										</span>
									</span>
								<?php endif; ?>
								<?php if ( '' !== $correct_href ) : ?>
									<a class="css-tc-edit" href="<?php echo esc_url( $correct_href ); ?>" aria-label="<?php echo esc_attr( $edit_label ); ?>">
										<?php echo $pencil; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
									</a>
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
						<div class="css-tc-day__pairs">
							<?php foreach ( $day['shifts'] as $pair ) : ?>
								<div class="css-tc-pair<?php echo ! empty( $pair['is_long'] ) ? ' is-long' : ''; ?>">
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
									<?php if ( ! empty( $pair['is_long'] ) ) : ?>
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
