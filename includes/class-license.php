<?php
/**
 * Licença do ML Gallery Migrator Pro.
 *
 * 1.1.0: a licença é emitida e conferida pelo site tools.mlopesdesign.com.br
 * (módulo ML License Hub, cliente ML_License_Client_V2). Regras do padrão ML:
 * 1 licença = 1 site (o Pacote Agência vale em vários sites); teste grátis de 30 dias
 * por site; sem teste/licença válidos, só fica travado iniciar/continuar a migração,
 * rodar os reparos que copiam ou recriam itens e gravar a configuração. As galerias já
 * migradas continuam no ar (são do ML Gallery Pro) e nenhum dado é apagado.
 *
 * Quem já usava o plugin antes da 1.1.0 recebe o teste grátis automaticamente na
 * primeira visita ao painel (o site não trava de surpresa após a atualização).
 *
 * @package MLGalleryMigratorPro
 */

namespace MLGMP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-ml-license-client.php';

final class License {

	const API_BASE    = 'https://tools.mlopesdesign.com.br/wp-json/mllh/v1';
	const PUBLIC_KEY  = 'VGZQp2X3RJMuDmQBlIEXS4fqsZD1CnFlIJgfVtY/pHo=';
	const PRODUCT_ID  = 'ml-gallery-migrator-pro';
	const BUY_URL     = 'https://tools.mlopesdesign.com.br/precos/';
	const ACCOUNT_URL = 'https://tools.mlopesdesign.com.br/minha-conta/';
	const AUTO_TRIAL  = 'mlgmp_auto_trial_tried';

	/** @var \ML_License_Client_V2|null */
	private static $client = null;

	/** @var bool|null Resultado guardado durante a requisição (zera quando a licença muda). */
	private static $locked = null;

	public static function client(): \ML_License_Client_V2 {
		if ( null === self::$client ) {
			self::$client = new \ML_License_Client_V2(
				[
					'api_base'   => self::API_BASE,
					'product'    => self::PRODUCT_ID,
					'public_key' => self::PUBLIC_KEY,
					'version'    => MLGMP_VERSION,
				]
			);
		}
		return self::$client;
	}

	/** Teste ou licença válidos neste site. */
	public static function is_active(): bool {
		return self::client()->is_premium();
	}

	/** Painel travado para gravar (sem teste nem licença). */
	public static function is_locked(): bool {
		if ( null === self::$locked ) {
			self::$locked = ! self::is_active();
		}
		return self::$locked;
	}

	public static function forget(): void {
		self::$locked = null;
	}

	public static function tab_url(): string {
		return admin_url( 'admin.php?page=' . MLGMP_SLUG . '&tab=license' );
	}

	/* ------------------------------------------------------------------ ganchos */

	public static function boot(): void {
		self::client(); // agenda a renovação diária.
		add_action( 'admin_init', [ __CLASS__, 'maybe_auto_trial' ], 5 );
		add_action( 'admin_post_mlgmp_activate_license', [ __CLASS__, 'handle_activate' ] );
		add_action( 'admin_post_mlgmp_sync_license', [ __CLASS__, 'handle_sync' ] );
		add_action( 'admin_post_mlgmp_remove_license', [ __CLASS__, 'handle_remove' ] );
		$opt = self::client()->option_name();
		foreach ( [ 'added_option', 'updated_option', 'deleted_option' ] as $hook ) {
			add_action(
				$hook,
				static function ( $name ) use ( $opt ) {
					if ( $name === $opt ) {
						License::forget();
					}
				}
			);
		}
	}

	/** Primeira visita ao painel: começa o teste grátis sozinho (uma tentativa por site). */
	public static function maybe_auto_trial(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) || get_option( self::AUTO_TRIAL ) ) {
			return;
		}
		update_option( self::AUTO_TRIAL, time(), false );
		$state = self::client()->state();
		if ( empty( $state['active'] ) && 'none' === ( $state['mode'] ?? 'none' ) && empty( $state['trial_used'] ) ) {
			self::client()->start_trial();
		}
	}

	public static function locked_message(): string {
		return __( 'Ative a licença (ou comece o teste grátis) para iniciar a migração, rodar reparos e salvar a configuração. As galerias já migradas continuam no ar.', 'ml-gallery-migrator-pro' );
	}

	/**
	 * Para as ações de gravação do painel (iniciar/continuar migração, reparos, configuração).
	 *
	 * @param bool $ajax Responde em JSON (AJAX) ou redireciona para a aba Licença.
	 */
	public static function guard_write( bool $ajax = true ): void {
		if ( ! self::is_locked() ) {
			return;
		}
		if ( $ajax ) {
			wp_send_json_error(
				[
					'message' => self::locked_message(),
					'locked'  => true,
				],
				403
			);
		}
		self::redirect_with_notice( 'error', self::locked_message() );
	}

	/* ------------------------------------------------------------------ tela */

	/** Estado pronto para a tela. */
	public static function summary(): array {
		$state  = self::client()->state();
		$active = ! empty( $state['active'] );
		$mode   = (string) ( $state['mode'] ?? 'none' );
		$plan   = strtolower( (string) ( $state['plan'] ?? '' ) );
		$days   = $state['days_left'] ?? null;

		$plans      = [
			'trial'    => 'Teste grátis',
			'monthly'  => 'Mensal',
			'annual'   => 'Anual',
			'yearly'   => 'Anual',
			'lifetime' => 'Vitalícia',
			'manual'   => 'Cortesia',
			'courtesy' => 'Cortesia',
		];
		$plan_label = $plans[ $plan ] ?? ( '' !== $plan ? ucfirst( $plan ) : '—' );

		if ( $active && 'trial' === $mode ) {
			$label   = 'Teste';
			$tone    = 'neutral';
			$message = null === $days ? 'Teste grátis ativo neste site.' : sprintf( 1 === (int) $days ? 'Teste grátis ativo: falta %d dia.' : 'Teste grátis ativo: faltam %d dias.', (int) $days );
		} elseif ( $active ) {
			$label   = 'Ativa';
			$tone    = 'success';
			$message = 'Licença ativa neste site.';
		} else {
			$label = 'Sem licença';
			$tone  = 'warning';
			if ( 'trial_expired' === ( $state['status'] ?? '' ) ) {
				$message = 'O teste grátis terminou. Ative uma licença para voltar a migrar e salvar a configuração. As galerias já migradas continuam no ar.';
			} elseif ( '' !== (string) ( $state['message'] ?? '' ) ) {
				$message = (string) $state['message'];
			} else {
				$message = 'Comece o teste grátis de 30 dias ou ative a sua licença.';
			}
		}

		$expires_label = '';
		if ( ! empty( $state['expires_at'] ) ) {
			$ts = strtotime( (string) $state['expires_at'] );
			if ( $ts ) {
				$expires_label = wp_date( get_option( 'date_format', 'd/m/Y' ), $ts );
			}
		}

		if ( ! $active ) {
			$valid = '—';
		} elseif ( 'lifetime' === $plan || '' === $expires_label ) {
			$valid = 'Sem vencimento';
		} else {
			$valid = $expires_label;
		}

		return [
			'active'          => $active,
			'mode'            => $mode,
			'is_trial'        => $active && 'trial' === $mode,
			'label'           => $label,
			'tone'            => $tone,
			'message'         => $message,
			'plan_label'      => $plan_label,
			'valid_label'     => $valid,
			'days_label'      => ( $active && null !== $days && '' !== $expires_label ) ? (string) (int) $days : '—',
			'key_masked'      => (string) ( $state['key_masked'] ?? '' ),
			'can_start_trial' => ! $active && empty( $state['trial_used'] ) && 'license' !== $mode,
			'domain'          => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
		];
	}

	/* ------------------------------------------------------------------ ações */

	public static function handle_activate(): void {
		self::guard_admin_request( 'mlgmp_activate_license' );
		$key    = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		$result = self::client()->activate( $key );
		if ( is_wp_error( $result ) ) {
			self::redirect_with_notice( 'error', $result->get_error_message() );
		}
		self::redirect_with_notice( 'success', __( 'Licença ativada neste site.', 'ml-gallery-migrator-pro' ) );
	}

	/** Começa o teste grátis ou confere a licença agora. */
	public static function handle_sync(): void {
		self::guard_admin_request( 'mlgmp_sync_license' );
		$op     = isset( $_POST['mlgmp_op'] ) ? sanitize_key( wp_unslash( $_POST['mlgmp_op'] ) ) : 'check';
		$result = 'trial' === $op ? self::client()->start_trial() : self::client()->refresh();
		if ( is_wp_error( $result ) ) {
			self::redirect_with_notice( 'error', $result->get_error_message() );
		}
		self::redirect_with_notice( 'success', 'trial' === $op ? __( 'Teste grátis de 30 dias ativo neste site.', 'ml-gallery-migrator-pro' ) : __( 'Licença conferida.', 'ml-gallery-migrator-pro' ) );
	}

	public static function handle_remove(): void {
		self::guard_admin_request( 'mlgmp_remove_license' );
		self::client()->deactivate();
		self::redirect_with_notice( 'success', __( 'Licença removida deste site. A chave pode ser usada em outro site.', 'ml-gallery-migrator-pro' ) );
	}

	private static function guard_admin_request( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permissão negada.', 'ml-gallery-migrator-pro' ) );
		}
		check_admin_referer( $nonce_action );
	}

	public static function redirect_with_notice( string $type, string $message ): void {
		$url = add_query_arg(
			[
				'mlgmp_lic_type' => rawurlencode( $type ),
				'mlgmp_lic_msg'  => rawurlencode( $message ),
			],
			self::tab_url()
		);
		wp_safe_redirect( $url );
		exit;
	}
}
