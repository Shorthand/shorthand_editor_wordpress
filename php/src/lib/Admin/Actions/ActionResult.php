<?php

namespace Shorthand\Admin\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ActionResult {

	/**
	 * @var string
	 */
	private $type;

	/**
	 * @var string|null
	 */
	private $redirect_url;

	/**
	 * @var string|null
	 */
	private $message;

	/**
	 * @var string|null
	 */
	private $title;

	/**
	 * @var string|null
	 */
	private $link_url;

	/**
	 * @var string|null
	 */
	private $link_text;

	private function __construct( string $type, ?string $redirect_url = null, ?string $message = null, ?string $title = null, ?string $link_url = null, ?string $link_text = null ) {
		$this->type         = $type;
		$this->redirect_url = $redirect_url;
		$this->message      = $message;
		$this->title        = $title;
		$this->link_url     = $link_url;
		$this->link_text    = $link_text;
	}

	public static function redirect( string $redirect_url ): self {
		return new self( 'redirect', $redirect_url );
	}

	public static function error( string $message, string $title, ?string $link_url = null, ?string $link_text = null ): self {
		return new self( 'error', null, $message, $title, $link_url, $link_text );
	}

	public function isRedirect(): bool {
		return 'redirect' === $this->type;
	}

	public function isError(): bool {
		return 'error' === $this->type;
	}

	public function getRedirectUrl(): ?string {
		return $this->redirect_url;
	}

	public function getMessage(): ?string {
		return $this->message;
	}

	public function getTitle(): ?string {
		return $this->title;
	}

	public function getLinkUrl(): ?string {
		return $this->link_url;
	}

	public function getLinkText(): ?string {
		return $this->link_text;
	}
}
