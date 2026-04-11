<?php
/**
 * Reads NextGEN Gallery data from its native database tables.
 * All reads are cursor-based to avoid OFFSET on large datasets.
 *
 * NGG tables (prefixed):
 *   ngg_galleries   – galleryid, name, path, title, pageid, previewpic, ...
 *   ngg_pictures    – pid, image_slug, galleryid, filename, description, alttext, sortorder, ...
 *   ngg_album       – id, name, sortorder, previewpic, ...  (parent album)
 *   ngg_gallery_sortorder / ngg_album_gallery_map  – album↔gallery mapping
 *
 * @package MLGalleryMigratorPro
 */

namespace MLGMP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NGGReader {

	// -----------------------------------------------------------------------
	// Presence check
	// -----------------------------------------------------------------------

	/**
	 * Returns TRUE if any NGG table is found OR if the NGG plugin file is active.
	 * Uses INFORMATION_SCHEMA to avoid MySQL LIKE wildcard issues with '_'.
	 */
	public static function ngg_active(): bool {
		return ! empty( self::detect()['tables_found'] ) || self::detect()['plugin_active'];
	}

	/**
	 * Full detection diagnostics — used by Migrator::analyse() for logging.
	 *
	 * @return array{tables_found: string[], tables_missing: string[], plugin_active: bool, db_name: string}
	 */
	public static function detect(): array {
		global $wpdb;

		$db_name  = DB_NAME;
		$prefix   = $wpdb->prefix;

		$candidates = [
			$prefix . 'ngg_gallery',   // NGG usa singular — NÃO "ngg_galleries".
			$prefix . 'ngg_pictures',
			$prefix . 'ngg_album',
		];

		$found   = [];
		$missing = [];

		foreach ( $candidates as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			
			if ( $exists ) {
				$found[] = $table;
			} else {
				$missing[] = $table;
			}
		}

		// Secondary check: is the NGG plugin file present and active?
		$plugin_active = false;
		if ( function_exists( 'is_plugin_active' ) ) {
			$plugin_active = is_plugin_active( 'nextgen-gallery/nggallery.php' )
			             || is_plugin_active( 'nextgen-gallery-pro/nggallery-pro.php' );
		} else {
			// Fallback: check active_plugins option directly.
			$active = (array) get_option( 'active_plugins', [] );
			foreach ( $active as $plugin ) {
				if ( false !== strpos( $plugin, 'nggallery' ) || false !== strpos( $plugin, 'nextgen-gallery' ) ) {
					$plugin_active = true;
					break;
				}
			}
		}

		return [
			'tables_found'   => $found,
			'tables_missing' => $missing,
			'plugin_active'  => $plugin_active,
			'db_name'        => $db_name,
		];
	}

	// -----------------------------------------------------------------------
	// Analysis (full counts — called once)
	// -----------------------------------------------------------------------

	/**
	 * @return array<string,int|string>
	 */
	public static function count_all(): array {
		global $wpdb;

		$detection = self::detect();
		$found     = $detection['tables_found'];

		// NOTA: NGG usa ngg_gallery (singular), NÃO ngg_galleries.
		$pg = $wpdb->prefix . 'ngg_gallery';
		$pp = $wpdb->prefix . 'ngg_pictures';
		$pa = $wpdb->prefix . 'ngg_album';

		// NOOP (Removed diagnostic logs)

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$galleries  = in_array( $pg, $found, true ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pg}" ) : 0;
		$images     = in_array( $pp, $found, true ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pp}" ) : 0;
		$albums     = in_array( $pa, $found, true ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pa}" ) : 0;
		// phpcs:enable

		$gallery_found = in_array( $pg, $found, true );

		$shortcodes = self::count_shortcodes_in_content();

		return [
			'galleries'       => $galleries,
			'albums'          => $albums,
			'images'          => $images,
			'thumbs'          => $images,
			'shortcodes'      => $shortcodes,
			'_tables_found'   => implode( ', ', $detection['tables_found'] ),
			'_tables_missing' => implode( ', ', $detection['tables_missing'] ),
			'_plugin_active'  => $detection['plugin_active'] ? 'sim' : 'não',
		];
	}

	private static function count_shortcodes_in_content(): int {
		global $wpdb;
		$ngg_tags = [
			'nggallery', 'nggtags', 'ngg_images', 'ngg',
			'ngg-album', 'album', 'nggalbum',
		];
		$count = 0;
		foreach ( $ngg_tags as $tag ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$n = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts}
					 WHERE post_status NOT IN ('trash','auto-draft')
					   AND post_content LIKE %s",
					'%[' . $tag . '%'
				)
			);
			$count += $n;
		}
		return $count;
	}

	// -----------------------------------------------------------------------
	// Galleries — cursor-based (by galleryid)
	// -----------------------------------------------------------------------

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_galleries_batch( int $after_id, int $batch_size ): array {
		global $wpdb;
		// NGG usa ngg_gallery (singular) e chave primária gid (não galleryid).
		$t = $wpdb->prefix . 'ngg_gallery';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT gid, name, path, title, pageid, previewpic
				 FROM {$t}
				 WHERE gid > %d
				 ORDER BY gid ASC
				 LIMIT %d",
				$after_id,
				$batch_size
			),
			ARRAY_A
		);
		return $rows ?: [];
	}

	// -----------------------------------------------------------------------
	// Albums — cursor-based (by id)
	// -----------------------------------------------------------------------

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_albums_batch( int $after_id, int $batch_size ): array {
		global $wpdb;
		$t = $wpdb->prefix . 'ngg_album';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, sortorder, pageid, previewpic
				 FROM {$t}
				 WHERE id > %d
				 ORDER BY id ASC
				 LIMIT %d",
				$after_id,
				$batch_size
			),
			ARRAY_A
		);
		return $rows ?: [];
	}

	/**
	 * Extracts gallery and album IDs from NGG sortorder string.
	 *
	 * @param string $sortorder Serialized or CSV string from NGG.
	 * @return array<int, array{type: string, id: int}>
	 */
	public static function parse_album_sortorder( string $sortorder ): array {
		if ( empty( $sortorder ) ) {
			return [];
		}

		$items = [];

		// 1. Decodifica Base64 se aplicável (NGG moderno às vezes usa Base64)
		$decoded_base64 = base64_decode( $sortorder, true );
		if ( $decoded_base64 && ( strpos( $decoded_base64, '[' ) === 0 || strpos( $decoded_base64, 'a:' ) === 0 ) ) {
			$sortorder = $decoded_base64;
		}

		// 2. Tenta PHP Unserialize
		$arr = @unserialize( $sortorder ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		
		// 3. Tenta JSON
		if ( ! is_array( $arr ) ) {
			$arr = json_decode( $sortorder, true );
		}

		// 4. Processar array resultante
		if ( is_array( $arr ) ) {
			foreach ( $arr as $val ) {
				$val = (string) $val;
				if ( preg_match( '/^a(\d+)$/i', $val, $m ) ) {
					$items[] = [ 'type' => 'album', 'id' => (int) $m[1] ];
				} elseif ( preg_match( '/^g(\d+)$/i', $val, $m ) ) {
					$items[] = [ 'type' => 'gallery', 'id' => (int) $m[1] ];
				} elseif ( is_numeric( $val ) ) {
					$items[] = [ 'type' => 'gallery', 'id' => (int) $val ];
				}
			}
		}

		// 5. Regex global sobre string bruta
		if ( empty( $items ) ) {
			if ( preg_match_all( '/([ga])(\d+)/i', $sortorder, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$type = strtolower( $match[1] ) === 'a' ? 'album' : 'gallery';
					$items[] = [ 'type' => $type, 'id' => (int) $match[2] ];
				}
			}
		}

		// 6. CSV fallback
		if ( empty( $items ) && preg_match( '/^[\d, ]+$/', $sortorder ) ) {
			$raw_ids = explode( ',', $sortorder );
			foreach ( $raw_ids as $rid ) {
				$rid = (int) trim( $rid );
				if ( $rid > 0 ) {
					$items[] = [ 'type' => 'gallery', 'id' => $rid ];
				}
			}
		}

		return $items;
	}

	// -----------------------------------------------------------------------

	// -----------------------------------------------------------------------
	// Images — cursor-based (by pid)
	// -----------------------------------------------------------------------

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_images_batch( int $after_id, int $batch_size ): array {
		global $wpdb;
		$t  = $wpdb->prefix . 'ngg_pictures';
		$tg = $wpdb->prefix . 'ngg_gallery'; // singular + JOIN por gid.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.pid, p.image_slug, p.galleryid, p.filename, p.description, p.alttext,
				        p.imagedate, p.sortorder, p.meta_data,
				        g.path AS gallery_path, g.name AS gallery_name
				 FROM {$t} p
				 LEFT JOIN {$tg} g ON g.gid = p.galleryid
				 WHERE p.pid > %d
				 ORDER BY p.pid ASC
				 LIMIT %d",
				$after_id,
				$batch_size
			),
			ARRAY_A
		);
		return $rows ?: [];
	}

	// -----------------------------------------------------------------------
	// Gallery path resolution
	// -----------------------------------------------------------------------

	/**
	 * Returns the resolved absolute path for a NGG gallery.
	 * NGG stores `path` relative to ABSPATH (e.g. "wp-content/gallery/my-gallery").
	 *
	 * @param  string $ngg_path Raw path from ngg_galleries.
	 * @return string Absolute path (may not exist).
	 */
	public static function resolve_gallery_abspath( string $ngg_path ): string {
		$ngg_path = trim( $ngg_path, '/' );
		// If already looks absolute, trust it.
		if ( path_is_absolute( $ngg_path ) ) {
			return $ngg_path;
		}
		return ABSPATH . $ngg_path;
	}

	/**
	 * Resolves the full path to the source image file.
	 *
	 * @param  array<string,mixed> $picture Row from ngg_pictures join ngg_galleries.
	 * @return string
	 */
	public static function resolve_image_abspath( array $picture ): string {
		$gallery_abs = self::resolve_gallery_abspath( (string) $picture['gallery_path'] );
		return rtrim( $gallery_abs, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $picture['filename'];
	}

	/**
	 * Resolves the thumbnail path.
	 * NGG stores thumbs inside a "thumbs" sub-folder of the gallery folder.
	 *
	 * @param  array<string,mixed> $picture Row from ngg_pictures join ngg_galleries.
	 * @return string
	 */
	public static function resolve_thumb_abspath( array $picture ): string {
		$gallery_abs = self::resolve_gallery_abspath( (string) $picture['gallery_path'] );
		$thumbs_dir  = rtrim( $gallery_abs, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'thumbs';
		
		// Tenta com prefixo thumbs_ (padrão antigo) e sem prefixo (algumas versões).
		$primary = $thumbs_dir . DIRECTORY_SEPARATOR . 'thumbs_' . $picture['filename'];
		if ( file_exists( $primary ) ) {
			return $primary;
		}
		
		return $thumbs_dir . DIRECTORY_SEPARATOR . $picture['filename'];
	}

	public static function resolve_thumbs_dir_abspath( string $gallery_path ): string {
		$gallery_abs = self::resolve_gallery_abspath( $gallery_path );
		return rtrim( $gallery_abs, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'thumbs';
	}

	// -----------------------------------------------------------------------
	// Preview / cover image resolution
	// -----------------------------------------------------------------------

	/**
	 * @param  int $pid  NGG picture ID.
	 * @return array<string,mixed>|null
	 */
	public static function get_picture_by_pid( int $pid ): ?array {
		global $wpdb;
		$t  = $wpdb->prefix . 'ngg_pictures';
		$tg = $wpdb->prefix . 'ngg_gallery'; // singular + JOIN por gid.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.*, g.path AS gallery_path, g.name AS gallery_name
				 FROM {$t} p
				 LEFT JOIN {$tg} g ON g.gid = p.galleryid
				 WHERE p.pid = %d",
				$pid
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	// -----------------------------------------------------------------------
	// Shortcode scanning — post-cursor-based
	// -----------------------------------------------------------------------

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_posts_with_shortcodes_batch( int $after_id, int $batch_size ): array {
		global $wpdb;
		$ngg_pattern = '%[ngg%';
		$album_pat   = '%[album%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_content, p.post_type, p.post_status
				 FROM {$wpdb->posts} p
				 WHERE p.ID > %d
				   AND p.post_status NOT IN ('trash','auto-draft')
				   AND (
				       p.post_content LIKE %s OR p.post_content LIKE %s
				       OR EXISTS (
				           SELECT 1 FROM {$wpdb->postmeta} pm 
				           WHERE pm.post_id = p.ID 
				             AND (pm.meta_value LIKE %s OR pm.meta_value LIKE %s)
				       )
				   )
				 ORDER BY p.ID ASC
				 LIMIT %d",
				$after_id,
				$ngg_pattern,
				$album_pat,
				$ngg_pattern,
				$album_pat,
				$batch_size
			),
			ARRAY_A
		);
		return $rows ?: [];
	}
}
