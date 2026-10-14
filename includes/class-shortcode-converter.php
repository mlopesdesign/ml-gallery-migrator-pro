<?php
/**
 * Converts source shortcodes to ML Gallery Pro equivalents.
 *
 * @package MLGalleryMigratorPro
 */

namespace MLGMP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ShortcodeConverter {

	private array $gallery_map = [];
	private array $album_map = [];
	private string $session_id;
	private string $source;
	private array $photo_shortcode_map = [];

	public function __construct( string $session_id, string $source = 'ngg' ) {
		$this->session_id = $session_id;
		$this->source     = in_array( $source, [ 'ngg', 'photo_gallery' ], true ) ? $source : 'ngg';
		$this->load_maps();
	}

	private function load_maps(): void {
		global $wpdb;
		$t = Installer::tables()['id_map'];

		// IMPORTANT: shortcode conversion must remain resilient after reset/rerun.
		// The current session may not contain all mappings if galleries/albums were
		// migrated in a previous session. Therefore we load the most recent mapping
		// per source_id across ALL sessions, preferring the current session when present.
		$rows = $wpdb->get_results(
			"SELECT id, session_id, source_id, dest_id, entity_type
			 FROM {$t}
			 WHERE status = 'done'
			   AND entity_type IN ('gallery','album')
			 ORDER BY id DESC",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $rows as $r ) {
			$source_id = (int) $r['source_id'];
			$dest_id   = (int) $r['dest_id'];
			if ( $source_id <= 0 || $dest_id <= 0 ) {
				continue;
			}

			if ( 'gallery' === $r['entity_type'] ) {
				if ( ! isset( $this->gallery_map[ $source_id ] ) || $r['session_id'] === $this->session_id ) {
					$this->gallery_map[ $source_id ] = $dest_id;
				}
			} elseif ( 'album' === $r['entity_type'] ) {
				if ( ! isset( $this->album_map[ $source_id ] ) || $r['session_id'] === $this->session_id ) {
					$this->album_map[ $source_id ] = $dest_id;
				}
			}
		}

		if ( 'photo_gallery' === $this->source ) {
			$this->photo_shortcode_map = PhotoGalleryReader::load_shortcode_map();
		}
	}

	public function convert( string $content ): array {
		if ( 'photo_gallery' === $this->source ) {
			return $this->convert_photo_gallery( $content );
		}
		return $this->convert_ngg( $content );
	}

	private function convert_ngg( string $content ): array {
		$conversions = 0;
		$unmapped    = 0;
		$replacements = [];
		$track = function ( $old, $new, $attrs_parsed ) use ( &$replacements ) {
			$replacements[] = [ 'old' => $old, 'new' => $new, 'parsed_attrs' => $attrs_parsed ];
		};

		$content = preg_replace_callback(
			'/\[(ngg|ngg_images|nggallery|ngg-album|album|nggalbum|nggtags)(?:\s+([^\]]+))?\]/i',
			function ( $m ) use ( &$conversions, &$unmapped, $track ) {
				$tag       = strtolower( $m[1] );
				$attrs_str = isset( $m[2] ) ? $m[2] : '';
				$attrs = [];
				if ( preg_match_all( '/([A-Z0-9_\-]+)\s*=\s*(?:([\'"])(.*?)\2|([^\s>]+))/i', $attrs_str, $att_matches, PREG_SET_ORDER ) ) {
					foreach ( $att_matches as $att ) {
						$key = strtolower( $att[1] );
						$val = ! empty( $att[2] ) ? $att[3] : $att[4];
						$attrs[ $key ] = trim( html_entity_decode( $val, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), " \t\n\r\0\x0B\"'”" );
					}
				}
				$type       = null;
				$target_id  = 0;
				$target_tag = '';
				if ( 'nggallery' === $tag ) {
					$type = 'gallery';
					$target_id = (int) ( $attrs['id'] ?? 0 );
				} elseif ( 'ngg_images' === $tag ) {
					$type = 'gallery';
					if ( isset( $attrs['gallery_ids'] ) ) {
						$target_id = (int) $attrs['gallery_ids'];
					} elseif ( isset( $attrs['container_ids'] ) ) {
						$target_id = (int) $attrs['container_ids'];
					} elseif ( isset( $attrs['ids'] ) ) {
						$target_id = (int) $attrs['ids'];
					}
				} elseif ( in_array( $tag, [ 'ngg-album', 'album', 'nggalbum' ], true ) ) {
					$type = 'album';
					$target_id = (int) ( $attrs['id'] ?? 0 );
				} elseif ( 'nggtags' === $tag ) {
					$type = 'tags';
					$target_tag = $attrs['tags'] ?? '';
				} elseif ( 'ngg' === $tag ) {
					$src    = strtolower( $attrs['src'] ?? $attrs['source'] ?? '' );
					$id_str = $attrs['ids'] ?? $attrs['container_ids'] ?? $attrs['gallery_ids'] ?? '';
					if ( empty( $id_str ) && isset( $attrs['id'] ) ) {
						$id_str = $attrs['id'];
					}
					if ( 'albums' === $src || 'album' === $src ) {
						$type = 'album';
					} elseif ( 'tags' === $src ) {
						$type = 'tags';
						$target_tag = $id_str ?: ( $attrs['tags'] ?? '' );
					} else {
						$type = 'gallery';
					}
					$target_id = (int) $id_str;
				}
				if ( 0 === $target_id && isset( $attrs['id'] ) ) {
					$target_id = (int) $attrs['id'];
				}
				$attrs_log = sprintf( 'tag=%s, type=%s, id=%d, tags="%s"', $tag, $type ?: 'null', $target_id, $target_tag );
				if ( 'tags' === $type && '' !== $target_tag ) {
					$conversions++;
					$new_sc = '[ml_tag_gallery tag="' . esc_attr( trim( $target_tag ) ) . '"]';
					$track( $m[0], $new_sc, $attrs_log );
					return $new_sc;
				}
				if ( 'gallery' === $type && $target_id > 0 && isset( $this->gallery_map[ $target_id ] ) ) {
					$conversions++;
					$new_sc = '[ml_gallery_pro gallery="' . $this->gallery_map[ $target_id ] . '"]';
					$track( $m[0], $new_sc, $attrs_log );
					return $new_sc;
				}
				if ( 'album' === $type && $target_id > 0 && isset( $this->album_map[ $target_id ] ) ) {
					$conversions++;
					$new_sc = '[ml_album album="' . $this->album_map[ $target_id ] . '"]';
					$track( $m[0], $new_sc, $attrs_log );
					return $new_sc;
				}
				$unmapped++;
				$track( $m[0], null, 'Falha (Não Mapeado): ' . $attrs_log );
				return '<!-- mlgmp-unmapped: ' . $tag . ' (type=' . $type . ' target_id=' . $target_id . ') -->' . $m[0];
			},
			$content
		);

		return [ 'content' => $content, 'conversions' => $conversions, 'unmapped' => $unmapped, 'replacements' => $replacements ];
	}

	private function convert_photo_gallery( string $content ): array {
		$conversions = 0;
		$unmapped    = 0;
		$replacements = [];

		$content = preg_replace_callback(
			'/\[Best_Wordpress_Gallery(?:\s+([^\]]+))?\]/i',
			function ( $m ) use ( &$conversions, &$unmapped, &$replacements ) {
				$attrs_str = isset( $m[1] ) ? (string) $m[1] : '';
				$attrs = [];
				if ( preg_match_all( '/([A-Z0-9_\-]+)\s*=\s*(?:([\'"])(.*?)\2|([^\s>]+))/i', $attrs_str, $matches, PREG_SET_ORDER ) ) {
					foreach ( $matches as $att ) {
						$key = strtolower( $att[1] );
						$val = '' !== ( $att[3] ?? '' ) ? $att[3] : ( $att[4] ?? '' );
						$attrs[ $key ] = trim( html_entity_decode( (string) $val, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
					}
				}
				$shortcode_id = (int) ( $attrs['id'] ?? 0 );
				$definition = $this->photo_shortcode_map[ $shortcode_id ] ?? null;
				if ( ! $definition ) {
					$unmapped++;
					$replacements[] = [ 'old' => $m[0], 'new' => null, 'parsed_attrs' => 'shortcode_id=' . $shortcode_id . ' sem definição bwg_shortcode' ];
					return '<!-- mlgmp-unmapped: Best_Wordpress_Gallery shortcode_id=' . $shortcode_id . ' -->' . $m[0];
				}
				if ( 'tags' === $definition['type'] && ! empty( $definition['tag'] ) ) {
					$conversions++;
					$new_sc = '[ml_tag_gallery tag="' . esc_attr( trim( (string) $definition['tag'] ) ) . '"]';
					$replacements[] = [ 'old' => $m[0], 'new' => $new_sc, 'parsed_attrs' => $definition ];
					return $new_sc;
				}
				if ( 'gallery' === $definition['type'] ) {
					$target = (int) ( $definition['target_id'] ?? 0 );
					if ( $target > 0 && isset( $this->gallery_map[ $target ] ) ) {
						$conversions++;
						$new_sc = '[ml_gallery_pro gallery="' . $this->gallery_map[ $target ] . '"]';
						$replacements[] = [ 'old' => $m[0], 'new' => $new_sc, 'parsed_attrs' => $definition ];
						return $new_sc;
					}
				}
				if ( 'album' === $definition['type'] ) {
					$target = (int) ( $definition['target_id'] ?? 0 );
					if ( $target > 0 && isset( $this->album_map[ $target ] ) ) {
						$conversions++;
						$new_sc = '[ml_album album="' . $this->album_map[ $target ] . '"]';
						$replacements[] = [ 'old' => $m[0], 'new' => $new_sc, 'parsed_attrs' => $definition ];
						return $new_sc;
					}
				}
				$unmapped++;
				$replacements[] = [ 'old' => $m[0], 'new' => null, 'parsed_attrs' => $definition ];
				return '<!-- mlgmp-unmapped: Best_Wordpress_Gallery shortcode_id=' . $shortcode_id . ' type=' . esc_attr( (string) $definition['type'] ) . ' target_id=' . (int) ( $definition['target_id'] ?? 0 ) . ' -->' . $m[0];
			},
			$content
		);

		return [ 'content' => $content, 'conversions' => $conversions, 'unmapped' => $unmapped, 'replacements' => $replacements ];
	}

	public function convert_widgets(): array {
		$sidebars = get_option( 'sidebars_widgets', [] );
		$converted_widgets = 0;
		foreach ( $sidebars as $sidebar => $widget_ids ) {
			if ( ! is_array( $widget_ids ) ) {
				continue;
			}
			foreach ( $widget_ids as $widget_id ) {
				if ( ! is_string( $widget_id ) ) {
					continue;
				}
				if ( 0 === strpos( $widget_id, 'text-' ) ) {
					$num     = (int) str_replace( 'text-', '', $widget_id );
					$key     = 'widget_text';
					$widgets = get_option( $key, [] );
					if ( isset( $widgets[ $num ]['text'] ) ) {
						$result = $this->convert( $widgets[ $num ]['text'] );
						if ( $result['conversions'] > 0 ) {
							$widgets[ $num ]['text'] = $result['content'];
							update_option( $key, $widgets );
							$converted_widgets += $result['conversions'];
						}
					}
				}
			}
		}
		return [ 'converted_widgets' => $converted_widgets ];
	}

	public function get_gallery_map(): array {
		return $this->gallery_map;
	}

	public function get_album_map(): array {
		return $this->album_map;
	}
}
