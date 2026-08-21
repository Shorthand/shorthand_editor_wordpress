<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DOMDocument;
use DOMXPath;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- The DOM API names its properties in camelCase.

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
		'skip-link',
	);

	/**
	 * Elements holding the narrow-screen copy of text that also exists wide.
	 *
	 * Themes emit a caption once per screen width and show one of the two.
	 * Dropping the narrow copy leaves the text present exactly once.
	 */
	private const NARROW_COPIES = array(
		'Responsive--hide-landscape',
		'Display--md-none',
	);

	/**
	 * Elements carrying text that never belongs in either column.
	 *
	 * A `nav` holds the section list. A `video` or `audio` holds only the
	 * message shown to a browser that cannot play it.
	 */
	private const DISCARDED_TAGS = array(
		'script',
		'style',
		'noscript',
		'title',
		'nav',
		'video',
		'audio',
	);

	/**
	 * Tags that imply a word boundary.
	 *
	 * The document model has no notion of block and inline, so the text either
	 * side of a `</li>` runs together unless a separator is placed here.
	 */
	private const BLOCK_TAGS = array(
		'p',
		'div',
		'section',
		'article',
		'header',
		'footer',
		'aside',
		'main',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'li',
		'ul',
		'ol',
		'dl',
		'dt',
		'dd',
		'table',
		'tr',
		'td',
		'th',
		'figure',
		'figcaption',
		'blockquote',
		'pre',
		'hr',
		'br',
	);

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

		foreach ( array_merge( self::CHROME_CLASSES, self::NARROW_COPIES ) as $class_name ) {
			$this->discard( $xpath, '//*[' . $this->has_class( $class_name ) . ']' );
		}

		$this->discard( $xpath, '//h1[' . $this->has_class( 'Theme-StoryTitle' ) . ']' );

		$this->space_blocks( $document, $xpath );

		$content = $this->to_text( $document->textContent );
		$prose   = $this->to_text( $this->prose_text( $xpath ) );

		if ( null === $content || null === $prose ) {
			return $empty;
		}

		return array(
			'content' => $content,
			'prose'   => $prose,
		);
	}

	/**
	 * Loads the body, or null when libxml cannot make a document of it.
	 *
	 * @param string $article The story bundle's `article.html`.
	 */
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
	 * Joins the body-text containers, skipping the title section.
	 *
	 * A nested container is skipped too, so its text is not counted twice.
	 *
	 * @param DOMXPath $xpath A query object over the cleaned story.
	 */
	private function prose_text( DOMXPath $xpath ): string {
		$body_text = $this->has_class( 'Theme-Layer-BodyText' );

		$query = '//*[' . $body_text . ']'
			. '[not(ancestor::*[' . $this->has_class( 'Theme-TitleSection' ) . '])]'
			. '[not(ancestor::*[' . $body_text . '])]';

		$nodes = $xpath->query( $query );
		if ( false === $nodes ) {
			return '';
		}

		$text = '';
		foreach ( $nodes as $node ) {
			$text .= $node->textContent . ' ';
		}

		return $text;
	}

	/**
	 * Puts a space either side of every block element.
	 *
	 * @param DOMDocument $document The parsed story.
	 * @param DOMXPath    $xpath    A query object over that story.
	 */
	private function space_blocks( DOMDocument $document, DOMXPath $xpath ): void {
		$nodes = $xpath->query( '//' . implode( '|//', self::BLOCK_TAGS ) );
		if ( false === $nodes ) {
			return;
		}

		foreach ( iterator_to_array( $nodes ) as $node ) {
			$parent = $node->parentNode;

			if ( null !== $parent ) {
				$parent->insertBefore( $document->createTextNode( ' ' ), $node );
				$parent->insertBefore( $document->createTextNode( ' ' ), $node->nextSibling );
			}
		}
	}

	/**
	 * Detaches every node the query matches.
	 *
	 * @param DOMXPath $xpath A query object over the story.
	 * @param string   $query An XPath expression.
	 */
	private function discard( DOMXPath $xpath, string $query ): void {
		$nodes = $xpath->query( $query );
		if ( false === $nodes ) {
			return;
		}

		// The node list is live: detaching during iteration would skip nodes.
		foreach ( iterator_to_array( $nodes ) as $node ) {
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

	/**
	 * Collapses extracted text to single-spaced words.
	 *
	 * Returns null rather than the uncollapsed text if PCRE ever fails, since
	 * text still holding newlines would index but never match a phrase search.
	 *
	 * @param string $text Text taken from the document.
	 */
	private function to_text( string $text ): ?string {
		// A non-breaking space is not whitespace to preg_replace().
		$text = str_replace( "\xC2\xA0", ' ', $text );

		$collapsed = preg_replace( '/\s+/u', ' ', $text );

		return is_string( $collapsed ) ? trim( $collapsed ) : null;
	}
}
