<?php
/**
 * My Time Clock → Request time off.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="css-tc-to" data-css-tc-timeoff>
	<p class="css-tc-to__back css-tc-no-print"><a href="<?php echo esc_url( Css_Tc_Shortcodes::times_url() ); ?>">&larr; <?php echo esc_html__( 'Back to my timecard', 'css-timeclock-addon' ); ?></a></p>
	<h1 class="css-tc-to__title"><?php echo esc_html__( 'Request time off', 'css-timeclock-addon' ); ?></h1>
	<p class="css-tc-to__lead" data-role="lead"></p>
	<div class="css-tc-to__balances" data-role="balances" aria-live="polite"></div>
	<p class="css-tc-to__msg" data-role="msg" hidden></p>

	<div class="css-tc-to__layout" data-role="layout" hidden>
		<section class="css-tc-to__cal" aria-label="<?php echo esc_attr__( 'Calendar', 'css-timeclock-addon' ); ?>">
			<div class="css-tc-to__calbar">
				<button type="button" class="css-tc-to__nav" data-action="prev" aria-label="<?php echo esc_attr__( 'Previous month', 'css-timeclock-addon' ); ?>">&lsaquo;</button>
				<h2 class="css-tc-to__month" data-role="month"></h2>
				<button type="button" class="css-tc-to__nav" data-action="next" aria-label="<?php echo esc_attr__( 'Next month', 'css-timeclock-addon' ); ?>">&rsaquo;</button>
			</div>
			<div class="css-tc-to__grid" data-role="grid" role="grid"></div>
			<p class="css-tc-to__legend">
				<span class="css-tc-to__key css-tc-to__key--sel"></span> <?php echo esc_html__( 'selected', 'css-timeclock-addon' ); ?>
				<span class="css-tc-to__key css-tc-to__key--pto"></span> <?php echo esc_html__( 'PTO', 'css-timeclock-addon' ); ?>
				<span class="css-tc-to__key css-tc-to__key--sick"></span> <?php echo esc_html__( 'sick', 'css-timeclock-addon' ); ?>
				<span class="css-tc-to__key css-tc-to__key--hol"></span> <?php echo esc_html__( 'holiday', 'css-timeclock-addon' ); ?>
			</p>
		</section>

		<section class="css-tc-to__form" aria-label="<?php echo esc_attr__( 'Request', 'css-timeclock-addon' ); ?>">
			<fieldset class="css-tc-to__types" data-role="types">
				<legend><?php echo esc_html__( 'Type', 'css-timeclock-addon' ); ?></legend>
			</fieldset>
			<label class="css-tc-to__field"><?php echo esc_html__( 'Hours per day', 'css-timeclock-addon' ); ?>
				<select data-role="hours"></select>
			</label>
			<p class="css-tc-to__picked" data-role="picked"></p>
			<label class="css-tc-to__field"><?php echo esc_html__( 'Note for your manager (optional)', 'css-timeclock-addon' ); ?>
				<textarea data-role="note" rows="3" maxlength="500"></textarea>
			</label>
			<p class="css-tc-to__error" data-role="error" hidden></p>
			<button type="button" class="css-tc-to__send" data-action="send" disabled><?php echo esc_html__( 'Send request', 'css-timeclock-addon' ); ?></button>
		</section>
	</div>

	<section id="css-tc-leave-mine" class="css-tc-to__mine">
		<h2><?php echo esc_html__( 'My requests', 'css-timeclock-addon' ); ?></h2>
		<div data-role="mine"></div>
	</section>
</div>
