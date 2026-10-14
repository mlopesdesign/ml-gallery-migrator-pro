<?php
namespace MLGMP;

/**
 * Atualizacao automatica via GitHub Releases.
 *
 * Fonte: repositorio PUBLICO mlopesdesign/ml-gallery-migrator-pro-releases (somente ZIPs).
 * - Usa somente o asset exato ml-gallery-migrator-pro-vX.Y.Z.zip (sem zipball, sem "qualquer ZIP").
 * - response quando ha versao nova; no_update caso contrario (controle de auto-update do WP).
 * - Cache: 6 h com sucesso, 15 min em falha (uma consulta forcada que falha nao apaga um resultado ainda valido).
 *
 * @since 1.0.69 (antes: repo de codigo, qualquer .zip ou zipball, falha sem cache)
 */
class Updater {

	const USER        = 'mlopesdesign';
	const REPO        = 'ml-gallery-migrator-pro-releases';
	const SLUG        = 'ml-gallery-migrator-pro';
	const CACHE_KEY   = 'mlgmp_github_release_v2';
	const CACHE_TTL   = 21600; // 6 h
	const FAIL_TTL    = 900;   // 15 min

	/** @var string */
	private $basename;

	/** @var string */
	private $version;

	/**
	 * @param string $file    Main plugin file path.
	 * @param string $version Plugin version.
	 */
	public function __construct( $file, $version ) {
		$this->basename = plugin_basename( $file );
		$this->version  = (string) $version;
	}

	/**
	 * Initialize hooks.
	 */
	public function boot() {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
		add_filter( 'plugins_api', [ $this, 'get_plugin_info' ], 10, 3 );
		add_action( 'upgrader_process_complete', [ $this, 'after_upgrade' ], 10, 2 );
	}

	/**
	 * @param bool $force Ignora o cache.
	 * @return array|null
	 */
	private function get_release( $force = false ) {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( ! $force ) {
			if ( is_array( $cached ) ) {
				return $cached;
			}
			if ( 'error' === $cached ) {
				return null;
			}
		}

		$response = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', self::USER, self::REPO ),
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'ML-Gallery-Migrator-Pro-Updater/' . $this->version,
				],
			]
		);

		$data = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		}

		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			if ( ! is_array( $cached ) ) {
				set_site_transient( self::CACHE_KEY, 'error', self::FAIL_TTL );
			}
			return null;
		}

		$ttl = '' !== $this->package_url( $data ) ? self::CACHE_TTL : self::FAIL_TTL;
		set_site_transient( self::CACHE_KEY, $data, $ttl );
		return $data;
	}

	/**
	 * @param array $release
	 * @return string
	 */
	private function release_version( $release ) {
		return ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' );
	}

	/**
	 * Somente o asset exato da propria versao.
	 *
	 * @param array $release
	 * @return string
	 */
	private function package_url( $release ) {
		$expected = self::SLUG . '-v' . $this->release_version( $release ) . '.zip';
		foreach ( (array) ( $release['assets'] ?? [] ) as $asset ) {
			if ( is_array( $asset ) && ( $asset['name'] ?? '' ) === $expected && ! empty( $asset['browser_download_url'] ) ) {
				return (string) $asset['browser_download_url'];
			}
		}
		return '';
	}

	/**
	 * @return array
	 */
	private function icons() {
		return [
			'1x'      => MLGMP_URL . 'assets/images/icon-128x128.png',
			'2x'      => MLGMP_URL . 'assets/images/icon-256x256.png',
			'default' => MLGMP_URL . 'assets/images/icon-256x256.png',
		];
	}

	/**
	 * Hooked to 'pre_set_site_transient_update_plugins'.
	 *
	 * @param object $transient
	 * @return object
	 */
	public function check_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_release();
		$remote  = $release ? $this->release_version( $release ) : '';
		$package = $release ? $this->package_url( $release ) : '';

		$item = (object) [
			'id'            => 'github.com/' . self::USER . '/' . self::REPO,
			'slug'          => self::SLUG,
			'plugin'        => $this->basename,
			'new_version'   => $remote,
			'url'           => 'https://github.com/' . self::USER . '/' . self::REPO,
			'package'       => $package,
			'icons'         => $this->icons(),
			'banners'       => [],
			'banners_rtl'   => [],
			'tested'        => '6.8',
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'compatibility' => new \stdClass(),
		];

		if ( '' !== $remote && '' !== $package && version_compare( $this->version, $remote, '<' ) ) {
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = [];
			}
			$transient->response[ $this->basename ] = $item;
			if ( isset( $transient->no_update ) && is_array( $transient->no_update ) ) {
				unset( $transient->no_update[ $this->basename ] );
			}
		} else {
			$item->new_version = $this->version;
			$item->package     = '';
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = [];
			}
			$transient->no_update[ $this->basename ] = $item;
			if ( isset( $transient->response ) && is_array( $transient->response ) ) {
				unset( $transient->response[ $this->basename ] );
			}
		}

		return $transient;
	}

	/**
	 * Hooked to 'plugins_api'.
	 *
	 * @param bool|object $result
	 * @param string      $action
	 * @param object      $args
	 * @return bool|object
	 */
	public function get_plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = $this->get_release();
		if ( ! $release || '' === $this->package_url( $release ) ) {
			return $result;
		}

		$res                = new \stdClass();
		$res->name          = 'ML Gallery Migrator Pro';
		$res->slug          = self::SLUG;
		$res->version       = $this->release_version( $release );
		$res->author        = '<a href="https://mlopesdesign.com.br/">M Lopes Design</a>';
		$res->homepage      = 'https://tools.mlopesdesign.com.br/';
		$res->requires      = '6.0';
		$res->tested        = '6.8';
		$res->requires_php  = '7.4';
		$res->last_updated  = (string) ( $release['published_at'] ?? '' );
		$res->icons         = $this->icons();
		$res->sections      = [
			'description' => 'Migração robusta do NextGEN Gallery e Photo Gallery para o ML Gallery Pro.',
			'changelog'   => nl2br( esc_html( (string) ( $release['body'] ?? '' ) ) ),
		];
		$res->download_link = $this->package_url( $release );

		return $res;
	}

	/**
	 * Limpa o cache (inclusive o da versao antiga) depois que este plugin e atualizado.
	 *
	 * @param \WP_Upgrader $upgrader
	 * @param array        $hook_extra
	 */
	public function after_upgrade( $upgrader, $hook_extra ) {
		if ( ! is_array( $hook_extra ) || ( $hook_extra['type'] ?? '' ) !== 'plugin' ) {
			return;
		}
		// Lote (painel/AJAX) usa 'plugins'; atualizacao simples e automatica usam 'plugin'.
		$mine = in_array( $this->basename, (array) ( $hook_extra['plugins'] ?? [] ), true )
			|| ( $hook_extra['plugin'] ?? '' ) === $this->basename;
		if ( $mine ) {
			delete_site_transient( self::CACHE_KEY );
			delete_site_transient( 'mlgmp_github_release_latest' );
		}
	}
}
