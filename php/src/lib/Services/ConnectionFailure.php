<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes one connection-flow failure mode in customer-facing terms.
 *
 * Instances carry everything a ConnectionErrorPage needs to render a
 * complete, actionable page: plain-language copy (what happened, why,
 * what to do next), a primary and optional secondary action, the HTTP
 * status the page should be served with, and a support-only diagnostics
 * array destined for the browser console — never the page body.
 *
 * All customer copy for the connect flow lives in the named constructors
 * here so the modes can be reviewed side by side (see PLA-2670).
 */
class ConnectionFailure {

	/**
	 * Stable failure-mode identifier, e.g. "connect.transport.dns".
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Page and browser-tab title.
	 *
	 * @var string
	 */
	private $title;

	/**
	 * What happened, in one or two plain sentences.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * Why it likely happened; empty when the cause adds nothing.
	 *
	 * @var string
	 */
	private $detail;

	/**
	 * What the reader should do next.
	 *
	 * @var string
	 */
	private $advice;

	/**
	 * Action links for the page, primary action first.
	 *
	 * @var array<int, array{url: string, text: string}>
	 */
	private $actions;

	/**
	 * HTTP status the error page should respond with.
	 *
	 * @var int
	 */
	private $status;

	/**
	 * Support-only diagnostics for the browser console.
	 *
	 * @var array<string, mixed>
	 */
	private $diagnostics;

	/**
	 * Build a failure description.
	 *
	 * @param string $slug        Stable failure-mode identifier.
	 * @param string $title       Page and browser-tab title.
	 * @param string $message     What happened.
	 * @param string $detail      Why it likely happened; may be empty.
	 * @param string $advice      What the reader should do next.
	 * @param array  $actions     Action links, primary first.
	 * @param int    $status      HTTP status for the page.
	 * @param array  $diagnostics Support-only console diagnostics.
	 */
	private function __construct( string $slug, string $title, string $message, string $detail, string $advice, array $actions, int $status, array $diagnostics = array() ) {
		$this->slug        = $slug;
		$this->title       = $title;
		$this->message     = $message;
		$this->detail      = $detail;
		$this->advice      = $advice;
		$this->actions     = $actions;
		$this->status      = $status;
		$this->diagnostics = $diagnostics;
	}

	public function get_slug(): string {
		return $this->slug;
	}

	public function get_title(): string {
		return $this->title;
	}

	public function get_message(): string {
		return $this->message;
	}

	public function get_detail(): string {
		return $this->detail;
	}

	public function get_advice(): string {
		return $this->advice;
	}

	/**
	 * Action links for the page, primary action first.
	 *
	 * @return array<int, array{url: string, text: string}>
	 */
	public function get_actions(): array {
		return $this->actions;
	}

	public function get_status(): int {
		return $this->status;
	}

	/**
	 * Support-only diagnostics destined for the browser console.
	 *
	 * @return array<string, mixed>
	 */
	public function get_diagnostics(): array {
		return $this->diagnostics;
	}

	/**
	 * Merge additional support-only diagnostics into the failure.
	 *
	 * @param array $diagnostics Extra diagnostics, merged over existing keys.
	 */
	public function with_diagnostics( array $diagnostics ): self {
		$copy              = clone $this;
		$copy->diagnostics = array_merge( $this->diagnostics, $diagnostics );
		return $copy;
	}

	private static function retry_action(): array {
		return array(
			'url'  => admin_url( 'admin-post.php?action=shorthand_connect_start' ),
			'text' => __( 'Try connecting again', 'the-shorthand-editor' ),
		);
	}

	private static function settings_action(): array {
		return array(
			'url'  => admin_url( 'options-general.php?page=theshed-settings' ),
			'text' => __( 'Go to Shorthand settings', 'the-shorthand-editor' ),
		);
	}

	/* ---- A: starting the connection ---------------------------------- */

	/**
	 * A1 — the current user cannot start or manage the connection.
	 */
	public static function permission_to_connect(): self {
		return new self(
			'connect.permission',
			__( 'A site administrator is needed for this step', 'the-shorthand-editor' ),
			__( 'Connecting this site to Shorthand changes site-wide settings, so it can only be done by a WordPress administrator.', 'the-shorthand-editor' ),
			__( 'Your account does not have the administrator role on this site.', 'the-shorthand-editor' ),
			__( 'Ask a site administrator to connect Shorthand, or to give your account the administrator role.', 'the-shorthand-editor' ),
			array(
				array(
					'url'  => admin_url( '/' ),
					'text' => __( 'Return to dashboard', 'the-shorthand-editor' ),
				),
			),
			403
		);
	}

	/**
	 * A2 / D8 — the installed plugin version is no longer accepted.
	 */
	public static function upgrade_required(): self {
		return new self(
			'connect.upgrade-required',
			__( 'Update the Shorthand plugin to connect', 'the-shorthand-editor' ),
			__( 'This version of the Shorthand plugin is too old to connect to Shorthand.', 'the-shorthand-editor' ),
			__( 'Shorthand occasionally retires old plugin versions when the connection between WordPress and Shorthand changes.', 'the-shorthand-editor' ),
			__( 'Update the plugin from the Plugins page, then connect again.', 'the-shorthand-editor' ),
			array(
				array(
					'url'  => self_admin_url( 'plugins.php' ),
					'text' => __( 'Go to Plugins', 'the-shorthand-editor' ),
				),
			),
			200
		);
	}

	/**
	 * A3 — the plugin's signing keys are missing from the options table.
	 */
	public static function signing_keys_missing(): self {
		return new self(
			'connect.keys-missing',
			__( 'The Shorthand connection needs to be set up again', 'the-shorthand-editor' ),
			__( 'The security keys this plugin uses to talk to Shorthand are missing from this site.', 'the-shorthand-editor' ),
			__( 'This can happen when plugin data is cleared, for example after a site migration or a database restore.', 'the-shorthand-editor' ),
			__( 'Reconnect to Shorthand from the settings page. Stories already published to this site are not affected.', 'the-shorthand-editor' ),
			array(
				self::settings_action(),
				self::retry_action(),
			),
			500
		);
	}

	/* ---- B/C: returning to WordPress --------------------------------- */

	/**
	 * B1 — the user cancelled at Shorthand; the return carries no token.
	 */
	public static function canceled(): self {
		return new self(
			'connect.canceled',
			__( 'Connection cancelled', 'the-shorthand-editor' ),
			__( 'The connection to Shorthand was not completed, and nothing on this site has changed.', 'the-shorthand-editor' ),
			'',
			__( 'You can connect again from the Shorthand settings page whenever you are ready.', 'the-shorthand-editor' ),
			array(
				self::settings_action(),
			),
			200
		);
	}

	/**
	 * C2 — the return leg is being completed by a non-administrator.
	 */
	public static function permission_to_complete(): self {
		return new self(
			'connect.permission-return',
			__( 'Finish connecting as the administrator who started it', 'the-shorthand-editor' ),
			__( 'The final step of the Shorthand connection must be completed by the same administrator account that started it.', 'the-shorthand-editor' ),
			__( 'You are currently signed in to WordPress with an account that cannot manage Shorthand settings.', 'the-shorthand-editor' ),
			__( 'Sign in with the administrator account that began the connection, then start the connection again.', 'the-shorthand-editor' ),
			array(
				array(
					'url'  => admin_url( '/' ),
					'text' => __( 'Return to dashboard', 'the-shorthand-editor' ),
				),
			),
			403
		);
	}

	/**
	 * C3 — the completion nonce is missing or no longer valid.
	 */
	public static function expired(): self {
		return new self(
			'connect.expired',
			__( 'This connection attempt has expired', 'the-shorthand-editor' ),
			__( 'The link back from Shorthand is no longer valid, so the connection was not completed.', 'the-shorthand-editor' ),
			__( 'This usually happens when the connection took a long time to finish, or when it was finished in a different browser or profile than it was started in.', 'the-shorthand-editor' ),
			__( 'Start the connection again and complete it in the same browser session.', 'the-shorthand-editor' ),
			array(
				self::retry_action(),
			),
			400
		);
	}

	/* ---- D: completing the handshake --------------------------------- */

	/**
	 * D1 — the token on the return URL could not be parsed.
	 */
	public static function return_token_malformed(): self {
		return new self(
			'connect.token.malformed',
			__( 'The link back from Shorthand was incomplete', 'the-shorthand-editor' ),
			__( 'Shorthand sent your browser back to WordPress, but part of the information it carried was missing or damaged, so the connection was not completed.', 'the-shorthand-editor' ),
			__( 'This can happen when the return link is truncated by a browser extension, a proxy, or an email or chat tool that rewrote the link.', 'the-shorthand-editor' ),
			__( 'Start the connection again. If it keeps failing, try a different browser without extensions.', 'the-shorthand-editor' ),
			array(
				self::retry_action(),
			),
			400
		);
	}

	/**
	 * D2 — DNS lookup for the Shorthand API failed.
	 */
	public static function transport_dns(): self {
		return new self(
			'connect.transport.dns',
			__( 'This site could not look up the Shorthand server', 'the-shorthand-editor' ),
			__( 'Your WordPress server could not find the address of the Shorthand service, so the connection was not completed.', 'the-shorthand-editor' ),
			__( 'This is a name-lookup (DNS) problem on the server that hosts this website — not on your own computer or network.', 'the-shorthand-editor' ),
			__( 'Ask your hosting provider to confirm that the server can look up and reach api.shorthand.com, then try again.', 'the-shorthand-editor' ),
			array(
				self::retry_action(),
			),
			502
		);
	}

	/**
	 * D3 — TLS negotiation or certificate verification failed.
	 */
	public static function transport_tls(): self {
		return new self(
			'connect.transport.tls',
			__( 'A secure connection to Shorthand could not be verified', 'the-shorthand-editor' ),
			__( 'Your WordPress server reached the Shorthand service but could not verify its secure (HTTPS) certificate, so the connection was stopped for safety.', 'the-shorthand-editor' ),
			__( 'This usually means the server has outdated certificate authorities, or a security appliance or proxy is intercepting HTTPS traffic.', 'the-shorthand-editor' ),
			__( 'Ask your hosting provider or IT team to update the server\'s certificate store, or to allow direct HTTPS access to api.shorthand.com.', 'the-shorthand-editor' ),
			array(
				self::retry_action(),
			),
			502
		);
	}

	/**
	 * D4 — the request to the Shorthand API timed out.
	 */
	public static function transport_timeout(): self {
		return new self(
			'connect.transport.timeout',
			__( 'The Shorthand server took too long to respond', 'the-shorthand-editor' ),
			__( 'Your WordPress server contacted the Shorthand service, but the reply did not arrive in time.', 'the-shorthand-editor' ),
			__( 'This is usually temporary — a slow network path or a busy moment on either side.', 'the-shorthand-editor' ),
			__( 'Try again now. If it keeps timing out, ask your hosting provider whether outbound requests from the server are being slowed or filtered.', 'the-shorthand-editor' ),
			array(
				self::retry_action(),
			),
			502
		);
	}

	/**
	 * D5 — the connection was refused or could not be established.
	 */
	public static function transport_unreachable(): self {
		return new self(
			'connect.transport.unreachable',
			__( 'This site could not reach the Shorthand server', 'the-shorthand-editor' ),
			__( 'Your WordPress server tried to contact the Shorthand service, but the connection could not be established.', 'the-shorthand-editor' ),
			__( 'An outbound firewall or proxy on the hosting side is the most common cause.', 'the-shorthand-editor' ),
			__( 'Ask your hosting provider to allow outbound HTTPS connections from this server to api.shorthand.com, then try again.', 'the-shorthand-editor' ),
			array(
				self::retry_action(),
			),
			502
		);
	}

	/**
	 * D6 — the Shorthand API itself rejected the authorization.
	 */
	public static function rejected_by_api(): self {
		return new self(
			'connect.rejected.api',
			__( 'Shorthand did not accept the connection', 'the-shorthand-editor' ),
			__( 'Shorthand declined to complete the connection for this site.', 'the-shorthand-editor' ),
			__( 'The authorization created when you approved the connection may have expired or been revoked.', 'the-shorthand-editor' ),
			__( 'Start the connection again from the beginning. If Shorthand keeps declining it, contact Shorthand support.', 'the-shorthand-editor' ),
			array(
				self::retry_action(),
			),
			502
		);
	}

	/**
	 * D7 — a firewall or network edge blocked the request before it
	 * reached Shorthand (response is not from the Shorthand API).
	 */
	public static function blocked_by_firewall(): self {
		return new self(
			'connect.rejected.edge',
			__( 'A firewall blocked the connection to Shorthand', 'the-shorthand-editor' ),
			__( 'Something between this site and Shorthand — a firewall, security service, or proxy — blocked the request before it reached Shorthand.', 'the-shorthand-editor' ),
			__( 'The reply did not come from Shorthand itself, which is how the plugin can tell the request was intercepted.', 'the-shorthand-editor' ),
			__( 'Try again in a few minutes. If it keeps happening, contact Shorthand support and mention that a firewall appears to be blocking the connection — the technical details are in this page\'s browser console.', 'the-shorthand-editor' ),
			array(
				self::retry_action(),
			),
			502
		);
	}

	/**
	 * D9 — the Shorthand API returned a server error.
	 */
	public static function server_error(): self {
		return new self(
			'connect.server-error',
			__( 'Shorthand had a problem completing the connection', 'the-shorthand-editor' ),
			__( 'The Shorthand service hit an unexpected error while setting up the connection. Nothing is wrong on your side.', 'the-shorthand-editor' ),
			'',
			__( 'Try again in a few minutes. If it keeps failing, contact Shorthand support — the technical details are in this page\'s browser console.', 'the-shorthand-editor' ),
			array(
				self::retry_action(),
			),
			502
		);
	}

	/**
	 * D10a — the Shorthand API asked the site to slow down.
	 */
	public static function rate_limited(): self {
		return new self(
			'connect.rate-limited',
			__( 'Too many attempts for the moment', 'the-shorthand-editor' ),
			__( 'Shorthand received too many requests from this site in a short time and asked it to pause.', 'the-shorthand-editor' ),
			'',
			__( 'Wait a minute or two, then try connecting again.', 'the-shorthand-editor' ),
			array(
				self::retry_action(),
			),
			503
		);
	}

	/**
	 * D10b — any other unexpected response.
	 */
	public static function unexpected_response(): self {
		return new self(
			'connect.unexpected-response',
			__( 'The connection could not be completed', 'the-shorthand-editor' ),
			__( 'Shorthand replied in a way the plugin did not expect, so the connection was not completed.', 'the-shorthand-editor' ),
			'',
			__( 'Try again. If it keeps failing, contact Shorthand support — the technical details are in this page\'s browser console.', 'the-shorthand-editor' ),
			array(
				self::retry_action(),
			),
			502
		);
	}

	/**
	 * D11 — a success response whose body was not usable.
	 */
	public static function response_invalid(): self {
		return new self(
			'connect.response-invalid',
			__( 'The connection could not be completed', 'the-shorthand-editor' ),
			__( 'Shorthand appeared to accept the connection, but its reply was missing the credentials this site needs, so nothing was saved.', 'the-shorthand-editor' ),
			__( 'A proxy or caching layer between this site and Shorthand may have altered the reply.', 'the-shorthand-editor' ),
			__( 'Try again. If it keeps failing, contact Shorthand support — the technical details are in this page\'s browser console.', 'the-shorthand-editor' ),
			array(
				self::retry_action(),
			),
			502
		);
	}

	/**
	 * D1′ / catch-all — an error the plugin did not anticipate.
	 */
	public static function unexpected_error(): self {
		return new self(
			'connect.unexpected-error',
			__( 'Something went wrong while connecting', 'the-shorthand-editor' ),
			__( 'The plugin hit an unexpected problem while completing the Shorthand connection.', 'the-shorthand-editor' ),
			'',
			__( 'Try again. If it keeps failing, contact Shorthand support — the technical details are in this page\'s browser console.', 'the-shorthand-editor' ),
			array(
				self::retry_action(),
			),
			500
		);
	}

	/* ---- E: after the connection degrades ----------------------------- */

	/**
	 * E1 — the stored connection is inactive; the reader can fix it.
	 */
	public static function connection_inactive_admin(): self {
		return new self(
			'connect.inactive',
			__( 'Shorthand is not connected', 'the-shorthand-editor' ),
			__( 'This site\'s connection to Shorthand is not active right now, so Shorthand stories cannot be opened or created.', 'the-shorthand-editor' ),
			'',
			__( 'Reconnect from the Shorthand settings page, then return to what you were doing.', 'the-shorthand-editor' ),
			array(
				self::settings_action(),
			),
			200
		);
	}

	/**
	 * E1 — the stored connection is inactive; the reader cannot fix it.
	 */
	public static function connection_inactive(): self {
		return new self(
			'connect.inactive',
			__( 'Shorthand is not connected', 'the-shorthand-editor' ),
			__( 'This site\'s connection to Shorthand is not active right now, so Shorthand stories cannot be opened or created.', 'the-shorthand-editor' ),
			'',
			__( 'Ask a site administrator to reconnect Shorthand from the plugin\'s settings page. You can share this page\'s web address with them.', 'the-shorthand-editor' ),
			array(
				array(
					'url'  => admin_url( '/' ),
					'text' => __( 'Return to dashboard', 'the-shorthand-editor' ),
				),
			),
			200
		);
	}
}
