<?php
/**
 * Corner links for kiosks and My Time Clock.
 *
 * @package CssTimeclockAddon
 *
 * @var array<int,array{url:string,label:string}> $css_tc_staff_links
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $css_tc_staff_links ) || ! is_array( $css_tc_staff_links ) ) {
	return;
}
?>
<nav class="css-tc-staff-nav css-tc-no-print" data-css-tc-staff-nav aria-label="<?php echo esc_attr__( 'Staff links', 'css-timeclock-addon' ); ?>">
	<?php foreach ( $css_tc_staff_links as $css_tc_staff_link ) : ?>
		<a href="<?php echo esc_url( $css_tc_staff_link['url'] ); ?>"><?php echo esc_html( $css_tc_staff_link['label'] ); ?></a>
	<?php endforeach; ?>
</nav>
