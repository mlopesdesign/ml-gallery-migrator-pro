<?php
/**
 * Reads Photo Gallery (10Web) data from its native tables.
 *
 * @package MLGalleryMigratorPro
 */

namespace MLGMP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PhotoGalleryReader {

	public static function plugin_file(): string {
		return 'photo-gallery/photo-gallery.php';
	}

	public static function detect(): array {
		global $wpdb;

		$candidates = [
			$wpdb->prefix . 'bwg_gallery',
			$wpdb->prefix . 'bwg_album',
			$wpdb->prefix . 'bwg_album_gallery',
			$wpdb->prefix . 'bwg_image',
			$wpdb->prefix . 'bwg_shortcode',
		];

		$found   = [];
		$missing = [];
		foreach ( $candidates as $table ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $exists ) {
				$found[] = $table;
			} else {
				$missing[] = $table;
			}
		}

		$plugin_active = false;
		if ( function_exists( 'is_plugin_active' ) ) {
			$plugin_active = is_plugin_active( self::plugin_file() );
		} else {
			$active = (array) get_option( 'active_plugins', [] );
			$plugin_active = in_array( self::plugin_file(), $active, true );
		}

		return [
			'tables_found'   => $found,
			'tables_missing' => $missing,
			'plugin_active'  => $plugin_active,
			'db_name'        => DB_NAME,
		];
	}

	public static function active(): bool {
		$detect = self::detect();
		return ! empty( $detect['tables_found'] ) || $detect['plugin_active'];
	}

	public static function count_all(): array {
		global $wpdb;
		$detection = self::detect();
		$found     = $detection['tables_found'];

		$tg = $wpdb->prefix . 'bwg_gallery';
		$ta = $wpdb->prefix . 'bwg_album';
		$ti = $wpdb->prefix . 'bwg_image';

		$galleries  = in_array( $tg, $found, true ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tg}" ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$albums     = in_array( $ta, $found, true ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ta}" ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$images     = in_array( $ti, $found, true ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ti}" ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_status NOT IN ('trash','auto-draft')
				   AND post_content LIKE %s",
				'%[Best_Wordpress_Gallery%'
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $count;
	}

	public static function get_galleries_batch( int $after_id, int $batch_size ): array {
		global $wpdb;
		$t = $wpdb->prefix . 'bwg_gallery';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, slug, description, preview_image, random_preview_image
				 FROM {$t}
				 WHERE id > %d
				 ORDER BY id ASC
				 LIMIT %d",
				$after_id,
				$batch_size
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $rows ?: [];
	}

	public static function get_albums_batch( int $after_id, int $batch_size ): array {
		global $wpdb;
		$t = $wpdb->prefix . 'bwg_album';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, slug, description, preview_image, random_preview_image
				 FROM {$t}
				 WHERE id > %d
				 ORDER BY id ASC
				 LIMIT %d",
				$after_id,
				$batch_size
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $rows ?: [];
	}

	public static function get_album_items( int $album_id ): array {
		global $wpdb;
		$t = $wpdb->prefix . 'bwg_album_gallery';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT alb_gal_id, is_album, `order`
				 FROM {$t}
				 WHERE album_id = %d
				 ORDER BY `order` ASC, id ASC",
				$album_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$items = [];
		foreach ( $rows ?: [] as $row ) {
			$items[] = [
				'type' => ! empty( $row['is_album'] ) ? 'album' : 'gallery',
				'id'   => (int) $row['alb_gal_id'],
			];
		}
		return $items;
	}

	public static function get_images_batch( int $after_id, int $batch_size ): array {
		global $wpdb;
		$ti = $wpdb->prefix . 'bwg_image';
		$tg = $wpdb->prefix . 'bwg_gallery';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.id AS pid, i.gallery_id AS galleryid, i.filename, i.image_url, i.thumb_url,
				        i.description, i.alt AS alttext, i.`order` AS sortorder,
				        g.name AS gallery_name, g.slug AS gallery_slug
				 FROM {$ti} i
				 LEFT JOIN {$tg} g ON g.id = i.gallery_id
				 WHERE i.id > %d
				 ORDER BY i.id ASC
				 LIMIT %d",
				$after_id,
				$batch_size
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $rows ?: [];
	}

	public static function get_picture_by_pid( int $pid ): ?array {
		global $wpdb;
		$ti = $wpdb->prefix . 'bwg_image';
		$tg = $wpdb->prefix . 'bwg_gallery';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT i.id AS pid, i.gallery_id AS galleryid, i.filename, i.image_url, i.thumb_url,
				        i.description, i.alt AS alttext, i.`order` AS sortorder,
				        g.name AS gallery_name, g.slug AS gallery_slug
				 FROM {$ti} i
				 LEFT JOIN {$tg} g ON g.id = i.gallery_id
				 WHERE i.id = %d",
				$pid
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $row ?: null;
	}

	public static function find_preview_pid( string $preview_ref, int $container_id = 0, string $container_type = 'gallery' ): int {
		global $wpdb;
		$preview_ref = trim( (string) $preview_ref );
		if ( '' === $preview_ref ) {
			return 0;
		}
		$basename = wp_basename( wp_parse_url( $preview_ref, PHP_URL_PATH ) ?: $preview_ref );
		$ti       = $wpdb->prefix . 'bwg_image';
		$where    = '';
		$args     = [];
		if ( 'gallery' === $container_type && $container_id > 0 ) {
			$where = ' AND gallery_id = %d';
			$args[] = $container_id;
		}
		$query = "SELECT id FROM {$ti} WHERE (image_url = %s OR thumb_url = %s OR filename = %s) {$where} ORDER BY id ASC LIMIT 1";
		$args = array_merge( [ $preview_ref, $preview_ref, $basename ], $args );
		$pid = (int) $wpdb->get_var( $wpdb->prepare( $query, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $pid;
	}

	public static function resolve_image_abspath( array $picture ): string {
		return self::normalize_media_path( (string) ( $picture['image_url'] ?? '' ) );
	}

	public static function resolve_thumb_abspath( array $picture ): string {
		$thumb = self::normalize_media_path( (string) ( $picture['thumb_url'] ?? '' ) );
		if ( $thumb && file_exists( $thumb ) ) {
			return $thumb;
		}
		return '';
	}

	public static function resolve_thumbs_dir_abspath( array $picture ): string {
		$thumb = self::resolve_thumb_abspath( $picture );
		return $thumb ? dirname( $thumb ) : '';
	}

	public static function load_shortcode_map(): array {
		global $wpdb;
		$t = $wpdb->prefix . 'bwg_shortcode';
		$rows = $wpdb->get_results( "SELECT id, tagtext FROM {$t} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$map = [];
		foreach ( $rows ?: [] as $row ) {
			$map[ (int) $row['id'] ] = self::parse_shortcode_tagtext( (string) $row['tagtext'] );
		}
		return $map;
	}

	public static function parse_shortcode_tagtext( string $tagtext ): array {
		$attrs = [];
		if ( preg_match_all( '/([A-Z0-9_\-]+)\s*=\s*(?:([\'"])(.*?)\2|([^\s>\]]+))/i', $tagtext, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$key = strtolower( $m[1] );
				$val = '' !== ( $m[3] ?? '' ) ? $m[3] : ( $m[4] ?? '' );
				$attrs[ $key ] = trim( html_entity_decode( (string) $val, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}
		}

		$resolved = [
			'type'      => 'gallery',
			'target_id' => 0,
			'tag'       => '',
			'raw'       => $tagtext,
			'attrs'     => $attrs,
		];

		if ( ! empty( $attrs['album_id'] ) ) {
			$resolved['type']      = 'album';
			$resolved['target_id'] = (int) $attrs['album_id'];
			return $resolved;
		}
		if ( ! empty( $attrs['gallery_id'] ) ) {
			$resolved['type']      = 'gallery';
			$resolved['target_id'] = (int) $attrs['gallery_id'];
			return $resolved;
		}
		if ( ! empty( $attrs['tag'] ) ) {
			$resolved['type'] = 'tags';
			$resolved['tag']  = (string) $attrs['tag'];
			return $resolved;
		}
		if ( preg_match( '/album_id\s*=\s*[\'"]?(\d+)/i', $tagtext, $m ) ) {
			$resolved['type']      = 'album';
			$resolved['target_id'] = (int) $m[1];
		} elseif ( preg_match( '/gallery_id\s*=\s*[\'"]?(\d+)/i', $tagtext, $m ) ) {
			$resolved['type']      = 'gallery';
			$resolved['target_id'] = (int) $m[1];
		} elseif ( preg_match( '/tag\s*=\s*[\'"]([^\'"]+)/i', $tagtext, $m ) ) {
			$resolved['type'] = 'tags';
			$resolved['tag']  = trim( $m[1] );
		}

		return $resolved;
	}

	private static function normalize_media_path( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( path_is_absolute( $raw ) ) {
			return $raw;
		}
		$path = wp_parse_url( $raw, PHP_URL_PATH );
		if ( is_string( $path ) && '' !== $path ) {
			$raw = ltrim( $path, '/' );
		}
		if ( 0 === strpos( $raw, 'wp-content/' ) ) {
			return trailingslashit( ABSPATH ) . $raw;
		}
		if ( 0 === strpos( $raw, 'uploads/' ) ) {
			return trailingslashit( ABSPATH ) . $raw;
		}
		if ( 0 === strpos( $raw, '/wp-content/' ) ) {
			return ABSPATH . ltrim( $raw, '/' );
		}
		if ( 0 === strpos( $raw, '/uploads/' ) ) {
			return ABSPATH . ltrim( $raw, '/' );
		}
		$uploads = wp_get_upload_dir();
		$basedir = $uploads['basedir'] ?? '';
		if ( $basedir ) {
			return trailingslashit( $basedir ) . ltrim( $raw, '/' );
		}
		return trailingslashit( ABSPATH ) . ltrim( $raw, '/' );
	}
}
