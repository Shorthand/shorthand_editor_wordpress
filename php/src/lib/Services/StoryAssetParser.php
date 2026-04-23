<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoryAssetParser {

	/**
	 * @return string[]
	 */
	public function find_shorthand_tags( string $content ): array {
		if ( ! preg_match_all( '/<(sh-[a-z0-9-]+)/i', $content, $matches ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_map( 'strtolower', $matches[1] )
			)
		);
	}

	/**
	 * @return array{content: string, scripts: string[]}
	 */
	public function extract_script_tags( string $content ): array {
		$scripts = array();

		if ( preg_match_all( '/<script\b[^>]*>.*?<\/script>/is', $content, $matches ) ) {
			$scripts = $matches[0];
			$content = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $content );
			$content = is_string( $content ) ? $content : '';
		}

		return array(
			'content' => $content,
			'scripts' => $scripts,
		);
	}

	/**
	 * @return array{content: string, styles: string[]}
	 */
	public function extract_style_tags( string $content ): array {
		$styles = array();

		if ( preg_match_all( '/<style\b[^>]*>(.*?)<\/style>/is', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$styles[] = $match[1];
			}

			$content = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', '', $content );
			$content = is_string( $content ) ? $content : '';
		}

		return array(
			'content' => $content,
			'styles'  => $styles,
		);
	}

	/**
	 * @return array<int, array<string, string|true>>
	 */
	public function extract_meta_tags( string $head_content ): array {
		if ( ! preg_match_all( '/<meta\b([^>]*)>/is', $head_content, $matches ) ) {
			return array();
		}

		$meta_tags = array();

		foreach ( $matches[1] as $attrs_string ) {
			$attrs = $this->parse_html_attributes( $attrs_string );
			if ( ! empty( $attrs ) ) {
				$meta_tags[] = $attrs;
			}
		}

		return $meta_tags;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function parse_head_assets( string $head_content ): array {
		$pattern = '/<(link|style|script)\b([^>]*)>(.*?)<\/\1>|<(link)\b([^>]*)>/is';

		if ( ! preg_match_all( $pattern, $head_content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$assets = array();

		foreach ( $matches as $match ) {
			if ( ! empty( $match[4][0] ) ) {
				$tag_name     = strtolower( $match[4][0] );
				$attrs_string = $match[5][0];
				$content      = '';
			} else {
				$tag_name     = strtolower( $match[1][0] );
				$attrs_string = $match[2][0];
				$content      = $match[3][0];
			}

			$attrs = $this->parse_html_attributes( $attrs_string );

			if ( 'link' === $tag_name ) {
				$rel  = $attrs['rel'] ?? '';
				$href = $attrs['href'] ?? '';
				if ( 'stylesheet' === $rel && is_string( $href ) && '' !== $href ) {
					$assets[] = array(
						'type' => 'style-link',
						'href' => $href,
					);
				}
			} elseif ( 'style' === $tag_name ) {
				$assets[] = array(
					'type'    => 'style-inline',
					'content' => $content,
				);
			} elseif ( 'script' === $tag_name ) {
				$defer = isset( $attrs['defer'] );
				$src   = $attrs['src'] ?? '';

				if ( is_string( $src ) && '' !== $src ) {
					$assets[] = array(
						'type'  => 'script-src',
						'src'   => $src,
						'defer' => $defer,
					);
				} else {
					$assets[] = array(
						'type'    => 'script-inline',
						'content' => html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
						'defer'   => $defer,
					);
				}
			}
		}

		return $assets;
	}

	/**
	 * Parses HTML attributes from a string.
	 *
	 * Handles both value attributes (name="value") and boolean attributes (defer, async).
	 *
	 * @return array<string, string|true>
	 */
	private function parse_html_attributes( string $attrs_string ): array {
		$attrs = array();

		if ( preg_match_all( '/([a-z0-9_-]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/is', $attrs_string, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$name = strtolower( $match[1] );
				if ( isset( $match[2] ) && '' !== $match[2] ) {
					$attrs[ $name ] = html_entity_decode( $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				} elseif ( isset( $match[3] ) && '' !== $match[3] ) {
					$attrs[ $name ] = html_entity_decode( $match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				} elseif ( isset( $match[4] ) && '' !== $match[4] ) {
					$attrs[ $name ] = html_entity_decode( $match[4], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				} else {
					$attrs[ $name ] = true;
				}
			}
		}

		return $attrs;
	}
}
