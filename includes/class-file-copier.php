<?php
/**
 * Physically copies files from NGG source locations into
 * wp-content/ml-gallery, preserving the original gallery subfolder structure.
 *
 * Destination layout:
 *   wp-content/ml-gallery/<gallery-slug>/<filename>
 *   wp-content/ml-gallery/<gallery-slug>/thumbs/thumbs_<filename>
 *
 * @package MLGalleryMigratorPro
 */

namespace MLGMP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FileCopier {

	/** @var string Absolute path to wp-content/ml-gallery/ */
	private string $base_dir;

	/** @var string Public URL base for wp-content/ml-gallery/ */
	private string $base_url;

	public function __construct() {
		$upload      = wp_upload_dir();
		$content_dir = WP_CONTENT_DIR;
		$content_url = WP_CONTENT_URL;

		$this->base_dir = rtrim( $content_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'ml-gallery';
		$this->base_url = rtrim( $content_url, '/' ) . '/ml-gallery';
	}

	// -----------------------------------------------------------------------
	// Public API
	// -----------------------------------------------------------------------

	/**
	 * Copy one image + its thumb into the destination.
	 *
	 * @param  array<string,mixed> $picture    NGG picture row (with gallery_path & gallery_name).
	 * @param  string              $gallery_slug  Slug for the destination gallery folder.
	 * @return array{
	 *   file_path: string, file_url: string,
	 *   thumb_path: string, thumb_url: string,
	 *   copied: bool, thumb_copied: bool
	 * }
	 */
	public function copy_image( array $picture, string $gallery_slug ): array {
		$result = [
			'file_path'   => '',
			'file_url'    => '',
			'thumb_path'  => '',
			'thumb_url'   => '',
			'copied'      => false,
			'thumb_copied'=> false,
		];

		$gallery_dest = $this->ensure_gallery_dir( $gallery_slug );
		$thumbs_dest  = $this->ensure_thumbs_dir( $gallery_slug );

		$filename      = sanitize_file_name( $picture['filename'] );
		$src_image     = NGGReader::resolve_image_abspath( $picture );
		$src_thumb     = NGGReader::resolve_thumb_abspath( $picture );

		$dest_image    = $gallery_dest . DIRECTORY_SEPARATOR . $filename;
		$dest_thumb    = $thumbs_dest  . DIRECTORY_SEPARATOR . 'thumbs_' . $filename;

		$result['file_path'] = $dest_image;
		$result['file_url']  = $this->base_url . '/' . $gallery_slug . '/' . rawurlencode( $filename );

		$result['thumb_path'] = $dest_thumb;
		$result['thumb_url']  = $this->base_url . '/' . $gallery_slug . '/thumbs/thumbs_' . rawurlencode( $filename );

		// ── Copy main image ───────────────────────────────────────────────
		if ( file_exists( $src_image ) ) {
			if ( ! file_exists( $dest_image ) ) {
				$result['copied'] = @copy( $src_image, $dest_image );
			} else {
				$result['copied'] = true; // Already present → idempotent.
			}
		}

		// ── Copy thumb ────────────────────────────────────────────────────
		if ( file_exists( $src_thumb ) ) {
			if ( ! file_exists( $dest_thumb ) ) {
				$result['thumb_copied'] = @copy( $src_thumb, $dest_thumb );
			} else {
				$result['thumb_copied'] = true;
			}
		}

		return $result;
	}

	/**
	 * Returns the public URL for an already-copied image.
	 *
	 * @param  string $gallery_slug
	 * @param  string $filename
	 * @return string
	 */
	public function image_url( string $gallery_slug, string $filename ): string {
		return $this->base_url . '/' . $gallery_slug . '/' . rawurlencode( $filename );
	}

	/**
	 * Returns the absolute dest path for an already-copied image.
	 *
	 * @param  string $gallery_slug
	 * @param  string $filename
	 * @return string
	 */
	public function image_path( string $gallery_slug, string $filename ): string {
		return $this->base_dir . DIRECTORY_SEPARATOR . $gallery_slug . DIRECTORY_SEPARATOR . sanitize_file_name( $filename );
	}

	public function get_base_dir(): string {
		return $this->base_dir;
	}

	public function get_base_url(): string {
		return $this->base_url;
	}

	// -----------------------------------------------------------------------
	// Directory helpers
	// -----------------------------------------------------------------------

	private function ensure_gallery_dir( string $gallery_slug ): string {
		$dir = $this->base_dir . DIRECTORY_SEPARATOR . $gallery_slug;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	private function ensure_thumbs_dir( string $gallery_slug ): string {
		$dir = $this->base_dir . DIRECTORY_SEPARATOR . $gallery_slug . DIRECTORY_SEPARATOR . 'thumbs';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/**
	 * Copies all files from a directory to the destination thumbs folder.
	 * 
	 * @param  string $src_dir      NGG source thumbs directory.
	 * @param  string $gallery_slug Destination gallery slug.
	 * @return int Number of files copied.
	 */
	public function copy_thumbs_dir( string $src_dir, string $gallery_slug ): int {
		if ( ! is_dir( $src_dir ) ) {
			return 0;
		}
		$dest_dir = $this->ensure_thumbs_dir( $gallery_slug );
		$count    = 0;
		$files    = scandir( $src_dir );
		
		if ( ! $files ) {
			return 0;
		}

		foreach ( $files as $file ) {
			if ( $file === '.' || $file === '..' ) {
				continue;
			}
			$src_file  = $src_dir . DIRECTORY_SEPARATOR . $file;
			$dest_file = $dest_dir . DIRECTORY_SEPARATOR . $file;
			
			if ( is_file( $src_file ) ) {
				if ( ! file_exists( $dest_file ) ) {
					if ( @copy( $src_file, $dest_file ) ) {
						$count++;
					}
				} else {
					$count++; // Ja existe, conta como processado (idempotente)
				}
			}
		}
		return $count;
	}

	/**
	 * Ensure top-level ml-gallery dir exists and is protected.
	 */
	public function ensure_base_dir(): bool {
		if ( ! is_dir( $this->base_dir ) ) {
			if ( ! wp_mkdir_p( $this->base_dir ) ) {
				return false;
			}
		}

		// Protect with an index.php.
		$index = $this->base_dir . DIRECTORY_SEPARATOR . 'index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php // Silence is golden.\n" );
		}

		return true;
	}
}
