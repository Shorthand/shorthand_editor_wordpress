<?php

namespace Shorthand\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Services\FileSystem;

/**
 * Explains, on the settings screen, why staging cannot be turned off.
 *
 * This is the one place vendor identity is allowed to appear. It produces a
 * message, never a behaviour: the publish path decides by URL scheme alone.
 * See `docs/services/file-system.md`. An unrecognised
 * host degrades to a sentence naming the scheme that was found.
 */
class UploadsHostNotice {

	/**
	 * Sentence explaining the host, or an empty string where uploads are local.
	 */
	public static function get_message(): string {
		$scheme = FileSystem::get_uploads_scheme();

		if ( '' === $scheme ) {
			return '';
		}

		switch ( $scheme ) {
			case 'vip':
				return __( 'This site stores uploads on the WordPress VIP File System, which stories cannot be unpacked into directly.', 'the-shorthand-editor' );
			case 'gs':
				return __( 'This site stores uploads in Google Cloud Storage, through WP Stateless in stateless mode, which stories cannot be unpacked into directly.', 'the-shorthand-editor' );
			case 's3':
				return __( 'This site stores uploads in Amazon S3, through WP Offload Media, which stories cannot be unpacked into directly.', 'the-shorthand-editor' );
			default:
				return sprintf(
					/* translators: %s: URL scheme of the uploads directory, such as "vip". */
					__( 'This site stores uploads through the %s:// stream wrapper, which stories cannot be unpacked into directly.', 'the-shorthand-editor' ),
					$scheme
				);
		}
	}
}
