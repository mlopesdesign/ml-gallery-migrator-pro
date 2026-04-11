<?php
/**
 * Converts NextGEN Gallery shortcodes to ML Gallery Pro equivalents.
 *
 * NGG shortcodes handled:
 *   [nggallery id="N"]                        → [ml_gallery_pro gallery="MLGP_ID"]
 *   [ngg_images gallery_ids="N" ...]          → [ml_gallery_pro gallery="MLGP_ID"]
 *   [ngg id="N"]                              → [ml_gallery_pro gallery="MLGP_ID"]
 *   [ngg-album id="N"]                        → [ml_album album="MLGP_ALBUM_ID"]
 *   [album id="N"]                            → [ml_album album="MLGP_ALBUM_ID"]
 *   [nggalbum id="N"]                         → [ml_album album="MLGP_ALBUM_ID"]
 *   [nggtags tags="..."]                      → [ml_tag_gallery tag="..."]
 *
 * The converter reads the persisted id_map table built during migration.
 *
 * @package MLGalleryMigratorPro
 */

namespace MLGMP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ShortcodeConverter {

	/** @var array<int,int> NGG gallery_id → MLGP gallery_id */
	private array $gallery_map = [];

	/** @var array<int,int> NGG album_id → MLGP album_id */
	private array $album_map = [];

	/** @var string */
	private string $session_id;

	public function __construct( string $session_id ) {
		$this->session_id = $session_id;
		$this->load_maps();
	}

	// -----------------------------------------------------------------------
	// Load ID maps from DB
	// -----------------------------------------------------------------------

	private function load_maps(): void {
		global $wpdb;
		$t = Installer::tables()['id_map'];

		// Galleries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_id, dest_id FROM {$t}
				 WHERE session_id = %s AND entity_type = 'gallery' AND status = 'done'",
				$this->session_id
			),
			ARRAY_A
		);
		foreach ( $rows as $r ) {
			$this->gallery_map[ (int) $r['source_id'] ] = (int) $r['dest_id'];
		}

		// Albums.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_id, dest_id FROM {$t}
				 WHERE session_id = %s AND entity_type = 'album' AND status = 'done'",
				$this->session_id
			),
			ARRAY_A
		);
		foreach ( $rows as $r ) {
			$this->album_map[ (int) $r['source_id'] ] = (int) $r['dest_id'];
		}
	}

	// -----------------------------------------------------------------------
	// Main conversion entry point
	// -----------------------------------------------------------------------

	/**
	 * Converts all NGG shortcodes in a content string.
	 *
	 * @param  string $content Raw post_content.
	 * @return array{content: string, conversions: int, unmapped: int}
	 */
	public function convert( string $content ): array {
		$conversions = 0;
		$unmapped    = 0;
		$replacements = [];

		$track = function ( $old, $new, $attrs_parsed ) use ( &$replacements ) {
			$replacements[] = [ 'old' => $old, 'new' => $new, 'parsed_attrs' => $attrs_parsed ];
		};

		// ── Parsers Universais para Todos os Formatos de NGG ─────────────
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
						$val = html_entity_decode( $val, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
						$attrs[ $key ] = trim( $val, " \t\n\r\0\x0B\"'”" );
					}
				}

				$type       = null;
				$target_id  = 0;
				$target_tag = '';

				if ( $tag === 'nggallery' ) {
					$type      = 'gallery';
					$target_id = (int) ( $attrs['id'] ?? 0 );
				} elseif ( $tag === 'ngg_images' ) {
					$type = 'gallery';
					if ( isset( $attrs['gallery_ids'] ) ) {
						$target_id = (int) $attrs['gallery_ids'];
					} elseif ( isset( $attrs['container_ids'] ) ) {
						$target_id = (int) $attrs['container_ids'];
					} elseif ( isset( $attrs['ids'] ) ) {
						$target_id = (int) $attrs['ids'];
					}
				} elseif ( in_array( $tag, [ 'ngg-album', 'album', 'nggalbum' ], true ) ) {
					$type      = 'album';
					$target_id = (int) ( $attrs['id'] ?? 0 );
				} elseif ( $tag === 'nggtags' ) {
					$type       = 'tags';
					$target_tag = $attrs['tags'] ?? '';
				} elseif ( $tag === 'ngg' ) {
					// Modern style format
					$src    = strtolower( $attrs['src'] ?? $attrs['source'] ?? '' );
					$id_str = $attrs['ids'] ?? $attrs['container_ids'] ?? $attrs['gallery_ids'] ?? '';
					
					if ( empty( $id_str ) && isset( $attrs['id'] ) ) {
						$id_str = $attrs['id'];
					}
					
					if ( $src === 'albums' || $src === 'album' ) {
						$type = 'album';
					} elseif ( $src === 'tags' ) {
						$type       = 'tags';
						$target_tag = $id_str ?: ( $attrs['tags'] ?? '' );
					} else {
						$type = 'gallery';
					}
					$target_id = (int) $id_str;
				}

				// Fallback attempt
				if ( $target_id === 0 && isset( $attrs['id'] ) ) {
					$target_id = (int) $attrs['id'];
				}

				$attrs_log = sprintf( "tag=%s, type=%s, id=%d, tags='%s'", $tag, $type ?: 'null', $target_id, $target_tag );

				// Roteador de Conversão
				if ( $type === 'tags' && ! empty( $target_tag ) ) {
					$conversions++;
					$new_sc = '[ml_tag_gallery tag="' . esc_attr( trim( $target_tag ) ) . '"]';
					$track( $m[0], $new_sc, $attrs_log );
					return $new_sc;
				}

				if ( $type === 'gallery' && $target_id > 0 && isset( $this->gallery_map[ $target_id ] ) ) {
					$conversions++;
					$new_sc = '[ml_gallery_pro gallery="' . $this->gallery_map[ $target_id ] . '"]';
					$track( $m[0], $new_sc, $attrs_log );
					return $new_sc;
				}

				if ( $type === 'album' && $target_id > 0 && isset( $this->album_map[ $target_id ] ) ) {
					$conversions++;
					$new_sc = '[ml_album album="' . $this->album_map[ $target_id ] . '"]';
					$track( $m[0], $new_sc, $attrs_log );
					return $new_sc;
				}

				// Falha: não mapeado
				$unmapped++;
				$track( $m[0], null, "Falha (Não Mapeado): " . $attrs_log );
				return '<!-- mlgmp-unmapped: ' . $tag . ' (type=' . $type . ' target_id=' . $target_id . ') -->' . $m[0];
			},
			$content
		);

		return [
			'content'      => $content,
			'conversions'  => $conversions,
			'unmapped'     => $unmapped,
			'replacements' => $replacements,
		];
	}

	// -----------------------------------------------------------------------
	// Widget text conversion
	// -----------------------------------------------------------------------

	/** Convert shortcodes inside registered text widgets. */
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
				if ( strpos( $widget_id, 'text-' ) === 0 ) {
					$num        = (int) str_replace( 'text-', '', $widget_id );
					$key        = 'widget_text';
					$widgets    = get_option( $key, [] );
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

	// -----------------------------------------------------------------------
	// Accessors (for mapping test/info)
	// -----------------------------------------------------------------------

	public function get_gallery_map(): array {
		return $this->gallery_map;
	}

	public function get_album_map(): array {
		return $this->album_map;
	}
}
