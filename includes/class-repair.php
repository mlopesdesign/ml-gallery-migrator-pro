<?php
/**
 * Repair Engine v1.0.55
 *
 * Idempotent repair and audit engine.
 * Uses only real schema columns:
 *   id_map: session_id, entity_type, source_id, dest_id, status, note, created_at
 *   mlgp_album_items: album_id, item_type, item_id, sort_order, created_at, updated_at
 *   mlgp_gallery_items: id, gallery_id, file_path, file_name, source_pid, ...
 *   mlgp_galleries: id, slug, title, ...
 *   mlgp_albums: id, slug, title, ...
 *
 * @package MLGalleryMigratorPro
 */

namespace MLGMP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Repair {

	private static ?Repair $instance = null;

	private FileCopier $file_copier;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->file_copier = new FileCopier();
	}

	public function boot(): void {
		add_action( 'wp_ajax_mlgmp_repair_run',      [ $this, 'ajax_repair_run' ] );
		add_action( 'wp_ajax_mlgmp_repair_status',   [ $this, 'ajax_repair_status' ] );
		add_action( 'wp_ajax_mlgmp_audit_galleries', [ $this, 'ajax_audit_galleries' ] );
		add_action( 'wp_ajax_mlgmp_recover_gallery', [ $this, 'ajax_recover_gallery' ] );
		add_action( 'wp_ajax_mlgmp_audit_orphans',   [ $this, 'ajax_audit_orphans' ] );
		add_action( 'wp_ajax_mlgmp_recover_orphan',  [ $this, 'ajax_recover_orphan' ] );
	}

	// -----------------------------------------------------------------------
	// Internal helpers
	// -----------------------------------------------------------------------

	private function current_session_id(): string {
		$state = State::get();
		return (string) ( $state['session_id'] ?? '' );
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s LIMIT 1',
				DB_NAME,
				$table
			)
		);
	}

	private function dest_gallery_exists( int $dest_id ): bool {
		global $wpdb;
		$tg = $wpdb->prefix . 'mlgp_galleries';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tg} WHERE id = %d LIMIT 1", $dest_id ) );
	}

	private function dest_album_exists( int $dest_id ): bool {
		global $wpdb;
		$ta = $wpdb->prefix . 'mlgp_albums';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$ta} WHERE id = %d LIMIT 1", $dest_id ) );
	}

	private function get_dest_gallery_slug( int $dest_id ): string {
		global $wpdb;
		$tg = $wpdb->prefix . 'mlgp_galleries';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) ( $wpdb->get_var( $wpdb->prepare( "SELECT slug FROM {$tg} WHERE id = %d LIMIT 1", $dest_id ) ) ?? '' );
	}

	/**
	 * Count gallery_items for a destination gallery.
	 */
	private function dest_gallery_item_count( int $gallery_id ): int {
		global $wpdb;
		$ti = $wpdb->prefix . 'mlgp_gallery_items';
		if ( ! $this->table_exists( $ti ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$ti} WHERE gallery_id = %d",
			$gallery_id
		) );
	}

	/**
	 * Count source images for a NGG gallery ID.
	 */
	private function ngg_gallery_image_count( int $src_gallery_id ): int {
		global $wpdb;
		$pp = $wpdb->prefix . 'ngg_pictures';
		if ( ! $this->table_exists( $pp ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$pp} WHERE galleryid = %d",
			$src_gallery_id
		) );
	}

	// -----------------------------------------------------------------------
	// Logical image helpers — WebP preference + basename dedup
	// -----------------------------------------------------------------------

	/**
	 * Collapse a list of filenames into logical image families.
	 * Rule: if both photo.webp and photo.jpg (or .jpeg/.png) exist, keep only photo.webp.
	 * Returns array of preferred filenames (one per logical family).
	 *
	 * @param string[] $filenames
	 * @return string[]
	 */
	private function resolve_logical_images( array $filenames ): array {
		$families = []; // basename (no ext) => preferred filename.
		$raster   = [ 'jpg', 'jpeg', 'png', 'gif', 'bmp' ];

		foreach ( $filenames as $fn ) {
			$ext      = strtolower( pathinfo( $fn, PATHINFO_EXTENSION ) );
			$basename = strtolower( pathinfo( $fn, PATHINFO_FILENAME ) );

			if ( ! isset( $families[ $basename ] ) ) {
				$families[ $basename ] = $fn;
			} elseif ( 'webp' === $ext ) {
				// WebP wins over anything else.
				$families[ $basename ] = $fn;
			} elseif ( 'webp' !== strtolower( pathinfo( $families[ $basename ], PATHINFO_EXTENSION ) ) ) {
				// Existing entry is also raster — use alphabetically lower for stability.
				if ( strcmp( $fn, $families[ $basename ] ) < 0 ) {
					$families[ $basename ] = $fn;
				}
			}
			// else: existing is already webp — keep it.
		}

		return array_values( $families );
	}

	/**
	 * Build a logical image list from a filesystem directory.
	 * Each entry is the preferred filename for its logical family.
	 * Returns array of strings.
	 *
	 * @param string $abs_dir Absolute path to gallery directory.
	 * @return string[]
	 */
	private function list_logical_images_from_fs( string $abs_dir ): array {
		if ( ! is_dir( $abs_dir ) ) {
			return [];
		}
		$image_exts = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp' ];
		$all_files  = [];
		$files      = @scandir( $abs_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $files ) {
			foreach ( $files as $f ) {
				if ( '.' === $f || '..' === $f ) continue;
				if ( in_array( strtolower( pathinfo( $f, PATHINFO_EXTENSION ) ), $image_exts, true ) ) {
					$all_files[] = $f;
				}
			}
		}
		return $this->resolve_logical_images( $all_files );
	}

	/**
	 * Count LOGICAL (deduplicated, WebP-preferred) destination gallery_items.
	 * Collapses basename variants so photo.webp and photo.jpg count as ONE.
	 *
	 * @param int $gallery_id Destination gallery ID.
	 * @return int
	 */
	private function dest_gallery_logical_item_count( int $gallery_id ): int {
		global $wpdb;
		$ti = $wpdb->prefix . 'mlgp_gallery_items';
		if ( ! $this->table_exists( $ti ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$filenames = $wpdb->get_col( $wpdb->prepare(
			"SELECT file_name FROM {$ti} WHERE gallery_id = %d",
			$gallery_id
		) );
		if ( empty( $filenames ) ) {
			return 0;
		}
		$logical = $this->resolve_logical_images( array_map( 'strval', $filenames ) );
		return count( $logical );
	}

	/**
	 * Check if a logical equivalent already exists in destination gallery_items.
	 * Matches by: source_pid, exact file_path, exact file_name, OR basename (any ext).
	 *
	 * @param int    $gallery_id
	 * @param int    $source_pid
	 * @param string $file_path  Absolute dest path.
	 * @param string $file_name  Filename (may include extension).
	 * @return int Existing item ID, 0 if not found.
	 */
	private function find_dest_gallery_item_logical( int $gallery_id, int $source_pid, string $file_path, string $file_name ): int {
		global $wpdb;
		$ti      = $wpdb->prefix . 'mlgp_gallery_items';
		$columns = $this->get_gallery_item_columns();

		// Priority 1: source_pid.
		if ( $source_pid > 0 && isset( $columns['source_pid'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$ti} WHERE gallery_id = %d AND source_pid = %d LIMIT 1",
				$gallery_id, $source_pid
			) );
			if ( $id > 0 ) return $id;
		}

		// Priority 2: exact file_path.
		if ( $file_path && isset( $columns['file_path'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$ti} WHERE gallery_id = %d AND file_path = %s LIMIT 1",
				$gallery_id, $file_path
			) );
			if ( $id > 0 ) return $id;
		}

		// Priority 3: exact file_name.
		if ( $file_name && isset( $columns['file_name'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$ti} WHERE gallery_id = %d AND file_name = %s LIMIT 1",
				$gallery_id, $file_name
			) );
			if ( $id > 0 ) return $id;
		}

		// Priority 4: basename match (any extension) — catches webp/jpg family dedup.
		$basename = pathinfo( $file_name, PATHINFO_FILENAME );
		if ( $basename && isset( $columns['file_name'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$ti}
				 WHERE gallery_id = %d
				   AND (
				     file_name LIKE %s
				     OR file_name LIKE %s
				     OR file_name LIKE %s
				     OR file_name LIKE %s
				     OR file_name LIKE %s
				   )
				 LIMIT 1",
				$gallery_id,
				$wpdb->esc_like( $basename ) . '.jpg',
				$wpdb->esc_like( $basename ) . '.jpeg',
				$wpdb->esc_like( $basename ) . '.png',
				$wpdb->esc_like( $basename ) . '.webp',
				$wpdb->esc_like( $basename ) . '.gif'
			) );
			if ( $id > 0 ) return $id;
		}

		return 0;
	}

	// -----------------------------------------------------------------------
	// repair_album_links
	// -----------------------------------------------------------------------

	/**
	 * Rebuilds real album→gallery/subalbum relationships in mlgp_album_items.
	 *
	 * Strategy:
	 *  1. Load ALL source→dest mappings (gallery + album) across ALL sessions.
	 *     This handles post-reset reruns where mappings are in a different session.
	 *  2. For each mapped album, read the source sortorder (NGG) or equivalent.
	 *  3. Compare against existing mlgp_album_items rows.
	 *  4. Insert only missing links. Skip existing ones (idempotent).
	 *  5. Report real numbers: valid, repaired, links_created, links_skipped, broken.
	 *
	 * Uses only real id_map columns: session_id, entity_type, source_id, dest_id, status, note.
	 * Uses only real mlgp_album_items columns: album_id, item_type, item_id, sort_order, created_at, updated_at.
	 *
	 * @param string $source 'ngg' or 'photo_gallery'
	 * @return array<string,mixed>
	 */
	public function repair_album_links( string $source = 'ngg' ): array {
		global $wpdb;

		$logger = new Logger( 'repair_engine', 'album_links' );

		$result = [
			'status'         => 'success',
			'checked'        => 0,
			'albums_valid'   => 0,
			'albums_repaired'=> 0,
			'links_created'  => 0,
			'links_skipped'  => 0,
			'remaining_broken'=> 0,
			'errors'         => [],
			'message'        => __( 'Reconstrução de vínculos de álbuns concluída.', 'ml-gallery-migrator-pro' ),
		];

		try {
			$id_map = Installer::tables()['id_map'];
			$tai    = $wpdb->prefix . 'mlgp_album_items';

			if ( ! $this->table_exists( $tai ) ) {
				$result['message'] = __( 'Tabela mlgp_album_items não encontrada.', 'ml-gallery-migrator-pro' );
				return $result;
			}

			// ── Step 1: Build cross-session source→dest maps ──────────────
			// Search ALL sessions so post-reset reruns still find previously migrated objects.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$all_gallery_maps = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT source_id, dest_id FROM {$id_map}
					 WHERE entity_type = 'gallery' AND status = 'done' AND dest_id > 0
					 ORDER BY id DESC",
				),
				ARRAY_A
			);
			$gal_src_to_dest = [];
			foreach ( $all_gallery_maps as $row ) {
				$sid = (int) $row['source_id'];
				$did = (int) $row['dest_id'];
				// Keep the most recent dest_id per source_id (ORDER BY id DESC).
				if ( ! isset( $gal_src_to_dest[ $sid ] ) ) {
					$gal_src_to_dest[ $sid ] = $did;
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$all_album_maps = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT source_id, dest_id FROM {$id_map}
					 WHERE entity_type = 'album' AND status = 'done' AND dest_id > 0
					 ORDER BY id DESC",
				),
				ARRAY_A
			);
			$alb_src_to_dest = [];
			foreach ( $all_album_maps as $row ) {
				$sid = (int) $row['source_id'];
				$did = (int) $row['dest_id'];
				if ( ! isset( $alb_src_to_dest[ $sid ] ) ) {
					$alb_src_to_dest[ $sid ] = $did;
				}
			}

			if ( empty( $alb_src_to_dest ) ) {
				$result['message'] = __( 'Nenhum álbum mapeado encontrado em nenhuma sessão.', 'ml-gallery-migrator-pro' );
				return $result;
			}

			// ── Step 2: Determine source album table ──────────────────────
			$ngg_album_tbl  = $wpdb->prefix . 'ngg_album';
			$has_ngg_albums = ( 'ngg' === $source ) && $this->table_exists( $ngg_album_tbl );

			$now = current_time( 'mysql' );

			// ── Step 3: For each mapped album, inspect + repair ──────────
			foreach ( $alb_src_to_dest as $src_album_id => $mlgp_album_id ) {
				$result['checked']++;

				// Verify destination album still exists.
				if ( ! $this->dest_album_exists( $mlgp_album_id ) ) {
					$result['remaining_broken']++;
					$logger->warning( sprintf(
						__( 'Álbum src=%d → dest=%d não existe em mlgp_albums.', 'ml-gallery-migrator-pro' ),
						$src_album_id, $mlgp_album_id
					) );
					continue;
				}

				// Get existing album_items for this destination album (both gallery + album types).
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$existing_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT item_type, item_id FROM {$tai} WHERE album_id = %d",
					$mlgp_album_id
				), ARRAY_A );

				// Build set keyed by "type:item_id" for O(1) dedup.
				$existing_set = [];
				foreach ( $existing_rows as $er ) {
					$existing_set[ $er['item_type'] . ':' . (int) $er['item_id'] ] = true;
				}

				// Get current max sort_order for this album.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$sort = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COALESCE(MAX(sort_order), -1) FROM {$tai} WHERE album_id = %d",
					$mlgp_album_id
				) );

				// ── Get source items for this album ───────────────────────
				$src_items = []; // array of ['type' => 'gallery'|'album', 'id' => int]

				if ( $has_ngg_albums ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$sortorder = $wpdb->get_var( $wpdb->prepare(
						"SELECT sortorder FROM {$ngg_album_tbl} WHERE id = %d LIMIT 1",
						$src_album_id
					) );
					if ( $sortorder ) {
						$src_items = NGGReader::parse_album_sortorder( (string) $sortorder );
					}
				} elseif ( 'photo_gallery' === $source ) {
					$reader = PhotoGalleryReader::instance();
					if ( method_exists( $reader, 'get_album_items' ) ) {
						$src_items = $reader->get_album_items( $src_album_id );
					} elseif ( method_exists( $reader, 'get_album_gallery_ids' ) ) {
						foreach ( $reader->get_album_gallery_ids( $src_album_id ) as $gid ) {
							$src_items[] = [ 'type' => 'gallery', 'id' => (int) $gid ];
						}
					}
				}

				// ── Compare + insert missing links ────────────────────────
				$album_links_inserted = 0;
				$album_links_skipped  = 0;
				$album_unmapped       = 0;

				foreach ( $src_items as $item ) {
					$item_type   = (string) ( $item['type'] ?? 'gallery' );
					$src_item_id = (int) ( $item['id'] ?? 0 );

					if ( ! $src_item_id ) {
						continue;
					}

					// Resolve dest_id using cross-session map.
					$dest_item_id = 0;
					if ( 'gallery' === $item_type ) {
						$dest_item_id = $gal_src_to_dest[ $src_item_id ] ?? 0;
					} elseif ( 'album' === $item_type ) {
						$dest_item_id = $alb_src_to_dest[ $src_item_id ] ?? 0;
					}

					if ( ! $dest_item_id ) {
						$album_unmapped++;
						$logger->warning( sprintf(
							__( 'Item não mapeado: %s src=%d (álbum dest=%d)', 'ml-gallery-migrator-pro' ),
							$item_type, $src_item_id, $mlgp_album_id
						) );
						continue;
					}

					$key = $item_type . ':' . $dest_item_id;

					if ( isset( $existing_set[ $key ] ) ) {
						$album_links_skipped++;
						continue; // Already linked — idempotent.
					}

					// Insert the missing link.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$inserted = $wpdb->insert(
						$tai,
						[
							'album_id'   => $mlgp_album_id,
							'item_type'  => $item_type,
							'item_id'    => $dest_item_id,
							'sort_order' => ++$sort,
							'created_at' => $now,
							'updated_at' => $now,
						],
						[ '%d', '%s', '%d', '%d', '%s', '%s' ]
					);

					if ( $inserted ) {
						$existing_set[ $key ] = true;
						$album_links_inserted++;
						$logger->info( sprintf(
							__( 'Link inserido: álbum dest=%d → %s dest=%d (src=%d)', 'ml-gallery-migrator-pro' ),
							$mlgp_album_id, $item_type, $dest_item_id, $src_item_id
						) );
					} else {
						$logger->error( sprintf( 'DB error inserting album_item: album=%d %s=%d', $mlgp_album_id, $item_type, $dest_item_id ) );
					}
				}

				// ── Determine per-album status ────────────────────────────
				$result['links_created'] += $album_links_inserted;
				$result['links_skipped'] += $album_links_skipped;

				if ( $album_links_inserted > 0 ) {
					$result['albums_repaired']++;
					$logger->info( sprintf(
						__( 'Álbum dest=%d reparado: %d links inseridos, %d ignorados, %d itens sem mapeamento.', 'ml-gallery-migrator-pro' ),
						$mlgp_album_id, $album_links_inserted, $album_links_skipped, $album_unmapped
					) );
				} elseif ( empty( $src_items ) ) {
					// No source items found — sortorder empty or not readable.
					$logger->info( sprintf(
						__( 'Álbum dest=%d: sortorder vazio ou não processável.', 'ml-gallery-migrator-pro' ),
						$mlgp_album_id
					) );
					$result['albums_valid']++;
				} else {
					$result['albums_valid']++;
					$logger->info( sprintf(
						__( 'Álbum dest=%d já correto: %d links existentes, %d ignorados.', 'ml-gallery-migrator-pro' ),
						$mlgp_album_id, count( $existing_rows ), $album_links_skipped
					) );
				}
			}

			// Final message.
			$result['message'] = sprintf(
				__( 'Reparação concluída: %d álbuns verificados, %d reparados, %d links criados, %d ignorados, %d ainda quebrados.', 'ml-gallery-migrator-pro' ),
				$result['checked'],
				$result['albums_repaired'],
				$result['links_created'],
				$result['links_skipped'],
				$result['remaining_broken']
			);

		} catch ( \Exception $e ) {
			$result['status']   = 'error';
			$result['errors'][] = $e->getMessage();
			$logger->error( 'Erro ao reconstruir vínculos de álbuns: ' . $e->getMessage() );
		}

		return $result;
	}
	// -----------------------------------------------------------------------
	// repair_missing_images — safe refill of empty/incomplete galleries
	// -----------------------------------------------------------------------

	/**
	 * Gallery column cache for repair_insert_gallery_item().
	 *
	 * @var array<string,true>|null
	 */
	private ?array $repair_gi_columns = null;

	/**
	 * Returns the real columns of mlgp_gallery_items (cached).
	 *
	 * @return array<string,true>
	 */
	private function get_gallery_item_columns(): array {
		global $wpdb;
		if ( null !== $this->repair_gi_columns ) {
			return $this->repair_gi_columns;
		}
		$ti      = $wpdb->prefix . 'mlgp_gallery_items';
		$this->repair_gi_columns = [];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( 'SHOW COLUMNS FROM ' . $ti, ARRAY_A );
		foreach ( (array) $results as $row ) {
			if ( ! empty( $row['Field'] ) ) {
				$this->repair_gi_columns[ (string) $row['Field'] ] = true;
			}
		}
		return $this->repair_gi_columns;
	}

	/**
	 * Find existing mlgp_gallery_items row by real identity fields.
	 * Never creates duplicates.
	 *
	 * @param int    $gallery_id  Destination gallery ID.
	 * @param int    $source_pid  NGG picture PID (0 if unknown).
	 * @param string $file_path   Absolute dest file path.
	 * @param string $file_name   Filename only.
	 * @return int  Existing item ID, 0 if not found.
	 */
	private function find_dest_gallery_item( int $gallery_id, int $source_pid, string $file_path, string $file_name ): int {
		global $wpdb;
		$ti      = $wpdb->prefix . 'mlgp_gallery_items';
		$columns = $this->get_gallery_item_columns();

		// Priority 1: source_pid (most reliable).
		if ( $source_pid > 0 && isset( $columns['source_pid'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$ti} WHERE gallery_id = %d AND source_pid = %d LIMIT 1",
				$gallery_id, $source_pid
			) );
			if ( $id > 0 ) {
				return $id;
			}
		}

		// Priority 2: file_path.
		if ( $file_path && isset( $columns['file_path'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$ti} WHERE gallery_id = %d AND file_path = %s LIMIT 1",
				$gallery_id, $file_path
			) );
			if ( $id > 0 ) {
				return $id;
			}
		}

		// Priority 3: file_name.
		if ( $file_name && isset( $columns['file_name'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$ti} WHERE gallery_id = %d AND file_name = %s LIMIT 1",
				$gallery_id, $file_name
			) );
			if ( $id > 0 ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * Insert a mlgp_gallery_items record after file copy.
	 * Checks for duplicates first — never inserts twice.
	 *
	 * @param array<string,mixed> $data  Must contain: gallery_id, source_pid, file_path, file_url, thumb_path, thumb_url, filename, sort_order.
	 * @return array{id:int,status:string}  status: 'inserted'|'skipped'|'error'.
	 */
	private function repair_insert_gallery_item( array $data ): array {
		global $wpdb;
		$ti      = $wpdb->prefix . 'mlgp_gallery_items';
		$columns = $this->get_gallery_item_columns();
		$now     = current_time( 'mysql' );

		$gallery_id = (int) ( $data['gallery_id'] ?? 0 );
		$source_pid = (int) ( $data['source_pid'] ?? 0 );
		$file_path  = (string) ( $data['file_path']  ?? '' );
		$file_url   = (string) ( $data['file_url']   ?? '' );
		$thumb_path = (string) ( $data['thumb_path'] ?? '' );
		$thumb_url  = (string) ( $data['thumb_url']  ?? '' );
		$filename   = sanitize_file_name( (string) ( $data['filename'] ?? '' ) );
		$sort_order = (int) ( $data['sort_order'] ?? 0 );
		$mime_type  = (string) ( $data['mime_type'] ?? 'image/jpeg' );
		$file_size  = ( $file_path && file_exists( $file_path ) ) ? (int) filesize( $file_path ) : 0;

		// Image dimensions.
		$width  = 0;
		$height = 0;
		if ( $file_path && file_exists( $file_path ) ) {
			$dims = @getimagesize( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $dims ) {
				$width  = (int) $dims[0];
				$height = (int) $dims[1];
				if ( ! $mime_type || 'image/jpeg' === $mime_type ) {
					$mime_type = $dims['mime'] ?? $mime_type;
				}
			}
		}

		// Dedup check.
		$existing_id = $this->find_dest_gallery_item( $gallery_id, $source_pid, $file_path, $filename );
		if ( $existing_id > 0 ) {
			return [ 'id' => $existing_id, 'status' => 'skipped' ];
		}

		// Build insert array using only columns that actually exist.
		$candidates = [
			'gallery_id'    => [ $gallery_id,  '%d' ],
			'source_pid'    => [ $source_pid,   '%d' ],
			'attachment_id' => [ 0,              '%d' ],
			'storage'       => [ 'local',        '%s' ],
			'file_path'     => [ $file_path,     '%s' ],
			'file_url'      => [ $file_url,      '%s' ],
			'thumb_path'    => [ $thumb_path,    '%s' ],
			'thumb_url'     => [ $thumb_url,     '%s' ],
			'medium_path'   => [ '',             '%s' ],
			'medium_url'    => [ '',             '%s' ],
			'large_path'    => [ '',             '%s' ],
			'large_url'     => [ '',             '%s' ],
			'file_name'     => [ $filename,      '%s' ],
			'original_name' => [ $filename,      '%s' ],
			'mime_type'     => [ $mime_type,     '%s' ],
			'width'         => [ $width,         '%d' ],
			'height'        => [ $height,        '%d' ],
			'file_size'     => [ $file_size,     '%d' ],
			'item_title'    => [ (string) ( $data['title']   ?? '' ), '%s' ],
			'item_caption'  => [ (string) ( $data['caption'] ?? '' ), '%s' ],
			'item_alt'      => [ (string) ( $data['alt']     ?? '' ), '%s' ],
			'item_link'     => [ '',             '%s' ],
			'item_tags'     => [ '',             '%s' ],
			'sort_order'    => [ $sort_order,    '%d' ],
			'is_visible'    => [ 1,              '%d' ],
			'created_at'    => [ $now,           '%s' ],
			'updated_at'    => [ $now,           '%s' ],
		];

		$fields  = [];
		$formats = [];
		foreach ( $candidates as $col => $payload ) {
			if ( isset( $columns[ $col ] ) ) {
				$fields[ $col ] = $payload[0];
				$formats[]      = $payload[1];
			}
		}

		if ( empty( $fields ) ) {
			return [ 'id' => 0, 'status' => 'error' ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert( $ti, $fields, $formats );
		if ( false === $ok ) {
			return [ 'id' => 0, 'status' => 'error' ];
		}
		return [ 'id' => (int) $wpdb->insert_id, 'status' => 'inserted' ];
	}

	/**
	 * Repairs missing and incomplete gallery images.
	 *
	 * For each source→dest gallery mapping:
	 *   1. Count source images vs destination gallery_items.
	 *   2. If destination is empty or has fewer items than source: refill.
	 *   3. For each source image:
	 *      - Copy file if missing (idempotent).
	 *      - Insert mlgp_gallery_items DB record if missing (idempotent).
	 *      - Skip if already complete.
	 *   4. Report real numbers — never fake success.
	 *
	 * Idempotent: safe to rerun multiple times.
	 *
	 * @param string $source 'ngg' or 'photo_gallery'
	 * @return array<string,mixed>
	 */
	public function repair_missing_images( string $source = 'ngg', array $scope = [] ): array {
		global $wpdb;

		// Scope params: type (global|gallery|album|filtered), filter (empty_only|incomplete_only|all),
		// dest_gallery_id (int), src_gallery_id (int), slug (string).
		$scope_type     = (string) ( $scope['type']            ?? 'global' );
		$scope_filter   = (string) ( $scope['filter']          ?? 'all' );
		$scope_dest_gal = (int)    ( $scope['dest_gallery_id'] ?? 0 );
		$scope_src_gal  = (int)    ( $scope['src_gallery_id']  ?? 0 );
		$scope_slug     = (string) ( $scope['slug']            ?? '' );

		$logger = new Logger( 'repair_engine', 'missing_images' );

		$result = [
			'status'               => 'success',
			'scope_type'           => $scope_type,
			'scope_filter'         => $scope_filter,
			'galleries_inspected'  => 0,
			'galleries_complete'   => 0,
			'galleries_empty'      => 0,
			'galleries_incomplete' => 0,
			'galleries_refilled'   => 0,
			'images_inspected'     => 0,
			'images_inserted'      => 0,
			'images_skipped'       => 0,
			'images_still_missing' => 0,
			'errors'               => [],
			'message'              => __( 'Verificação de imagens concluída.', 'ml-gallery-migrator-pro' ),
		];

		try {
			$ti = $wpdb->prefix . 'mlgp_gallery_items';

			if ( ! $this->table_exists( $ti ) ) {
				$result['message'] = __( 'Tabela de destino não encontrada.', 'ml-gallery-migrator-pro' );
				return $result;
			}

			// Only NGG fully supported for now (Photo Gallery reader may differ).
			if ( 'ngg' !== $source ) {
				$result['message'] = __( 'Repreenchimento automático disponível apenas para NGG nesta versão.', 'ml-gallery-migrator-pro' );
				return $result;
			}

			$id_map = Installer::tables()['id_map'];
			$pp     = $wpdb->prefix . 'ngg_pictures';

			if ( ! $this->table_exists( $pp ) ) {
				$result['message'] = __( 'Tabela ngg_pictures não encontrada.', 'ml-gallery-migrator-pro' );
				return $result;
			}

			// ── Cross-session gallery map ─────────────────────────────────
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$gallery_maps = $wpdb->get_results(
				"SELECT source_id, dest_id FROM {$id_map}
				 WHERE entity_type = 'gallery' AND status = 'done' AND dest_id > 0
				 ORDER BY id DESC",
				ARRAY_A
			);

			// Deduplicate: keep most recent dest_id per source_id.
			$src_to_dest = [];
			foreach ( $gallery_maps as $row ) {
				$sid = (int) $row['source_id'];
				$did = (int) $row['dest_id'];
				if ( ! isset( $src_to_dest[ $sid ] ) ) {
					$src_to_dest[ $sid ] = $did;
				}
			}

			if ( empty( $src_to_dest ) ) {
				$result['message'] = __( 'Nenhuma galeria mapeada encontrada.', 'ml-gallery-migrator-pro' );
				return $result;
			}

			// ── Apply scope filter before iterating ──────────────────────
			if ( 'gallery' === $scope_type ) {
				// Narrow to a single gallery by dest_id, src_id, or slug.
				$narrowed = [];
				foreach ( $src_to_dest as $sid => $did ) {
					if ( $scope_dest_gal > 0 && $did !== $scope_dest_gal ) continue;
					if ( $scope_src_gal  > 0 && $sid !== $scope_src_gal  ) continue;
					if ( $scope_slug !== '' && $this->get_dest_gallery_slug( $did ) !== $scope_slug ) continue;
					$narrowed[ $sid ] = $did;
				}
				$src_to_dest = $narrowed;
			} elseif ( 'filtered' === $scope_type ) {
				// Keep only galleries matching the chosen filter.
				$narrowed = [];
				foreach ( $src_to_dest as $sid => $did ) {
					$d_cnt = $this->dest_gallery_item_count( $did );
					$s_cnt = $this->ngg_gallery_image_count( $sid );
					if ( $s_cnt === 0 ) continue; // Source empty — skip.
					if ( 'empty_only'      === $scope_filter && $d_cnt !== 0 ) continue;
					if ( 'incomplete_only' === $scope_filter && $d_cnt === 0 ) continue; // Incomplete = has items but fewer.
					if ( 'incomplete_only' === $scope_filter && $d_cnt >= $s_cnt ) continue;
					// 'all' or unrecognized filter: keep if empty OR incomplete.
					if ( 'all' === $scope_filter && $d_cnt >= $s_cnt ) continue;
					$narrowed[ $sid ] = $did;
				}
				$src_to_dest = $narrowed;
			}

			if ( empty( $src_to_dest ) && 'global' !== $scope_type ) {
				$result['message'] = __( 'Nenhuma galeria corresponde ao escopo selecionado.', 'ml-gallery-migrator-pro' );
				return $result;
			}

			foreach ( $src_to_dest as $src_gal_id => $dest_gal_id ) {
				$result['galleries_inspected']++;

				// Verify destination gallery still exists.
				if ( ! $this->dest_gallery_exists( $dest_gal_id ) ) {
					$result['images_still_missing']++;
					continue;
				}

				$gallery_slug = $this->get_dest_gallery_slug( $dest_gal_id );
				if ( ! $gallery_slug ) {
					continue;
				}

				// Count source images for this gallery.
				$src_count = $this->ngg_gallery_image_count( $src_gal_id );
				if ( $src_count === 0 ) {
					$result['galleries_complete']++;
					continue; // Source is genuinely empty.
				}

				// Count destination items.
				$dest_count = $this->dest_gallery_item_count( $dest_gal_id );

				if ( $dest_count === $src_count ) {
					$result['galleries_complete']++;
					continue; // Already complete.
				}

				if ( $dest_count === 0 ) {
					$result['galleries_empty']++;
				} else {
					$result['galleries_incomplete']++;
				}

				$logger->info( sprintf(
					__( 'Galeria dest=%d (%s): destino tem %d/%d itens — repreenchendo.', 'ml-gallery-migrator-pro' ),
					$dest_gal_id, $gallery_slug, $dest_count, $src_count
				) );

				// ── Load all source images for this gallery ───────────────
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$tg      = $wpdb->prefix . 'ngg_gallery';
				$src_images = $wpdb->get_results( $wpdb->prepare(
					"SELECT p.pid, p.image_slug, p.galleryid, p.filename, p.description,
					        p.alttext, p.sortorder, p.meta_data,
					        g.path AS gallery_path, g.name AS gallery_name
					 FROM {$pp} p
					 LEFT JOIN {$tg} g ON g.gid = p.galleryid
					 WHERE p.galleryid = %d
					 ORDER BY p.sortorder ASC, p.pid ASC",
					$src_gal_id
				), ARRAY_A );

				// ── Get current max sort_order in destination ─────────────
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$max_sort = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COALESCE(MAX(sort_order), -1) FROM {$ti} WHERE gallery_id = %d",
					$dest_gal_id
				) );

				$gallery_inserted = 0;
				$gallery_skipped  = 0;
				$sort_counter     = $max_sort;

				foreach ( $src_images as $pic ) {
					$result['images_inspected']++;

					// Copy file to destination (idempotent — skips if already exists).
					$copy_result = $this->file_copier->copy_image( $pic, $gallery_slug, 'ngg' );

					$dest_file_path = $copy_result['file_path'];
					$dest_file_url  = $copy_result['file_url'];
					$dest_thumb_path= $copy_result['thumb_path'];
					$dest_thumb_url = $copy_result['thumb_url'];

					if ( ! $dest_file_path ) {
						$result['images_still_missing']++;
						continue;
					}

					// Determine mime type from filename.
					$ext       = strtolower( pathinfo( (string) $pic['filename'], PATHINFO_EXTENSION ) );
					$mime_map  = [
						'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
						'png'  => 'image/png',  'gif'  => 'image/gif',
						'webp' => 'image/webp', 'bmp'  => 'image/bmp',
					];
					$mime_type = $mime_map[ $ext ] ?? 'image/jpeg';

					// Insert DB record (idempotent dedup inside repair_insert_gallery_item).
					$insert_result = $this->repair_insert_gallery_item( [
						'gallery_id'  => $dest_gal_id,
						'source_pid'  => (int) $pic['pid'],
						'file_path'   => $dest_file_path,
						'file_url'    => $dest_file_url,
						'thumb_path'  => $dest_thumb_path,
						'thumb_url'   => $dest_thumb_url,
						'filename'    => (string) $pic['filename'],
						'mime_type'   => $mime_type,
						'sort_order'  => ++$sort_counter,
						'title'       => (string) ( $pic['image_slug'] ?? '' ),
						'caption'     => (string) ( $pic['description'] ?? '' ),
						'alt'         => (string) ( $pic['alttext'] ?? '' ),
					] );

					if ( 'inserted' === $insert_result['status'] ) {
						$gallery_inserted++;
						$result['images_inserted']++;
						$logger->info( sprintf(
							__( 'Item inserido: galeria dest=%d pid=%d arquivo=%s', 'ml-gallery-migrator-pro' ),
							$dest_gal_id, (int) $pic['pid'], (string) $pic['filename']
						) );
					} elseif ( 'skipped' === $insert_result['status'] ) {
						$gallery_skipped++;
						$result['images_skipped']++;
					} else {
						$result['images_still_missing']++;
						$logger->error( sprintf(
							'Falha ao inserir item: galeria dest=%d pid=%d',
							$dest_gal_id, (int) $pic['pid']
						) );
					}
				}

				if ( $gallery_inserted > 0 ) {
					$result['galleries_refilled']++;
					$logger->info( sprintf(
						__( 'Galeria dest=%d repreenchida: %d inseridos, %d já existiam.', 'ml-gallery-migrator-pro' ),
						$dest_gal_id, $gallery_inserted, $gallery_skipped
					) );
				}
			}

			// Final human-readable conclusion.
			$result['message'] = sprintf(
				__( 'Concluído: %d galerias verificadas, %d completas, %d vazias, %d incompletas, %d repreenchidas. Imagens: %d inseridas, %d ignoradas, %d ainda ausentes.', 'ml-gallery-migrator-pro' ),
				$result['galleries_inspected'],
				$result['galleries_complete'],
				$result['galleries_empty'],
				$result['galleries_incomplete'],
				$result['galleries_refilled'],
				$result['images_inserted'],
				$result['images_skipped'],
				$result['images_still_missing']
			);

		} catch ( \Exception $e ) {
			$result['status']   = 'error';
			$result['errors'][] = $e->getMessage();
			$logger->error( 'Erro ao reparar imagens: ' . $e->getMessage() );
		}

		return $result;
	}

	// -----------------------------------------------------------------------
	// recover_incomplete_galleries — dedicated first-class recovery
	// -----------------------------------------------------------------------

	/**
	 * Recover empty/incomplete destination galleries from the NGG source.
	 *
	 * Algorithm:
	 *   1. Build cross-session source→dest gallery map.
	 *   2. Filter by $mode: all | empty_only | incomplete_only.
	 *   3. Filter by specific gallery IDs when $dest_gal_id / $src_gal_id > 0.
	 *   4. For each candidate gallery:
	 *      a. Count source images vs destination items.
	 *      b. Load all source pictures from ngg_pictures.
	 *      c. Per picture: check dedup (source_pid / file_path / file_name).
	 *      d. Copy file if missing; insert gallery_item if missing.
	 *      e. If DB record exists but file missing: recopy only.
	 *   5. Return real stats — never fake success.
	 *
	 * Idempotent: safe to rerun. Never duplicates gallery_items.
	 * Batched: $batch_size galleries per call to prevent timeout.
	 *
	 * Uses only real id_map schema: session_id, entity_type, source_id, dest_id, status, note.
	 *
	 * @param string $source      'ngg' (photo_gallery not yet supported for full refill).
	 * @param string $mode        'all' | 'empty_only' | 'incomplete_only'.
	 * @param int    $dest_gal_id  0 = all; >0 = specific destination gallery.
	 * @param int    $src_gal_id   0 = all; >0 = specific source gallery.
	 * @param int    $batch_size   Galleries per call (default 50).
	 * @return array<string,mixed>
	 */
	public function recover_incomplete_galleries(
		string $source      = 'ngg',
		string $mode        = 'all',
		int    $dest_gal_id = 0,
		int    $src_gal_id  = 0,
		int    $batch_size  = 50
	): array {
		global $wpdb;

		$logger = new Logger( 'repair_engine', 'recover_incomplete' );

		$result = [
			'status'               => 'success',
			'mode'                 => $mode,
			'galleries_scanned'    => 0,
			'galleries_complete'   => 0,
			'galleries_empty'      => 0,
			'galleries_incomplete' => 0,
			'galleries_recovered'  => 0,
			'images_inserted'      => 0,
			'images_skipped'       => 0,
			'images_repaired'      => 0,
			'remaining_broken'     => 0,
			'errors'               => [],
			'message'              => __( 'Recuperação de galerias concluída.', 'ml-gallery-migrator-pro' ),
		];

		try {
			$id_map = Installer::tables()['id_map'];
			$ti     = $wpdb->prefix . 'mlgp_gallery_items';
			$pp     = $wpdb->prefix . 'ngg_pictures';
			$tg_ngg = $wpdb->prefix . 'ngg_gallery';

			if ( ! $this->table_exists( $ti ) ) {
				$result['message'] = __( 'Tabela mlgp_gallery_items não encontrada.', 'ml-gallery-migrator-pro' );
				return $result;
			}

			if ( 'ngg' !== $source ) {
				$result['message'] = __( 'Recuperação disponível apenas para NGG nesta versão.', 'ml-gallery-migrator-pro' );
				return $result;
			}

			if ( ! $this->table_exists( $pp ) || ! $this->table_exists( $tg_ngg ) ) {
				$result['message'] = __( 'Tabelas NGG (ngg_pictures / ngg_gallery) não encontradas.', 'ml-gallery-migrator-pro' );
				return $result;
			}

			// ── Build cross-session source→dest gallery map ───────────────
			// Search ALL sessions so post-reset reruns still find migrated objects.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$gallery_maps = $wpdb->get_results(
				"SELECT source_id, dest_id FROM {$id_map}
				 WHERE entity_type = 'gallery' AND status = 'done' AND dest_id > 0
				 ORDER BY id DESC",
				ARRAY_A
			);

			// Deduplicate: keep most-recent dest_id per source_id.
			$src_to_dest = [];
			foreach ( $gallery_maps as $row ) {
				$sid = (int) $row['source_id'];
				$did = (int) $row['dest_id'];
				if ( ! isset( $src_to_dest[ $sid ] ) ) {
					$src_to_dest[ $sid ] = $did;
				}
			}

			// ── Apply specific gallery filter ─────────────────────────────
			if ( $dest_gal_id > 0 || $src_gal_id > 0 ) {
				foreach ( array_keys( $src_to_dest ) as $sid ) {
					$did = $src_to_dest[ $sid ];
					if ( $dest_gal_id > 0 && $did !== $dest_gal_id ) {
						unset( $src_to_dest[ $sid ] );
					} elseif ( $src_gal_id > 0 && $sid !== $src_gal_id ) {
						unset( $src_to_dest[ $sid ] );
					}
				}
			}

			if ( empty( $src_to_dest ) ) {
				$result['message'] = __( 'Nenhuma galeria mapeada encontrada.', 'ml-gallery-migrator-pro' );
				return $result;
			}

			$processed_gals = 0;

			foreach ( $src_to_dest as $sid => $did ) {

				// Respect batch size limit.
				if ( $processed_gals >= $batch_size ) {
					$logger->info( sprintf(
						'Limite de lote (%d galerias) atingido. Execute novamente para continuar.',
						$batch_size
					) );
					break;
				}

				$result['galleries_scanned']++;

				// Verify destination gallery still exists.
				if ( ! $this->dest_gallery_exists( $did ) ) {
					$result['remaining_broken']++;
					$logger->warning( sprintf( 'Galeria dest=%d não existe em mlgp_galleries.', $did ) );
					continue;
				}

				$src_count  = $this->ngg_gallery_image_count( $sid );
				$dest_count = $this->dest_gallery_item_count( $did );

				// Classify gallery.
				if ( $src_count === 0 ) {
					$result['galleries_complete']++;
					continue; // Source is empty — nothing to refill.
				}
				if ( $dest_count >= $src_count ) {
					$result['galleries_complete']++;
					continue; // Destination already complete.
				}

				$is_empty      = ( 0 === $dest_count );
				$is_incomplete = ( $dest_count > 0 && $dest_count < $src_count );

				if ( $is_empty ) {
					$result['galleries_empty']++;
				} else {
					$result['galleries_incomplete']++;
				}

				// Apply mode filter.
				if ( 'empty_only' === $mode && ! $is_empty ) {
					continue;
				}
				if ( 'incomplete_only' === $mode && ! $is_incomplete ) {
					continue;
				}

				$gallery_slug = $this->get_dest_gallery_slug( $did );
				if ( ! $gallery_slug ) {
					$result['remaining_broken']++;
					$logger->warning( sprintf( 'Slug não encontrado para galeria dest=%d.', $did ) );
					continue;
				}

				$logger->info( sprintf(
					__( 'Recuperando galeria dest=%d (%s): %d/%d itens — modo=%s', 'ml-gallery-migrator-pro' ),
					$did, $gallery_slug, $dest_count, $src_count, $mode
				) );

				// ── Load source images for this gallery ───────────────────
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$src_images = $wpdb->get_results( $wpdb->prepare(
					"SELECT p.pid, p.image_slug, p.galleryid, p.filename, p.description,
					        p.alttext, p.sortorder, p.meta_data,
					        g.path AS gallery_path, g.name AS gallery_name
					 FROM {$pp} p
					 LEFT JOIN {$tg_ngg} g ON g.gid = p.galleryid
					 WHERE p.galleryid = %d
					 ORDER BY p.sortorder ASC, p.pid ASC",
					$sid
				), ARRAY_A );

				if ( empty( $src_images ) ) {
					continue;
				}

				// Get current max sort_order in destination.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$max_sort = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COALESCE(MAX(sort_order), -1) FROM {$ti} WHERE gallery_id = %d",
					$did
				) );

				$gal_inserted = 0;
				$gal_skipped  = 0;
				$gal_repaired = 0;
				$sort_ctr     = $max_sort;

				foreach ( $src_images as $pic ) {
					$pid      = (int) $pic['pid'];
					$filename = sanitize_file_name( (string) $pic['filename'] );
					if ( ! $filename ) {
						continue;
					}

					// ── Dedup check BEFORE file copy ─────────────────────
					$dest_file_path = $this->file_copier->image_path( $gallery_slug, $filename );
					$existing_id    = $this->find_dest_gallery_item( $did, $pid, $dest_file_path, $filename );

					if ( $existing_id > 0 ) {
						if ( file_exists( $dest_file_path ) ) {
							// DB record exists AND file present — nothing to do.
							$gal_skipped++;
							continue;
						}
						// DB record exists but file missing — recopy only.
						$this->file_copier->copy_image( $pic, $gallery_slug, 'ngg' );
						$gal_repaired++;
						$logger->info( sprintf(
							__( 'Arquivo recopiado: pid=%d %s (galeria dest=%d)', 'ml-gallery-migrator-pro' ),
							$pid, $filename, $did
						) );
						continue;
					}

					// ── Copy file (idempotent — skips if already on disk) ────
					$copy = $this->file_copier->copy_image( $pic, $gallery_slug, 'ngg' );

					if ( ! $copy['file_path'] ) {
						$result['remaining_broken']++;
						$logger->warning( sprintf( 'Falha ao copiar arquivo: pid=%d %s', $pid, $filename ) );
						continue;
					}

					// ── Determine MIME type ───────────────────────────────────
					$ext      = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
					$mime_map = [
						'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
						'png'  => 'image/png',  'gif'  => 'image/gif',
						'webp' => 'image/webp', 'bmp'  => 'image/bmp',
					];
					$mime = $mime_map[ $ext ] ?? 'image/jpeg';

					// ── Insert gallery_item with dedup inside ─────────────────
					$ins = $this->repair_insert_gallery_item( [
						'gallery_id'  => $did,
						'source_pid'  => $pid,
						'file_path'   => $copy['file_path'],
						'file_url'    => $copy['file_url'],
						'thumb_path'  => $copy['thumb_path'],
						'thumb_url'   => $copy['thumb_url'],
						'filename'    => $filename,
						'mime_type'   => $mime,
						'sort_order'  => ++$sort_ctr,
						'title'       => (string) ( $pic['image_slug']  ?? '' ),
						'caption'     => (string) ( $pic['description'] ?? '' ),
						'alt'         => (string) ( $pic['alttext']     ?? '' ),
					] );

					if ( 'inserted' === $ins['status'] ) {
						$gal_inserted++;
						$logger->info( sprintf(
							__( 'Inserido: galeria dest=%d pid=%d %s', 'ml-gallery-migrator-pro' ),
							$did, $pid, $filename
						) );
					} elseif ( 'skipped' === $ins['status'] ) {
						$gal_skipped++;
					} else {
						$result['remaining_broken']++;
						$logger->error( sprintf( 'Erro ao inserir: galeria=%d pid=%d', $did, $pid ) );
					}
				}

				$result['images_inserted'] += $gal_inserted;
				$result['images_skipped']  += $gal_skipped;
				$result['images_repaired'] += $gal_repaired;

				if ( $gal_inserted > 0 || $gal_repaired > 0 ) {
					$result['galleries_recovered']++;
					$logger->info( sprintf(
						__( 'Galeria dest=%d recuperada: %d inseridas, %d ignoradas, %d reparadas.', 'ml-gallery-migrator-pro' ),
						$did, $gal_inserted, $gal_skipped, $gal_repaired
					) );
				}

				$processed_gals++;
			}

			$result['message'] = sprintf(
				__( 'Recuperação: %d galerias verificadas, %d completas, %d vazias, %d incompletas, %d recuperadas. Imagens: %d inseridas, %d ignoradas, %d reparadas, %d com falha.', 'ml-gallery-migrator-pro' ),
				$result['galleries_scanned'],
				$result['galleries_complete'],
				$result['galleries_empty'],
				$result['galleries_incomplete'],
				$result['galleries_recovered'],
				$result['images_inserted'],
				$result['images_skipped'],
				$result['images_repaired'],
				$result['remaining_broken']
			);

		} catch ( \Exception $e ) {
			$result['status']   = 'error';
			$result['errors'][] = $e->getMessage();
			$logger->error( 'Erro na recuperação de galerias: ' . $e->getMessage() );
		}

		return $result;
	}

	// -----------------------------------------------------------------------
	// repair_missing_thumbs
	// -----------------------------------------------------------------------

	/**
	 * @param string $source
	 * @return array<string,mixed>
	 */
	public function repair_missing_thumbs( string $source = 'ngg' ): array {
		global $wpdb;

		$logger = new Logger( 'repair_engine', 'missing_thumbs' );

		$result = [
			'status'   => 'success',
			'checked'  => 0,
			'recopied' => 0,
			'errors'   => [],
			'message'  => __( 'Verificação de miniaturas concluída.', 'ml-gallery-migrator-pro' ),
		];

		try {
			$id_map     = Installer::tables()['id_map'];
			$session_id = $this->current_session_id();

			if ( empty( $session_id ) ) {
				$result['message'] = __( 'Nenhuma sessão de migração ativa.', 'ml-gallery-migrator-pro' );
				return $result;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$galleries = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT source_id, dest_id FROM {$id_map}
					 WHERE session_id = %s AND entity_type = 'gallery' AND status = 'done'",
					$session_id
				),
				ARRAY_A
			);

			if ( empty( $galleries ) ) {
				$result['message'] = __( 'Nenhuma galeria mapeada para validação de miniaturas.', 'ml-gallery-migrator-pro' );
				return $result;
			}

			$reader = 'photo_gallery' === $source
				? PhotoGalleryReader::instance()
				: NGGReader::instance();

			foreach ( $galleries as $gal ) {
				$result['checked']++;
				$gallery_slug = $this->get_dest_gallery_slug( (int) $gal['dest_id'] );
				if ( ! $gallery_slug ) {
					continue;
				}

				$src_thumbs_dir = null;
				if ( method_exists( $reader, 'get_thumbs_dir_for_source_gallery' ) ) {
					$src_thumbs_dir = $reader->get_thumbs_dir_for_source_gallery( (int) $gal['source_id'] );
				} elseif ( method_exists( $reader, 'get_thumbs_dir_for_gallery' ) ) {
					$src_thumbs_dir = $reader->get_thumbs_dir_for_gallery( (int) $gal['source_id'] );
				}

				if ( $src_thumbs_dir && is_dir( $src_thumbs_dir ) ) {
					$copied              = (int) $this->file_copier->copy_thumbs_dir( $src_thumbs_dir, $gallery_slug );
					$result['recopied'] += $copied;
					if ( $copied > 0 ) {
						$logger->info( sprintf(
							__( '%d miniatura(s) recopiada(s) para galeria %s', 'ml-gallery-migrator-pro' ),
							$copied, $gallery_slug
						) );
					}
				}
			}
		} catch ( \Exception $e ) {
			$result['status']   = 'error';
			$result['errors'][] = $e->getMessage();
			$logger->error( 'Erro ao reparar miniaturas: ' . $e->getMessage() );
		}

		return $result;
	}

	// -----------------------------------------------------------------------
	// fix_orphans — real reconciliation engine with filesystem fallback
	// -----------------------------------------------------------------------

	/**
	 * Full reconciliation engine for orphan/incomplete galleries.
	 *
	 * Strategy:
	 *  1. Cursor-based scan of ALL NGG source galleries (not id_map — avoids broken-mapping blindspot).
	 *  2. For each NGG gallery:
	 *     a. Check id_map for any existing mapping (cross-session).
	 *     b. If no mapping or mapping broken: scan mlgp_galleries for equivalent by title/slug.
	 *     c. If destination gallery found: check item count vs source count.
	 *     d. If empty/incomplete: refill via repair_insert_gallery_item (idempotent dedup).
	 *     e. If no destination at all: log as unrecoverable orphan (do not create gallery — avoids duplicates).
	 *  3. Also uses filesystem fallback: counts real image files in NGG gallery folder.
	 *     A folder with images is evidence of a recoverable gallery even if DB mapping is broken.
	 *
	 * Batched by $limit NGG galleries per call. Cursor is NGG gallery gid.
	 * Never creates duplicate galleries. Only refills existing ones.
	 *
	 * @param string $source
	 * @param int    $cursor   Last NGG gid processed (0 = start from beginning).
	 * @param int    $limit    NGG galleries per batch.
	 * @return array<string,mixed>
	 */
	public function fix_orphans( string $source = 'ngg', int $cursor = 0, int $limit = 50 ): array {
		global $wpdb;

		$logger = new Logger( 'repair_engine', 'fix_orphans' );

		$result = [
			'status'              => 'success',
			'galleries_scanned'   => 0,
			'orphan_galleries'    => 0,
			'empty_dest_galleries'=> 0,
			'incomplete_galleries'=> 0,
			'galleries_recovered' => 0,
			'images_inserted'     => 0,
			'images_skipped'      => 0,
			'images_repaired'     => 0,
			'files_recopied'      => 0,
			'mappings_relinked'   => 0,
			'remaining_broken'    => 0,
			'orphan_images'       => 0,
			'marked'              => 0,
			'next_cursor'         => 0,
			'done'                => false,
			'errors'              => [],
			'message'             => __( 'Reconciliação de órfãos concluída.', 'ml-gallery-migrator-pro' ),
		];

		if ( 'ngg' !== $source ) {
			$result['message'] = __( 'Reconciliação disponível apenas para NGG nesta versão.', 'ml-gallery-migrator-pro' );
			$result['done']    = true;
			return $result;
		}

		try {
			$id_map  = Installer::tables()['id_map'];
			$ti      = $wpdb->prefix . 'mlgp_gallery_items';
			$tg_mlgp = $wpdb->prefix . 'mlgp_galleries';
			$tg_ngg  = $wpdb->prefix . 'ngg_gallery';
			$pp      = $wpdb->prefix . 'ngg_pictures';

			if ( ! $this->table_exists( $tg_ngg ) ) {
				$result['message'] = __( 'Tabela ngg_gallery não encontrada.', 'ml-gallery-migrator-pro' );
				$result['done'] = true;
				return $result;
			}

			// ── Step 1: Load NGG galleries in cursor-based batch ─────────────
			// Scan NGG directly — independent of id_map integrity.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ngg_galleries = $wpdb->get_results( $wpdb->prepare(
				"SELECT gid, name, path, title, previewpic FROM {$tg_ngg}
				 WHERE gid > %d ORDER BY gid ASC LIMIT %d",
				$cursor, $limit
			), ARRAY_A );

			if ( empty( $ngg_galleries ) ) {
				$result['done']    = true;
				$result['message'] = __( 'Reconciliação concluída — todas as galerias NGG verificadas.', 'ml-gallery-migrator-pro' );
				return $result;
			}

			// ── Step 2: Build cross-session source→dest map ──────────────────
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$all_maps = $wpdb->get_results(
				"SELECT source_id, dest_id FROM {$id_map}
				 WHERE entity_type = 'gallery' AND status = 'done' AND dest_id > 0
				 ORDER BY id DESC",
				ARRAY_A
			);
			$src_to_dest = [];
			foreach ( $all_maps as $m ) {
				$sid = (int) $m['source_id'];
				$did = (int) $m['dest_id'];
				if ( ! isset( $src_to_dest[ $sid ] ) ) {
					$src_to_dest[ $sid ] = $did;
				}
			}

			$last_gid = 0;

			foreach ( $ngg_galleries as $ngg ) {
				$gid      = (int) $ngg['gid'];
				$last_gid = $gid;
				$result['galleries_scanned']++;

				$title    = $ngg['title'] ?: $ngg['name'] ?: ( 'Gallery #' . $gid );
				$ngg_path = (string) $ngg['path'];

				// ── Step 3: Count source images ──────────────────────────────
				// DB count first.
				$src_db_count = $this->ngg_gallery_image_count( $gid );

				// Filesystem fallback: count image files in the physical folder.
				$src_fs_count = 0;
				$gallery_abs  = '';
				if ( $ngg_path ) {
					$gallery_abs = NGGReader::resolve_gallery_abspath( $ngg_path );
					if ( is_dir( $gallery_abs ) ) {
						$image_exts = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp' ];
						$files = @scandir( $gallery_abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						if ( $files ) {
							foreach ( $files as $file ) {
								if ( '.' === $file || '..' === $file ) continue;
								$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
								if ( in_array( $ext, $image_exts, true ) ) {
									$src_fs_count++;
								}
							}
						}
					}
				}

				// Use the higher of DB and filesystem counts as evidence.
				$src_count = max( $src_db_count, $src_fs_count );

				if ( $src_count === 0 ) {
					continue; // Genuinely empty source — nothing to recover.
				}

				// ── Step 4: Find destination gallery ─────────────────────────
				$dest_id = $src_to_dest[ $gid ] ?? 0;

				// If not in id_map, try to find by title or slug in mlgp_galleries.
				$relinked = false;
				if ( ! $dest_id || ! $this->dest_gallery_exists( $dest_id ) ) {
					$slug_guess = sanitize_title( $ngg['name'] ?: $title );
					if ( $this->table_exists( $tg_mlgp ) ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$found_id = (int) $wpdb->get_var( $wpdb->prepare(
							"SELECT id FROM {$tg_mlgp} WHERE title = %s OR slug = %s LIMIT 1",
							$title, $slug_guess
						) );
						if ( $found_id > 0 ) {
							$dest_id  = $found_id;
							$relinked = true;
							// Update id_map with the rediscovered mapping.
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
							$wpdb->replace( $id_map, [
								'session_id'  => 'orphan-recovery',
								'entity_type'=> 'gallery',
								'source_id'  => $gid,
								'dest_id'    => $dest_id,
								'status'     => 'done',
								'note'       => 'relinked by fix_orphans',
								'created_at' => current_time( 'mysql' ),
							], [ '%s', '%s', '%d', '%d', '%s', '%s', '%s' ] );
							$result['mappings_relinked']++;
							$src_to_dest[ $gid ] = $dest_id; // Update local cache.
							$logger->info( sprintf(
								__( 'Galeria revinculada: NGG #%d "%s" → destino id=%d', 'ml-gallery-migrator-pro' ),
								$gid, $title, $dest_id
							) );
						}
					}
				}

				if ( ! $dest_id || ! $this->dest_gallery_exists( $dest_id ) ) {
					// No destination gallery found — cannot recover without risk of duplication.
					$result['orphan_galleries']++;
					$result['remaining_broken']++;
					$logger->warning( sprintf(
						__( 'Galeria órfã sem destino: NGG #%d "%s" tem %d imagens mas nenhum destino encontrado.', 'ml-gallery-migrator-pro' ),
						$gid, $title, $src_count
					) );
					continue;
				}

				// ── Step 5: Check destination completeness ────────────────
				$dest_count = $this->dest_gallery_item_count( $dest_id );

				if ( $dest_count >= $src_count && ! $relinked ) {
					continue; // Already complete.
				}

				if ( $dest_count === 0 ) {
					$result['empty_dest_galleries']++;
				} else {
					$result['incomplete_galleries']++;
				}

				$gallery_slug = $this->get_dest_gallery_slug( $dest_id );
				if ( ! $gallery_slug ) {
					$result['remaining_broken']++;
					continue;
				}

				$logger->info( sprintf(
					__( 'Recuperando galeria NGG #%d "%s" → dest=%d (%s): %d/%d itens.', 'ml-gallery-migrator-pro' ),
					$gid, $title, $dest_id, $gallery_slug, $dest_count, $src_count
				) );

				// ── Step 6: Load source images and refill ─────────────────
				// Use DB if available; filesystem fallback otherwise.
				$src_images = [];
				if ( $src_db_count > 0 && $this->table_exists( $pp ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$src_images = $wpdb->get_results( $wpdb->prepare(
						"SELECT p.pid, p.image_slug, p.galleryid, p.filename, p.description,
						        p.alttext, p.sortorder, p.meta_data,
						        g.path AS gallery_path, g.name AS gallery_name
						 FROM {$pp} p
						 LEFT JOIN {$tg_ngg} g ON g.gid = p.galleryid
						 WHERE p.galleryid = %d
						 ORDER BY p.sortorder ASC, p.pid ASC",
						$gid
					), ARRAY_A );
				} elseif ( $src_fs_count > 0 && is_dir( $gallery_abs ) ) {
					// Filesystem fallback: synthesize picture rows from files on disk.
					$image_exts = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp' ];
					$files = @scandir( $gallery_abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( $files ) {
						$sort_num = 0;
						foreach ( $files as $file ) {
							if ( '.' === $file || '..' === $file ) continue;
							$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
							if ( ! in_array( $ext, $image_exts, true ) ) continue;
							// Synthesize a pseudo-picture row from filesystem.
							$src_images[] = [
								'pid'          => 0, // No DB pid — filesystem only.
								'image_slug'   => pathinfo( $file, PATHINFO_FILENAME ),
								'galleryid'    => $gid,
								'filename'     => $file,
								'description' => '',
								'alttext'      => '',
								'sortorder'    => $sort_num++,
								'meta_data'    => null,
								'gallery_path' => $ngg_path,
								'gallery_name' => $ngg['name'],
							];
						}
					}
				}

				if ( empty( $src_images ) ) {
					$result['remaining_broken']++;
					$logger->warning( sprintf( 'Sem imagens recuperáveis para galeria NGG #%d.', $gid ) );
					continue;
				}

				// Get current max sort_order.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$max_sort = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COALESCE(MAX(sort_order),-1) FROM {$ti} WHERE gallery_id = %d",
					$dest_id
				) );
				$sort_ctr     = $max_sort;
				$gal_inserted = 0;
				$gal_skipped  = 0;
				$gal_repaired = 0;

				foreach ( $src_images as $pic ) {
					$pid      = (int) $pic['pid'];
					$filename = sanitize_file_name( (string) $pic['filename'] );
					if ( ! $filename ) continue;

					$dest_file = $this->file_copier->image_path( $gallery_slug, $filename );
					$existing  = $this->find_dest_gallery_item_logical( $dest_id, $pid, $dest_file, $filename );

					if ( $existing > 0 ) {
						if ( file_exists( $dest_file ) ) {
							$gal_skipped++;
							continue;
						}
						// DB record exists but file missing.
						$this->file_copier->copy_image( $pic, $gallery_slug, 'ngg' );
						$gal_repaired++;
						$result['files_recopied']++;
						continue;
					}

					$copy = $this->file_copier->copy_image( $pic, $gallery_slug, 'ngg' );
					if ( ! $copy['file_path'] ) {
						$result['remaining_broken']++;
						continue;
					}

					$ext  = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
					$mime = [ 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
					          'gif' => 'image/gif',  'webp' => 'image/webp', 'bmp' => 'image/bmp' ][ $ext ] ?? 'image/jpeg';

					$ins = $this->repair_insert_gallery_item( [
						'gallery_id' => $dest_id,
						'source_pid' => $pid,
						'file_path'  => $copy['file_path'],
						'file_url'   => $copy['file_url'],
						'thumb_path' => $copy['thumb_path'],
						'thumb_url'  => $copy['thumb_url'],
						'filename'   => $filename,
						'mime_type'  => $mime,
						'sort_order' => ++$sort_ctr,
						'title'      => (string) ( $pic['image_slug']  ?? '' ),
						'caption'    => (string) ( $pic['description'] ?? '' ),
						'alt'        => (string) ( $pic['alttext']     ?? '' ),
					] );

					if ( 'inserted' === $ins['status'] ) {
						$gal_inserted++;
					} elseif ( 'skipped' === $ins['status'] ) {
						$gal_skipped++;
					} else {
						$result['remaining_broken']++;
					}
				}

				$result['images_inserted'] += $gal_inserted;
				$result['images_skipped']  += $gal_skipped;
				$result['images_repaired'] += $gal_repaired;

				if ( $gal_inserted > 0 || $gal_repaired > 0 ) {
					$result['galleries_recovered']++;
					$logger->info( sprintf(
						__( 'Recuperada: NGG #%d → dest=%d: %d inseridas, %d ignoradas, %d reparadas.', 'ml-gallery-migrator-pro' ),
						$gid, $dest_id, $gal_inserted, $gal_skipped, $gal_repaired
					) );
					// Mark in id_map if not already done.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->update(
						$id_map,
						[ 'status' => 'done', 'note' => 'recovered by fix_orphans' ],
						[ 'session_id' => 'orphan-recovery', 'entity_type' => 'gallery', 'source_id' => $gid ],
						[ '%s', '%s' ],
						[ '%s', '%s', '%d' ]
					);
				}
			}

			$result['next_cursor'] = $last_gid;
			$result['done']        = count( $ngg_galleries ) < $limit;

			$result['message'] = $result['done']
				? sprintf(
					__( 'Reconciliação concluída: %d galerias, %d vazias, %d incompletas, %d recuperadas, %d revinculadas, %d imagens inseridas, %d ainda quebradas.', 'ml-gallery-migrator-pro' ),
					$result['galleries_scanned'],
					$result['empty_dest_galleries'],
					$result['incomplete_galleries'],
					$result['galleries_recovered'],
					$result['mappings_relinked'],
					$result['images_inserted'],
					$result['remaining_broken']
				)
				: sprintf(
					__( 'Lote parcial (cursor=%d): continue. %d galerias verificadas, %d recuperadas até agora.', 'ml-gallery-migrator-pro' ),
					$last_gid,
					$result['galleries_scanned'],
					$result['galleries_recovered']
				);

		} catch ( \Exception $e ) {
			$result['status']   = 'error';
			$result['errors'][] = $e->getMessage();
			$logger->error( 'Erro na reconciliação de órfãos: ' . $e->getMessage() );
		}

		return $result;
	}

	// -----------------------------------------------------------------------
	// revalidate_integrity — real read-only audit
	// -----------------------------------------------------------------------

	/**
	 * Real integrity audit. Returns source snapshot, destination snapshot,
	 * reconciliation with coverage, and explicit detection of:
	 *   - empty destination galleries with non-empty source
	 *   - missing album links
	 *   - missing DB records
	 *   - missing physical files
	 *   - duplicate risks
	 *
	 * All numeric values. Never NaN.
	 *
	 * @param string $source
	 * @return array<string,mixed>
	 */
	public function revalidate_integrity( string $source = 'ngg' ): array {
		global $wpdb;

		$logger = new Logger( 'repair_engine', 'revalidate_integrity' );

		$result = [
			'status'           => 'success',
			'source_snapshot'  => [],
			'dest_snapshot'    => [],
			'reconciliation'   => [],
			'audit'            => [],
			'integrity_status' => 'unknown',
			'integrity_label'  => '',
			'errors'           => [],
			'message'          => __( 'Auditoria de integridade concluída.', 'ml-gallery-migrator-pro' ),
		];

		try {
			$id_map     = Installer::tables()['id_map'];
			$tg         = $wpdb->prefix . 'mlgp_galleries';
			$ti         = $wpdb->prefix . 'mlgp_gallery_items';
			$ta         = $wpdb->prefix . 'mlgp_albums';
			$tai        = $wpdb->prefix . 'mlgp_album_items';
			$session_id = $this->current_session_id();

			// ─── Source Snapshot ─────────────────────────────────────────
			$source_snapshot = [];
			foreach ( [ 'gallery', 'album', 'image' ] as $etype ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$total = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$id_map} WHERE session_id = %s AND entity_type = %s",
					$session_id, $etype
				) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$done = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$id_map} WHERE session_id = %s AND entity_type = %s AND status = 'done'",
					$session_id, $etype
				) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$broken = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$id_map} WHERE session_id = %s AND entity_type = %s AND status IN ('broken','orphan')",
					$session_id, $etype
				) );
				$source_snapshot[ $etype ] = [
					'total'  => $total,
					'done'   => $done,
					'broken' => $broken,
				];
			}
			$result['source_snapshot'] = $source_snapshot;

			// ─── Destination Snapshot ─────────────────────────────────────
			$dest_galleries   = 0;
			$dest_albums      = 0;
			$dest_items       = 0;
			$dest_album_links = 0;
			$missing_files    = 0;

			if ( $this->table_exists( $tg ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$dest_galleries = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tg}" );
			}
			if ( $this->table_exists( $ta ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$dest_albums = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ta}" );
			}
			if ( $this->table_exists( $ti ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$dest_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ti}" );
				if ( ! empty( $session_id ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$filepaths = $wpdb->get_col( $wpdb->prepare(
						"SELECT gi.file_path FROM {$id_map} m
						 JOIN {$ti} gi ON gi.id = m.dest_id
						 WHERE m.session_id = %s AND m.entity_type = 'image' AND m.status = 'done'
						 LIMIT 10000",
						$session_id
					) );
					foreach ( $filepaths as $rel ) {
						if ( $rel ) {
							$abs = ABSPATH . ltrim( $rel, '/' );
							if ( ! file_exists( $abs ) ) {
								$missing_files++;
							}
						}
					}
				}
			}
			if ( $this->table_exists( $tai ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$dest_album_links = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tai}" );
			}

			$result['dest_snapshot'] = [
				'galleries'     => $dest_galleries,
				'albums'        => $dest_albums,
				'items'         => $dest_items,
				'album_links'   => $dest_album_links,
				'missing_files' => $missing_files,
			];

			// ─── Extended Audit: empty destination galleries ───────────────
			$empty_dest_with_src   = 0;
			$albums_missing_links  = 0;
			$mapped_images_no_db   = 0;

			if ( ! empty( $session_id ) && $this->table_exists( $tg ) && $this->table_exists( $ti ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$gallery_maps = $wpdb->get_results( $wpdb->prepare(
					"SELECT source_id, dest_id FROM {$id_map}
					 WHERE session_id = %s AND entity_type = 'gallery' AND status = 'done'",
					$session_id
				), ARRAY_A );

				foreach ( $gallery_maps as $gm ) {
					$dest_count = $this->dest_gallery_item_count( (int) $gm['dest_id'] );
					if ( 'ngg' === $source ) {
						$src_count = $this->ngg_gallery_image_count( (int) $gm['source_id'] );
						if ( $src_count > 0 ) {
							if ( $dest_count === 0 ) {
								$empty_dest_with_src++;
							} elseif ( $dest_count < $src_count ) {
								// Incomplete: has items but fewer than source.
								$empty_dest_with_src++; // Counts as needing repair.
							}
						}
					}
				}

				// Albums with 0 gallery links in mlgp_album_items.
				if ( $this->table_exists( $tai ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$album_maps = $wpdb->get_results( $wpdb->prepare(
						"SELECT dest_id FROM {$id_map}
						 WHERE session_id = %s AND entity_type = 'album' AND status = 'done'",
						$session_id
					), ARRAY_A );
					foreach ( $album_maps as $am ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$link_count = (int) $wpdb->get_var( $wpdb->prepare(
							"SELECT COUNT(*) FROM {$tai} WHERE album_id = %d",
							(int) $am['dest_id']
						) );
						if ( $link_count === 0 ) {
							$albums_missing_links++;
						}
					}
				}

				// Mapped images with no DB record (LEFT JOIN null).
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$mapped_images_no_db = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$id_map} m
					 LEFT JOIN {$ti} gi ON gi.id = m.dest_id
					 WHERE m.session_id = %s AND m.entity_type = 'image'
					   AND m.status = 'done' AND gi.id IS NULL",
					$session_id
				) );
			}

			$result['audit'] = [
				'empty_dest_galleries_with_src_images' => $empty_dest_with_src,
				'albums_with_missing_links'            => $albums_missing_links,
				'mapped_images_missing_db_record'      => $mapped_images_no_db,
				'mapped_images_missing_files'          => $missing_files,
			];

			// ─── Reconciliation — safe division, never NaN ───────────────
			$img_total  = (int) ( $source_snapshot['image']['total']   ?? 0 );
			$img_done   = (int) ( $source_snapshot['image']['done']    ?? 0 );
			$img_broken = (int) ( $source_snapshot['image']['broken']  ?? 0 );
			$gal_total  = (int) ( $source_snapshot['gallery']['total'] ?? 0 );
			$gal_done   = (int) ( $source_snapshot['gallery']['done']  ?? 0 );
			$alb_total  = (int) ( $source_snapshot['album']['total']   ?? 0 );
			$alb_done   = (int) ( $source_snapshot['album']['done']    ?? 0 );

			$img_cov = ( $img_total > 0 ) ? round( $img_done / $img_total * 100, 1 ) : 0.0;
			$gal_cov = ( $gal_total > 0 ) ? round( $gal_done / $gal_total * 100, 1 ) : 0.0;
			$alb_cov = ( $alb_total > 0 ) ? round( $alb_done / $alb_total * 100, 1 ) : 0.0;

			$result['reconciliation'] = [
				'session_id'       => $session_id,
				'has_session'      => ! empty( $session_id ),
				'galleries_mapped' => $gal_done,
				'galleries_total'  => $gal_total,
				'albums_mapped'    => $alb_done,
				'albums_total'     => $alb_total,
				'images_mapped'    => $img_done,
				'images_total'     => $img_total,
				'images_broken'    => $img_broken,
				'missing_files'    => $missing_files,
				'image_coverage'   => $img_cov,
				'gallery_coverage' => $img_cov,
				'gallery_cov_pct'  => $gal_cov,
				'album_cov_pct'    => $alb_cov,
			];

			// ─── Integrity Status ─────────────────────────────────────────
			$issues = $img_broken + $missing_files + $empty_dest_with_src + $albums_missing_links + $mapped_images_no_db;

			if ( empty( $session_id ) ) {
				$status_key = 'no_session';
				$label      = __( 'Sem sessão ativa', 'ml-gallery-migrator-pro' );
			} elseif ( $img_total === 0 && $gal_total === 0 ) {
				$status_key = 'not_started';
				$label      = __( 'Migração não iniciada', 'ml-gallery-migrator-pro' );
			} elseif ( $issues === 0 && $img_done > 0 ) {
				$status_key = 'ok';
				$label      = __( 'Integridade OK', 'ml-gallery-migrator-pro' );
			} elseif ( $issues > 0 ) {
				$status_key = 'partial';
				$label      = sprintf( __( 'Integridade parcial — %d problema(s)', 'ml-gallery-migrator-pro' ), $issues );
			} else {
				$status_key = 'pending';
				$label      = __( 'Migração em andamento', 'ml-gallery-migrator-pro' );
			}

			$result['integrity_status'] = $status_key;
			$result['integrity_label']  = $label;

			$logger->info( sprintf(
				'Auditoria: galerias %d/%d (%.1f%%), álbuns %d/%d, imagens %d/%d (%.1f%%). '
				. 'Galerias destino vazias c/ fonte: %d. Álbuns sem links: %d. Imagens sem DB: %d. Arquivos ausentes: %d. Status: %s',
				$gal_done, $gal_total, $gal_cov,
				$alb_done, $alb_total,
				$img_done, $img_total, $img_cov,
				$empty_dest_with_src, $albums_missing_links,
				$mapped_images_no_db, $missing_files, $status_key
			) );

		} catch ( \Exception $e ) {
			$result['status']   = 'error';
			$result['errors'][] = $e->getMessage();
			$logger->error( 'Erro na auditoria: ' . $e->getMessage() );
		}

		return $result;
	}

	// -----------------------------------------------------------------------
	// AJAX handlers
	// -----------------------------------------------------------------------

	public function ajax_repair_run(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mlgmp_ajax' ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
		}

		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';

		// Licença: sem teste/licença, os reparos que copiam ou recriam itens ficam travados.
		// A auditoria de integridade (só leitura) continua liberada.
		if ( 'revalidate_integrity' !== $operation ) {
			License::guard_write( true );
		}

		$source    =isset( $_POST['source'] )    ? sanitize_key( wp_unslash( $_POST['source'] ) )    : 'ngg';
		$cursor    = isset( $_POST['cursor'] )     ? absint( wp_unslash( $_POST['cursor'] ) )           : 0;

		if ( ! in_array( $source, [ 'ngg', 'photo_gallery' ], true ) ) {
			$source = 'ngg';
		}

		// ── Scope params ─────────────────────────────────────────────────────
		$valid_scope_types   = [ 'global', 'gallery', 'album', 'filtered' ];
		$valid_scope_filters = [ 'all', 'empty_only', 'incomplete_only' ];
		$scope = [
			'type'            => in_array( $_POST['scope_type']   ?? '', $valid_scope_types,   true ) ? sanitize_key( wp_unslash( $_POST['scope_type'] ) )   : 'global',
			'filter'          => in_array( $_POST['scope_filter'] ?? '', $valid_scope_filters, true ) ? sanitize_key( wp_unslash( $_POST['scope_filter'] ) ) : 'all',
			'dest_gallery_id' => absint( $_POST['scope_dest_gal'] ?? 0 ),
			'src_gallery_id'  => absint( $_POST['scope_src_gal']  ?? 0 ),
			'dest_album_id'   => absint( $_POST['scope_dest_alb'] ?? 0 ),
			'slug'            => sanitize_text_field( wp_unslash( $_POST['scope_slug'] ?? '' ) ),
		];

		@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		switch ( $operation ) {
			case 'repair_album_links':
				$result = $this->repair_album_links( $source );
				break;
			case 'repair_missing_images':
				$result = $this->repair_missing_images( $source, $scope );
				break;
			case 'repair_missing_thumbs':
				$result = $this->repair_missing_thumbs( $source );
				break;
			case 'recover_incomplete_galleries':
				$recover_mode = in_array( $scope['filter'] ?? 'all', [ 'empty_only', 'incomplete_only', 'all' ], true )
					? ( $scope['filter'] ?? 'all' ) : 'all';
				$result = $this->recover_incomplete_galleries(
					$source,
					$recover_mode,
					(int) ( $scope['dest_gallery_id'] ?? 0 ),
					(int) ( $scope['src_gallery_id']  ?? 0 ),
					50
				);
				break;
			case 'fix_orphans':
				$result = $this->fix_orphans( $source, $cursor, 500 );
				break;
			case 'revalidate_integrity':
				$result = $this->revalidate_integrity( $source );
				break;
			default:
				wp_send_json_error( [ 'message' => 'Unknown operation' ], 400 );
				return;
		}

		wp_send_json_success( [ 'repair_result' => $result ] );
	}

	public function ajax_repair_status(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}

		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mlgmp_ajax' ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
		}

		$source = isset( $_REQUEST['source'] ) ? sanitize_key( wp_unslash( $_REQUEST['source'] ) ) : 'ngg';
		if ( ! in_array( $source, [ 'ngg', 'photo_gallery' ], true ) ) {
			$source = 'ngg';
		}

		wp_send_json_success( [ 'status' => $this->revalidate_integrity( $source ) ] );
	}
	// -----------------------------------------------------------------------
	// Gallery mismatch audit (AJAX)
	// -----------------------------------------------------------------------

	/**
	 * AJAX: Return paginated gallery mismatch list.
	 * READ-ONLY — never times out because it only counts rows.
	 *
	 * POST: nonce, source, filter (all|empty_only|incomplete_only|ok), page, per_page
	 */
	public function ajax_audit_galleries(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mlgmp_ajax' ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
		}

		// Capture any stray output (PHP notices/warnings) to keep response clean JSON.
		ob_start();

		global $wpdb;

		$source   = ( isset( $_POST['source'] ) && 'photo_gallery' === sanitize_key( wp_unslash( $_POST['source'] ) ) ) ? 'photo_gallery' : 'ngg';
		$filter   = in_array( $_POST['filter'] ?? 'all', [ 'all', 'empty_only', 'incomplete_only', 'ok' ], true )
			? sanitize_key( wp_unslash( $_POST['filter'] ) ) : 'all';
		$page     = max( 1, absint( $_POST['page']     ?? 1 ) );
		$per_page = max( 10, min( 200, absint( $_POST['per_page'] ?? 50 ) ) );

		$id_map = Installer::tables()['id_map'];
		$ti     = $wpdb->prefix . 'mlgp_gallery_items';

		if ( ! $this->table_exists( $ti ) ) {
			wp_send_json_error( [ 'message' => 'mlgp_gallery_items not found' ], 500 );
		}

		// Cross-session source→dest map (most recent per source_id).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$maps = $wpdb->get_results(
			"SELECT source_id, dest_id FROM {$id_map}
			 WHERE entity_type = 'gallery' AND status = 'done' AND dest_id > 0
			 ORDER BY id DESC",
			ARRAY_A
		);

		$src_to_dest = [];
		foreach ( $maps as $row ) {
			$sid = (int) $row['source_id'];
			$did = (int) $row['dest_id'];
			if ( ! isset( $src_to_dest[ $sid ] ) ) {
				$src_to_dest[ $sid ] = $did;
			}
		}

		$tg_ngg = $wpdb->prefix . 'ngg_gallery';
		$has_ngg = $this->table_exists( $tg_ngg );

		// Also add NGG galleries NOT in id_map (orphaned from broken mapping)
		// but which have a matching destination gallery by title/slug.
		if ( $has_ngg ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$all_ngg = $wpdb->get_results(
				"SELECT gid, name, title, path FROM {$tg_ngg} ORDER BY gid ASC",
				ARRAY_A
			);
			$tg_mlgp = $wpdb->prefix . 'mlgp_galleries';
			foreach ( $all_ngg as $ng ) {
				$sid = (int) $ng['gid'];
				if ( isset( $src_to_dest[ $sid ] ) ) continue; // Already mapped.
				$t = $ng['title'] ?: $ng['name'] ?: '';
				$s = sanitize_title( $ng['name'] ?: $t );
				if ( $t && $this->table_exists( $tg_mlgp ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$did = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM {$tg_mlgp} WHERE title = %s OR slug = %s LIMIT 1",
						$t, $s
					) );
					if ( $did > 0 ) {
						$src_to_dest[ $sid ] = $did; // Temporarily add for audit.
					}
				}
			}
		}

		$rows = [];
		foreach ( $src_to_dest as $sid => $did ) {
			$src_count  = $this->ngg_gallery_image_count( $sid );
			$dest_count = $this->dest_gallery_logical_item_count( $did );

			// Filesystem fallback: use logical file count if DB has nothing.
			if ( $src_count === 0 && $has_ngg ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ngg_path_row = $wpdb->get_var( $wpdb->prepare(
					"SELECT path FROM {$tg_ngg} WHERE gid = %d LIMIT 1", $sid
				) );
				if ( $ngg_path_row ) {
					$abs = NGGReader::resolve_gallery_abspath( $ngg_path_row );
					if ( is_dir( $abs ) ) {
						$exts = [ 'jpg','jpeg','png','gif','webp','bmp' ];
						$files = @scandir( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						if ( $files ) {
							foreach ( $files as $f ) {
								if ( '.' === $f || '..' === $f ) continue;
								if ( in_array( strtolower( pathinfo( $f, PATHINFO_EXTENSION ) ), $exts, true ) ) {
									$src_count++;
								}
							}
						}
					}
				}
			}

			$diff       = $src_count - $dest_count;

			if ( $src_count === 0 ) {
				$status = 'ok';
			} elseif ( $dest_count === 0 ) {
				$status = 'empty';
			} elseif ( $dest_count < $src_count ) {
				$status = 'incomplete';
			} else {
				$status = 'ok';
			}

			if ( 'ok'            === $filter && 'ok' !== $status ) continue;
			if ( 'empty_only'   === $filter && 'empty' !== $status ) continue;
			if ( 'incomplete_only' === $filter && 'incomplete' !== $status ) continue;
			if ( 'all'          === $filter && 'ok' === $status ) continue;

			$src_name = '';
			$src_path = '';
			if ( $has_ngg && 'ngg' === $source ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ngg = $wpdb->get_row( $wpdb->prepare(
					"SELECT name, title, path FROM {$tg_ngg} WHERE gid = %d LIMIT 1", $sid
				), ARRAY_A );
				$src_name = $ngg['title'] ?? $ngg['name'] ?? ( 'NGG #' . $sid );
				$src_path = $ngg['path']  ?? '';
			}

			$rows[] = [
				'src_id'     => $sid,
				'dest_id'    => $did,
				'src_name'   => $src_name,
				'src_path'   => $src_path,
				'dest_slug'  => $this->get_dest_gallery_slug( $did ),
				'src_count'  => $src_count,
				'dest_count' => $dest_count,
				'diff'       => $diff,
				'status'     => $status,
			];
		}

		$total      = count( $rows );
		$paged_rows = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );

		ob_end_clean(); // Discard any PHP notice/warning output.
		wp_send_json_success( [
			'rows'     => $paged_rows,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		] );
	}

	// -----------------------------------------------------------------------
	// Single-gallery recovery (AJAX — JS drives the loop)
	// -----------------------------------------------------------------------

	/**
	 * AJAX: Recover ONE gallery per request.
	 * No 504 — processes only one gallery's images per call.
	 * JS calls this sequentially for each selected gallery.
	 *
	 * POST: nonce, source, src_id, dest_id
	 */
	public function ajax_recover_gallery(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mlgmp_ajax' ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
		}

		// Licença: recuperar itens é uma migração parcial — travado sem teste/licença.
		License::guard_write( true );

		// Capture any stray output to keep response clean JSON.
		ob_start();

		global $wpdb;

		$source  = ( isset( $_POST['source'] ) && 'photo_gallery' === sanitize_key( wp_unslash( $_POST['source'] ) ) ) ? 'photo_gallery' : 'ngg';
		$src_id  = absint( $_POST['src_id']  ?? 0 );
		$dest_id = absint( $_POST['dest_id'] ?? 0 );

		if ( ! $src_id || ! $dest_id ) {
			wp_send_json_error( [ 'message' => 'src_id and dest_id are required' ], 400 );
		}
		if ( 'ngg' !== $source ) {
			wp_send_json_error( [ 'message' => 'Only NGG source is supported' ], 400 );
		}
		if ( ! $this->dest_gallery_exists( $dest_id ) ) {
			wp_send_json_error( [ 'message' => "dest_id={$dest_id} does not exist in mlgp_galleries" ] );
		}

		$gallery_slug = $this->get_dest_gallery_slug( $dest_id );
		if ( ! $gallery_slug ) {
			wp_send_json_error( [ 'message' => "No slug found for dest_id={$dest_id}" ] );
		}

		@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$logger = new Logger( 'repair_engine', 'recover_gallery' );
		$ti     = $wpdb->prefix . 'mlgp_gallery_items';
		$pp     = $wpdb->prefix . 'ngg_pictures';
		$tg_ngg = $wpdb->prefix . 'ngg_gallery';

		$out = [
			'status'        => 'success',
			'src_id'        => $src_id,
			'dest_id'       => $dest_id,
			'dest_slug'     => $gallery_slug,
			'images_total'  => 0,
			'inserted'      => 0,
			'skipped'       => 0,
			'repaired'      => 0,
			'broken'        => 0,
			'message'       => '',
		];

		// Load source images.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$src_images = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.pid, p.image_slug, p.galleryid, p.filename, p.description,
			        p.alttext, p.sortorder, p.meta_data,
			        g.path AS gallery_path, g.name AS gallery_name
			 FROM {$pp} p
			 LEFT JOIN {$tg_ngg} g ON g.gid = p.galleryid
			 WHERE p.galleryid = %d
			 ORDER BY p.sortorder ASC, p.pid ASC",
			$src_id
		), ARRAY_A );

		$out['images_total'] = count( $src_images );

		if ( empty( $src_images ) ) {
			$out['message'] = "Source gallery {$src_id} has no images.";
			wp_send_json_success( [ 'result' => $out ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$max_sort = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(MAX(sort_order),-1) FROM {$ti} WHERE gallery_id = %d",
			$dest_id
		) );
		$sort_ctr = $max_sort;

		foreach ( $src_images as $pic ) {
			$pid      = (int) $pic['pid'];
			$filename = sanitize_file_name( (string) $pic['filename'] );
			if ( ! $filename ) {
				continue;
			}

			// Dedup check before any write.
			$dest_file = $this->file_copier->image_path( $gallery_slug, $filename );
			$existing  = $this->find_dest_gallery_item_logical( $dest_id, $pid, $dest_file, $filename );

			if ( $existing > 0 ) {
				if ( file_exists( $dest_file ) ) {
					$out['skipped']++;
					continue;
				}
				// DB record present but file missing — recopy only, no DB insert.
				$this->file_copier->copy_image( $pic, $gallery_slug, 'ngg' );
				$out['repaired']++;
				$logger->info( "Recopiado: pid={$pid} {$filename} dest={$dest_id}" );
				continue;
			}

			// Copy file (idempotent — skips if already on disk).
			$copy = $this->file_copier->copy_image( $pic, $gallery_slug, 'ngg' );
			if ( ! $copy['file_path'] ) {
				$out['broken']++;
				$logger->warning( "Falha ao copiar: pid={$pid} {$filename}" );
				continue;
			}

			$ext  = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			$mime = [ 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
			          'gif' => 'image/gif',  'webp' => 'image/webp', 'bmp' => 'image/bmp' ][ $ext ] ?? 'image/jpeg';

			$ins = $this->repair_insert_gallery_item( [
				'gallery_id' => $dest_id,
				'source_pid' => $pid,
				'file_path'  => $copy['file_path'],
				'file_url'   => $copy['file_url'],
				'thumb_path' => $copy['thumb_path'],
				'thumb_url'  => $copy['thumb_url'],
				'filename'   => $filename,
				'mime_type'  => $mime,
				'sort_order' => ++$sort_ctr,
				'title'      => (string) ( $pic['image_slug']  ?? '' ),
				'caption'    => (string) ( $pic['description'] ?? '' ),
				'alt'        => (string) ( $pic['alttext']     ?? '' ),
			] );

			if ( 'inserted' === $ins['status'] ) {
				$out['inserted']++;
				$logger->info( "Inserido: pid={$pid} {$filename} → dest={$dest_id}" );
			} elseif ( 'skipped' === $ins['status'] ) {
				$out['skipped']++;
			} else {
				$out['broken']++;
				$logger->error( "Erro ao inserir: pid={$pid} dest={$dest_id}" );
			}
		}

		$out['message'] = sprintf(
			'Galeria dest=%d: %d inseridas, %d ignoradas, %d reparadas, %d com falha.',
			$dest_id, $out['inserted'], $out['skipped'], $out['repaired'], $out['broken']
		);
		$logger->info( $out['message'] );

		ob_end_clean(); // Discard any PHP notice/warning output.
		wp_send_json_success( [ 'result' => $out ] );
	}

	// -----------------------------------------------------------------------
	// Orphan audit + single-gallery orphan recovery (AJAX)
	// -----------------------------------------------------------------------

	/**
	 * AJAX: Read-only orphan audit.
	 * Scans all NGG galleries, applies DB + filesystem evidence, returns candidate list.
	 * Never times out — only reads/counts, never writes.
	 *
	 * POST: nonce, source, filter (all|empty_only|incomplete_only|no_dest), page, per_page
	 */
	public function ajax_audit_orphans(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mlgmp_ajax' ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
		}

		ob_start();

		global $wpdb;

		$source   = ( isset( $_POST['source'] ) && 'photo_gallery' === sanitize_key( wp_unslash( $_POST['source'] ) ) ) ? 'photo_gallery' : 'ngg';
		$filter   = in_array( $_POST['filter'] ?? 'all', [ 'all', 'empty_only', 'incomplete_only', 'no_dest' ], true )
			? sanitize_key( wp_unslash( $_POST['filter'] ) ) : 'all';
		$page     = max( 1, absint( $_POST['page']     ?? 1 ) );
		$per_page = max( 10, min( 200, absint( $_POST['per_page'] ?? 50 ) ) );

		$id_map  = Installer::tables()['id_map'];
		$ti      = $wpdb->prefix . 'mlgp_gallery_items';
		$tg_ngg  = $wpdb->prefix . 'ngg_gallery';
		$tg_mlgp = $wpdb->prefix . 'mlgp_galleries';

		if ( ! $this->table_exists( $tg_ngg ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => 'ngg_gallery table not found' ], 500 );
		}

		// Cross-session source→dest map.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$maps = $wpdb->get_results(
			"SELECT source_id, dest_id FROM {$id_map}
			 WHERE entity_type = 'gallery' AND status = 'done' AND dest_id > 0
			 ORDER BY id DESC",
			ARRAY_A
		);
		$src_to_dest = [];
		foreach ( $maps as $m ) {
			$sid = (int) $m['source_id'];
			$did = (int) $m['dest_id'];
			if ( ! isset( $src_to_dest[ $sid ] ) ) {
				$src_to_dest[ $sid ] = $did;
			}
		}

		// Load all NGG galleries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ngg_galleries = $wpdb->get_results(
			"SELECT gid, name, title, path FROM {$tg_ngg} ORDER BY gid ASC",
			ARRAY_A
		);

		$rows = [];

		foreach ( $ngg_galleries as $ngg ) {
			$gid   = (int) $ngg['gid'];
			$title = $ngg['title'] ?: $ngg['name'] ?: ( 'Gallery #' . $gid );

			// Source logical count: DB (raw) + filesystem (deduplicated, WebP-preferred).
			$src_db  = $this->ngg_gallery_image_count( $gid );
			$ngg_abs = $ngg['path'] ? NGGReader::resolve_gallery_abspath( $ngg['path'] ) : '';
			$src_logical_fs = count( $this->list_logical_images_from_fs( $ngg_abs ) );
			// Use filesystem logical count as primary (correctly deduped); DB count as fallback if fs=0.
			$src_count = $src_logical_fs > 0 ? $src_logical_fs : $src_db;
			if ( $src_count === 0 ) continue; // Nothing to recover.

			// Destination gallery.
			$dest_id  = $src_to_dest[ $gid ] ?? 0;
			$evidence = 'mapping';

			if ( ! $dest_id || ! $this->dest_gallery_exists( $dest_id ) ) {
				// Try title/slug relink.
				$slug_g = sanitize_title( $ngg['name'] ?: $title );
				if ( $this->table_exists( $tg_mlgp ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$fid = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM {$tg_mlgp} WHERE title = %s OR slug = %s LIMIT 1",
						$title, $slug_g
					) );
					if ( $fid > 0 ) {
						$dest_id  = $fid;
						$evidence = 'slug_relink';
					}
				}
			} elseif ( $src_db > 0 ) {
				$evidence = 'mapping';
			} else {
				$evidence = 'filesystem';
			}

			$dest_count = $dest_id ? $this->dest_gallery_logical_item_count( $dest_id ) : 0;
			$dest_slug  = $dest_id ? $this->get_dest_gallery_slug( $dest_id ) : '';
			$diff       = $src_count - $dest_count;

			if ( ! $dest_id ) {
				$status = 'no_dest';
			} elseif ( $dest_count === 0 ) {
				$status = 'empty';
			} elseif ( $dest_count < $src_count ) {
				$status = 'incomplete';
			} else {
				$status = 'ok';
			}

			// Apply filter.
			if ( 'no_dest'       === $filter && 'no_dest'    !== $status ) continue;
			if ( 'empty_only'    === $filter && 'empty'      !== $status ) continue;
			if ( 'incomplete_only' === $filter && 'incomplete' !== $status ) continue;
			if ( 'all'           === $filter && 'ok'         === $status ) continue;

			$rows[] = [
				'src_id'     => $gid,
				'dest_id'    => $dest_id,
				'src_name'   => $title,
				'src_path'   => $ngg['path'] ?? '',
				'dest_slug'  => $dest_slug,
				'src_count'  => $src_count,
				'dest_count' => $dest_count,
				'diff'       => $diff,
				'status'     => $status,
				'evidence'   => $evidence,
			];
		}

		$total      = count( $rows );
		$paged_rows = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );

		ob_end_clean();
		wp_send_json_success( [
			'rows'     => $paged_rows,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		] );
	}

	/**
	 * AJAX: Recover ONE orphan gallery per request.
	 * Identical logic to ajax_recover_gallery but includes relink step.
	 *
	 * POST: nonce, source, src_id, dest_id (0 = no destination found = skip)
	 */
	public function ajax_recover_orphan(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mlgmp_ajax' ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
		}

		// Licença: recuperar itens é uma migração parcial — travado sem teste/licença.
		License::guard_write( true );

		ob_start();

		global $wpdb;

		$source  = ( isset( $_POST['source'] ) && 'photo_gallery' === sanitize_key( wp_unslash( $_POST['source'] ) ) ) ? 'photo_gallery' : 'ngg';
		$src_id  = absint( $_POST['src_id']  ?? 0 );
		$dest_id = absint( $_POST['dest_id'] ?? 0 );

		if ( ! $src_id ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => 'src_id required' ], 400 );
		}
		if ( ! $dest_id ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => "No destination for NGG gallery {$src_id} — cannot recover without risk of duplication." ] );
		}
		if ( 'ngg' !== $source ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => 'Only NGG source supported' ], 400 );
		}
		if ( ! $this->dest_gallery_exists( $dest_id ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => "dest_id={$dest_id} not found in mlgp_galleries" ] );
		}

		$gallery_slug = $this->get_dest_gallery_slug( $dest_id );
		if ( ! $gallery_slug ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => "No slug for dest_id={$dest_id}" ] );
		}

		@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$logger = new Logger( 'repair_engine', 'recover_orphan' );
		$id_map  = Installer::tables()['id_map'];
		$ti      = $wpdb->prefix . 'mlgp_gallery_items';
		$pp      = $wpdb->prefix . 'ngg_pictures';
		$tg_ngg  = $wpdb->prefix . 'ngg_gallery';

		$out = [
			'status'       => 'success',
			'src_id'       => $src_id,
			'dest_id'      => $dest_id,
			'dest_slug'    => $gallery_slug,
			'images_total' => 0,
			'inserted'     => 0,
			'skipped'      => 0,
			'repaired'     => 0,
			'broken'       => 0,
			'relinked'     => false,
			'message'      => '',
		];

		// Ensure mapping is recorded.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_map = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$id_map} WHERE entity_type = 'gallery' AND source_id = %d AND dest_id = %d LIMIT 1",
			$src_id, $dest_id
		) );
		if ( ! $existing_map ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->replace( $id_map, [
				'session_id'  => 'orphan-recovery',
				'entity_type'=> 'gallery',
				'source_id'  => $src_id,
				'dest_id'    => $dest_id,
				'status'     => 'done',
				'note'       => 'recovered by ajax_recover_orphan',
				'created_at' => current_time( 'mysql' ),
			], [ '%s', '%s', '%d', '%d', '%s', '%s', '%s' ] );
			$out['relinked'] = true;
		}

		// Load source images: DB first, filesystem fallback.
		$src_images = [];
		if ( $this->table_exists( $pp ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$src_images = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.pid, p.image_slug, p.galleryid, p.filename, p.description,
				        p.alttext, p.sortorder, p.meta_data,
				        g.path AS gallery_path, g.name AS gallery_name
				 FROM {$pp} p
				 LEFT JOIN {$tg_ngg} g ON g.gid = p.galleryid
				 WHERE p.galleryid = %d
				 ORDER BY p.sortorder ASC, p.pid ASC",
				$src_id
			), ARRAY_A );
		}

		// Filesystem fallback if DB is empty.
		if ( empty( $src_images ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ngg_path = $wpdb->get_var( $wpdb->prepare(
				"SELECT path FROM {$tg_ngg} WHERE gid = %d LIMIT 1", $src_id
			) );
			if ( $ngg_path ) {
				$abs  = NGGReader::resolve_gallery_abspath( $ngg_path );
				$exts = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp' ];
				$fls  = @scandir( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $fls ) {
					$sort = 0;
					foreach ( $fls as $f ) {
						if ( '.' === $f || '..' === $f ) continue;
						if ( ! in_array( strtolower( pathinfo( $f, PATHINFO_EXTENSION ) ), $exts, true ) ) continue;
						$src_images[] = [
							'pid'          => 0,
							'image_slug'   => pathinfo( $f, PATHINFO_FILENAME ),
							'galleryid'    => $src_id,
							'filename'     => $f,
							'description' => '',
							'alttext'      => '',
							'sortorder'    => $sort++,
							'meta_data'    => null,
							'gallery_path' => $ngg_path,
							'gallery_name' => '',
						];
					}
				}
			}
		}

		$out['images_total'] = count( $src_images );

		if ( empty( $src_images ) ) {
			$out['message'] = "No source images found for NGG gallery {$src_id}.";
			ob_end_clean();
			wp_send_json_success( [ 'result' => $out ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$max_sort = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(MAX(sort_order),-1) FROM {$ti} WHERE gallery_id = %d",
			$dest_id
		) );
		$sort_ctr = $max_sort;

		// Apply WebP-preference dedup on the source image list.
		// If both photo.webp and photo.jpg exist, keep only webp.
		$logical_filenames = $this->resolve_logical_images(
			array_map( function( $p ) { return (string) $p['filename']; }, $src_images )
		);
		$logical_set = array_flip( $logical_filenames ); // for O(1) lookup.
		$out['logical_families'] = count( $logical_filenames );
		$out['raster_skipped']   = count( $src_images ) - count( $logical_filenames );

		foreach ( $src_images as $pic ) {
			$pid      = (int) $pic['pid'];
			$filename = sanitize_file_name( (string) $pic['filename'] );
			if ( ! $filename ) continue;

			// Skip raster duplicate if a webp logical candidate was selected instead.
			if ( ! isset( $logical_set[ $filename ] ) ) {
				$out['skipped']++;
				continue;
			}

			$dest_file = $this->file_copier->image_path( $gallery_slug, $filename );
			$existing  = $this->find_dest_gallery_item_logical( $dest_id, $pid, $dest_file, $filename );

			if ( $existing > 0 ) {
				if ( file_exists( $dest_file ) ) { $out['skipped']++; continue; }
				$this->file_copier->copy_image( $pic, $gallery_slug, 'ngg' );
				$out['repaired']++;
				$logger->info( "Recopiado: pid={$pid} {$filename} dest={$dest_id}" );
				continue;
			}

			$copy = $this->file_copier->copy_image( $pic, $gallery_slug, 'ngg' );
			if ( ! $copy['file_path'] ) { $out['broken']++; continue; }

			$ext  = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			$mime = [ 'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png'  => 'image/png',
			          'gif'  => 'image/gif',  'webp' => 'image/webp', 'bmp'  => 'image/bmp' ][ $ext ] ?? 'image/jpeg';

			$ins = $this->repair_insert_gallery_item( [
				'gallery_id' => $dest_id,
				'source_pid' => $pid,
				'file_path'  => $copy['file_path'],
				'file_url'   => $copy['file_url'],
				'thumb_path' => $copy['thumb_path'],
				'thumb_url'  => $copy['thumb_url'],
				'filename'   => $filename,
				'mime_type'  => $mime,
				'sort_order' => ++$sort_ctr,
				'title'      => (string) ( $pic['image_slug']  ?? '' ),
				'caption'    => (string) ( $pic['description'] ?? '' ),
				'alt'        => (string) ( $pic['alttext']     ?? '' ),
			] );

			if ( 'inserted' === $ins['status'] ) {
				$out['inserted']++;
				$logger->info( "Inserido: pid={$pid} {$filename} → dest={$dest_id}" );
			} elseif ( 'skipped' === $ins['status'] ) {
				$out['skipped']++;
			} else {
				$out['broken']++;
			}
		}

		$out['message'] = sprintf(
			'Órfão dest=%d: %d inseridas, %d ignoradas, %d reparadas, %d com falha. %d famílias lógicas, %d duplicatas raster ignoradas.',
			$dest_id, $out['inserted'], $out['skipped'], $out['repaired'], $out['broken'],
			$out['logical_families'], $out['raster_skipped']
		);
		$logger->info( $out['message'] );

		ob_end_clean();
		wp_send_json_success( [ 'result' => $out ] );
	}

}