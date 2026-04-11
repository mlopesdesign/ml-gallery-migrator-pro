<?php
/**
 * AJAX handlers.
 *
 * All actions require: nonce = mlgmp_ajax, capability = manage_options.
 *
 * Actions:
 *   mlgmp_analyse      – Run analysis, store in state.
 *   mlgmp_start        – Start (or restart from same session) migration.
 *   mlgmp_batch        – Run one batch (called repeatedly by JS in continuous mode).
 *   mlgmp_pause        – Set status = paused.
 *   mlgmp_resume       – Set status = running.
 *   mlgmp_cancel       – Set status = cancelled.
 *   mlgmp_reset        – Full reset of state + log.
 *   mlgmp_poll_state   – Return current state + new log entries (polling).
 *
 * @package MLGalleryMigratorPro
 */

namespace MLGMP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ajax {

	private static ?Ajax $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	// -----------------------------------------------------------------------
	// Boot
	// -----------------------------------------------------------------------

	public function boot(): void {
		$actions = [
			'mlgmp_analyse',
			'mlgmp_start',
			'mlgmp_ready',
			'mlgmp_batch',
			'mlgmp_pause',
			'mlgmp_resume',
			'mlgmp_cancel',
			'mlgmp_reset',
			'mlgmp_poll_state',
		];

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, [ $this, 'dispatch' ] );
		}
	}

	// -----------------------------------------------------------------------
	// Dispatcher
	// -----------------------------------------------------------------------

	public function dispatch(): void {
		// Auth.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}

		// Nonce.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nonce = isset( $_REQUEST['nonce'] ) ? wp_unslash( $_REQUEST['nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mlgmp_ajax' ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$action = sanitize_key( isset( $_REQUEST['action'] ) ? wp_unslash( $_REQUEST['action'] ) : '' );

		switch ( $action ) {
			case 'mlgmp_analyse':
				$this->handle_analyse();
				break;
			case 'mlgmp_start':
				$this->handle_start();
				break;
			case 'mlgmp_ready':
				$this->handle_ready();
				break;
			case 'mlgmp_batch':
				$this->handle_batch();
				break;
			case 'mlgmp_pause':
				$this->handle_pause();
				break;
			case 'mlgmp_resume':
				$this->handle_resume();
				break;
			case 'mlgmp_cancel':
				$this->handle_cancel();
				break;
			case 'mlgmp_reset':
				$this->handle_reset();
				break;
			case 'mlgmp_poll_state':
				$this->handle_poll();
				break;
			default:
				wp_send_json_error( [ 'message' => 'Unknown action' ], 400 );
		}
	}

	// -----------------------------------------------------------------------
	// Handlers
	// -----------------------------------------------------------------------

	private function handle_analyse(): void {
		$result = Migrator::analyse();
		$state  = State::get();
		$state['analysis'] = $result;
		if ( isset( $result['galleries'] ) ) {
			$state['totals'] = [
				'galleries'  => (int) $result['galleries'],
				'albums'     => (int) $result['albums'],
				'images'     => (int) $result['images'],
				'thumbs'     => (int) $result['thumbs'],
				'shortcodes' => (int) $result['shortcodes'],
			];
		}
		State::save( $state );
		wp_send_json_success( [ 'analysis' => $result, 'state' => $state ] );
	}

	private function handle_start(): void {
		$state  = State::get();
		$params = $this->get_ui_params();

		// Validação: pelo menos uma etapa selecionada.
		$enabled_stages = array_keys( array_filter( $params['stages_enabled'] ?? [] ) );
		if ( empty( $enabled_stages ) ) {
			wp_send_json_error( [ 'message' => __( 'Selecione pelo menos uma etapa para iniciar a migração.', 'ml-gallery-migrator-pro' ) ], 400 );
		}

		// Se já estiver em meio a uma sessão READY (single batch), apenas retoma mantendo cursores.
		if ( $state['status'] === State::STATUS_READY ) {
			$state = State::resume_from_ready( $state, $params );
			$log   = new Logger( $state['session_id'], $state['current_stage'] );
			$log->info( sprintf( __( 'Próximo lote iniciado. Modo: %1$s, lote: %2$d', 'ml-gallery-migrator-pro' ), $state['mode'], $state['batch_size'] ) );
			wp_send_json_success( [ 'state' => $state ] );
			return;
		}

		// Caso contrário (IDLE, DONE, CANCELLED), começamos um "novo" ciclo de cursores 
		// mas preservando contadores totais conforme pedido pelo usuário.
		
		if ( $state['status'] === State::STATUS_RUNNING || $state['status'] === State::STATUS_PAUSED ) {
			wp_send_json_error( [ 'message' => __( 'Migração já em curso ou pausada. Use Retomar.', 'ml-gallery-migrator-pro' ) ], 400 );
		}

		// Se não houver sessão ativa, gera uma.
		if ( empty( $state['session_id'] ) ) {
			$state = State::reset();
		} else {
			// Reinicia cursores para permitir re-passagem (ex: overwrite ou novas imagens).
			// Mas mantemos os números de processados/erros/etc do histórico.
			$state['current_stage'] = State::STAGE_GALLERIES;
			foreach ( $state['cursors'] as $k => $v ) {
				$state['cursors'][ $k ] = 0;
			}
		}

		$state['status'] = State::STATUS_RUNNING;
		State::set_params( $state, $params );
		State::save( $state );

		$log = new Logger( $state['session_id'], 'init' );
		$log->info( sprintf(
			__( 'Migração iniciada. Modo: %1$s, lote: %2$d, etapas: [%3$s], duplicados: %4$s', 'ml-gallery-migrator-pro' ),
			$state['mode'],
			$state['batch_size'],
			implode( ', ', $enabled_stages ),
			$state['duplicate_mode']
		) );

		wp_send_json_success( [ 'state' => $state ] );
	}

	/**
	 * Chamado pelo JS após 1 lote em modo single.
	 * Seta status = READY (preserva cursores/contadores).
	 */
	private function handle_ready(): void {
		$state = State::get();
		if ( ! in_array( $state['status'], [ State::STATUS_RUNNING, State::STATUS_PAUSED ], true ) ) {
			wp_send_json_error( [ 'message' => 'Not running/paused' ], 400 );
		}
		$state['status'] = State::STATUS_READY;
		State::save( $state );
		$log = new Logger( $state['session_id'], $state['current_stage'] );
		$log->info( __( 'Lote concluído. Pronto para próximo lote.', 'ml-gallery-migrator-pro' ) );
		wp_send_json_success( [ 'state' => $state ] );
	}

	private function handle_batch(): void {
		$state = State::get();

		if ( $state['status'] !== State::STATUS_RUNNING ) {
			wp_send_json_error( [
				'message' => sprintf( __( 'Não está em execução (status=%s)', 'ml-gallery-migrator-pro' ), $state['status'] ),
				'state'   => $state,
			], 400 );
		}

		// Enforce timeout safety.
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$migrator = new Migrator();
		$result   = $migrator->run_batch();

		// Quick poll: return new log entries.
		$after_id = isset( $_POST['last_log_id'] ) ? absint( wp_unslash( $_POST['last_log_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$logs     = Logger::get_entries( $result['state']['session_id'], 50, $after_id );

		wp_send_json_success( [
			'batch_result' => $result,
			'logs'         => array_reverse( $logs ),
			'state'        => $result['state'],
		] );
	}

	private function handle_pause(): void {
		$state = State::get();

		if ( $state['status'] !== State::STATUS_RUNNING ) {
			wp_send_json_error( [ 'message' => 'Not running' ], 400 );
		}

		$state['status'] = State::STATUS_PAUSED;
		State::save( $state );

		$log = new Logger( $state['session_id'], $state['current_stage'] );
		$log->info( __( 'Migração pausada pelo usuário.', 'ml-gallery-migrator-pro' ) );

		wp_send_json_success( [ 'state' => $state ] );
	}

	private function handle_resume(): void {
		$state = State::get();

		if ( $state['status'] !== State::STATUS_PAUSED ) {
			wp_send_json_error( [ 'message' => 'Not paused' ], 400 );
		}

		$params = $this->get_ui_params();
		$state  = State::resume_from_ready( $state, $params );

		$log = new Logger( $state['session_id'], $state['current_stage'] );
		$log->info( sprintf( __( 'Migração retomada. Modo: %1$s, lote: %2$d', 'ml-gallery-migrator-pro' ), $state['mode'], $state['batch_size'] ) );

		wp_send_json_success( [ 'state' => $state ] );
	}

	/**
	 * Helper para extrair parâmetros do UI de forma consistente.
	 */
	private function get_ui_params(): array {
		$params = [];
		if ( isset( $_POST['mode'] ) ) {
			$params['mode'] = sanitize_key( wp_unslash( $_POST['mode'] ) );
		}
		if ( isset( $_POST['batch_size'] ) ) {
			$params['batch_size'] = absint( wp_unslash( $_POST['batch_size'] ) );
		}
		if ( isset( $_POST['duplicate_mode'] ) ) {
			$params['duplicate_mode'] = sanitize_key( wp_unslash( $_POST['duplicate_mode'] ) );
		}
		if ( isset( $_POST['stages_enabled'] ) ) {
			// Pode vir como array ou JSON dependendo do JS.
			$raw = wp_unslash( $_POST['stages_enabled'] );
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$params['stages_enabled'] = [];
				$all = [ 'galleries', 'albums', 'images', 'thumbs', 'shortcodes' ];
				foreach ( $all as $s ) {
					$params['stages_enabled'][ $s ] = in_array( $s, $decoded, true );
				}
			}
		}
		return $params;
	}

	private function handle_cancel(): void {
		$state = State::get();
		$state['status'] = State::STATUS_CANCELLED;
		State::save( $state );

		$log = new Logger( $state['session_id'], $state['current_stage'] );
		$log->warning( __( 'Migração cancelada pelo usuário.', 'ml-gallery-migrator-pro' ) );

		wp_send_json_success( [ 'state' => $state ] );
	}

	private function handle_reset(): void {
		$old_session = State::get()['session_id'];
		if ( $old_session ) {
			Logger::clear( $old_session );
		}
		// Clear pending cover stashes.
		delete_option( 'mlgmp_pending_gallery_covers' );
		delete_option( 'mlgmp_pending_album_covers' );

		$state = State::reset();
		$state['status'] = State::STATUS_IDLE;
		State::save( $state );

		wp_send_json_success( [ 'state' => $state ] );
	}

	private function handle_poll(): void {
		$state    = State::get();
		$after_id = isset( $_POST['last_log_id'] ) ? absint( wp_unslash( $_POST['last_log_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$logs = $state['session_id']
			? Logger::get_entries( $state['session_id'], 50, $after_id )
			: [];

		wp_send_json_success( [
			'state' => $state,
			'logs'  => array_reverse( $logs ),
		] );
	}
}
