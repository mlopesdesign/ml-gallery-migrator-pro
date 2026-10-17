<?php
/**
 * Plugin Name: ML Gallery Migrator Pro
 * Plugin URI:  https://tools.mlopesdesign.com.br/
 * Description: Migração robusta do NextGEN Gallery e Photo Gallery para o ML Gallery Pro. Motor em lotes, logs persistentes, conversão de shortcodes e cópia física de arquivos.
 * Version: 1.1.0
 * Author:      M Lopes Design
 * Author URI:  https://mlopesdesign.com.br/
 * Text Domain: ml-gallery-migrator-pro
 * Domain Path: /languages
 * Update URI: https://github.com/mlopesdesign/ml-gallery-migrator-pro-releases
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package MLGalleryMigratorPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MLGMP_VERSION', '1.1.0' );
define( 'MLGMP_FILE',     __FILE__ );
define( 'MLGMP_BASENAME', plugin_basename( __FILE__ ) );
define( 'MLGMP_DIR',      plugin_dir_path( __FILE__ ) );
define( 'MLGMP_URL',      plugin_dir_url( __FILE__ ) );
define( 'MLGMP_SLUG',     'ml-gallery-migrator-pro' );

require_once MLGMP_DIR . 'includes/class-installer.php';
require_once MLGMP_DIR . 'includes/class-license.php';
require_once MLGMP_DIR . 'includes/class-state.php';
require_once MLGMP_DIR . 'includes/class-logger.php';
require_once MLGMP_DIR . 'includes/class-ngg-reader.php';
require_once MLGMP_DIR . 'includes/class-photo-gallery-reader.php';
require_once MLGMP_DIR . 'includes/class-file-copier.php';
require_once MLGMP_DIR . 'includes/class-shortcode-converter.php';
require_once MLGMP_DIR . 'includes/class-migrator.php';
require_once MLGMP_DIR . 'includes/class-config.php';
require_once MLGMP_DIR . 'includes/class-repair.php';
require_once MLGMP_DIR . 'includes/class-admin.php';
require_once MLGMP_DIR . 'includes/class-ajax.php';
require_once MLGMP_DIR . 'includes/class-updater.php';
require_once MLGMP_DIR . 'includes/class-ngg-cleaner.php';
require_once MLGMP_DIR . 'includes/class-photo-gallery-cleaner.php';
require_once MLGMP_DIR . 'includes/class-reliability.php';

register_activation_hook( __FILE__, [ 'MLGMP\\Installer', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'MLGMP\\Installer', 'deactivate' ] );

add_action( 'plugins_loaded', static function () {
	load_plugin_textdomain( 'ml-gallery-migrator-pro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Licença pelo site tools.mlopesdesign.com.br (ML License Hub).
	\MLGMP\License::boot();
	\MLGMP\Admin::instance()->boot();
	\MLGMP\Ajax::instance()->boot();
	\MLGMP\Config::instance()->boot();
	\MLGMP\Repair::instance()->boot();
	\MLGMP\NGGCleaner::instance()->boot();
	\MLGMP\PhotoGalleryCleaner::instance()->boot();

	// Atualizador: painel, WP-Cron (atualizacao automatica) e WP-CLI.
	if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		$updater = new \MLGMP\Updater( MLGMP_FILE, MLGMP_VERSION );
		$updater->boot();
	}
} );
