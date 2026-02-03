<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$theshed_output_rows = isset( $args['rows'] ) ? $args['rows'] : 0;
$theshed_output_cols = isset( $args['cols'] ) ? $args['cols'] : 0;
$theshed_output_id   = $args['label_for'];
$theshed_is_readonly = isset( $args['readonly'] ) && $args['readonly'];
echo '<textarea';
echo ' id=\'' . esc_attr( $theshed_output_id ) . '\'';
echo ' name=\'' . esc_attr( $theshed_output_id ) . '\'';
echo $theshed_is_readonly ? ' readonly ' : '';
if ( $theshed_output_rows ) {
	echo ' rows=\'' . esc_attr( $theshed_output_rows ) . '\'';
}
if ( $theshed_output_cols ) {
	echo ' cols=\'' . esc_attr( $theshed_output_cols ) . '\'';
}
echo '>' . esc_textarea( $args['value'] ) . '</textarea>';
