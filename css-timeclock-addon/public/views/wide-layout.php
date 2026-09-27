<?php
/**
 * Full-width canvas for My Time Clock and the kiosks.
 *
 * The theme template is not loaded, so a narrow content column cannot
 * squeeze the week grid. Container queries still stack a narrow window.
 *
 * @package CssTimeclockAddon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$css_tc_wide_post  = get_queried_object();
$css_tc_wide_kiosk = $css_tc_wide_post instanceof WP_Post && (
	has_shortcode( (string) $css_tc_wide_post->post_content, 'css_tc_pin_kiosk' )
	|| has_shortcode( (string) $css_tc_wide_post->post_content, 'css_tc_name_kiosk' )
);
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'css-tc-wide-layout' ); ?>>
<?php
if ( function_exists( 'wp_body_open' ) ) {
	wp_body_open();
}
?>
<div class="css-tc-wide<?php echo $css_tc_wide_kiosk ? ' css-tc-wide--kiosk' : ''; ?>">
	<?php if ( ! $css_tc_wide_kiosk ) : ?>
		<header class="css-tc-wide__bar">
			<a class="css-tc-wide__home" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a>
			<?php if ( is_user_logged_in() ) : ?>
				<span class="css-tc-wide__user"><?php echo esc_html( wp_get_current_user()->display_name ); ?></span>
			<?php endif; ?>
		</header>
	<?php endif; ?>
	<main class="css-tc-wide__main">
		<?php
		while ( have_posts() ) {
			the_post();
			the_content();
		}
		?>
	</main>
</div>
<?php wp_footer(); ?>
</body>
</html>
