<?php
/**
 * Persistent migration state stored in wp_options.
 *
 * State keys:
 *   session_id      – unique run identifier (UUID-like)
 *   status          – idle | running | paused | done | cancelled | error
 *   mode            – single | continuous
 *   batch_size      – int (default 20)
 *   current_stage   – galleries | albums | images | thumbs | shortcodes | done
 *   cursors         – associative array per stage (persistent cursor IDs)
 *   counters        – processed / skipped / errors / orphans per stage
 *   totals          – total counts discovered in analysis phase
 *   started_at      – timestamp
 *   updated_at      – timestamp
 *   analysis        – last analysis result
 *
 * @package MLGalleryMigratorPro
 */

namespace MLGMP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class State {

	const OPTION_KEY = 'mlgmp_state';

	const STATUS_IDLE      = 'idle';
	const STATUS_RUNNING   = 'running';
	const STATUS_PAUSED    = 'paused';
	const STATUS_READY     = 'ready';    // Entre-lotes (single mode) — cursores preservados.
	const STATUS_DONE      = 'done';
	const STATUS_CANCELLED = 'cancelled';
	const STATUS_ERROR     = 'error';

	const STAGE_GALLERIES  = 'galleries';
	const STAGE_ALBUMS     = 'albums';
	const STAGE_ALBUMS_REWIRE = 'albums_rewire';
	const STAGE_IMAGES     = 'images';
	const STAGE_THUMBS     = 'thumbs';
	const STAGE_SHORTCODES = 'shortcodes';
	const STAGE_DONE       = 'done';

	const STAGES = [
		self::STAGE_GALLERIES,
		self::STAGE_ALBUMS,
		self::STAGE_ALBUMS_REWIRE,
		self::STAGE_IMAGES,
		self::STAGE_THUMBS,
		self::STAGE_SHORTCODES,
		self::STAGE_DONE,
	];

	// -----------------------------------------------------------------------
	// Default state skeleton
	// -----------------------------------------------------------------------

	public static function default(): array {
		return [
			'session_id'      => '',
			'status'          => self::STATUS_IDLE,
			'mode'            => 'continuous',
			'batch_size'      => 20,
			'current_stage'   => self::STAGE_GALLERIES,
			'cursors'         => [
				self::STAGE_GALLERIES     => 0,
				self::STAGE_ALBUMS        => 0,
				self::STAGE_ALBUMS_REWIRE => 0,
				self::STAGE_IMAGES        => 0,
				self::STAGE_THUMBS        => 0,
				self::STAGE_SHORTCODES    => 0,
			],
			'counters'        => self::empty_counters(),
			'totals'          => self::empty_totals(),
			'started_at'      => '',
			'updated_at'      => '',
			'analysis'        => null,
			// Etapas habilitadas: todas ativas por padrão.
			'stages_enabled'  => [
				self::STAGE_GALLERIES     => true,
				self::STAGE_ALBUMS        => true,
				self::STAGE_ALBUMS_REWIRE => true,
				self::STAGE_IMAGES        => true,
				self::STAGE_THUMBS        => true,
				self::STAGE_SHORTCODES    => true,
			],
			// Modo de duplicação: 'ignore' (pula existentes) | 'overwrite' (atualiza).
			'duplicate_mode'  => 'ignore',
		];
	}

	private static function empty_counters(): array {
		$c = [];
		foreach ( self::STAGES as $s ) {
			$c[ $s ] = [
				'processed'               => 0,
				'skipped'                 => 0,
				'errors'                  => 0,
				'orphans'                 => 0,
				'ignored_mapping'         => 0,
				'ignored_revision'        => 0,
				'ignored_draft'           => 0,
				'ignored_widget_orphan' => 0,
				'analyzed_contents'       => 0,
				'found_shortcodes'        => 0,
			];
		}
		return $c;
	}

	private static function empty_totals(): array {
		return [
			'galleries'  => 0,
			'albums'     => 0,
			'images'     => 0,
			'thumbs'     => 0,
			'shortcodes' => 0,
		];
	}

	// -----------------------------------------------------------------------
	// Read / Write
	// -----------------------------------------------------------------------

	/** @return array<string,mixed> */
	public static function get(): array {
		$saved = get_option( self::OPTION_KEY, [] );
		return is_array( $saved ) ? wp_parse_args( $saved, self::default() ) : self::default();
	}

	/** @param array<string,mixed> $state */
	public static function save( array $state ): void {
		$state['updated_at'] = current_time( 'mysql' );
		update_option( self::OPTION_KEY, $state, false );
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Reset completo: gera novo session_id, zera cursores/contadores.
	 * Preserva analysis e totals.
	 */
	public static function reset(): array {
		$old_analysis         = self::get()['analysis'] ?? null;
		$old_totals           = self::get()['totals'] ?? self::empty_totals();
		$fresh                = self::default();
		$fresh['session_id']  = self::generate_session_id();
		$fresh['started_at']  = current_time( 'mysql' );
		$fresh['analysis']    = $old_analysis;
		$fresh['totals']      = $old_totals;
		self::save( $fresh );
		return $fresh;
	}

	/**
	 * Retomar do estado READY: mantém session_id, cursores e contadores.
	 * Apenas muda status para RUNNING. Atualiza parâmetros se fornecidos.
	 */
	public static function resume_from_ready( array $state, array $params = [] ): array {
		$state['status'] = self::STATUS_RUNNING;
		self::set_params( $state, $params );
		self::save( $state );
		return $state;
	}

	/**
	 * Atualiza configurações de runtime (mode, batch, stages, etc)
	 */
	public static function set_params( array &$state, array $params ): void {
		if ( isset( $params['mode'] ) && in_array( $params['mode'], [ 'continuous', 'single' ], true ) ) {
			$state['mode'] = $params['mode'];
		}
		if ( isset( $params['batch_size'] ) ) {
			$state['batch_size'] = max( 1, (int) $params['batch_size'] );
		}
		if ( isset( $params['stages_enabled'] ) && is_array( $params['stages_enabled'] ) ) {
			foreach ( self::STAGE_DONE !== $params['stages_enabled'] ? $params['stages_enabled'] : [] as $s => $val ) {
				if ( isset( $state['stages_enabled'][ $s ] ) ) {
					$state['stages_enabled'][ $s ] = (bool) $val;
				}
			}
			if ( isset( $params['stages_enabled'][ self::STAGE_ALBUMS ] ) ) {
				$state['stages_enabled'][ self::STAGE_ALBUMS_REWIRE ] = (bool) $params['stages_enabled'][ self::STAGE_ALBUMS ];
			}
		}
		if ( isset( $params['duplicate_mode'] ) && in_array( $params['duplicate_mode'], [ 'ignore', 'overwrite' ], true ) ) {
			$state['duplicate_mode'] = $params['duplicate_mode'];
		}
	}

	public static function generate_session_id(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000,
			mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);
	}

	/** Advance stage cursor to next stage. */
	public static function advance_stage( array &$state ): void {
		$idx = array_search( $state['current_stage'], self::STAGES, true );
		if ( false === $idx ) {
			$state['current_stage'] = self::STAGE_DONE;
			return;
		}
		$next = self::STAGES[ $idx + 1 ] ?? self::STAGE_DONE;
		$state['current_stage'] = $next;
	}

	/** Increment one counter key inside the current stage. */
	public static function inc( array &$state, string $counter = 'processed', int $amount = 1 ): void {
		$stage = $state['current_stage'];
		$state['counters'][ $stage ][ $counter ] = ( $state['counters'][ $stage ][ $counter ] ?? 0 ) + $amount;
	}

	/** Total processed across all stages. */
	public static function total_processed( array $state ): int {
		$n = 0;
		foreach ( $state['counters'] as $c ) {
			$n += (int) ( $c['processed'] ?? 0 );
		}
		return $n;
	}

	/** Grand total items. */
	public static function grand_total( array $state ): int {
		return array_sum( $state['totals'] );
	}
}
