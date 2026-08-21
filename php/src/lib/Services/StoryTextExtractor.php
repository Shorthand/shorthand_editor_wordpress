<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DOMDocument;
use DOMXPath;

/**
 * Derives plain text from a story bundle's `article.html`.
 *
 * Stories are rendered from the `story_body` meta by the plugin's own
 * template, so `post_content` and `post_excerpt` are never displayed on a
 * single story. They are populated because core search only ever queries
 * `post_title`, `post_excerpt` and `post_content` — until story prose reaches
 * one of those columns, a story matches on its title alone.
 */
class StoryTextExtractor {

	/**
	 * Elements whose text belongs to the page furniture, not the story.
	 */
	private const CHROME_CLASSES = array(
		'Theme-Footer',
		'Theme-SocialIcons',
		'Theme-Logos',
		'Theme-skip-content-link',
	);

	/**
	 * Elements carrying text that never belongs in either column.
	 *
	 * `wp_strip_all_tags()` drops script and style itself, but only when the
	 * tags are balanced, and it keeps noscript text. Removing the nodes here
	 * is exact, because the parser has already resolved the boundaries.
	 */
	private const DISCARDED_TAGS = array( 'script', 'style', 'noscript', 'title' );

	/**
	 * Tags that imply a word boundary.
	 *
	 * `strip_tags()` inserts no separator, so `<li>One</li><li>Two</li>`
	 * collapses to `OneTwo` without this.
	 */
	private const BLOCK_TAGS = '@</?(?:p|div|section|article|header|footer|aside|nav|main|h[1-6]|li|ul|ol|dl|dt|dd|table|tr|td|th|figure|figcaption|blockquote|pre|hr|br)\b[^>]*>@i';

	/**
	 * Splits a story body into the text for each column.
	 *
	 * `content` is the whole story minus chrome and the story title, which
	 * `post_title` already holds. `prose` is the body text alone, with the
	 * title section — byline, storytitle and leadin — left out.
	 *
	 * @param string $article The story bundle's `article.html`.
	 * @return array{content: string, prose: string} Empty strings when the body cannot be parsed.
	 */
	public function extract( string $article ): array {
		$empty = array(
			'content' => '',
			'prose'   => '',
		);

		if ( '' === trim( $article ) || ! class_exists( 'DOMDocument' ) ) {
			return $empty;
		}

		$document = $this->parse( $article );
		if ( null === $document ) {
			return $empty;
		}

		$xpath = new DOMXPath( $document );

		foreach ( self::DISCARDED_TAGS as $tag ) {
			$this->discard( $xpath, '//' . $tag );
		}

		foreach ( self::CHROME_CLASSES as $class_name ) {
			$this->discard( $xpath, '//*[' . $this->has_class( $class_name ) . ']' );
		}

		$this->discard( $xpath, '//h1[' . $this->has_class( 'Theme-StoryTitle' ) . ']' );

		$content = $this->to_text( (string) $document->saveHTML() );

		return array(
			'content' => $content,
			'prose'   => $this->to_text( $this->prose_html( $document, $xpath ) ),
		);
	}

	private function parse( string $article ): ?DOMDocument {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );

		/*
		 * libxml is an HTML4 parser: it assumes Latin-1 without a charset
		 * declaration, and objects to every HTML5 element a bundle contains.
		 */
		$loaded = $document->loadHTML(
			'<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $article,
			LIBXML_NOERROR | LIBXML_NOWARNING
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $loaded ? $document : null;
	}

	/**
	 * Concatenates the body-text containers, skipping the title section.
	 *
	 * @param DOMDocument $document The parsed story.
	 * @param DOMXPath    $xpath    A query object over that story.
	 */
	private function prose_html( DOMDocument $document, DOMXPath $xpath ): string {
		$query = '//*[' . $this->has_class( 'Theme-Layer-BodyText' ) . ']'
			. '[not(ancestor::*[' . $this->has_class( 'Theme-TitleSection' ) . '])]';

		$nodes = $xpath->query( $query );
		if ( false === $nodes ) {
			return '';
		}

		$html = '';
		foreach ( $nodes as $node ) {
			$html .= (string) $document->saveHTML( $node ) . ' ';
		}

		return $html;
	}

	private function discard( DOMXPath $xpath, string $query ): void {
		$nodes = $xpath->query( $query );
		if ( false === $nodes ) {
			return;
		}

		// The node list is live: detaching during iteration would skip nodes.
		foreach ( iterator_to_array( $nodes ) as $node ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property.
			$parent = $node->parentNode;

			if ( null !== $parent ) {
				$parent->removeChild( $node );
			}
		}
	}

	/**
	 * Matches one class token, so `Theme-StoryTitleBanner` does not match
	 * `Theme-StoryTitle`.
	 *
	 * @param string $class_name The single class to match.
	 */
	private function has_class( string $class_name ): string {
		return sprintf(
			"contains(concat(' ', normalize-space(@class), ' '), ' %s ')",
			$class_name
		);
	}

	private function to_text( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		$spaced = preg_replace( self::BLOCK_TAGS, '$0 ', $html );

		$text = wp_strip_all_tags( is_string( $spaced ) ? $spaced : $html, true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// A decoded &nbsp; is not whitespace to preg_replace().
		$text = str_replace( "\xC2\xA0", ' ', $text );

		$collapsed = preg_replace( '/\s+/u', ' ', $text );

		return trim( is_string( $collapsed ) ? $collapsed : $text );
	}
}
