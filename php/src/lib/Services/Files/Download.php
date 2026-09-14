<?php

namespace Shorthand\Services\Files;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Services\StoryId;

/**
 * One transfer of a story archive, in the chunks it arrives in.
 *
 * Chunks arrive one WP Cron request at a time, so they are held in uploads
 * rather than in a temp directory the next request would not find. They are
 * named beside the bundle, `{bundle}_{nonce}_{n}.part`, rather than inside a
 * directory of their own: an empty directory cannot be removed on every host,
 * and one would be left behind by every publish.
 *
 * A download is finished once the archive is assembled. Nothing reads it after
 * that, which is why it is not part of `Bundle`.
 *
 * See `docs/services/file-system.md`.
 */
class Download {

	/**
	 * Uploads directory the chunks are written to.
	 *
	 * @var \Shorthand\Services\Files\Uploads
	 */
	private $uploads;

	/**
	 * Bundle directory the chunks are named beside.
	 *
	 * @var string
	 */
	private $bundle_path;

	/**
	 * Request nonce identifying this download.
	 *
	 * @var string
	 */
	private $nonce;

	/**
	 * Open a download through `Bundle::download()`, which knows the path.
	 *
	 * @param \Shorthand\Services\Files\Uploads $uploads     Uploads directory.
	 * @param string                            $bundle_path Bundle directory the chunks sit beside.
	 * @param string                            $nonce       Request nonce identifying the download.
	 */
	public function __construct( Uploads $uploads, string $bundle_path, string $nonce ) {
		$this->uploads     = $uploads;
		$this->bundle_path = $bundle_path;
		$this->nonce       = $nonce;
	}

	/**
	 * Prepares uploads for the chunks.
	 *
	 * Only the directory the bundle itself sits in is created, because that is
	 * the only one a chunk path needs.
	 */
	public function start(): bool {
		return $this->uploads->make_dir( dirname( $this->bundle_path ) );
	}

	/**
	 * Where one chunk is written.
	 *
	 * @param int $index Position of the chunk in the archive.
	 */
	public function chunk_path( int $index ): string {
		return $this->bundle_path . '_' . $this->nonce . '_' . $index . '.part';
	}

	/**
	 * Every chunk path, in order.
	 *
	 * @param int $chunks Number of chunks downloaded.
	 * @return string[]
	 */
	public function chunk_paths( int $chunks ): array {
		$paths = array();

		for ( $idx = 0; $idx < $chunks; $idx++ ) {
			$paths[] = $this->chunk_path( $idx );
		}

		return $paths;
	}

	/**
	 * Removes the chunks.
	 *
	 * They are named, not listed: a download cannot be enumerated on every
	 * host.
	 *
	 * @param int $chunks Number of chunks downloaded.
	 */
	public function discard( int $chunks ): void {
		foreach ( $this->chunk_paths( $chunks ) as $path ) {
			$this->uploads->delete( $path );
		}
	}

	/**
	 * Removes the chunks of a download queued before the chunk rename.
	 *
	 * Those went into a directory beside the bundle, `{bundle}_{nonce}`, which
	 * is derived here rather than read back from the task or the pull record:
	 * no path out of stored data should reach a delete loop. The directory
	 * itself is left, as an empty one cannot be removed on every host.
	 *
	 * Remove once no pull or task recorded against the previous release can
	 * still be found.
	 *
	 * @param int $chunks Number of chunks it had written.
	 */
	public function discard_legacy( int $chunks ): void {
		$dir = $this->bundle_path . '_' . $this->nonce;

		for ( $idx = 0; $idx < $chunks; $idx++ ) {
			$this->uploads->delete( $dir . '/file-' . $idx . '.part' );
		}
	}

	/**
	 * The nonce, where it can be part of a path.
	 *
	 * A nonce is generated, not received, so an unusable one never happens in
	 * practice. It is validated the same way a story ID is because both are
	 * interpolated into directory names, and neither is worth trusting on that
	 * account.
	 *
	 * @return string The nonce, or an empty string.
	 */
	public function segment(): string {
		return StoryId::is_valid( $this->nonce ) ? $this->nonce : '';
	}
}
