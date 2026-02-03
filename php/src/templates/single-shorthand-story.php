<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single shorthand story template.
 *
 * @package Shorthand Connect
 */

if ( wp_is_block_theme() ) {
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
			// Pass 'post' context string rather than pre-resolved array, so dynamic
			// sh-* tag filters added by pre_kses are included when KSES resolves tags.
			echo wp_kses(
				$theshed_story_meta['story_body'][0],
				'post',
				\Shorthand\Services\StoryKses::get_allowed_protocols()
			);
			\Shorthand\Services\StoryKses::enqueue_story_scripts( $theshed_story_version );
			\Shorthand\Services\StoryKses::disable();
		}

	endwhile;
}

if ( wp_is_block_theme() ) {
	wp_footer();
	?>
	</body>
	</html>
	<?php
} else {
	get_footer();
}
