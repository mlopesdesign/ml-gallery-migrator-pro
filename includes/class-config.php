<?php
/**
 * Configuration Engine — Persistent settings storage in wp_options.
 *
 * Stores application-wide configuration settings that control migration behavior.
 * Settings are persisted in wp_options with option_name = 'mlgmp_settings'.
 *
 * Structure:
 *   batch_size       – Number of items to process per batch (default 20)
 *   enabled_stages   – Array of enabled stages (galleries, albums, images, thumbs, shortcodes)
 *   duplicate_mode   – How to handle duplicates: 'ignore' | 'overwrite'
 *   repair_flags     – Reserved for future repair engine flags
 *
 * @package MLGalleryMigratorPro
 */

namespace MLGMP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Config {

	const OPTION_KEY = 'mlgmp_settings';

	private static ?Config $instance = null;

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
		add_action( 'wp_ajax_mlgmp_save_settings', [ $this, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_mlgmp_load_settings', [ $this, 'ajax_load_settings' ] );
	}

	// -----------------------------------------------------------------------
	// Get / Save / Load
	// -----------------------------------------------------------------------

	/**
	 * Get all settings with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_all(): array {
		$saved = get_option( self::OPTION_KEY, [] );
		return is_array( $saved ) ? wp_parse_args( $saved, self::defaults() ) : self::defaults();
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$settings = self::get_all();
		return $settings[ $key ] ?? $default;
	}

	/**
	 * Save all settings.
	 *
	 * @param array<string,mixed> $settings
	 */
	public static function save_all( array $settings ): void {
		$sanitized = self::sanitize( $settings );
		update_option( self::OPTION_KEY, $sanitized, false );
	}

	/**
	 * Update a single setting.
	 *
	 * @param string $key
	 * @param mixed  $value
	 */
	public static function set( string $key, $value ): void {
		$settings       = self::get_all();
		$settings[ $key ] = $value;
		self::save_all( $settings );
	}

	/**
	 * Reset settings to defaults.
	 */
	public static function reset(): void {
		delete_option( self::OPTION_KEY );
	}

	// -----------------------------------------------------------------------
	// Defaults
	// -----------------------------------------------------------------------

	/**
	 * Default settings structure.
	 *
	 * @return array<string,mixed>
	 */
	private static function defaults(): array {
		return [
			'batch_size'     => 20,
			'enabled_stages' => [
				'galleries'  => true,
				'albums'     => true,
				'images'     => true,
				'thumbs'     => true,
				'shortcodes' => true,
			],
			'duplicate_mode' => 'ignore',
			'repair_flags'   => [],
		];
	}

	// -----------------------------------------------------------------------
	// Sanitization
	// -----------------------------------------------------------------------

	/**
	 * Sanitize settings before saving.
	 *
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	private static function sanitize( array $settings ): array {
		$sanitized = [];

		// Batch size: positive integer.
		$sanitized['batch_size'] = isset( $settings['batch_size'] )
			? max( 1, (int) $settings['batch_size'] )
			: self::defaults()['batch_size'];

		// Enabled stages: array of booleans.
		$sanitized['enabled_stages'] = [];
		$valid_stages                = [ 'galleries', 'albums', 'images', 'thumbs', 'shortcodes' ];
		if ( isset( $settings['enabled_stages'] ) && is_array( $settings['enabled_stages'] ) ) {
			foreach ( $valid_stages as $stage ) {
				$sanitized['enabled_stages'][ $stage ] = isset( $settings['enabled_stages'][ $stage ] )
					? (bool) $settings['enabled_stages'][ $stage ]
					: self::defaults()['enabled_stages'][ $stage ];
			}
		} else {
			$sanitized['enabled_stages'] = self::defaults()['enabled_stages'];
		}

		// Duplicate mode: 'ignore' or 'overwrite'.
		$sanitized['duplicate_mode'] = isset( $settings['duplicate_mode'] )
			? sanitize_key( $settings['duplicate_mode'] )
			: self::defaults()['duplicate_mode'];
		if ( ! in_array( $sanitized['duplicate_mode'], [ 'ignore', 'overwrite' ], true ) ) {
			$sanitized['duplicate_mode'] = self::defaults()['duplicate_mode'];
		}

		// Repair flags: array (reserved for future use).
		$sanitized['repair_flags'] = isset( $settings['repair_flags'] ) && is_array( $settings['repair_flags'] )
			? $settings['repair_flags']
			: [];

		return $sanitized;
	}

	// -----------------------------------------------------------------------
	// AJAX Handlers
	// -----------------------------------------------------------------------

	/**
	 * AJAX: Save settings from configuration UI.
	 */
	public function ajax_save_settings(): void {
		// Auth.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}

		// Nonce.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mlgmp_ajax' ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
		}

		// Extract settings from POST.
		$settings = [];

		if ( isset( $_POST['batch_size'] ) ) {
			$settings['batch_size'] = absint( wp_unslash( $_POST['batch_size'] ) );
		}

		if ( isset( $_POST['duplicate_mode'] ) ) {
			$settings['duplicate_mode'] = sanitize_key( wp_unslash( $_POST['duplicate_mode'] ) );
		}

		if ( isset( $_POST['enabled_stages'] ) ) {
			$raw = wp_unslash( $_POST['enabled_stages'] );
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$settings['enabled_stages'] = [];
				$valid_stages              = [ 'galleries', 'albums', 'images', 'thumbs', 'shortcodes' ];
				foreach ( $valid_stages as $stage ) {
					$settings['enabled_stages'][ $stage ] = in_array( $stage, $decoded, true );
				}
			}
		}

		// Save.
		$existing = self::get_all();
		$merged   = array_merge( $existing, $settings );
		self::save_all( $merged );

		// Log.
		$logger = new Logger( 'config_engine', 'settings' );
		$logger->info( 'Configurações salvas via painel.' );

		wp_send_json_success( [
			'message'  => __( 'Configuração salva com sucesso.', 'ml-gallery-migrator-pro' ),
			'settings' => self::get_all(),
		] );
	}

	/**
	 * AJAX: Load current settings for UI.
	 */
	public function ajax_load_settings(): void {
		// Auth.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}

		// Nonce.
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mlgmp_ajax' ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
		}

		wp_send_json_success( [ 'settings' => self::get_all() ] );
	}
}
