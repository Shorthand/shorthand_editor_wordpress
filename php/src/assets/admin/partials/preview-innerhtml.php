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
	if ( ! empty( $story_head_for_meta ) ) {
		\Shorthand\Services\StoryKses::echo_meta_tags( $story_head_for_meta );
	}
	wp_print_styles();
	wp_print_head_scripts();
	?>
</head>

<body <?php body_class(); ?>>
	<?php
	\Shorthand\Services\StoryKses::enable();
	\Shorthand\Services\StoryKses::echo_extract_and_enqueue_assets( $story_body, $story_version );
	\Shorthand\Services\StoryKses::disable();
	?>

	<?php wp_print_footer_scripts(); ?>
</body>

</html>