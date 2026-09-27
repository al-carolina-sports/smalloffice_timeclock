<?php
/**
 * Employee timecard page.
 *
 * @package CssTimeclockAddon
 *
 * @var bool                          $logged_in
 * @var bool                          $allowed
 * @var string                        $login_url
 * @var string                        $view
 * @var array<string,mixed>|null      $sheet
 * @var array<string,mixed>|null      $form
 * @var string                        $form_error
 * @var string                        $notice
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view       = isset( $view ) ? $view : 'timecard';
$sheet      = isset( $sheet ) ? $sheet : null;
$form       = isset( $form ) ? $form : null;
$form_error = isset( $form_error ) ? (string) $form_error : '';
$notice     = isset( $notice ) ? (string) $notice : '';
$mode       = 'employee';
?>
<div class="css-tc-times" data-enabled="<?php echo $allowed ? '1' : '0'; ?>">
	<?php if ( ! Css_Tc_Shortcodes::uses_wide_layout() ) : ?>
		<div class="css-tc-times-nav">
			<?php Css_Tc_Shortcodes::render_staff_nav( 'times' ); ?>
		</div>
	<?php endif; ?>
	<?php if ( ! $logged_in ) : ?>
		<div class="css-tc-times__panel">
			<h1><?php echo esc_html__( 'Your timecard', 'css-timeclock-addon' ); ?></h1>
			<p><?php echo esc_html__( 'Sign in with your work WordPress account to see your timecard.', 'css-timeclock-addon' ); ?></p>
			<p><a class="css-tc-times__button" href="<?php echo esc_url( $login_url ); ?>"><?php echo esc_html__( 'Sign in', 'css-timeclock-addon' ); ?></a></p>
		</div>
	<?php elseif ( ! $allowed ) : ?>
		<div class="css-tc-times__panel">
			<h1><?php echo esc_html__( 'Your timecard', 'css-timeclock-addon' ); ?></h1>
			<p><?php echo esc_html__( 'This page is for time-clock employees. If you need access, ask a supervisor to assign you an Employee role.', 'css-timeclock-addon' ); ?></p>
		</div>
	<?php elseif ( 'correct' === $view ) : ?>
		<?php include CSS_TC_ADDON_DIR . 'public/views/period-corrections.php'; ?>
	<?php elseif ( $sheet ) : ?>
		<?php include CSS_TC_ADDON_DIR . 'public/views/timecard-sheet.php'; ?>
	<?php endif; ?>
</div>
