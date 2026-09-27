<?php
/**
 * PIN pad kiosk markup.
 *
 * @package CssTimeclockAddon
 *
 * @var bool   $enabled
 * @var string $mode
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="css-tc-kiosk" data-kiosk="pin" data-enabled="<?php echo $enabled ? '1' : '0'; ?>">
	<div class="css-tc-kiosk__chrome">
		<div class="css-tc-kiosk__brand">
			<span class="css-tc-kiosk__mark" aria-hidden="true"></span>
			<div>
				<p class="css-tc-kiosk__eyebrow"><?php echo esc_html__( 'Time clock', 'css-timeclock-addon' ); ?></p>
				<h1 class="css-tc-kiosk__title"><?php echo esc_html__( 'Clock in with your PIN', 'css-timeclock-addon' ); ?></h1>
			</div>
		</div>
		<div class="css-tc-kiosk__aside">
			<?php Css_Tc_Shortcodes::render_staff_nav( 'kiosk' ); ?>
			<p class="css-tc-kiosk__clock" data-role="live-clock" aria-live="off"></p>
		</div>
	</div>

	<div class="css-tc-kiosk__layout">
	<?php if ( ! $enabled ) : ?>
		<div class="css-tc-kiosk__stage">
			<div class="css-tc-kiosk__panel css-tc-kiosk__panel--message">
				<p><?php echo esc_html__( 'This PIN kiosk is turned off. A supervisor can enable it under SMOTC.', 'css-timeclock-addon' ); ?></p>
			</div>
		</div>
	<?php else : ?>
		<div class="css-tc-kiosk__stage" data-role="stage">
			<section class="css-tc-kiosk__panel" data-screen="pin" hidden>
				<p class="css-tc-kiosk__prompt" data-role="pin-prompt"><?php echo esc_html__( 'Enter your PIN', 'css-timeclock-addon' ); ?></p>
				<p class="css-tc-kiosk__dots" data-role="pin-dots" aria-live="polite"></p>
				<p class="css-tc-kiosk__error" data-role="error" hidden></p>
				<div class="css-tc-pad" data-role="pad">
					<button type="button" class="css-tc-pad__key" data-digit="1">1</button>
					<button type="button" class="css-tc-pad__key" data-digit="2">2</button>
					<button type="button" class="css-tc-pad__key" data-digit="3">3</button>
					<button type="button" class="css-tc-pad__key" data-digit="4">4</button>
					<button type="button" class="css-tc-pad__key" data-digit="5">5</button>
					<button type="button" class="css-tc-pad__key" data-digit="6">6</button>
					<button type="button" class="css-tc-pad__key" data-digit="7">7</button>
					<button type="button" class="css-tc-pad__key" data-digit="8">8</button>
					<button type="button" class="css-tc-pad__key" data-digit="9">9</button>
					<button type="button" class="css-tc-pad__key css-tc-pad__key--ghost" data-action="clear"><?php echo esc_html__( 'Clear', 'css-timeclock-addon' ); ?></button>
					<button type="button" class="css-tc-pad__key" data-digit="0">0</button>
					<button type="button" class="css-tc-pad__key css-tc-pad__key--ghost" data-action="back"><?php echo esc_html__( 'Back', 'css-timeclock-addon' ); ?></button>
				</div>
				<button type="button" class="css-tc-btn css-tc-btn--in css-tc-btn--wide" data-action="submit-pin"><?php echo esc_html__( 'Continue', 'css-timeclock-addon' ); ?></button>
			</section>

			<section class="css-tc-kiosk__panel" data-screen="action" hidden>
				<p class="css-tc-kiosk__hello" data-role="hello"></p>
				<p class="css-tc-kiosk__meta" data-role="status"></p>
				<p class="css-tc-kiosk__error" data-role="action-error" hidden></p>
				<div class="css-tc-kiosk__actions">
					<button type="button" class="css-tc-btn css-tc-btn--in" data-action="clock_in"><?php echo esc_html__( 'Clock in', 'css-timeclock-addon' ); ?></button>
					<button type="button" class="css-tc-btn css-tc-btn--out" data-action="clock_out"><?php echo esc_html__( 'Clock out', 'css-timeclock-addon' ); ?></button>
				</div>
				<button type="button" class="css-tc-btn css-tc-btn--text" data-action="cancel"><?php echo esc_html__( 'Cancel', 'css-timeclock-addon' ); ?></button>
			</section>

			<section class="css-tc-kiosk__panel css-tc-kiosk__panel--success" data-screen="success" hidden>
				<p class="css-tc-kiosk__success-title" data-role="success-title"></p>
				<p class="css-tc-kiosk__success-detail" data-role="success-detail"></p>
			</section>
		</div>
	<?php endif; ?>
		<?php
		// Must stay inside .css-tc-kiosk so StatusBoard finds [data-role="board"].
		include CSS_TC_ADDON_DIR . 'public/views/status-board.php';
		?>
	</div>
</div>
