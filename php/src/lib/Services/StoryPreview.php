<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoryPreview {

	/**
	 * @var string
	 */
	private $head;

	/**
	 * @var string
	 */
	private $body;

	/**
	 * @var int|null
	 */
	private $content_version;

	public function __construct( string $head, string $body, ?int $content_version ) {
		$this->head            = $head;
		$this->body            = $body;
		$this->content_version = $content_version;
	}

	/**
	 * @param mixed $payload
	 */
	public static function from_payload( $payload, ?int $content_version ): ?self {
		if ( ! is_object( $payload ) && ! is_array( $payload ) ) {
			return null;
		}

		$head = self::read_payload_value( $payload, 'head' );
		$body = self::read_payload_value( $payload, 'article' );

		return new self( $head, $body, $content_version );
	}

	public function with_content( string $head, string $body ): self {
		return new self( $head, $body, $this->content_version );
	}

	public function get_head(): string {
		return $this->head;
	}

	public function get_body(): string {
		return $this->body;
	}

	public function get_content_version(): ?int {
		return $this->content_version;
	}

	/**
	 * @param object|mixed[] $payload
	 */
	private static function read_payload_value( $payload, string $key ): string {
		if ( is_object( $payload ) && isset( $payload->{$key} ) && is_string( $payload->{$key} ) ) {
			return $payload->{$key};
		}

		if ( is_array( $payload ) && isset( $payload[ $key ] ) && is_string( $payload[ $key ] ) ) {
			return $payload[ $key ];
		}

		return '';
	}
}
