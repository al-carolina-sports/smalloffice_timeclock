<?php
/**
 * Dashboard widget: read-only status and a link to My Time Clock.
 *
 * @package CssTimeclockAddon
 *
 * @var string $url
 * @var string $status
 * @var string $hours
 * @var string $range
 * @var string $payday
 * @var bool   $in
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$payday = isset( $payday ) ? (string) $payday : '';
?>
<div class="css-tc-reach">
	<p class="css-tc-reach__status <?php echo $in ? 'is-in' : 'is-out'; ?>"><?php echo esc_html( $status ); ?></p>
	<p class="css-tc-reach__hours"><?php echo esc_html( $hours ); ?></p>
	<?php if ( '' !== $range ) : ?>
		<p class="css-tc-reach__range"><?php echo esc_html( $range ); ?></p>
	<?php endif; ?>
	<?php if ( ! empty( $payday ) ) : ?>
		<p class="css-tc-reach__payday"><?php echo esc_html( $payday ); ?></p>
	<?php endif; ?>
	<p class="css-tc-reach__action">
		<a class="button button-primary button-hero css-tc-reach__button" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html__( 'View my timecard', 'css-timeclock-addon' ); ?></a>
	</p>
</div>
