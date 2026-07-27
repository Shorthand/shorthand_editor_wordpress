<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single shorthand story template.
 *
 * @package Shorthand Connect
 */

$theshed_is_block_theme = wp_is_block_theme();
$theshed_header_part    = '';
$theshed_footer_part    = '';

if ( $theshed_is_block_theme ) {
	/*
	 * Render the header and footer parts before `<head>` is written. Block themes
	 * enqueue their block styles and scripts while the blocks render, so anything
	 * rendered after wp_head() has already run would go unstyled.
	 */
	$theshed_header_part = \Shorthand\Plugin\Templates::render_block_template_part( 'header' );
	$theshed_footer_part = \Shorthand\Plugin\Templates::render_block_template_part( 'footer' );

	?>
	<!DOCTYPE html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>">
		<?php wp_head(); ?>
	</head>
	<body <?php body_class(); ?>>
	<?php
	wp_body_open();
	?>
	<div class="wp-site-blocks">
		<header class="wp-block-template-part">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup rendered by core's block_template_part().
			echo $theshed_header_part;
			?>
		</header>
	<?php
} else {
	get_header();
}

if ( post_password_required( $post->ID ) ) {
	return get_the_password_form();
} else {
	while ( have_posts() ) :
		the_post();

		$theshed_story_meta = get_post_meta( $post->ID );
		if ( isset( $theshed_story_meta['story_body'][0] ) ) {
			$theshed_story_version = isset( $theshed_story_meta['story_version'][0] ) && is_numeric( $theshed_story_meta['story_version'][0] )
				? (int) $theshed_story_meta['story_version'][0]
				: null;

			\Shorthand\Services\StoryKses::enable();
			\Shorthand\Services\StoryKses::echo_extract_and_enqueue_assets( $theshed_story_meta['story_body'][0], $theshed_story_version );
			\Shorthand\Services\StoryKses::disable();
		}

	endwhile;
}

if ( $theshed_is_block_theme ) {
	?>
		<footer class="wp-block-template-part">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup rendered by core's block_template_part().
			echo $theshed_footer_part;
			?>
		</footer>
	</div>
	<?php
	wp_footer();
	?>
	</body>
	</html>
	<?php
} else {
	get_footer();
}
