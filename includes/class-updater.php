<?php
namespace MLGMP;

/**
 * Handles automatic updates for the plugin from GitHub Releases.
 */
class Updater {
	/**
	 * Repository owner.
	 * @var string
	 */
	private $username = 'mlopesdesign';

	/**
	 * Repository name.
	 * @var string
	 */
	private $repository = 'ml-gallery-migrator-pro';

	/**
	 * Plugin file relative path.
	 * @var string
	 */
	private $basename;

	/**
	 * Plugin version.
	 * @var string
	 */
	private $version;

	/**
	 * Constructor.
	 *
	 * @param string $file    Main plugin file path.
	 * @param string $version Plugin version.
	 */
	public function __construct( $file, $version ) {
		$this->basename = plugin_basename( $file );
		$this->version  = $version;
	}

	/**
	 * Initialize hooks.
	 */
	public function boot() {
		add_filter( 'site_transient_update_plugins', [ $this, 'update_plugins_filter' ] );
		add_filter( 'plugins_api', [ $this, 'get_plugin_info' ], 10, 3 );
	}

	/**
	 * Fetches the latest release info from GitHub.
	 *
	 * @return object|bool
	 */
	private function get_latest_github_release() {
		$transient_key = 'mlgmp_github_release_latest';
		$release = get_site_transient( $transient_key );

		if ( false !== $release ) {
			return $release;
		}

		$url = "https://api.github.com/repos/{$this->username}/{$this->repository}/releases/latest";
		$response = wp_remote_get( $url, [
			'headers' => [
				'Accept' => 'application/vnd.github.v3+json',
			],
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return false;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ) );
		if ( ! $release ) {
			return false;
		}

		// Cache for 12 hours
		set_site_transient( $transient_key, $release, 12 * HOUR_IN_SECONDS );

		return $release;
	}

	/**
	 * Hooked to 'site_transient_update_plugins'.
	 *
	 * @param object $transient
	 * @return object
	 */
	public function update_plugins_filter( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_github_release();
		if ( ! $release ) {
			return $transient;
		}

		// Clean version strings (remove 'v' prefix if present)
		$latest_version = ltrim( $release->tag_name, 'v' );
		
		if ( version_compare( $latest_version, $this->version, '>' ) ) {
			$obj = new \stdClass();
			$obj->slug        = 'ml-gallery-migrator-pro';
			$obj->new_version = $latest_version;
			$obj->url         = $release->html_url;
			$obj->package     = $this->get_zip_url( $release );
			$obj->icons       = [
				'default' => MLGMP_URL . 'assets/icon-256x256.png',
			];

			$transient->response[ $this->basename ] = $obj;
		} else {
			// Ensure it's in the 'no_update' list if it's the current version
			$obj = new \stdClass();
			$obj->id          = $this->basename;
			$obj->slug        = 'ml-gallery-migrator-pro';
			$obj->new_version = $latest_version;
			$obj->url         = $release->html_url;
			$obj->package     = $this->get_zip_url( $release );
			$transient->no_update[ $this->basename ] = $obj;
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
		if ( $action !== 'plugin_information' || $args->slug !== 'ml-gallery-migrator-pro' ) {
			return $result;
		}

		$release = $this->get_latest_github_release();
		if ( ! $release ) {
			return $result;
		}

		$res = new \stdClass();
		$res->name           = 'ML Gallery Migrator Pro';
		$res->slug           = 'ml-gallery-migrator-pro';
		$res->version        = ltrim( $release->tag_name, 'v' );
		$res->author         = '<a href="https://mlopesdesign.com.br/">M Lopes Design</a>';
		$res->homepage       = 'https://tools.mlopesdesign.com.br/';
		$res->requires       = '6.0';
		$res->tested         = '6.7';
		$res->last_updated   = $release->published_at;
		$res->sections       = [
			'description' => 'Migração robusta do NextGEN Gallery para o ML Gallery Pro.',
			'changelog'   => $this->markdown_to_html( $release->body ),
		];
		$res->download_link  = $this->get_zip_url( $release );

		return $res;
	}

	/**
	 * Finds the ZIP asset URL in the release object.
	 *
	 * @param object $release
	 * @return string
	 */
	private function get_zip_url( $release ) {
		if ( ! empty( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if ( strpos( $asset->name, '.zip' ) !== false ) {
					return $asset->browser_download_url;
				}
			}
		}
		// Fallback to source ZIP (not recommended for in-place update if structures differ)
		return $release->zipball_url;
	}

	/**
	 * Basic markdown converter for the changelog modal.
	 *
	 * @param string $content
	 * @return string
	 */
	private function markdown_to_html( $content ) {
		$content = preg_replace( '/^### (.*)$/m', '<h4>$1</h4>', $content );
		$content = preg_replace( '/^## (.*)$/m', '<h3>$1</h3>', $content );
		$content = preg_replace( '/^# (.*)$/m', '<h2>$1</h2>', $content );
		$content = preg_replace( '/\*\*(.*)\*\*/U', '<strong>$1</strong>', $content );
		$content = preg_replace( '/\*(.*)\*/U', '<em>$1</em>', $content );
		$content = nl2br( $content );
		return $content;
	}
}
