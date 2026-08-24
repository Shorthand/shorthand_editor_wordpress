<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$theshed_output_id   = $args['label_for'];
$theshed_is_checked  = ! empty( $args['value'] );
$theshed_is_disabled = isset( $args['disabled'] ) && $args['disabled'];
$theshed_label       = isset( $args['label'] ) ? $args['label'] : '';
$theshed_description = isset( $args['description'] ) ? $args['description'] : '';
?>
<label for="<?php echo esc_attr( $theshed_output_id ); ?>">
	<input type="checkbox"
		id="<?php echo esc_attr( $theshed_output_id ); ?>"
		name="<?php echo esc_attr( $theshed_output_id ); ?>"
		value="1"
		<?php checked( $theshed_is_checked ); ?>
		<?php disabled( $theshed_is_disabled ); ?> />
	<?php echo esc_html( $theshed_label ); ?>
</label>
<?php if ( '' !== $theshed_description ) { ?>
	<p class="description"><?php echo esc_html( $theshed_description ); ?></p>
<?php } ?>
