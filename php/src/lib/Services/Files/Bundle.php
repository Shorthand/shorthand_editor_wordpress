<?php

namespace Shorthand\Services\Files;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	 * Uploads directory the bundle is written to.
	 *
	 * @var \Shorthand\Services\Files\Uploads
	 */
	private $uploads;

	/**
	 * Post the bundle belongs to, and holds the manifest.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Shorthand story ID, already validated as a path segment.
	 *
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
	 * Post the bundle belongs to.
	 */
	public function post_id(): int {
		return $this->post_id;
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
	 * Records what the bundle holds.
	 *
	 * Called last in a publish, so that a failure while storing the story's
	 * documents leaves the previous manifest in place.
	 *
	 * @param array $manifest Every file the bundle holds, to size and CRC32.
	 */
	public function commit( array $manifest ): void {
		update_post_meta( $this->post_id, self::MANIFEST_META, $manifest );
	}

	/**
	 * The transfer that fills this bundle, by the nonce identifying it.
	 *
	 * @param string $nonce Request nonce identifying the download.
	 */
	public function download( string $nonce ): Download {
		return new Download( $this->uploads, $this->path(), $nonce );
	}

	/**
	 * Assembles a download, unpacks it, and copies what changed into uploads.
	 *
	 * Everything expensive is hidden here. The archive is assembled and
	 * unpacked locally, because `ZipArchive` cannot target a stream wrapper;
	 * only files whose size or CRC32 differ from the stored manifest are
	 * written; and files that left the story are deleted.
	 *
	 * @param string $nonce  Request nonce identifying the download.
	 * @param int    $chunks Number of chunks downloaded.
	 * @return array{head: string, article: string, head_path: string, article_path: string, manifest: array}|\WP_Error
	 */
	public function publish( string $nonce, int $chunks ) {
		$download = $this->download( $nonce );
		$staging  = Staging::open( $this->uploads, 'sh_pull_' . $download->segment() . '_' );

		if ( null === $staging ) {
			return new WP_Error( 'file', 'No file system is available to unpack the story.' );
		}

		try {
			return $this->unpack( $staging, $download, $chunks );
		} finally {
			/*
			 * The story is in uploads by now, so this fails nothing. The
			 * directory name is random and recorded nowhere, so this is the
			 * one chance to say it was left.
			 */
			if ( ! $staging->discard() ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: %s: local path of a directory a story publish worked in. */
						esc_html__( 'The story staging directory %s could not be removed, so it stays until the system clears its temp directory.', 'the-shorthand-editor' ),
						esc_html( $staging->path() )
					),
					'1.0.10'
				);
			}
		}
	}

	/**
	 * Removes every file the manifest names, and forgets the manifest.
	 *
	 * The `{post_id}` parent is left alone: it cannot be listed, so it cannot
	 * be known to be empty.
	 *
	 * The manifest goes whether or not every delete landed. This runs on
	 * `before_delete_post`, and WordPress drops the post's meta straight after,
	 * so keeping the record would buy nothing: a file the host refused is an
	 * orphan either way.
	 */
	public function delete(): void {
		$this->prune( $this->manifest() );

		delete_post_meta( $this->post_id, self::MANIFEST_META );
	}

	/**
	 * The publish, with the staging directory already open.
	 *
	 * @param \Shorthand\Services\Files\Staging  $staging  Scratch directory for this publish.
	 * @param \Shorthand\Services\Files\Download $download Transfer the archive arrived in.
	 * @param int                                $chunks   Number of chunks downloaded.
	 * @return array{head: string, article: string, head_path: string, article_path: string, manifest: array}|\WP_Error
	 */
	private function unpack( Staging $staging, Download $download, int $chunks ) {
		$archive_path = $staging->gather( $download->chunk_paths( $chunks ), 'archive.zip' );

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

		$manifest = $archive->manifest();
		$stored   = $this->manifest();
		$copied   = $this->copy( $unpacked, $manifest, $stored );

		if ( is_wp_error( $copied ) ) {
			$written = $copied->get_error_data( 'partial_manifest' );

			/* A digits-only name is an integer key, which `array_merge()` renumbers. */
			if ( ! empty( $written ) ) {
				$this->commit( array_replace( $stored, $written ) );
			}

			return $copied;
		}

		$this->prune( Manifest::removed( $stored, $copied ) );

		return array(
			'head'         => $archive->document( 'head.html' ),
			'article'      => $archive->document( 'article.html' ),
			'head_path'    => $this->path() . '/head.html',
			'article_path' => $this->path() . '/article.html',
			'manifest'     => $copied,
		);
	}

	/**
	 * Copies an unpacked tree into the bundle, skipping unchanged files.
	 *
	 * Driven from `$manifest`, never by listing either directory. A file is
	 * unchanged when its name, size and CRC32 all match the stored entry.
	 *
	 * @param string $source_dir Unpacked tree in the staging directory.
	 * @param array  $manifest   The tree to copy: bundle path to size and CRC32.
	 * @param array  $stored     Manifest of the last successful publish.
	 * @return array|\WP_Error The bundle as it now stands, or an error.
	 */
	private function copy( string $source_dir, array $manifest, array $stored ) {
		$bundle_path = $this->path();
		$made        = array();
		$written     = array();

		foreach ( $manifest as $name => $entry ) {
			if ( $this->is_unchanged( $stored, $name, $entry ) ) {
				continue;
			}

			$dest_path   = $bundle_path . '/' . $name;
			$parent_path = dirname( $dest_path );

			if ( ! isset( $made[ $parent_path ] ) ) {
				if ( ! $this->uploads->make_dir( $parent_path ) ) {
					return self::partial( new WP_Error( 'file', "Could not create the bundle directory {$parent_path}.", $parent_path ), $written );
				}

				$made[ $parent_path ] = true;
			}

			$result = $this->uploads->write( $source_dir . '/' . $name, $dest_path );

			if ( is_wp_error( $result ) ) {
				return self::partial( $result, $written );
			}

			if ( ! $result ) {
				return self::partial( new WP_Error( 'file', "Could not write the story file {$dest_path}.", $dest_path ), $written );
			}

			$written[ $name ] = $entry;
		}

		return $manifest;
	}

	/**
	 * Attaches to an error the files that reached uploads before it.
	 *
	 * A file no manifest names can never be removed: `prune()` and `delete()`
	 * both work from the manifest, because uploads cannot be listed.
	 *
	 * @param \WP_Error $error   Failure that ended the copy.
	 * @param array     $written Entries written before it, keyed by bundle path.
	 */
	private static function partial( WP_Error $error, array $written ): WP_Error {
		$error->add( 'partial_manifest', 'Files written before the failure.', $written );

		return $error;
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

			$this->uploads->delete( $path );
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
}
