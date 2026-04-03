<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoryAssetEnqueuer {

	/**
	 * @param string[] $scripts
	 */
	public function enqueue_story_scripts( array $scripts, ?int $story_version = null ): void {
		if ( empty( $scripts ) ) {
			return;
		}

		wp_enqueue_script( StoryKses::SCRIPT_HANDLE );

		foreach ( $scripts as $script ) {
			if ( preg_match( '/src=["\']([^"\']+)["\']/', $script, $src_match ) ) {
				$handle = 'theshed-story-' . md5( $src_match[1] );
				wp_enqueue_script( $handle, $src_match[1], array(), $story_version, true );
			} elseif ( preg_match( '/<script\b[^>]*>(.*?)<\/script>/is', $script, $content_match ) ) {
				$script_content = html_entity_decode( $content_match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				wp_add_inline_script( StoryKses::SCRIPT_HANDLE, $script_content ); /* phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion */
			}
		}
	}

	/**
	 * @param string[] $styles
	 */
	public function enqueue_inline_styles( array $styles, string $handle_prefix ): void {
		$style_index = 0;

		foreach ( $styles as $style ) {
			$handle = $handle_prefix . $style_index;
			wp_register_style( $handle, false, array(), null ); /* phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion */
			wp_enqueue_style( $handle );
			wp_add_inline_style( $handle, $style );
			++$style_index;
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $assets
	 */
	public function enqueue_head_assets( array $assets, bool $in_footer = false, ?int $story_version = null ): void {
		$style_index  = 0;
		$script_index = 0;

		foreach ( $assets as $asset ) {
			if ( 'style-link' === $asset['type'] ) {
				wp_enqueue_style( 'theshed-story-style-' . $style_index, $asset['href'], array(), $story_version );
				++$style_index;
				continue;
			}

			if ( 'style-inline' === $asset['type'] ) {
				$handle = 'theshed-story-inline-style-' . $style_index;
				wp_register_style( $handle, false, array(), null ); /* phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion */
				wp_enqueue_style( $handle );
				wp_add_inline_style( $handle, $asset['content'] );
				++$style_index;
				continue;
			}

			$script_args = array(
				'in_footer' => $in_footer,
			);
			if ( ! empty( $asset['defer'] ) ) {
				$script_args['strategy'] = 'defer';
			}

			if ( 'script-src' === $asset['type'] ) {
				wp_enqueue_script(
					'theshed-story-head-script-' . $script_index,
					$asset['src'],
					array(),
					$story_version,
					$script_args
				);
				++$script_index;
				continue;
			}

			if ( 'script-inline' === $asset['type'] ) {
				$handle = 'theshed-story-head-inline-' . $script_index;
				wp_register_script( $handle, false, array(), null, $script_args ); /* phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion */
				wp_enqueue_script( $handle );
				wp_add_inline_script( $handle, $asset['content'] );
				++$script_index;
			}
		}
	}
}
