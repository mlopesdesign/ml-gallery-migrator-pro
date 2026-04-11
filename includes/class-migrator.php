<?php
/**
 * Core migration engine.
 *
 * Processes exactly ONE batch per call. The caller (AJAX handler) decides
 * whether to continue (continuous mode) or stop (single-batch mode).
 *
 * Stages (in order):
 *   1. galleries   – Create MLGP galleries from NGG galleries.
 *   2. albums      – Create MLGP albums from NGG albums + wire gallery links.
 *   3. images      – Copy physical files + create gallery_items records.
 *   4. thumbs      – Back-fill thumb metadata for already-copied items.
 *   5. shortcodes  – Scan posts/pages and convert NGG shortcodes.
 *   done           – Terminal stage.
 *
 * @package MLGalleryMigratorPro
 */

namespace MLGMP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Migrator {

	/** @var array<string,mixed> */
	private array $state;

	/** @var Logger */
	private Logger $log;

	/** @var FileCopier */
	private FileCopier $copier;

	/** @var string MLGP galleries table. */
	private string $tg;

	/** @var string MLGP gallery_items table. */
	private string $ti;

	/** @var string MLGP albums table. */
	private string $ta;

	/** @var string MLGP album_items table. */
	private string $tai;

	/** @var string ID map table. */
	private string $map;

	public function __construct() {
		global $wpdb;
		$this->state  = State::get();
		$this->log    = new Logger( $this->state['session_id'], $this->state['current_stage'] );
		$this->copier = new FileCopier();

		// MLGP tables (installed by ML Gallery Pro itself).
		$this->tg  = $wpdb->prefix . 'mlgp_galleries';
		$this->ti  = $wpdb->prefix . 'mlgp_gallery_items';
		$this->ta  = $wpdb->prefix . 'mlgp_albums';
		$this->tai = $wpdb->prefix . 'mlgp_album_items';
		$this->map = Installer::tables()['id_map'];
	}

	// -----------------------------------------------------------------------
	// Public API — called from AJAX handler
	// -----------------------------------------------------------------------

	/**
	 * Run one batch for the current stage.
	 *
	 * @return array{
	 *   stage: string, processed: int, stage_done: bool,
	 *   all_done: bool, state: array<string,mixed>
	 * }
	 */
	public function run_batch(): array {
		$state = &$this->state;

		// Guard: only run if status is running.
		if ( $state['status'] !== State::STATUS_RUNNING ) {
			return $this->result( 0, false, false );
		}

		// Garante que o estágio atual está habilitado.
		$stage   = $state['current_stage'];
		$enabled = $state['stages_enabled'] ?? [];

		while (
			$stage !== State::STAGE_DONE
			&& isset( $enabled[ $stage ] )
			&& ! $enabled[ $stage ]
		) {
			$this->log->info( "Estágio '{$stage}' desabilitado — pulando." );
			State::advance_stage( $state );
			$stage = $state['current_stage'];
		}
		$state['current_stage'] = $stage;

		if ( $stage === State::STAGE_DONE ) {
			$state['status'] = State::STATUS_DONE;
			State::save( $state );
			return $this->result( 0, true, true );
		}

		$this->log->set_stage( $stage );

		// Executa o estágio.
		switch ( $stage ) {
			case State::STAGE_GALLERIES:
				$processed = $this->process_galleries();
				break;
			case State::STAGE_ALBUMS:
				$processed = $this->process_albums();
				break;
			case State::STAGE_ALBUMS_REWIRE:
				$processed = $this->process_albums_rewire();
				break;
			case State::STAGE_IMAGES:
				$processed = $this->process_images();
				break;
			case State::STAGE_THUMBS:
				$processed = $this->process_thumbs();
				break;
			case State::STAGE_SHORTCODES:
				$processed = $this->process_shortcodes();
				break;
			default:
				$state['status'] = State::STATUS_ERROR;
				State::save( $state );
				return $this->result( 0, false, false );
		}

		// After processing, check if stage is exhausted.
		$stage_done = $this->is_stage_exhausted( $stage );

		if ( $stage_done ) {
			$this->log->info( sprintf( __( 'Estágio "%s" concluído.', 'ml-gallery-migrator-pro' ), $stage ) );

			if ( $stage === State::STAGE_SHORTCODES ) {
				$c = $state['counters'][State::STAGE_SHORTCODES] ?? [];
				$analisados = $c['analyzed_contents'] ?? 0;
				$encontrados = $c['found_shortcodes'] ?? 0;
				$convertidos = $c['processed'] ?? 0;
				
				$this->log->info( __( '=== RESUMO DE SHORTCODES ===', 'ml-gallery-migrator-pro' ) );
				$this->log->info( sprintf( __( '- Conteúdos analisados: %d', 'ml-gallery-migrator-pro' ), $analisados ) );
				$this->log->info( sprintf( __( '- Shortcodes encontrados: %d', 'ml-gallery-migrator-pro' ), $encontrados ) );
				$this->log->info( sprintf( __( '- Conversões aplicadas: %d', 'ml-gallery-migrator-pro' ), $convertidos ) );
				$this->log->info( sprintf( __( '- Ignorados sem mapeamento: %d', 'ml-gallery-migrator-pro' ), ($c['ignored_mapping'] ?? 0) ) );
				$this->log->info( sprintf( __( '- Revisões ignoradas: %d', 'ml-gallery-migrator-pro' ), ($c['ignored_revision'] ?? 0) ) );
				$this->log->info( sprintf( __( '- Drafts ignorados: %d', 'ml-gallery-migrator-pro' ), ($c['ignored_draft'] ?? 0) ) );
				$this->log->info( sprintf( __( '- Erros reais: %d', 'ml-gallery-migrator-pro' ), ($c['errors'] ?? 0) ) );
			}

			// Se images ou thumbs acabaram, tenta vincular as capas agora que as imagens existem.
			if ( $stage === State::STAGE_IMAGES || $stage === State::STAGE_THUMBS ) {
				$this->flush_gallery_covers();
			}

			State::advance_stage( $state );

			if ( $state['current_stage'] === State::STAGE_DONE ) {
				$state['status'] = State::STATUS_DONE;
				State::save( $state );
				return $this->result( $processed, true, true );
			}
		}

		State::save( $state );
		return $this->result( $processed, $stage_done, false );
	}

	// -----------------------------------------------------------------------
	// Stage: galleries
	// -----------------------------------------------------------------------

	private function process_galleries(): int {
		$state      = &$this->state;
		$cursor     = (int) ( $state['cursors'][ State::STAGE_GALLERIES ] ?? 0 );
		$batch_size = (int) $state['batch_size'];
		$dup_mode   = $state['duplicate_mode'] ?? 'ignore';
		$processed  = 0;

		$rows = NGGReader::get_galleries_batch( $cursor, $batch_size );

		if ( empty( $rows ) ) {
			return 0;
		}

		foreach ( $rows as $ngg ) {
			// ngg_gallery usa gid como chave primária (não galleryid).
			$ngg_id = (int) $ngg['gid'];
			$cursor = max( $cursor, $ngg_id );
			$slug   = $this->unique_slug( sanitize_title( $ngg['name'] ?: $ngg['title'] ?: 'galeria-' . $ngg_id ), 'gallery' );
			$title  = ! empty( $ngg['title'] ) ? $ngg['title'] : $ngg['name'];

			$existing = $this->already_mapped( 'gallery', $ngg_id );

			if ( $existing ) {
				if ( 'ignore' === $dup_mode ) {
					$this->log->info( sprintf( __( 'Galeria NGG #%d "%s" já migrada — ignorada (dup_mode=ignore).', 'ml-gallery-migrator-pro' ), $ngg_id, $title ) );
					State::inc( $state, 'skipped' );
					$state['cursors'][ State::STAGE_GALLERIES ] = $cursor;
					continue;
				}
				// overwrite: atualiza título na tabela MLGP.
				$mlgp_id = $this->get_mapped_dest( 'gallery', $ngg_id );
				if ( $mlgp_id ) {
					$this->update_gallery( $mlgp_id, $title );
					$this->log->info( sprintf( __( 'Galeria "%s" (NGG #%d) sobreescrita → MLGP #%d.', 'ml-gallery-migrator-pro' ), $title, $ngg_id, $mlgp_id ) );
					State::inc( $state, 'processed' );
					$processed++;
				}
				$state['cursors'][ State::STAGE_GALLERIES ] = $cursor;
				continue;
			}

			$mlgp_id = $this->insert_gallery( $title, $slug, (int) $ngg['previewpic'] );

			if ( $mlgp_id ) {
				$this->persist_map( 'gallery', $ngg_id, $mlgp_id );
				State::inc( $state, 'processed' );
				$processed++;
				$this->log->info( sprintf( __( 'Galeria "%s" (NGG #%d) → MLGP #%d', 'ml-gallery-migrator-pro' ), $title, $ngg_id, $mlgp_id ) );
			} else {
				State::inc( $state, 'errors' );
				$this->log->error( sprintf( __( 'Falha ao criar galeria NGG #%d: %s', 'ml-gallery-migrator-pro' ), $ngg_id, $title ) );
			}

			$state['cursors'][ State::STAGE_GALLERIES ] = $cursor;
		}

		return $processed;
	}

	// -----------------------------------------------------------------------
	// Stage: albums_rewire (Pass 2)
	// -----------------------------------------------------------------------

	private function process_albums_rewire(): int {
		$state      = &$this->state;
		$cursor     = (int) ( $state['cursors'][ State::STAGE_ALBUMS_REWIRE ] ?? 0 );
		$batch_size = (int) $state['batch_size'];
		$processed  = 0;

		$rows = NGGReader::get_albums_batch( $cursor, $batch_size );

		if ( empty( $rows ) ) {
			return 0;
		}

		foreach ( $rows as $ngg ) {
			$ngg_id = (int) $ngg['id'];
			$cursor = max( $cursor, $ngg_id );
			$title  = ! empty( $ngg['name'] ) ? $ngg['name'] : 'Álbum ' . $ngg_id;

			$mlgp_id = $this->get_mapped_dest( 'album', $ngg_id );
			
			if ( $mlgp_id ) {
				$typed_items = NGGReader::parse_album_sortorder( (string) $ngg['sortorder'] );
				$this->rewire_album_galleries( $mlgp_id, $typed_items );
				$this->log->info( "Álbum '{$title}' (NGG #{$ngg_id}) rewire (Passo 2) concluído. Itens processados na estrutura." );
				State::inc( $state, 'processed' );
				$processed++;
			}

			$state['cursors'][ State::STAGE_ALBUMS_REWIRE ] = $cursor;
		}

		return $processed;
	}

	// -----------------------------------------------------------------------
	// Stage: albums
	// -----------------------------------------------------------------------

	private function process_albums(): int {
		$state      = &$this->state;
		$cursor     = (int) ( $state['cursors'][ State::STAGE_ALBUMS ] ?? 0 );
		$batch_size = (int) $state['batch_size'];
		$dup_mode   = $state['duplicate_mode'] ?? 'ignore';
		$processed  = 0;

		$rows = NGGReader::get_albums_batch( $cursor, $batch_size );

		if ( empty( $rows ) ) {
			return 0;
		}

		foreach ( $rows as $ngg ) {
			$ngg_id = (int) $ngg['id'];
			$cursor = max( $cursor, $ngg_id );
			$slug   = $this->unique_slug( sanitize_title( $ngg['name'] ?: 'album-' . $ngg_id ), 'album' );
			$title  = ! empty( $ngg['name'] ) ? $ngg['name'] : 'Álbum ' . $ngg_id;

			$existing = $this->already_mapped( 'album', $ngg_id );

			if ( $existing ) {
				$mlgp_id = $this->get_mapped_dest( 'album', $ngg_id );
				if ( 'ignore' === $dup_mode ) {
					$this->log->info( "Álbum NGG #{$ngg_id} '{$title}' já migrado — ignorado (dup_mode=ignore)." );
					State::inc( $state, 'skipped' );
					$state['cursors'][ State::STAGE_ALBUMS ] = $cursor;
					continue;
				}
				// overwrite: apenas loga, o rewire ocorrerá no Passo 2.
				if ( $mlgp_id ) {
					$this->log->info( "Álbum '{$title}' (NGG #{$ngg_id}) existente → MLGP #{$mlgp_id}. Relacionamentos serão atualizados na Passagem 2." );
					State::inc( $state, 'processed' );
					$processed++;
				}
				$state['cursors'][ State::STAGE_ALBUMS ] = $cursor;
				continue;
			}

			// Novo álbum
			$mlgp_id = $this->insert_album( $title, $slug );

			if ( $mlgp_id ) {
				// Cover image.
				$preview_pid = (int) $ngg['previewpic'];
				if ( $preview_pid > 0 ) {
					$this->stash_album_cover( $mlgp_id, $preview_pid );
				}

				$this->persist_map( 'album', $ngg_id, $mlgp_id );
				State::inc( $state, 'processed' );
				$processed++;
				$this->log->info( "[Sucesso] Álbum '{$title}' (NGG #{$ngg_id}) concluído com paridade tipada (Galerias e Sub-álbuns)." );
				
				$this->flush_gallery_covers();
			} else {
				State::inc( $state, 'errors' );
				$this->log->error( "Falha ao criar álbum NGG #{$ngg_id}: {$title}" );
			}

			$state['cursors'][ State::STAGE_ALBUMS ] = $cursor;
		}

		return $processed;
	}

	// -----------------------------------------------------------------------
	// Stage: images
	// -----------------------------------------------------------------------

	private function process_images(): int {
		$state      = &$this->state;
		$cursor     = (int) ( $state['cursors'][ State::STAGE_IMAGES ] ?? 0 );
		$batch_size = (int) $state['batch_size'];
		$processed  = 0;

		$rows = NGGReader::get_images_batch( $cursor, $batch_size );

		if ( empty( $rows ) ) {
			return 0;
		}

		$this->copier->ensure_base_dir();

		foreach ( $rows as $ngg ) {
			$ngg_pid  = (int) $ngg['pid'];
			$cursor   = max( $cursor, $ngg_pid );

			if ( $this->already_mapped( 'image', $ngg_pid ) ) {
				State::inc( $state, 'skipped' );
				$state['cursors'][ State::STAGE_IMAGES ] = $cursor;
				continue;
			}

			// Resolve source.
			$src_abs = NGGReader::resolve_image_abspath( $ngg );

			if ( ! file_exists( $src_abs ) ) {
				State::inc( $state, 'orphans' );
				$this->log->orphan(
					"Imagem órfã — arquivo não encontrado: {$src_abs}",
					[ 'ngg_pid' => $ngg_pid, 'filename' => $ngg['filename'], 'gallery_id' => $ngg['galleryid'] ]
				);
				$this->persist_map( 'image', $ngg_pid, 0, 'orphan', 'Arquivo não encontrado: ' . $src_abs );
				$state['cursors'][ State::STAGE_IMAGES ] = $cursor;
				continue;
			}

			// Resolve destination gallery ID.
			$mlgp_gal_id = $this->get_mapped_dest( 'gallery', (int) $ngg['galleryid'] );

			if ( ! $mlgp_gal_id ) {
				State::inc( $state, 'errors' );
				$this->log->error(
					"Erro de mapeamento: Galeria NGG #{$ngg['galleryid']} não encontrada no destino. Pule a etapa de galerias?",
					[ 'ngg_pid' => $ngg_pid, 'filename' => $ngg['filename'] ]
				);
				$state['cursors'][ State::STAGE_IMAGES ] = $cursor;
				continue;
			}

			$gallery_slug = $this->get_gallery_slug( $mlgp_gal_id );

			// Copy physical file.
			$copy_result = $this->copier->copy_image( $ngg, $gallery_slug );

			if ( ! $copy_result['copied'] ) {
				State::inc( $state, 'errors' );
				$this->log->error(
					"Falha ao copiar imagem pid={$ngg_pid}: {$src_abs}",
					[ 'dest' => $copy_result['file_path'] ]
				);
				$state['cursors'][ State::STAGE_IMAGES ] = $cursor;
				continue;
			}

			// Gather dimensions if possible.
			$dims = @getimagesize( $copy_result['file_path'] );

			// Insert MLGP gallery_item.
			$res = $this->insert_gallery_item( [
				'gallery_id' => $mlgp_gal_id,
				'source_pid' => $ngg_pid, // Mantém rastreio original para evitar Duplicate Entry 0-0.
				'file_path'  => $copy_result['file_path'],
				'file_url'   => $copy_result['file_url'],
				'thumb_path' => $copy_result['thumb_path'],
				'thumb_url'  => $copy_result['thumb_url'],
				'filename'   => $ngg['filename'],
				'title'      => $ngg['alttext'] ?: $ngg['description'],
				'caption'    => $ngg['description'],
				'alt'        => $ngg['alttext'],
				'sort_order' => (int) $ngg['sortorder'],
				'width'      => $dims ? (int) $dims[0] : 0,
				'height'     => $dims ? (int) $dims[1] : 0,
				'mime_type'  => $dims ? $dims['mime'] : '',
			] );

			$item_id = is_array( $res ) ? $res['id'] : $res;
			$status  = is_array( $res ) ? $res['status'] : 'inserted';

			if ( $item_id ) {
				$this->persist_map( 'image', $ngg_pid, $item_id );
				
				if ( $status === 'skipped' ) {
					State::inc( $state, 'skipped' );
					$this->log->debug( "[Ponteiro avança: {$cursor}] Imagem pid={$ngg_pid} (gallery_id={$mlgp_gal_id}) IGNORADA por duplicidade → item existente #{$item_id}" );
				} elseif ( $status === 'updated' ) {
					State::inc( $state, 'processed' );
					$processed++;
					$this->log->debug( "[Ponteiro avança: {$cursor}] Imagem pid={$ngg_pid} (gallery_id={$mlgp_gal_id}) SOBRESCRITA → item #{$item_id}" );
				} else {
					State::inc( $state, 'processed' );
					$processed++;
					$this->log->debug( "[Ponteiro avança: {$cursor}] Imagem pid={$ngg_pid} (gallery_id={$mlgp_gal_id}) INSERIDA → item #{$item_id}" );
				}
			} else {
				global $wpdb;
				$db_err = $wpdb->last_error ?: '(sem detalhe — verifique colunas da tabela ' . $this->ti . ')';
				State::inc( $state, 'errors' );
				$this->log->error( "[Falha no avanço: {$cursor}] Falha ao inserir item pid={$ngg_pid} (gallery_id={$mlgp_gal_id}) [{$ngg['filename']}]. Motivo real: {$db_err}" );
			}

			$state['cursors'][ State::STAGE_IMAGES ] = $cursor;
		}

		// Flush gallery covers that can now be resolved.
		$this->flush_gallery_covers();

		return $processed;
	}

	// -----------------------------------------------------------------------
	// Stage: thumbs
	// -----------------------------------------------------------------------

	private function process_thumbs(): int {
		$state      = &$this->state;
		$cursor     = (int) ( $state['cursors'][ State::STAGE_THUMBS ] ?? 0 );
		$batch_size = (int) $state['batch_size'];
		$processed  = 0;

		global $wpdb;

		// Iteramos sobre as GALERIAS para copiar as pastas de thumbs de uma vez.
		$galleries = NGGReader::get_galleries_batch( $cursor, $batch_size );

		if ( empty( $galleries ) ) {
			return 0;
		}

		foreach ( $galleries as $ngg ) {
			$ngg_id = (int) $ngg['gid'];
			$cursor = max( $cursor, $ngg_id );
			$title  = $ngg['title'] ?: $ngg['name'];
			
			// 1. Localiza a galeria de destino.
			$mlgp_gal_id = $this->get_mapped_dest( 'gallery', $ngg_id );
			if ( ! $mlgp_gal_id ) {
				$this->log->warning( "[thumbs] Pulo: Galeria NGG #{$ngg_id} não mapeada no destino." );
				$state['cursors'][ State::STAGE_THUMBS ] = $cursor;
				continue;
			}

			$slug = $this->get_gallery_slug( $mlgp_gal_id );

			// 2. Localiza a pasta de origem NGG.
			$src_thumbs_dir = NGGReader::resolve_thumbs_dir_abspath( (string) $ngg['path'] );
			
			if ( ! is_dir( $src_thumbs_dir ) ) {
				$this->log->error( "[thumbs] Galeria '{$title}' → Pasta /thumbs não encontrada na origem: {$src_thumbs_dir}" );
				State::inc( $state, 'errors' );
				$state['cursors'][ State::STAGE_THUMBS ] = $cursor;
				continue;
			}

			// 3. Copia física real.
			$this->log->debug( "[thumbs] Galeria '{$title}' → origem encontrada em {$src_thumbs_dir}" );
			$copied_count = $this->copier->copy_thumbs_dir( $src_thumbs_dir, $slug );
			$this->log->info( "[thumbs] Galeria '{$title}': {$copied_count} arquivos copiados/verificados." );

			// 4. Vincula as miniaturas no banco para os itens dessa galeria.
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, file_name, file_path FROM {$this->ti} WHERE gallery_id = %d",
					$mlgp_gal_id
				),
				ARRAY_A
			);

			// 4. Indexar arquivos físicamente presentes na pasta de origem para busca inteligente.
			$files_on_disk = scandir( $src_thumbs_dir );
			$files_on_disk = is_array( $files_on_disk ) ? $files_on_disk : [];

			$links_count = 0;
			foreach ( $items as $item ) {
				$filename    = $item['file_name'];
				$item_id     = (int) $item['id'];
				$found_file  = '';
				$reason      = 'Nenhum padrão compatível encontrado no diretório.';
				
				// Padrões de procura inteligentes (NGG varia prefixos e extensões as vezes)
				$base_name = pathinfo( $filename, PATHINFO_FILENAME );
				
				// 1. Procura exata (thumbs_ + nome ou apenas nome)
				$candidates = [ 'thumbs_' . $filename, $filename ];
				foreach ( $candidates as $cand ) {
					if ( in_array( $cand, $files_on_disk, true ) ) {
						$found_file = $cand;
						break;
					}
				}

				// 2. Procura por nome base (se falhou a exata)
				if ( ! $found_file ) {
					foreach ( $files_on_disk as $file ) {
						if ( $file === '.' || $file === '..' ) continue;
						
						// Se o nome do arquivo no disco contém o nome base da imagem original
						if ( stripos( $file, $base_name ) !== false ) {
							// Prefere os que começam com thumbs_ ou terminam com o nome base
							if ( 0 === stripos( $file, 'thumbs_' ) || stripos( $file, $base_name ) !== false ) {
								$found_file = $file;
								break;
							}
						}
					}
				}

				if ( $found_file ) {
					$thumb_rel = 'thumbs/' . $found_file;
					$thumb_url = $this->copier->get_base_url() . '/' . $slug . '/' . $thumb_rel;
					$src_path  = $src_thumbs_dir . DIRECTORY_SEPARATOR . $found_file;
					$dest_path = $this->copier->get_base_dir() . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'thumbs' . DIRECTORY_SEPARATOR . $found_file;

					// Persiste apenas os campos reais presentes no schema do MLGP.
					$updated = $wpdb->update(
						$this->ti,
						[ 
							'thumb_path' => $thumb_rel, 
							'thumb_url'  => $thumb_url
						],
						[ 'id' => $item_id ]
					);

					if ( false !== $updated ) {
						$links_count++;
						$this->log->debug( "[thumbs] Vinculei: '{$filename}' -> '{$found_file}' | Item #{$item_id} | Dest: {$thumb_rel}" );
						$this->log->debug( "[thumbs-audit] Origem: {$src_path} | Destino: {$dest_path}" );
					} else {
						$db_err = $wpdb->last_error ?: '(sem detalhe)';
						$this->log->error( "[thumbs] Erro DB ao atualizar item #{$item_id}: {$db_err}" );
					}
				} else {
					$this->log->warning( "[thumbs] Não encontrei miniatura para '{$filename}' na pasta '{$slug}/thumbs/'. Motivo: {$reason}" );
				}
			}

			if ( $links_count > 0 ) {
				$this->log->info( "[thumbs] Galeria '{$title}': {$links_count} miniaturas vinculadas aos itens." );
				$processed += $links_count;
				// Incrementamos o processado global para feedback visual
				$state['counters'][ State::STAGE_THUMBS ]['processed'] = ( $state['counters'][ State::STAGE_THUMBS ]['processed'] ?? 0 ) + $links_count;
			}

			// 5. Vincular Capa da Galeria e Card.
			$this->flush_gallery_covers();

			$state['cursors'][ State::STAGE_THUMBS ] = $cursor;
		}

		return $processed;
	}

	// -----------------------------------------------------------------------
	// Stage: shortcodes
	// -----------------------------------------------------------------------

	private function process_shortcodes(): int {
		$state      = &$this->state;
		$cursor     = (int) ( $state['cursors'][ State::STAGE_SHORTCODES ] ?? 0 );
		$batch_size = (int) $state['batch_size'];
		$processed  = 0;

		$converter = new ShortcodeConverter( $state['session_id'] );
		$posts     = NGGReader::get_posts_with_shortcodes_batch( $cursor, $batch_size );

		if ( empty( $posts ) ) {
			// Also convert widgets (done once when shortcodes stage is exhausted or first run).
			$converter->convert_widgets();
			return 0;
		}

		global $wpdb;

		foreach ( $posts as $post ) {
			$post_id = (int) $post['ID'];
			$cursor  = max( $cursor, $post_id );

			$replacements_all = [];
			$has_conversion   = false;

			// 1. Tenta converter post_content
			$result = $converter->convert( $post['post_content'] );
			if ( $result['conversions'] > 0 ) {
				$has_conversion = true;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->posts,
					[ 'post_content' => $result['content'] ],
					[ 'ID'           => $post_id ],
					[ '%s' ],
					[ '%d' ]
				);
			}

			// Agrupa logs do content
			if ( ! empty( $result['replacements'] ) ) {
				foreach ( $result['replacements'] as $rep ) {
					$rep['local']       = 'post_content';
					$replacements_all[] = $rep;
				}
			}

			// 2. Tenta converter postmetas (ex. construtor Nicepage/Elementor) suportando serialização
			$metas = get_post_meta( $post_id );
			if ( $metas && is_array( $metas ) ) {
				foreach ( $metas as $meta_key => $meta_values ) {
					foreach ( $meta_values as $raw_meta_str ) {
						// Pesquisa rápida antes do unserialize para poupar performance
						if ( stripos( $raw_meta_str, '[ngg' ) !== false || stripos( $raw_meta_str, '[album' ) !== false ) {
							$real_val = get_post_meta( $post_id, $meta_key, true ); // Traz formato real (array/string/obj)
							
							$meta_replacements = [];
							$real_val_converted = $this->recursive_convert_meta( $real_val, $converter, $meta_replacements );
							
							if ( ! empty( $meta_replacements ) ) {
								$has_conversion_meta = false;
								foreach ( $meta_replacements as $mrep ) {
									$mrep['local']       = "meta: {$meta_key}";
									$replacements_all[]  = $mrep;
									if ( $mrep['new'] ) {
										$has_conversion_meta = true;
									}
								}
								if ( $has_conversion_meta ) {
									$has_conversion = true;
									update_post_meta( $post_id, $meta_key, $real_val_converted );
								}
							}
						}
					}
				}
			}

			if ( $has_conversion ) {
				// Invalidate any page caches.
				clean_post_cache( $post_id );
				$processed++;
				State::inc( $state, 'processed' );
			}

			// Lida com logging detalhado
			foreach ( $replacements_all as $rep ) {
				State::inc( $state, 'found_shortcodes' );
				$attrs_str = $rep['parsed_attrs'] ?? 'nenhum';
				$local     = $rep['local'] ?? 'desconhecido';
				$status    = $post['post_status'] ?? 'publish';
				$type      = $post['post_type']   ?? 'post';

				if ( $rep['new'] ) {
					$this->log->info( "[Tipo: {$type}] [Status: {$status}] Post #{$post_id} '{$post['post_title']}': [Convertido no {$local}] {$rep['old']} → {$rep['new']} | Detectado: {$attrs_str}" );
					continue;
				}

				// Lógica de Falha no Mapeamento (Órfãos/Ignorados)
				$reason = 'ignorados_sem_mapeamento';
				$counter = 'ignored_mapping';
				$log_msg = "Sem Mapeamento";

				// Categorização por Status
				if ( in_array( $status, [ 'revision', 'inherit' ], true ) ) {
					$reason  = 'revisão ignorada';
					$counter = 'ignored_revision';
				} elseif ( in_array( $status, [ 'draft', 'auto-draft' ], true ) ) {
					$reason  = 'rascunho ignorado';
					$counter = 'ignored_draft';
				} elseif ( strpos( $local, 'widget' ) !== false || strpos( $local, 'option' ) !== false ) {
					$reason  = 'widget órfão ignorado';
					$counter = 'ignored_widget_orphan';
				}

				State::inc( $state, $counter );
				$this->log->info( "[Tipo: {$type}] [Status: {$status}] Post #{$post_id} '{$post['post_title']}': [{$reason} no {$local}] {$rep['old']} | Detectado: {$attrs_str}" );
			}

			// Conta o post como um conteúdo analisado
			State::inc( $state, 'analyzed_contents' );

			$state['cursors'][ State::STAGE_SHORTCODES ] = $cursor;
		}

		return $processed;
	}

	/**
	 * Aplica a conversão de shortcodes recursivamente respeitando estruturas de dados serializadas (Nicepage).
	 */
	private function recursive_convert_meta( $data, $converter, &$meta_replacements ) {
		if ( is_string( $data ) ) {
			if ( stripos( $data, '[ngg' ) !== false || stripos( $data, '[album' ) !== false ) {
				$result = $converter->convert( $data );
				if ( ! empty( $result['replacements'] ) ) {
					foreach ( $result['replacements'] as $rep ) {
						$meta_replacements[] = $rep;
					}
				}
				return $result['content'];
			}
			return $data;
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = $this->recursive_convert_meta( $v, $converter, $meta_replacements );
			}
			return $data;
		}

		if ( is_object( $data ) ) {
			$clone = clone $data;
			foreach ( get_object_vars( $clone ) as $k => $v ) {
				$clone->$k = $this->recursive_convert_meta( $v, $converter, $meta_replacements );
			}
			return $clone;
		}

		return $data;
	}

	// -----------------------------------------------------------------------
	// Exhaustion check — is there anything left in the current stage?
	// -----------------------------------------------------------------------

	private function is_stage_exhausted( string $stage ): bool {
		$state      = $this->state;
		$cursor     = (int) ( $state['cursors'][ $stage ] ?? 0 );
		$batch_size = 1; // Just check for 1 more row.

		switch ( $stage ) {
			case State::STAGE_GALLERIES:
				return empty( NGGReader::get_galleries_batch( $cursor, $batch_size ) );
			case State::STAGE_ALBUMS:
				return empty( NGGReader::get_albums_batch( $cursor, $batch_size ) );
			case State::STAGE_IMAGES:
				return empty( NGGReader::get_images_batch( $cursor, $batch_size ) );
			case State::STAGE_THUMBS:
				return $this->thumbs_exhausted( $cursor );
			case State::STAGE_SHORTCODES:
				return empty( NGGReader::get_posts_with_shortcodes_batch( $cursor, $batch_size ) );
		}

		return true;
	}

	private function thumbs_exhausted( int $cursor ): bool {
		$batch_size = 1;
		return empty( NGGReader::get_galleries_batch( $cursor, $batch_size ) );
	}

	// -----------------------------------------------------------------------
	// MLGP DB writers
	// -----------------------------------------------------------------------

	private function insert_gallery( string $title, string $slug, int $preview_pid = 0 ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$this->tg,
			[
				'title'              => $title,
				'slug'               => $slug,
				'description'        => '',
				'status'             => 'published',
				'cover_attachment_id'=> 0,
				'cover_item_id'      => 0,
				'display_type'       => 'grid',
				'settings_json'      => null,
				'created_by'         => get_current_user_id(),
				'created_at'         => $now,
				'updated_at'         => $now,
			],
			[ '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s' ]
		);
		$id = (int) $wpdb->insert_id;

		// Stash preview_pid for cover resolution after images.
		if ( $preview_pid > 0 && $id > 0 ) {
			$this->stash_gallery_cover( $id, $preview_pid );
		}

		return $id;
	}

	private function update_gallery( int $id, string $title ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$this->tg,
			[ 'title' => $title, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	private function rewire_album_galleries( int $album_id, array $typed_items ): void {
		global $wpdb;
		// Clear existing items for this album.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $this->tai, [ 'album_id' => $album_id ], [ '%d' ] );

		// Re-insert using exact mapping.
		$sort          = 0;
		$total_orig    = count( $typed_items );
		$mapped_ids    = [];
		$orphan_source = [];

		$this->log->info( "--- Auditoria de Vínculo (Tipada): MLGP Álbum #{$album_id} ---" );

		foreach ( $typed_items as $item ) {
			$ngg_id   = (int) $item['id'];
			$type     = $item['type']; // 'gallery' ou 'album'
			$mlgp_id  = $this->get_mapped_dest( $type, $ngg_id );

			if ( $mlgp_id ) {
				$this->insert_album_item( $album_id, $mlgp_id, $type, $sort++ );
				$mapped_ids[] = $mlgp_id;
				$this->log->info( "- Item OK: NGG {$type} #{$ngg_id} → MLGP #{$mlgp_id}" );
			} else {
				$orphan_source[] = "{$type}#{$ngg_id}";
				$this->log->warning( "- Item ÓRFÃO: NGG {$type} #{$ngg_id} (não mapeado)" );
			}
		}

		$this->log->info( "- Total Processado: {$sort} de {$total_orig} itens originais." );

		if ( ! empty( $orphan_source ) ) {
			$this->log->warning( "- Órfãos Detetados: [" . implode( ',', $orphan_source ) . ']' );
		}
	}

	private function insert_album( string $title, string $slug ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$this->ta,
			[
				'title'               => $title,
				'slug'                => $slug,
				'description'         => '',
				'status'              => 'publish', // Fix: Pro version uses 'publish' instead of 'published'
				'cover_attachment_id' => 0,
				'cover_item_id'       => 0,
				'display_type'        => 'grid',
				'settings_json'       => '{}',
				'created_by'          => get_current_user_id(),
				'created_at'          => $now,
				'updated_at'          => $now,
			],
			[ '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	private function insert_album_item( int $album_id, int $item_id, string $item_type, int $sort ): void {
		global $wpdb;
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$this->tai,
			[
				'album_id'   => $album_id,
				'item_type'  => $item_type,
				'item_id'    => $item_id,
				'sort_order' => $sort,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%d', '%s', '%d', '%d', '%s', '%s' ]
		);
	}

	/** 
	 * @param array<string,mixed> $data 
	 * @return int|array 
	 */
	private function insert_gallery_item( array $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		// Diagnóstico pré-insert.
		$this->log->debug(
			sprintf(
				'INSERT gallery_item: tabela=%s gallery_id=%d file=%s',
				$this->ti,
				(int) $data['gallery_id'],
				(string) $data['filename']
			)
		);

		// Campos mínimos compatíveis com todas as versões do MLGP.
		// Inclui source_pid para evitar conflito de chaves únicas em tabelas preparadas.
		$fields = [
			'gallery_id'    => (int) $data['gallery_id'],
			'source_pid'    => (int) ( $data['source_pid'] ?? 0 ),
			'file_path'     => (string) $data['file_path'],
			'file_url'      => (string) $data['file_url'],
			'thumb_path'    => (string) $data['thumb_path'],
			'thumb_url'     => (string) $data['thumb_url'],
			'file_name'     => (string) $data['filename'],
			'original_name' => (string) $data['filename'],
			'mime_type'     => (string) $data['mime_type'],
			'width'         => (int) $data['width'],
			'height'        => (int) $data['height'],
			'item_title'    => (string) ( $data['title']   ?? '' ),
			'item_caption'  => (string) ( $data['caption'] ?? '' ),
			'item_alt'      => (string) ( $data['alt']     ?? '' ),
			'sort_order'    => (int) $data['sort_order'],
			'is_visible'    => 1,
			'created_at'    => $now,
			'updated_at'    => $now,
		];

		$formats = [
			'%d', '%d', '%s', '%s', '%s', '%s',
			'%s', '%s', '%s',
			'%d', '%d',
			'%s', '%s', '%s',
			'%d', '%d',
			'%s', '%s',
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $this->ti, $fields, $formats );

		if ( false === $result ) {
			$db_error = $wpdb->last_error;
			if ( stripos( $db_error, 'Duplicate entry' ) !== false && stripos( $db_error, 'uniq_gallery_source_pid' ) !== false ) {
				// Localiza o ID existente
				$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$this->ti} WHERE gallery_id = %d AND source_pid = %d",
					$data['gallery_id'],
					$data['source_pid']
				) );

				if ( $existing_id > 0 ) {
					if ( ($this->state['duplicate_mode'] ?? 'ignore') === 'overwrite' ) {
						// Sobrescreve (atualiza tudo menos criado_em e chaves únicas)
						unset($fields['created_at']);
						$wpdb->update( $this->ti, $fields, [ 'id' => $existing_id ] );
						return [ 'id' => $existing_id, 'status' => 'updated' ];
					}
					return [ 'id' => $existing_id, 'status' => 'skipped' ];
				}
			}

			// Loga o erro real do MySQL.
			$this->log->error( 'DB insert_gallery_item: ' . $db_error );
			return 0;
		}

		return [ 'id' => (int) $wpdb->insert_id, 'status' => 'inserted' ];
	}

	// -----------------------------------------------------------------------
	// ID map helpers
	// -----------------------------------------------------------------------

	private function already_mapped( string $type, int $source_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->map}
				 WHERE session_id = %s AND entity_type = %s AND source_id = %d
				 LIMIT 1",
				$this->state['session_id'],
				$type,
				$source_id
			)
		);
		return (bool) $exists;
	}

	private function persist_map(
		string $type,
		int $source_id,
		int $dest_id,
		string $status = 'done',
		string $note = ''
	): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->replace(
			$this->map,
			[
				'session_id'  => $this->state['session_id'],
				'entity_type' => $type,
				'source_id'   => $source_id,
				'dest_id'     => $dest_id,
				'status'      => $status,
				'note'        => $note ?: null,
				'created_at'  => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%d', '%d', '%s', '%s', '%s' ]
		);
	}

	private function get_mapped_dest( string $type, int $source_id ): int {
		global $wpdb;

		// 1. Tenta na sessão atual (mais preciso).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dest = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT dest_id FROM {$this->map}
				 WHERE session_id = %s AND entity_type = %s AND source_id = %d AND status = 'done'
				 LIMIT 1",
				$this->state['session_id'],
				$type,
				$source_id
			)
		);

		// 2. Fallback: procura EM QUALQUER sessão anterior (suporte a migração incremental).
		if ( ! $dest ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$dest = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT dest_id FROM {$this->map}
					 WHERE entity_type = %s AND source_id = %d AND status = 'done'
					 ORDER BY id DESC
					 LIMIT 1",
					$type,
					$source_id
				)
			);
		}

		$dest_id = (int) $dest;

		if ( $dest_id > 0 ) {
			// 3. Validação de integridade física.
			if ( ! $this->entity_exists( $type, $dest_id ) ) {
				return 0;
			}
		}

		return $dest_id;
	}

	private function entity_exists( string $type, int $id ): bool {
		global $wpdb;
		$table = '';
		switch ( $type ) {
			case 'gallery':
				$table = $this->tg;
				break;
			case 'album':
				$table = $this->ta;
				break;
			case 'image':
				$table = $this->ti;
				break;
		}
		if ( ! $table ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d LIMIT 1", $id ) );
	}

	// -----------------------------------------------------------------------
	// Cover image wiring (gallery)
	// -----------------------------------------------------------------------

	private function stash_gallery_cover( int $mlgp_gallery_id, int $ngg_pid ): void {
		$covers = (array) get_option( 'mlgmp_pending_gallery_covers', [] );
		$covers[ $mlgp_gallery_id ] = $ngg_pid;
		update_option( 'mlgmp_pending_gallery_covers', $covers, false );
	}

	private function stash_album_cover( int $mlgp_album_id, int $ngg_pid ): void {
		$covers = (array) get_option( 'mlgmp_pending_album_covers', [] );
		$covers[ $mlgp_album_id ] = $ngg_pid;
		update_option( 'mlgmp_pending_album_covers', $covers, false );
	}

	/** Flush all pending gallery covers that have been mapped by now. */
	private function flush_gallery_covers(): void {
		global $wpdb;
		$pending = (array) get_option( 'mlgmp_pending_gallery_covers', [] );
		$updated = false;

		foreach ( $pending as $mlgp_gal_id => $ngg_pid ) {
			$item_id = $this->get_mapped_dest( 'image', (int) $ngg_pid );
			if ( $item_id > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->update(
					$this->tg,
					[ 'cover_item_id' => $item_id, 'updated_at' => current_time( 'mysql' ) ],
					[ 'id' => $mlgp_gal_id ],
					[ '%d', '%s' ],
					[ '%d' ]
				);
				unset( $pending[ $mlgp_gal_id ] );
				$updated = true;
			}
		}

		if ( $updated ) {
			update_option( 'mlgmp_pending_gallery_covers', $pending, false );
		}

		// Also flush album covers.
		$album_pending = (array) get_option( 'mlgmp_pending_album_covers', [] );
		$album_updated = false;

		foreach ( $album_pending as $mlgp_alb_id => $ngg_pid ) {
			$item_id = $this->get_mapped_dest( 'image', (int) $ngg_pid );
			if ( $item_id > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->update(
					$this->ta,
					[ 'cover_item_id' => $item_id, 'updated_at' => current_time( 'mysql' ) ],
					[ 'id' => $mlgp_alb_id ],
					[ '%d', '%s' ],
					[ '%d' ]
				);
				unset( $album_pending[ $mlgp_alb_id ] );
				$album_updated = true;
			}
		}

		if ( $album_updated ) {
			update_option( 'mlgmp_pending_album_covers', $album_pending, false );
		}
	}

	// -----------------------------------------------------------------------
	// Slug helpers
	// -----------------------------------------------------------------------

	private function unique_slug( string $base, string $type ): string {
		global $wpdb;
		$table = 'gallery' === $type ? $this->tg : $this->ta;
		$slug  = $base;
		$i     = 1;

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $slug )
			);
			if ( ! $exists ) {
				break;
			}
			$slug = $base . '-' . $i++;
		}

		return $slug;
	}

	private function get_gallery_slug( int $mlgp_gallery_id ): string {
		global $wpdb;
		if ( $mlgp_gallery_id <= 0 ) {
			return 'error-no-id-' . time();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$slug = $wpdb->get_var(
			$wpdb->prepare( "SELECT slug FROM {$this->tg} WHERE id = %d LIMIT 1", $mlgp_gallery_id )
		);
		return $slug ?: 'galeria-' . $mlgp_gallery_id;
	}

	private function get_gallery_slug_from_item( int $item_id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$gallery_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT gallery_id FROM {$this->ti} WHERE id = %d LIMIT 1",
				$item_id
			)
		);
		return $this->get_gallery_slug( $gallery_id );
	}

	// -----------------------------------------------------------------------
	// Result helper
	// -----------------------------------------------------------------------

	/** @return array<string,mixed> */
	private function result( int $processed, bool $stage_done, bool $all_done ): array {
		return [
			'stage'      => $this->state['current_stage'],
			'processed'  => $processed,
			'stage_done' => $stage_done,
			'all_done'   => $all_done,
			'state'      => $this->state,
		];
	}

	// -----------------------------------------------------------------------
	// Analysis (called before migration starts)
	// -----------------------------------------------------------------------

	public static function analyse(): array {
		$detection = NGGReader::detect();

		// Need a session_id for logging — use existing or create temp.
		$state      = State::get();
		$session_id = $state['session_id'] ?: 'analyse-' . time();
		$log        = new Logger( $session_id, 'analyse' );

		$log->info( 'Plugin NGG ativo: ' . ( $detection['plugin_active'] ? 'SIM' : 'NÃO' ) );
		$log->info( 'Tabelas NGG encontradas: ' . ( $detection['tables_found'] ? implode( ', ', $detection['tables_found'] ) : 'NENHUMA' ) );

		if ( $detection['tables_missing'] ) {
			$log->warning( 'Tabelas NGG ausentes: ' . implode( ', ', $detection['tables_missing'] ) );
		}

		$ngg_ok = ! empty( $detection['tables_found'] ) || $detection['plugin_active'];

		if ( ! $ngg_ok ) {
			$msg = 'NextGEN Gallery não detectado. Plugin ativo: '
			     . ( $detection['plugin_active'] ? 'SIM' : 'NÃO' )
			     . ' | Tabelas: nenhuma encontrada no banco "' . $detection['db_name'] . '"';
			$log->error( $msg );
			return [ 'error' => $msg ];
		}

		$counts = NGGReader::count_all();
		$log->info( sprintf(
			'Acervo: %d galeria(s), %d álbum(ns), %d imagem(ns), %d shortcode(s).',
			$counts['galleries'],
			$counts['albums'],
			$counts['images'],
			$counts['shortcodes']
		) );

		return $counts;
	}
}
