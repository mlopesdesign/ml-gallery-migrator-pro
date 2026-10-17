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

		// Licença: sem teste/licença, iniciar e continuar a migração ficam travados.
		// Análise, pausar, cancelar, zerar e acompanhar continuam liberados.
		if ( in_array( $action, [ 'mlgmp_start', 'mlgmp_batch', 'mlgmp_resume' ], true ) && License::is_locked() ) {
			$state = State::get();
			if ( 'mlgmp_batch' === $action && State::STATUS_RUNNING === $state['status'] ) {
				// Migração em andamento quando a licença venceu: pausa (pode retomar depois de ativar).
				$state['status'] = State::STATUS_PAUSED;
				State::save( $state );
				$log = new Logger( $state['session_id'], $state['current_stage'] );
				$log->warning( __( 'Migração pausada: sem licença ativa neste site.', 'ml-gallery-migrator-pro' ) );
			}
			wp_send_json_error(
				[
					'message' => License::locked_message(),
					'locked'  => true,
					'state'   => $state,
				],
				403
			);
		}

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
		$params = $this->get_ui_params();
		$source = isset( $params['source'] ) ? $params['source'] : ( State::get()['source'] ?? 'ngg' );
		$result = Migrator::analyse( $source );
		$state  = State::get();
		$state['analysis'] = $result;
		if ( empty( $state['analyses'] ) || ! is_array( $state['analyses'] ) ) {
			$state['analyses'] = [
				'ngg'           => null,
				'photo_gallery' => null,
			];
		}
		$state['analyses'][ $source ] = $result;
		$state['source']   = $source;
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
		$state = State::get();

		// Configuration comes ONLY from saved settings (mlgmp_settings).
		// batch_size, stages_enabled and duplicate_mode are NOT read from POST/UI.
		$settings = Config::get_all();
		$params   = [];

		$params['batch_size']     = (int) $settings['batch_size'];
		$params['duplicate_mode'] = (string) $settings['duplicate_mode'];

		// Build stages_enabled from persisted config.
		$config_stages  = is_array( $settings['enabled_stages'] ) ? $settings['enabled_stages'] : [];
		$all_stages     = [ 'galleries', 'albums', 'images', 'thumbs', 'shortcodes' ];
		$stages_enabled = [];
		foreach ( $all_stages as $s ) {
			$stages_enabled[ $s ] = ! empty( $config_stages[ $s ] );
		}
		$params['stages_enabled'] = $stages_enabled;

		// Source and mode are UI/session concerns, not persistent config.
		$ui = $this->get_ui_params();
		if ( ! empty( $ui['source'] ) ) {
			$params['source'] = $ui['source'];
		}
		if ( ! empty( $ui['mode'] ) ) {
			$params['mode'] = $ui['mode'];
		}

		// Validacao: pelo menos uma etapa selecionada.
		$enabled_stages = array_keys( array_filter( $params['stages_enabled'] ) );
		if ( empty( $enabled_stages ) ) {
			wp_send_json_error( [ 'message' => __( 'Selecione pelo menos uma etapa para iniciar a migração.', 'ml-gallery-migrator-pro' ) ], 400 );
		}

		// Se ja estiver em meio a uma sessao READY (single batch), apenas retoma mantendo cursores.
		if ( $state['status'] === State::STATUS_READY ) {
			$state = State::resume_from_ready( $state, $params );
			$log   = new Logger( $state['session_id'], $state['current_stage'] );
			$log->info( sprintf( __( 'Próximo lote iniciado. Modo: %1$s, lote: %2$d', 'ml-gallery-migrator-pro' ), $state['mode'], $state['batch_size'] ) );
			wp_send_json_success( [ 'state' => $state ] );
			return;
		}

		// Caso contrario (IDLE, DONE, CANCELLED), comecamos um "novo" ciclo de cursores
		// mas preservando contadores totais conforme pedido pelo usuario.

		if ( $state['status'] === State::STATUS_RUNNING || $state['status'] === State::STATUS_PAUSED ) {
			wp_send_json_error( [ 'message' => __( 'Migração já em curso ou pausada. Use Retomar.', 'ml-gallery-migrator-pro' ) ], 400 );
		}

		// Se nao houver sessao ativa, gera uma.
		if ( empty( $state['session_id'] ) ) {
			$state = State::reset();
		} else {
			// Reinicia cursores para permitir re-passagem (ex: overwrite ou novas imagens).
			// Mas mantemos os numeros de processados/erros/etc do historico.
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
	 * Chamado pelo JS apos 1 lote em modo single.
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

		// Configuration comes ONLY from saved settings (mlgmp_settings).
		// batch_size, stages_enabled and duplicate_mode are NOT read from POST/UI.
		$settings = Config::get_all();
		$params   = [];

		$params['batch_size']     = (int) $settings['batch_size'];
		$params['duplicate_mode'] = (string) $settings['duplicate_mode'];

		$config_stages  = is_array( $settings['enabled_stages'] ) ? $settings['enabled_stages'] : [];
		$all_stages     = [ 'galleries', 'albums', 'images', 'thumbs', 'shortcodes' ];
		$stages_enabled = [];
		foreach ( $all_stages as $s ) {
			$stages_enabled[ $s ] = ! empty( $config_stages[ $s ] );
		}
		$params['stages_enabled'] = $stages_enabled;

		// Mode (single/continuous) is a UI session preference, not persistent config.
		$ui = $this->get_ui_params();
		if ( ! empty( $ui['mode'] ) ) {
			$params['mode'] = $ui['mode'];
		}

		$state = State::resume_from_ready( $state, $params );

		$log = new Logger( $state['session_id'], $state['current_stage'] );
		$log->info( sprintf( __( 'Migração retomada. Modo: %1$s, lote: %2$d', 'ml-gallery-migrator-pro' ), $state['mode'], $state['batch_size'] ) );

		wp_send_json_success( [ 'state' => $state ] );
	}

	/**
	 * Helper para extrair parametros do UI.
	 * Usado apenas para: source, mode.
	 * batch_size, stages_enabled e duplicate_mode NAO sao lidos daqui para mlgmp_start/mlgmp_resume.
	 * Esses valores vem exclusivamente de Config::get_all().
	 */
	private function get_ui_params(): array {
		$params = [];
		if ( isset( $_POST['mode'] ) ) {
			$params['mode'] = sanitize_key( wp_unslash( $_POST['mode'] ) );
		}
		if ( isset( $_POST['source'] ) ) {
			$params['source'] = sanitize_key( wp_unslash( $_POST['source'] ) );
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
