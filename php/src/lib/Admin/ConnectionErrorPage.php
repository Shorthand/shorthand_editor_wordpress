<?php

namespace Shorthand\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Version;
use Shorthand\Services\ConnectionFailure;

/**
 * Renders a branded, actionable page for a connection-flow failure.
 *
 * Replaces the bare wp_die() screens in the connect flow (PLA-2670).
 * Every page states what happened, why, and what to do next, is served
 * with an HTTP status that matches the failure rather than a blanket
 * 500, and prints support-only diagnostics to the browser console —
 * never to the page body, and never including tokens or secrets.
 */
class ConnectionErrorPage {

	/**
	 * Render the page for a failure and terminate the request.
	 *
	 * @param ConnectionFailure $failure     The failure to present.
	 * @param bool              $should_exit Exit after rendering. Tests pass false.
	 */
	public function render( ConnectionFailure $failure, bool $should_exit = true ): void {
		if ( ! headers_sent() ) {
			status_header( $failure->get_status() );
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
		}

		echo $this->build_html( $failure ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- build_html escapes every interpolated value.

		if ( $should_exit ) {
			exit;
		}
	}

	/**
	 * Build the full HTML document for a failure page.
	 *
	 * @param ConnectionFailure $failure The failure to present.
	 */
	public function build_html( ConnectionFailure $failure ): string {
		$title   = $failure->get_title();
		$actions = $failure->get_actions();

		$actions_html = '';
		foreach ( $actions as $index => $action ) {
			$class         = 0 === $index ? 'sh-action sh-action-primary' : 'sh-action';
			$actions_html .= sprintf(
				'<a class="%1$s" href="%2$s">%3$s</a>',
				esc_attr( $class ),
				esc_url( $action['url'] ),
				esc_html( $action['text'] )
			);
		}

		$detail_html = '';
		if ( '' !== $failure->get_detail() ) {
			$detail_html = '<p class="sh-detail">' . esc_html( $failure->get_detail() ) . '</p>';
		}

		return '<!DOCTYPE html>' .
			'<html lang="' . esc_attr( function_exists( 'get_bloginfo' ) ? get_bloginfo( 'language' ) : 'en' ) . '">' .
			'<head>' .
			'<meta charset="utf-8" />' .
			'<meta name="viewport" content="width=device-width, initial-scale=1.0" />' .
			'<meta name="robots" content="noindex, nofollow" />' .
			'<title>' . esc_html( $title ) . ' &#8212; Shorthand</title>' .
			'<style>' . $this->styles() . '</style>' .
			'</head>' .
			'<body>' .
			'<main class="sh-card">' .
			'<p class="sh-brand">Shorthand <span>for WordPress</span></p>' .
			'<h1>' . esc_html( $title ) . '</h1>' .
			'<p class="sh-message">' . esc_html( $failure->get_message() ) . '</p>' .
			$detail_html .
			'<p class="sh-advice">' . esc_html( $failure->get_advice() ) . '</p>' .
			'<div class="sh-actions">' . $actions_html . '</div>' .
			'<p class="sh-ref">' . esc_html( $failure->get_slug() ) . '</p>' .
			'</main>' .
			$this->diagnostics_script( $failure ) .
			'</body></html>';
	}

	/**
	 * Support-only diagnostics, printed to the browser console.
	 *
	 * Kept as a single serialisable object so a future support-enquiry
	 * feature can forward it unchanged. Must never include tokens.
	 *
	 * @param ConnectionFailure $failure The failure whose diagnostics to emit.
	 */
	private function diagnostics_script( ConnectionFailure $failure ): string {
		$diagnostics = array_merge(
			array(
				'mode'           => $failure->get_slug(),
				'timestamp'      => gmdate( 'c' ),
				'plugin_version' => Version::PLUGIN_VERSION,
				'wp_version'     => isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '',
				'php_version'    => phpversion(),
			),
			$failure->get_diagnostics()
		);

		$json = wp_json_encode( $diagnostics, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES );

		return '<script>' .
			'console.info("Shorthand connection diagnostics — share these details with Shorthand support:", ' . $json . ');' .
			'</script>';
	}

	private function styles(): string {
		return '
			html { background: #f0f0f1; }
			body {
				margin: 0; padding: 24px;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
				color: #1d2327; line-height: 1.55;
				display: flex; justify-content: center;
			}
			.sh-card {
				background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
				max-width: 34rem; width: 100%; margin-top: 8vh;
				padding: 32px 36px 28px; box-sizing: border-box;
				align-self: flex-start;
			}
			.sh-brand {
				margin: 0 0 20px; font-size: 13px; font-weight: 700;
				letter-spacing: 0.02em; color: #1d2327;
			}
			.sh-brand span { font-weight: 400; color: #646970; }
			h1 {
				margin: 0 0 12px; font-size: 21px; line-height: 1.3;
				font-weight: 600; letter-spacing: -0.01em;
			}
			p { margin: 0 0 12px; font-size: 14px; }
			.sh-detail { color: #50575e; }
			.sh-advice { font-weight: 600; }
			.sh-actions { margin: 20px 0 8px; display: flex; flex-wrap: wrap; gap: 10px; }
			.sh-action {
				display: inline-block; padding: 7px 14px; border-radius: 3px;
				font-size: 13px; text-decoration: none;
				color: #2271b1; border: 1px solid #2271b1; background: #fff;
			}
			.sh-action-primary { color: #fff; background: #2271b1; }
			.sh-action:focus { outline: 2px solid #2271b1; outline-offset: 2px; }
			.sh-ref {
				margin: 16px 0 0; padding-top: 14px; border-top: 1px solid #f0f0f1;
				font-family: Consolas, Monaco, monospace; font-size: 11px; color: #8c8f94;
			}
			@media (prefers-color-scheme: dark) {
				html { background: #1d2327; }
				body { color: #e2e4e7; }
				.sh-card { background: #2c3338; border-color: #3c434a; }
				.sh-brand, h1 { color: #e2e4e7; }
				.sh-brand span { color: #a7aaad; }
				.sh-detail { color: #a7aaad; }
				.sh-action { background: transparent; color: #72aee6; border-color: #72aee6; }
				.sh-action-primary { background: #2271b1; color: #fff; border-color: #2271b1; }
				.sh-ref { border-top-color: #3c434a; color: #787c82; }
			}
		';
	}
}
