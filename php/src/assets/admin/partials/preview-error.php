<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<?php
		wp_print_styles();
		wp_print_head_scripts();
	?>
</head>
<body>
	
	<h1>
		Preview unavailable
	</h1>

	<p>
		<?php echo esc_html( $message ); ?>
	</p>

	<?php wp_print_footer_scripts(); ?>
</body>
</html>
