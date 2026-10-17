<?php
/**
 * ML License Client v2 — cliente de licenças do site tools.mlopesdesign.com.br
 * (módulo ML License Hub) para plugins WordPress.
 *
 * Copie este arquivo para o plugin (includes/class-ml-license-client.php) sem alterar.
 * Vários plugins podem trazer a mesma cópia: a classe só é declarada uma vez.
 *
 * v2 (15/10/2026): acrescenta o teste grátis por site (endpoint license/trial).
 * O nome da classe mudou de V1 para V2 de propósito: se um plugin antigo trouxer a
 * V1 (sem teste) e carregar primeiro, a V2 continua existindo com todos os métodos.
 *
 * Uso:
 *   $license = new ML_License_Client_V2( array(
 *       'api_base'   => 'https://tools.mlopesdesign.com.br/wp-json/mllh/v1',
 *       'product'    => 'ml-gallery-pro',
 *       'public_key' => '...base64 da chave pública do site...',
 *       'version'    => MLGP_VERSION,
 *   ) );
 *   $license->start_trial();      // teste grátis deste site (uma vez por site)
 *   $license->activate( $key );   // chave comprada (retorna array|WP_Error)
 *   $license->is_premium();       // teste ou licença válidos
 *   $license->deactivate();       // libera este site para usar a chave em outro
 *
 * Funcionamento: a resposta do servidor vem assinada (Ed25519) e é conferida com a
 * chave pública. O token vale até 7 dias; o cliente renova sozinho uma vez por dia
 * (cron). Sem conexão, o site continua liberado até o token vencer.
 *
 * @package ML_License_Client
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ML_License_Client_V2' ) ) :

	final class ML_License_Client_V2 {

		private string $api_base;
		private string $product;
		private string $public_key;
		private string $version;
		private string $option;

		public function __construct( array $config ) {
			$this->api_base   = untrailingslashit( (string) $config['api_base'] );
			$this->product    = sanitize_key( (string) $config['product'] );
			$this->public_key = (string) $config['public_key'];
			$this->version    = (string) ( $config['version'] ?? '' );
			$this->option     = 'mllc_' . str_replace( '-', '_', $this->product );

			$hook = $this->option . '_refresh';
			add_action( $hook, array( $this, 'refresh' ) );
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', $hook );
			}
		}

		/** Nome do evento de cron (para o plugin desagendar ao desinstalar). */
		public function cron_hook(): string {
			return $this->option . '_refresh';
		}

		/** Nome da option local (para o plugin limpar ao desinstalar). */
		public function option_name(): string {
			return $this->option;
		}

		/** ID deste site: sha256 do home_url normalizado. */
		public function instance_id(): string {
			$home = strtolower( untrailingslashit( preg_replace( '#^https?://#', '', home_url() ) ) );
			return hash( 'sha256', $this->product . '|' . $home );
		}

		/** Ativa uma chave comprada. @return array|WP_Error */
		public function activate( string $key ) {
			$key = strtoupper( trim( $key ) );
			if ( '' === $key ) {
				return new WP_Error( 'empty_key', 'Informe a chave da licença.' );
			}
			$resp = $this->post(
				'license/activate',
				array(
					'license_key'    => $key,
					'instance_label' => $this->label(),
					'kind'           => 'domain',
				)
			);
			if ( is_wp_error( $resp ) ) {
				return $resp;
			}
			return $this->store( $key, $resp, 'license' );
		}

		/**
		 * Inicia (ou retoma) o teste grátis deste site. O servidor guarda o início do
		 * teste por site: reinstalar o plugin não reinicia a contagem.
		 *
		 * @return array|WP_Error
		 */
		public function start_trial() {
			$resp = $this->post( 'license/trial', array( 'instance_label' => $this->label() ) );
			if ( is_wp_error( $resp ) ) {
				if ( 'trial_expired' === $resp->get_error_code() ) {
					$this->save_error( '', 'trial', 'trial_expired', $resp->get_error_message() );
				}
				return $resp;
			}
			return $this->store( '', $resp, 'trial' );
		}

		/** Renova o token (cron diário e ao abrir a tela de licença). @return array|WP_Error */
		public function refresh() {
			$saved = $this->saved();
			$mode  = (string) ( $saved['mode'] ?? '' );

			if ( '' !== (string) ( $saved['license_key'] ?? '' ) ) {
				$resp = $this->post( 'license/validate', array( 'license_key' => $saved['license_key'] ) );
				if ( is_wp_error( $resp ) ) {
					// Sem internet: mantém o token atual até ele vencer (até 7 dias).
					if ( 'offline' !== $resp->get_error_code() ) {
						$this->save_error( (string) $saved['license_key'], 'license', $resp->get_error_code(), $resp->get_error_message() );
					}
					return $resp;
				}
				return $this->store( (string) $saved['license_key'], $resp, 'license' );
			}

			if ( 'trial' === $mode ) {
				return $this->start_trial();
			}

			return $this->state();
		}

		/** Libera este site no servidor e apaga o estado local. @return true */
		public function deactivate() {
			$saved = $this->saved();
			if ( ! empty( $saved['license_key'] ) ) {
				$this->post( 'license/deactivate', array( 'license_key' => $saved['license_key'] ) );
			}
			// Um teste em andamento continua valendo: volta para ele em vez de travar o site.
			if ( ! empty( $saved['trial_used'] ) ) {
				update_option( $this->option, array( 'mode' => 'trial', 'trial_used' => 1 ), false );
				$this->start_trial();
				return true;
			}
			delete_option( $this->option );
			return true;
		}

		/** Teste ou licença válidos agora. */
		public function is_premium(): bool {
			return ! empty( $this->state()['active'] );
		}

		/**
		 * Estado local (sem rede), conferindo a assinatura do token.
		 *
		 * Chaves: active, mode (trial|license|none), plan, status, expires_at,
		 * days_left, key_masked, trial_used, message.
		 */
		public function state(): array {
			$saved      = $this->saved();
			$mode       = (string) ( $saved['mode'] ?? ( empty( $saved['license_key'] ) ? '' : 'license' ) );
			$trial_used = ! empty( $saved['trial_used'] ) || 'trial' === $mode;
			$masked     = ! empty( $saved['license_key'] ) ? substr( (string) $saved['license_key'], 0, 3 ) . '•••••-' . substr( (string) $saved['license_key'], -5 ) : '';
			$payload    = ! empty( $saved['token'] ) ? $this->verify( (string) $saved['token'] ) : null;

			if ( ! $payload ) {
				$status = (string) ( $saved['error'] ?? ( '' === $mode ? 'none' : 'needs_check' ) );
				return array(
					'active'     => false,
					'mode'       => '' === $mode ? 'none' : $mode,
					'plan'       => '',
					'status'     => $status,
					'expires_at' => null,
					'days_left'  => null,
					'key_masked' => $masked,
					'trial_used' => $trial_used,
					'message'    => (string) ( $saved['message'] ?? '' ),
				);
			}

			$active     = ! empty( $payload['premium'] ) && (int) $payload['exp'] > time();
			$expires_at = isset( $payload['expires_at'] ) ? (string) $payload['expires_at'] : null;
			$days_left  = null;
			if ( $expires_at ) {
				$ts = strtotime( $expires_at );
				if ( $ts ) {
					$days_left = max( 0, (int) ceil( ( $ts - time() ) / DAY_IN_SECONDS ) );
				}
			}

			return array(
				'active'     => $active,
				'mode'       => '' === $mode ? 'license' : $mode,
				'plan'       => (string) ( $payload['plan'] ?? '' ),
				'status'     => $active ? (string) ( $payload['status'] ?? 'active' ) : 'needs_check',
				'expires_at' => $expires_at,
				'days_left'  => $days_left,
				'key_masked' => $masked,
				'trial_used' => $trial_used,
				'message'    => $active ? '' : 'Não foi possível confirmar a licença nos últimos dias. Conecte o site à internet ou verifique a chave.',
			);
		}

		// ---------------------------------------------------------------- interno

		private function label(): string {
			return (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}

		private function saved(): array {
			$v = get_option( $this->option, array() );
			return is_array( $v ) ? $v : array();
		}

		private function save_error( string $key, string $mode, string $code, string $message ): void {
			$saved = $this->saved();
			update_option(
				$this->option,
				array(
					'license_key' => $key,
					'mode'        => $mode,
					'token'       => '',
					'trial_used'  => ( ! empty( $saved['trial_used'] ) || 'trial' === $mode ) ? 1 : 0,
					'error'       => $code,
					'message'     => $message,
				),
				false
			);
		}

		/** @return array|WP_Error */
		private function store( string $key, array $resp, string $mode ) {
			$token = (string) ( $resp['token'] ?? '' );
			if ( ! $this->verify( $token ) ) {
				return new WP_Error( 'bad_signature', 'A resposta do servidor de licenças não pôde ser verificada.' );
			}
			$saved = $this->saved();
			update_option(
				$this->option,
				array(
					'license_key' => $key,
					'mode'        => $mode,
					'token'       => $token,
					'trial_used'  => ( ! empty( $saved['trial_used'] ) || 'trial' === $mode ) ? 1 : 0,
					'checked_at'  => time(),
				),
				false
			);
			return $this->state();
		}

		private function verify( string $token ): ?array {
			$parts = explode( '.', $token );
			if ( 2 !== count( $parts ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
				return null;
			}
			$json = $this->b64url( $parts[0] );
			$sig  = $this->b64url( $parts[1] );
			$pk   = base64_decode( $this->public_key, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			if ( false === $pk || 32 !== strlen( $pk ) || 64 !== strlen( $sig ) ) {
				return null;
			}
			try {
				if ( ! sodium_crypto_sign_verify_detached( $sig, $json, $pk ) ) {
					return null;
				}
			} catch ( \Throwable $e ) {
				return null;
			}
			$data = json_decode( $json, true );
			if ( ! is_array( $data ) || ( $data['product'] ?? '' ) !== $this->product || ( $data['instance'] ?? '' ) !== $this->instance_id() ) {
				return null;
			}
			return $data;
		}

		private function b64url( string $s ): string {
			$d = base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			return false === $d ? '' : $d;
		}

		/** @return array|WP_Error */
		private function post( string $endpoint, array $body ) {
			$body = array_merge(
				array(
					'product'     => $this->product,
					'instance_id' => $this->instance_id(),
					'version'     => $this->version,
				),
				$body
			);
			$res  = wp_remote_post(
				$this->api_base . '/' . $endpoint,
				array(
					'timeout' => 15,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $body ),
				)
			);
			if ( is_wp_error( $res ) ) {
				return new WP_Error( 'offline', 'Sem conexão com o servidor de licenças.' );
			}
			$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( $code >= 400 || ! is_array( $data ) || empty( $data['ok'] ) ) {
				return new WP_Error( (string) ( $data['code'] ?? 'http_' . $code ), (string) ( $data['message'] ?? 'Erro do servidor de licenças.' ) );
			}
			return $data;
		}
	}

endif;
