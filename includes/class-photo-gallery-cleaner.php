<?php
/**
 * Photo Gallery cleanup engine.
 *
 * @package MLGalleryMigratorPro
 */

namespace MLGMP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PhotoGalleryCleaner {

	private static ?PhotoGalleryCleaner $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'admin_post_mlgmp_photo_gallery_cleanup', [ $this, 'handle_cleanup_request' ] );
	}

	public static function scan(): array {
		global $wpdb;
		$tables = self::find_tables();
		$dirs   = self::find_dirs();
		$option_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				    OR option_name LIKE %s
				    OR option_name LIKE %s
				    OR option_name LIKE %s
				    OR option_name LIKE %s
				    OR option_name LIKE %s",
				'wd_bwg%',
				'bwg_%',
				'photo_gallery%',
				'widget_bwg%',
				'_transient_bwg_%',
				'_site_transient_bwg_%'
			)
		);
		$usermeta_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta}
				 WHERE meta_key LIKE %s OR meta_key LIKE %s",
				'bwg_%',
				'wd_bwg%'
			)
		);
		$cron_hooks = self::find_cron_hooks();
		$detection  = PhotoGalleryReader::detect();

		return [
			'tables'        => $tables,
			'tables_count'  => count( $tables ),
			'options_count' => $option_count,
			'usermeta_count'=> $usermeta_count,
			'cron_hooks'    => $cron_hooks,
			'cron_count'    => count( $cron_hooks ),
			'dirs'          => $dirs,
			'dirs_count'    => count( $dirs ),
			'plugin_active' => (bool) $detection['plugin_active'],
			'detected'      => ! empty( $tables ) || $option_count > 0 || $usermeta_count > 0 || ! empty( $dirs ) || ! empty( $cron_hooks ) || ! empty( $detection['tables_found'] ),
		];
	}

	public function handle_cleanup_request(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permissão negada.', 'ml-gallery-migrator-pro' ) );
		}
		check_admin_referer( 'mlgmp_photo_gallery_cleanup', 'mlgmp_cleanup_nonce' );
		$state = State::get();
		if ( in_array( $state['status'], [ State::STATUS_RUNNING, State::STATUS_PAUSED ], true ) ) {
			$this->redirect( 'busy' );
		}
		$scan = self::scan();
		if ( $scan['plugin_active'] ) {
			$this->redirect( 'active' );
		}
		$result = self::cleanup();
		$this->redirect( 'done', $result );
	}

	public static function cleanup(): array {
		global $wpdb;
		$removed = [ 'tables' => 0, 'options' => 0, 'usermeta' => 0, 'cron' => 0, 'dirs' => 0 ];
		foreach ( self::find_tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
			++$removed['tables'];
		}
		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				'wd_bwg%',
				'bwg_%',
				'photo_gallery%',
				'widget_bwg%',
				'_transient_bwg_%',
				'_site_transient_bwg_%'
			)
		);
		foreach ( (array) $option_names as $option_name ) {
			if ( delete_option( $option_name ) ) {
				++$removed['options'];
			}
			if ( is_multisite() && delete_site_option( $option_name ) ) {
				++$removed['options'];
			}
		}
		$umeta_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT umeta_id FROM {$wpdb->usermeta}
				 WHERE meta_key LIKE %s OR meta_key LIKE %s",
				'bwg_%',
				'wd_bwg%'
			)
		);
		foreach ( (array) $umeta_ids as $umeta_id ) {
			$deleted = $wpdb->delete( $wpdb->usermeta, [ 'umeta_id' => (int) $umeta_id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( $deleted ) {
				++$removed['usermeta'];
			}
		}
		foreach ( self::find_cron_hooks() as $hook ) {
			while ( $timestamp = wp_next_scheduled( $hook ) ) {
				if ( wp_unschedule_event( $timestamp, $hook ) ) {
					++$removed['cron'];
				} else {
					break;
				}
			}
		}
		foreach ( self::find_dirs() as $dir ) {
			if ( self::rrmdir( $dir ) ) {
				++$removed['dirs'];
			}
		}
		return $removed;
	}

	private static function find_tables(): array {
		global $wpdb;
		$tables = [];
		$rows = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'bwg_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $rows ) {
			$tables = array_merge( $tables, $rows );
		}
		$tables = array_values( array_unique( array_filter( array_map( 'strval', $tables ) ) ) );
		sort( $tables );
		return $tables;
	}

	private static function find_dirs(): array {
		$dirs = [];
		$candidates = [
			WP_CONTENT_DIR . '/uploads/photo-gallery',
			WP_CONTENT_DIR . '/uploads/bwg_uploads',
			WP_CONTENT_DIR . '/uploads/bwg',
		];
		foreach ( $candidates as $dir ) {
			if ( file_exists( $dir ) ) {
				$dirs[] = $dir;
			}
		}
		return $dirs;
	}

	private static function find_cron_hooks(): array {
		$cron = _get_cron_array();
		$hooks = [];
		if ( ! is_array( $cron ) ) {
			return $hooks;
		}
		foreach ( $cron as $events ) {
			if ( ! is_array( $events ) ) {
				continue;
			}
			foreach ( $events as $hook => $instances ) {
				$hook_l = strtolower( (string) $hook );
				if ( false !== strpos( $hook_l, 'bwg' ) || false !== strpos( $hook_l, 'photo_gallery' ) || false !== strpos( $hook_l, 'photo-gallery' ) ) {
					$hooks[] = (string) $hook;
				}
			}
		}
		$hooks = array_values( array_unique( $hooks ) );
		sort( $hooks );
		return $hooks;
	}

	private static function rrmdir( string $dir ): bool {
		if ( ! file_exists( $dir ) ) {
			return false;
		}
		if ( is_file( $dir ) || is_link( $dir ) ) {
			return @unlink( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$items = scandir( $dir );
		if ( false === $items ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			self::rrmdir( $dir . DIRECTORY_SEPARATOR . $item );
		}
		return @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	private function redirect( string $status, array $data = [] ): void {
		$url = add_query_arg(
			[
				'page'               => MLGMP_SLUG,
				'mlgmp_cleanup'      => $status,
				'mlgmp_cleanup_kind' => 'photo_gallery',
				'mlgmp_cleanup_data' => rawurlencode( wp_json_encode( $data ) ),
			],
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
