<?php
/**
 * Read-only reliability reporting layer for migration reconciliation.
 *
 * @package MLGalleryMigratorPro
 */

namespace MLGMP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Reliability {

	/**
	 * @return array<string,mixed>
	 */
	public static function build_report( string $source, array $state ): array {
		$source      = 'photo_gallery' === $source ? 'photo_gallery' : 'ngg';
		$source_meta = self::source_meta( $source );
		$source_data = self::source_snapshot( $source, $source_meta );
		$dest_data   = self::destination_snapshot();
		$recon_data  = self::reconciliation_summary( $source, $state, $source_data, $dest_data );
		$status_data = self::integrity_status( $source, $state, $source_data, $dest_data, $recon_data );

		return [
			'source'      => $source,
			'source_meta' => $source_meta,
			'source_card' => $source_data,
			'dest_card'   => $dest_data,
			'recon_card'  => $recon_data,
			'status_card' => $status_data,
			'report_text' => self::report_text( $source_meta, $source_data, $dest_data, $recon_data, $status_data ),
		];
	}

	/**
	 * @return array<string,string>
	 */
	private static function source_meta( string $source ): array {
		if ( 'photo_gallery' === $source ) {
			return [
				'label' => 'Photo Gallery',
				'short' => 'Photo Gallery',
			];
		}

		return [
			'label' => 'NextGEN Gallery',
			'short' => 'NGG',
		];
	}

	/**
	 * @param array<string,string> $source_meta
	 * @return array<string,mixed>
	 */
	private static function source_snapshot( string $source, array $source_meta ): array {
		$detection = 'photo_gallery' === $source ? PhotoGalleryReader::detect() : NGGReader::detect();
		$counts    = 'photo_gallery' === $source ? PhotoGalleryReader::count_all() : NGGReader::count_all();

		return [
			'label'          => $source_meta['label'],
			'active'         => ! empty( $detection['plugin_active'] ),
			'detected'       => ! empty( $detection['plugin_active'] ) || ! empty( $detection['tables_found'] ),
			'tables_found'   => array_values( $detection['tables_found'] ),
			'tables_missing' => array_values( $detection['tables_missing'] ),
			'db_name'        => (string) $detection['db_name'],
			'metrics'        => [
				'galleries'  => (int) ( $counts['galleries'] ?? 0 ),
				'albums'     => (int) ( $counts['albums'] ?? 0 ),
				'images'     => (int) ( $counts['images'] ?? 0 ),
				'shortcodes' => (int) ( $counts['shortcodes'] ?? 0 ),
			],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function destination_snapshot(): array {
		global $wpdb;

		$tables = [
			'galleries'   => $wpdb->prefix . 'mlgp_galleries',
			'albums'      => $wpdb->prefix . 'mlgp_albums',
			'items'       => $wpdb->prefix . 'mlgp_gallery_items',
			'album_links' => $wpdb->prefix . 'mlgp_album_items',
		];

		$metrics = [
			'galleries'     => 0,
			'albums'        => 0,
			'items'         => 0,
			'album_links'   => 0,
			'gallery_dirs'  => 0,
		];

		$available = [];
		foreach ( $tables as $key => $table ) {
			$available[ $key ] = self::table_exists( $table );
			if ( $available[ $key ] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$metrics[ $key ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			}
		}

		$media_root = trailingslashit( WP_CONTENT_DIR ) . 'ml-gallery';
		if ( is_dir( $media_root ) ) {
			$entries = scandir( $media_root );
			if ( is_array( $entries ) ) {
				foreach ( $entries as $entry ) {
					if ( '.' === $entry || '..' === $entry || 'index.php' === $entry ) {
						continue;
					}

					if ( is_dir( $media_root . DIRECTORY_SEPARATOR . $entry ) ) {
						$metrics['gallery_dirs']++;
					}
				}
			}
		}

		// Logical item count: collapse basename variants (jpg+webp = 1 logical image).
		$metrics['items_logical'] = $metrics['items'];
		if ( $available['items'] && $metrics['items'] > 0 ) {
			$ti = $wpdb->prefix . 'mlgp_gallery_items';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$filenames = $wpdb->get_col( "SELECT file_name FROM {$ti} WHERE file_name != '' AND file_name IS NOT NULL LIMIT 500000" );
			if ( ! empty( $filenames ) ) {
				$families = [];
				$raster   = [ 'jpg', 'jpeg', 'png', 'gif', 'bmp' ];
				foreach ( $filenames as $fn ) {
					$fn       = (string) $fn;
					$ext      = strtolower( pathinfo( $fn, PATHINFO_EXTENSION ) );
					$basename = strtolower( pathinfo( $fn, PATHINFO_FILENAME ) );
					if ( ! isset( $families[ $basename ] ) ) {
						$families[ $basename ] = $ext;
					} elseif ( 'webp' === $ext ) {
						$families[ $basename ] = 'webp'; // webp wins.
					}
				}
				$metrics['items_logical'] = count( $families );
			}
		}

		return [
			'ready'      => $available['galleries'] && $available['albums'] && $available['items'],
			'media_root' => $media_root,
			'available'  => $available,
			'metrics'    => $metrics,
		];
	}

	/**
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $source_data
	 * @param array<string,mixed> $dest_data
	 * @return array<string,mixed>
	 */
	private static function reconciliation_summary( string $source, array $state, array $source_data, array $dest_data ): array {
		global $wpdb;

		// session_matches: current state session exists and matches the selected source.
		// If it doesn't match (source changed, reset, etc.) we still show cross-session data.
		$session_matches = ! empty( $state['session_id'] ) && ( $state['source'] ?? 'ngg' ) === $source;
		$mapped          = [
			'gallery' => 0,
			'album'   => 0,
			'image'   => 0,
			'orphan'  => 0,
		];
		$log_levels       = [
			'error'   => 0,
			'warning' => 0,
			'orphan'  => 0,
		];

		$map_table = Installer::tables()['id_map'];

		if ( $session_matches ) {
			// Use current session.
			$query_session = $state['session_id'];
		} else {
			// Fallback: find the most recent session in id_map with 'done' records.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$query_session = (string) ( $wpdb->get_var(
				"SELECT session_id FROM {$map_table}
				 WHERE status = 'done' AND entity_type = 'image'
				 ORDER BY id DESC LIMIT 1"
			) ?? '' );
		}

		if ( $query_session ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT entity_type, status, COUNT(*) AS total
					 FROM {$map_table}
					 WHERE session_id = %s
					 GROUP BY entity_type, status",
					$query_session
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $rows as $row ) {
				$type   = (string) ( $row['entity_type'] ?? '' );
				$status = (string) ( $row['status'] ?? '' );
				$total  = (int) ( $row['total'] ?? 0 );

				if ( 'orphan' === $status ) {
					$mapped['orphan'] += $total;
					continue;
				}

				if ( 'done' === $status && isset( $mapped[ $type ] ) ) {
					$mapped[ $type ] += $total;
				}
			}
		}

		if ( $session_matches && ! empty( $state['session_id'] ) ) {
			$log_table = Installer::tables()['migration_log'];
			$log_rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT level, COUNT(*) AS total
					 FROM {$log_table}
					 WHERE session_id = %s
					 GROUP BY level",
					$state['session_id']
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $log_rows as $row ) {
				$level = (string) ( $row['level'] ?? '' );
				if ( isset( $log_levels[ $level ] ) ) {
					$log_levels[ $level ] = (int) ( $row['total'] ?? 0 );
				}
			}
		}

		$source_metrics = (array) ( $source_data['metrics'] ?? [] );
		$shortcodes     = $session_matches ? (int) ( $state['counters'][ State::STAGE_SHORTCODES ]['processed'] ?? 0 ) : 0;
		$remaining      = [
			'galleries'  => max( 0, (int) ( $source_metrics['galleries'] ?? 0 ) - $mapped['gallery'] ),
			'albums'     => max( 0, (int) ( $source_metrics['albums'] ?? 0 ) - $mapped['album'] ),
			'images'     => max( 0, (int) ( $source_metrics['images'] ?? 0 ) - $mapped['image'] - $mapped['orphan'] ),
			'shortcodes' => max( 0, (int) ( $source_metrics['shortcodes'] ?? 0 ) - $shortcodes ),
		];

		return [
			'session_matches' => $session_matches,
			'session_id'      => (string) ( $state['session_id'] ?? '' ),
			'status'          => (string) ( $state['status'] ?? State::STATUS_IDLE ),
			'current_stage'   => (string) ( $state['current_stage'] ?? State::STAGE_GALLERIES ),
			'mapped'          => $mapped,
			'log_levels'      => $log_levels,
			'shortcodes'      => $shortcodes,
			'remaining'       => $remaining,
			'coverage'        => [
				'galleries' => self::percentage( $mapped['gallery'], (int) ( $source_metrics['galleries'] ?? 0 ) ),
				'albums'    => self::percentage( $mapped['album'], (int) ( $source_metrics['albums'] ?? 0 ) ),
				'images'    => self::percentage( $mapped['image'], (int) ( $source_metrics['images'] ?? 0 ) ),
			],
			'destination_items'         => (int) ( $dest_data['metrics']['items'] ?? 0 ),
		'destination_items_logical' => (int) ( $dest_data['metrics']['items_logical'] ?? $dest_data['metrics']['items'] ?? 0 ),
		];
	}

	/**
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $source_data
	 * @param array<string,mixed> $dest_data
	 * @param array<string,mixed> $recon_data
	 * @return array<string,mixed>
	 */
	private static function integrity_status( string $source, array $state, array $source_data, array $dest_data, array $recon_data ): array {
		$source_ready      = ! empty( $source_data['detected'] );
		$destination_ready = ! empty( $dest_data['ready'] );
		$session_matches   = ! empty( $recon_data['session_matches'] );
		$errors            = (int) ( $recon_data['log_levels']['error'] ?? 0 );
		$orphans           = (int) ( $recon_data['mapped']['orphan'] ?? 0 );
		$status            = (string) ( $state['status'] ?? State::STATUS_IDLE );

		$rows = [
			[
				'label' => __( 'Origem lida', 'ml-gallery-migrator-pro' ),
				'value' => $source_ready ? __( 'Detectada com sucesso', 'ml-gallery-migrator-pro' ) : __( 'Não detectada', 'ml-gallery-migrator-pro' ),
				'tone'  => $source_ready ? 'success' : 'danger',
			],
			[
				'label' => __( 'Destino pronto', 'ml-gallery-migrator-pro' ),
				'value' => $destination_ready ? __( 'Estrutura ML Gallery Pro disponível', 'ml-gallery-migrator-pro' ) : __( 'Tabelas principais ausentes', 'ml-gallery-migrator-pro' ),
				'tone'  => $destination_ready ? 'success' : 'danger',
			],
			[
				'label' => __( 'Escopo da sessão', 'ml-gallery-migrator-pro' ),
				'value' => $session_matches ? __( 'Sessão alinhada com a origem selecionada', 'ml-gallery-migrator-pro' ) : __( 'Sem sessão ativa desta origem', 'ml-gallery-migrator-pro' ),
				'tone'  => $session_matches ? 'success' : 'neutral',
			],
			[
				'label' => __( 'Saúde operacional', 'ml-gallery-migrator-pro' ),
				'value' => self::status_label( $status ),
				'tone'  => self::status_tone( $status ),
			],
			[
				'label' => __( 'Erros registrados', 'ml-gallery-migrator-pro' ),
				'value' => sprintf( _n( '%d erro', '%d erros', $errors, 'ml-gallery-migrator-pro' ), $errors ),
				'tone'  => $errors > 0 ? 'warning' : 'success',
			],
			[
				'label' => __( 'Órfãos conciliados', 'ml-gallery-migrator-pro' ),
				'value' => sprintf( _n( '%d item órfão', '%d itens órfãos', $orphans, 'ml-gallery-migrator-pro' ), $orphans ),
				'tone'  => $orphans > 0 ? 'warning' : 'success',
			],
		];

		$overall_tone = 'neutral';
		$headline     = __( 'Camada de confiabilidade pronta para auditoria.', 'ml-gallery-migrator-pro' );

		if ( ! $source_ready || ! $destination_ready ) {
			$overall_tone = 'danger';
			$headline     = __( 'Dependências da auditoria ainda não estão completas.', 'ml-gallery-migrator-pro' );
		} elseif ( $errors > 0 || $orphans > 0 ) {
			$overall_tone = 'warning';
			$headline     = __( 'A reconciliação encontrou pontos que merecem validação final.', 'ml-gallery-migrator-pro' );
		} elseif ( $session_matches && State::STATUS_DONE === $status ) {
			$overall_tone = 'success';
			$headline     = __( 'Sessão concluída com sinais consistentes de integridade.', 'ml-gallery-migrator-pro' );
		}

		return [
			'rows'         => $rows,
			'overall_tone' => $overall_tone,
			'headline'     => $headline,
			'source'       => $source,
		];
	}

	/**
	 * @param array<string,string> $source_meta
	 * @param array<string,mixed>  $source_data
	 * @param array<string,mixed>  $dest_data
	 * @param array<string,mixed>  $recon_data
	 * @param array<string,mixed>  $status_data
	 */
	private static function report_text( array $source_meta, array $source_data, array $dest_data, array $recon_data, array $status_data ): string {
		$source_metrics = (array) $source_data['metrics'];
		$dest_metrics   = (array) $dest_data['metrics'];
		$mapped         = (array) $recon_data['mapped'];
		$remaining      = (array) $recon_data['remaining'];

		$lines   = [];
		$lines[] = 'ML Gallery Migrator Pro - Final Integrity Report';
		$lines[] = 'Origem: ' . $source_meta['label'];
		$lines[] = 'Status: ' . (string) $status_data['headline'];
		$lines[] = '';
		$lines[] = 'Source Snapshot';
		$lines[] = sprintf(
			'Galleries: %d | Albums: %d | Images: %d | Shortcodes: %d',
			(int) $source_metrics['galleries'],
			(int) $source_metrics['albums'],
			(int) $source_metrics['images'],
			(int) $source_metrics['shortcodes']
		);
		$lines[] = '';
		$lines[] = 'Destination Snapshot';
		$lines[] = sprintf(
			'Galleries: %d | Albums: %d | Items: %d | Album Links: %d | Gallery Dirs: %d',
			(int) $dest_metrics['galleries'],
			(int) $dest_metrics['albums'],
			(int) $dest_metrics['items'],
			(int) $dest_metrics['album_links'],
			(int) $dest_metrics['gallery_dirs']
		);
		$lines[] = '';
		$lines[] = 'Reconciliation Summary';
		$lines[] = sprintf(
			'Mapped Galleries: %d | Mapped Albums: %d | Mapped Images: %d | Orphans: %d | Converted Shortcodes: %d',
			(int) $mapped['gallery'],
			(int) $mapped['album'],
			(int) $mapped['image'],
			(int) $mapped['orphan'],
			(int) $recon_data['shortcodes']
		);
		$lines[] = sprintf(
			'Remaining Galleries: %d | Remaining Albums: %d | Remaining Images: %d | Remaining Shortcodes: %d',
			(int) $remaining['galleries'],
			(int) $remaining['albums'],
			(int) $remaining['images'],
			(int) $remaining['shortcodes']
		);

		return implode( "\n", $lines );
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return (bool) $exists;
	}

	private static function percentage( int $part, int $total ): int {
		if ( $total <= 0 ) {
			return 0;
		}

		return (int) min( 100, round( ( $part / $total ) * 100 ) );
	}

	private static function status_label( string $status ): string {
		$map = [
			State::STATUS_IDLE      => __( 'Aguardando', 'ml-gallery-migrator-pro' ),
			State::STATUS_RUNNING   => __( 'Executando', 'ml-gallery-migrator-pro' ),
			State::STATUS_PAUSED    => __( 'Pausado', 'ml-gallery-migrator-pro' ),
			State::STATUS_READY     => __( 'Pronto para o próximo lote', 'ml-gallery-migrator-pro' ),
			State::STATUS_DONE      => __( 'Concluído', 'ml-gallery-migrator-pro' ),
			State::STATUS_CANCELLED => __( 'Cancelado', 'ml-gallery-migrator-pro' ),
			State::STATUS_ERROR     => __( 'Erro', 'ml-gallery-migrator-pro' ),
		];

		return $map[ $status ] ?? $status;
	}

	private static function status_tone( string $status ): string {
		switch ( $status ) {
			case State::STATUS_DONE:
				return 'success';
			case State::STATUS_RUNNING:
			case State::STATUS_READY:
				return 'neutral';
			case State::STATUS_PAUSED:
			case State::STATUS_CANCELLED:
				return 'warning';
			case State::STATUS_ERROR:
				return 'danger';
			default:
				return 'neutral';
		}
	}
}
