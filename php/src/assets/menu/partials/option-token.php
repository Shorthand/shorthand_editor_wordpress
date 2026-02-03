<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( isset( $args['readonly'] ) && $args['readonly'] ) { ?>
	<span id='<?php echo esc_attr( $args['label_for'] ); ?>'>
			<?php echo esc_html( $args['value'] ); ?>
	</span>
	<?php
	if ( isset( $args['link'] ) && isset( $args['link_text'] ) ) {
		echo '<a href="' . esc_url( $args['link'] ) . '">' . esc_html( $args['link_text'] ) . '</a>';
	}
	?>
<?php } else { ?>
	<input type='text' id='<?php echo esc_attr( $args['label_for'] ); ?>' name='<?php echo esc_attr( $args['label_for'] ); ?>'
		value='<?php echo esc_attr( $args['value'] ); ?>' />
<?php } ?>