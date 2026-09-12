<?php

namespace Shorthand\Services\Files;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Services\StoryId;
use WP_Error;

/**
 * The files of one published story, and everything done to them.
 *
 * A bundle lives at `uploads/shorthand/{post_id}/{story_id}`. It is written
 * once per publish and never read back, so the manifest in post meta is the
 * only record of what it holds: it says what to skip copying, what to delete,
 * and what a sidecar plugin has to mirror.
 *
 * Callers ask for a publish, not for file operations. Where a file lands, what
 * is worth copying again, and how a bundle is emptied on a host that cannot
 * list one are all settled here.
 *
 * See `docs/services/file-system.md`.
 */
class Bundle {

	/**
	 * Post meta holding the manifest of the last successful publish.
	 */
	const MANIFEST_META = 'story_manifest';

	/**
	 * @var \Shorthand\Services\Files\Uploads
	 */
	private $uploads;

	/**
	 * @var int
	 */
	private $post_id;

	/**
	 * @var string
	 */
	private $story_id;

	/**
	 * Open a bundle through `BundleStore`, which validates the story ID.
	 *
	 * @param \Shorthand\Services\Files\Uploads $uploads  Uploads directory.
	 * @param int                               $post_id  Post the bundle belongs to.
	 * @param string                            $story_id Shorthand story ID, valid as a path segment.
	 */
	public function __construct( Uploads $uploads, int $post_id, string $story_id ) {
		$this->uploads  = $uploads;
		$this->post_id  = $post_id;
		$this->story_id = $story_id;
	}

	/**
	 * Absolute path of the bundle directory.
	 */
	public function path(): string {
		return wp_upload_dir()['basedir'] . '/shorthand/' . $this->post_id . '/' . $this->story_id;
	}

	/**
	 * Public URL of the bundle directory.
	 */
	public function url(): string {
		$url = wp_upload_dir()['baseurl'] . '/shorthand/' . $this->post_id . '/' . $this->story_id;

		/**
		 * Filters the public URL a story's files are served from.
		 *
		 * @param string $url Bundle URL derived from the uploads directory.
		 */
		return apply_filters( 'theshed_get_story_url', $url );
	}

	/**
	 * Manifest of the last successful publish.
	 *
	 * @return array<string, array{size: int, crc: int}>
	 */
	public function manifest(): array {
		return Manifest::from_meta( get_post_meta( $this->post_id, self::MANIFEST_META, true ) );
	}

	/**
	 * Prepares uploads for the chunks of a download.
	 *
	 * Only the directory the bundle itself sits in is created. Chunks are
	 * named beside the bundle rather than inside a directory of their own,
	 * because an empty directory cannot be removed on every host, and one
	 * would be left behind by every publish.
	 */
	public function start_download(): bool {
		return $this->uploads->make_dir( dirname( $this->path() ) );
	}

	/**
	 * Where one chunk of a download is written.
	 *
	 * Chunks arrive one WP Cron request at a time, so they are held in uploads
	 * rather than in a temp directory that the next request would not find.
	 *
	 * @param string $nonce Request nonce identifying the download.
	 * @param int    $index Position of the chunk in the archive.
	 */
	public function chunk_path( string $nonce, int $index ): string {
		return $this->path() . '_' . $nonce . '_' . $index . '.part';
	}

	/**
	 * Removes the chunks of one download.
	 *
	 * The chunks are named, not listed: a download directory cannot be
	 * enumerated on every host.
	 *
	 * @param string $nonce  Request nonce identifying the download.
	 * @param int    $chunks Number of chunks downloaded into it.
	 */
	public function discard_download( string $nonce, int $chunks ): void {
		for ( $idx = 0; $idx < $chunks; $idx++ ) {
			$this->uploads->delete( $this->chunk_path( $nonce, $idx ) );
		}
	}

	/**
	 * Assembles a download, unpacks it, and copies what changed into uploads.
	 *
	 * Everything expensive is hidden here. The archive is assembled and
	 * unpacked locally, because `ZipArchive` cannot target a stream wrapper;
	 * only files whose size or CRC32 differ from the stored manifest are
	 * written; files that left the story are deleted; and the documents move
	 * to a new path each publish, so no path is rewritten often enough to meet
	 * a host's modification cap.
	 *
	 * @param string $nonce  Request nonce identifying the download.
	 * @param int    $chunks Number of chunks downloaded.
	 * @return array{head: string, article: string, head_path: string, article_path: string}|\WP_Error
	 */
	public function publish( string $nonce, int $chunks ) {
		$staging = Staging::open( $this->uploads, 'sh_pull_' . $this->safe_nonce( $nonce ) . '_' );

		try {
			return $this->unpack( $staging, $nonce, $chunks );
		} finally {
			$staging->discard();
		}
	}

	/**
	 * Removes every file the manifest names, and forgets the manifest.
	 *
	 * The `{post_id}` parent is left alone: it cannot be listed, so it cannot
	 * be known to be empty.
	 */
	public function delete(): void {
		$this->prune( $this->manifest() );

		delete_post_meta( $this->post_id, self::MANIFEST_META );

		/**
		 * Fires once a story's files have been removed from uploads.
		 *
		 * @param string $path     Bundle directory.
		 * @param int    $post_id  Post the bundle belonged to.
		 * @param string $story_id Shorthand story ID.
		 */
		do_action( 'theshed_story_bundle_deleted', $this->path(), $this->post_id, $this->story_id );
	}

	/**
	 * The publish, with the staging directory already open.
	 *
	 * @param \Shorthand\Services\Files\Staging $staging Scratch directory for this publish.
	 * @param string                            $nonce   Request nonce identifying the download.
	 * @param int                               $chunks  Number of chunks downloaded.
	 * @return array{head: string, article: string, head_path: string, article_path: string}|\WP_Error
	 */
	private function unpack( Staging $staging, string $nonce, int $chunks ) {
		$archive_path = $staging->gather( $this->chunk_paths( $nonce, $chunks ), 'archive.zip' );

		if ( null === $archive_path ) {
			return new WP_Error( 'file', 'Failed to assemble story download.', $staging->file( 'archive.zip' ) );
		}

		$archive = Archive::open( $archive_path );

		if ( is_wp_error( $archive ) ) {
			return $archive;
		}

		$unpacked = $staging->file( 'unpacked' );

		$extracted = $archive->unpack_to( $unpacked );

		if ( is_wp_error( $extracted ) ) {
			return $extracted;
		}

		$documents_dir = $this->documents_dir( $nonce );
		$manifest      = $archive->manifest();

		if ( '' !== $documents_dir ) {
			$manifest = Manifest::relocate_documents( $manifest, $documents_dir );
		}

		$stored = $this->manifest();
		$copied = $this->copy( $unpacked, $manifest, $stored );

		if ( is_wp_error( $copied ) ) {
			return $copied;
		}

		$this->prune( Manifest::removed( $stored, $copied ) );

		update_post_meta( $this->post_id, self::MANIFEST_META, $copied );

		/**
		 * Fires once a story's files are in uploads, before its markup is stored.
		 *
		 * A sidecar plugin mirroring uploads elsewhere has the whole bundle
		 * here, and each written file individually through
		 * `theshed_story_file_written`.
		 *
		 * @param array  $manifest Every file the bundle now holds, to size and CRC32.
		 * @param string $path     Bundle directory.
		 * @param int    $post_id  Post the bundle belongs to.
		 * @param string $story_id Shorthand story ID.
		 */
		do_action( 'theshed_story_bundle_published', $copied, $this->path(), $this->post_id, $this->story_id );

		$documents_path = '' === $documents_dir ? $this->path() : $this->path() . '/' . $documents_dir;

		return array(
			'head'         => $archive->document( 'head.html' ),
			'article'      => $archive->document( 'article.html' ),
			'head_path'    => $documents_path . '/head.html',
			'article_path' => $documents_path . '/article.html',
		);
	}

	/**
	 * Copies an unpacked tree into the bundle, skipping unchanged files.
	 *
	 * Driven from `$manifest`, never by listing either directory. A file is
	 * unchanged when its name, size and CRC32 all match the stored entry.
	 *
	 * @param string $source_dir Unpacked tree in the staging directory.
	 * @param array  $manifest   The tree to copy: bundle path to size, CRC32, and the unpacked name where it differs.
	 * @param array  $stored     Manifest of the last successful publish.
	 * @return array|\WP_Error The bundle as it now stands, or an error.
	 */
	private function copy( string $source_dir, array $manifest, array $stored ) {
		$bundle_path = $this->path();
		$made        = array();

		foreach ( $manifest as $name => $entry ) {
			if ( $this->is_unchanged( $stored, $name, $entry ) ) {
				continue;
			}

			$dest_path   = $bundle_path . '/' . $name;
			$parent_path = dirname( $dest_path );

			if ( ! isset( $made[ $parent_path ] ) ) {
				if ( ! $this->uploads->make_dir( $parent_path ) ) {
					return new WP_Error( 'file', "Could not create the bundle directory {$parent_path}.", $parent_path );
				}

				$made[ $parent_path ] = true;
			}

			$source_path = $source_dir . '/' . ( isset( $entry['from'] ) ? $entry['from'] : $name );

			$written = $this->uploads->write( $source_path, $dest_path );

			if ( is_wp_error( $written ) ) {
				return $written;
			}

			if ( ! $written ) {
				return new WP_Error( 'file', "Could not write the story file {$dest_path}.", $dest_path );
			}

			/**
			 * Fires for each story file written into uploads.
			 *
			 * Skipped files do not fire: the copy is a diff against the last
			 * publish, and what it skips is already there unchanged.
			 *
			 * @param string $path    Absolute path in uploads.
			 * @param string $name    Path relative to the bundle directory.
			 * @param int    $post_id Post the bundle belongs to.
			 */
			do_action( 'theshed_story_file_written', $dest_path, $name, $this->post_id );
		}

		return $this->without_sources( $manifest );
	}

	/**
	 * Deletes every file a manifest names, without listing the directory.
	 *
	 * @param array $manifest Files to remove, keyed by path relative to the bundle.
	 */
	private function prune( array $manifest ): void {
		$bundle_path = $this->path();

		foreach ( array_keys( $manifest ) as $name ) {
			$path = $bundle_path . '/' . $name;

			if ( ! $this->uploads->delete( $path ) ) {
				continue;
			}

			/**
			 * Fires for each story file removed from uploads.
			 *
			 * @param string $path    Absolute path in uploads.
			 * @param string $name    Path relative to the bundle directory.
			 * @param int    $post_id Post the bundle belongs to.
			 */
			do_action( 'theshed_story_file_deleted', $path, $name, $this->post_id );
		}
	}

	/**
	 * Reports whether an unpacked file matches the entry stored for it.
	 *
	 * @param array                      $stored Manifest of the last successful publish.
	 * @param string                     $name   Path of the file within the bundle.
	 * @param array{size: int, crc: int} $entry  Entry read from the archive index.
	 * @return bool True when the file can be skipped.
	 */
	private function is_unchanged( array $stored, string $name, array $entry ): bool {
		if ( ! isset( $stored[ $name ] ) ) {
			return false;
		}

		$previous = $stored[ $name ];

		return isset( $previous['size'], $previous['crc'] )
			&& (int) $previous['size'] === $entry['size']
			&& (int) $previous['crc'] === $entry['crc'];
	}

	/**
	 * Drops the copy instructions, leaving a description of the bundle.
	 *
	 * @param array $manifest Manifest that drove the copy.
	 * @return array<string, array{size: int, crc: int}>
	 */
	private function without_sources( array $manifest ): array {
		foreach ( $manifest as $name => $entry ) {
			if ( ! isset( $entry['from'] ) ) {
				continue;
			}

			unset( $entry['from'] );

			$manifest[ $name ] = $entry;
		}

		return $manifest;
	}

	/**
	 * Every chunk path of one download, in order.
	 *
	 * @param string $nonce  Request nonce identifying the download.
	 * @param int    $chunks Number of chunks downloaded.
	 * @return string[]
	 */
	private function chunk_paths( string $nonce, int $chunks ): array {
		$paths = array();

		for ( $idx = 0; $idx < $chunks; $idx++ ) {
			$paths[] = $this->chunk_path( $nonce, $idx );
		}

		return $paths;
	}

	/**
	 * Bundle-relative directory holding the documents of one publish.
	 *
	 * A nonce that cannot be a path segment leaves the documents at the root
	 * of the bundle, which is where they were before they were versioned.
	 *
	 * @param string $nonce Request nonce identifying the download.
	 * @return string Directory relative to the bundle, or an empty string.
	 */
	private function documents_dir( string $nonce ): string {
		$safe = $this->safe_nonce( $nonce );

		return '' === $safe ? '' : "docs/{$safe}";
	}

	/**
	 * The nonce, where it can be part of a path.
	 *
	 * A nonce is generated, not received, so this never fires in practice. It
	 * is validated the same way a story ID is because both are interpolated
	 * into directory names, and neither is worth trusting on that account.
	 *
	 * @param string $nonce Request nonce identifying the download.
	 * @return string The nonce, or an empty string.
	 */
	private function safe_nonce( string $nonce ): string {
		return StoryId::is_valid( $nonce ) ? $nonce : '';
	}
}
