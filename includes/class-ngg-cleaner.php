<?php
/**
 * NGG cleanup engine.
 *
 * @package MLGalleryMigratorPro
 */

namespace MLGMP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NGGCleaner {

	private static ?NGGCleaner $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'admin_post_mlgmp_ngg_cleanup',  [ $this, 'handle_cleanup_request' ] );
		add_action( 'wp_ajax_mlgmp_cleanup_batch',   [ $this, 'ajax_cleanup_batch' ] );
		add_action( 'wp_ajax_mlgmp_cleanup_scan',    [ $this, 'ajax_cleanup_scan' ] );
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
				'ngg_%',
				'nextgen_%',
				'photocrati_%',
				'_transient_ngg_%',
				'_transient_photocrati_%',
				'_site_transient_ngg_%'
			)
		);

		$usermeta_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta}
				 WHERE meta_key LIKE %s
				    OR meta_key LIKE %s
				    OR meta_key LIKE %s",
				'ngg_%',
				'nextgen_%',
				'photocrati_%'
			)
		);

		$cron_hooks = self::find_cron_hooks();
		$detection  = NGGReader::detect();

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
			'ngg_detected'  => ! empty( $tables ) || $option_count > 0 || $usermeta_count > 0 || ! empty( $dirs ) || ! empty( $cron_hooks ) || ! empty( $detection['tables_found'] ),
		];
	}

	public function handle_cleanup_request(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permissão negada.', 'ml-gallery-migrator-pro' ) );
		}

		check_admin_referer( 'mlgmp_ngg_cleanup', 'mlgmp_cleanup_nonce' );

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

		$removed = [
			'tables'   => 0,
			'options'  => 0,
			'usermeta' => 0,
			'cron'     => 0,
			'dirs'     => 0,
		];

		foreach ( self::find_tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
			++$removed['tables'];
		}

		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				    OR option_name LIKE %s
				    OR option_name LIKE %s
				    OR option_name LIKE %s
				    OR option_name LIKE %s
				    OR option_name LIKE %s
				    OR option_name LIKE %s
				    OR option_name LIKE %s",
				'ngg_%',
				'nextgen_%',
				'photocrati_%',
				'widget_ngg%',
				'_transient_ngg_%',
				'_transient_photocrati_%',
				'_site_transient_ngg_%',
				'_site_transient_photocrati_%'
			)
		);
		foreach ( (array) $option_names as $option_name ) {
			if ( delete_option( $option_name ) ) {
				++$removed['options'];
			}
			if ( is_multisite() ) {
				if ( delete_site_option( $option_name ) ) {
					++$removed['options'];
				}
			}
		}

		$meta_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT umeta_id FROM {$wpdb->usermeta}
				 WHERE meta_key LIKE %s
				    OR meta_key LIKE %s
				    OR meta_key LIKE %s",
				'ngg_%',
				'nextgen_%',
				'photocrati_%'
			)
		);
		foreach ( (array) $meta_keys as $umeta_id ) {
			$deleted = $wpdb->delete( $wpdb->usermeta, [ 'umeta_id' => (int) $umeta_id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( $deleted ) {
				++$removed['usermeta'];
			}
		}

		foreach ( self::find_cron_hooks() as $hook ) {
			$count_for_hook = 0;
			while ( $timestamp = wp_next_scheduled( $hook ) ) {
				if ( wp_unschedule_event( $timestamp, $hook ) ) {
					++$count_for_hook;
				} else {
					break;
				}
			}
			$removed['cron'] += $count_for_hook;
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
		$patterns = [
			$wpdb->esc_like( $wpdb->prefix . 'ngg_' ) . '%',
			$wpdb->esc_like( $wpdb->prefix . 'photocrati_' ) . '%',
		];
		foreach ( $patterns as $pattern ) {
			$rows = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $rows ) {
				$tables = array_merge( $tables, $rows );
			}
		}
		$tables = array_values( array_unique( array_filter( array_map( 'strval', $tables ) ) ) );
		sort( $tables );
		return $tables;
	}

	private static function find_dirs(): array {
		$dirs = [];
		$candidates = [
			WP_CONTENT_DIR . '/gallery',
			WP_CONTENT_DIR . '/cache/ngg',
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
				if ( false !== strpos( $hook_l, 'ngg' ) || false !== strpos( $hook_l, 'nextgen' ) || false !== strpos( $hook_l, 'photocrati' ) ) {
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

	// -----------------------------------------------------------------------
	// Batched AJAX cleanup — replaces heavy admin-post, prevents 504
	// -----------------------------------------------------------------------

	/**
	 * AJAX: Return fresh scan data as JSON (read-only).
	 */
	public function ajax_cleanup_scan(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mlgmp_ajax' ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
		}
		ob_start();
		$scan = self::scan();
		ob_end_clean();
		wp_send_json_success( [ 'scan' => $scan ] );
	}

	/**
	 * AJAX: Execute ONE cleanup step per request.
	 *
	 * POST params:
	 *   nonce      – mlgmp_ajax nonce
	 *   step       – 'options' | 'usermeta' | 'cron' | 'tables' | 'dirs'
	 *   allow_dirs – '1' to permit folder deletion (default '0')
	 *   batch      – int items per call (default 100)
	 *   offset     – int cursor within step (default 0)
	 */
	public function ajax_cleanup_batch(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mlgmp_ajax' ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
		}

		$valid_steps = [ 'options', 'usermeta', 'cron', 'tables', 'dirs' ];
		$step        = sanitize_key( wp_unslash( $_POST['step'] ?? '' ) );
		if ( ! in_array( $step, $valid_steps, true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid step: ' . $step ], 400 );
		}

		$allow_dirs = '1' === ( $_POST['allow_dirs'] ?? '0' );
		$batch_size = max( 10, min( 500, (int) ( $_POST['batch']  ?? 100 ) ) );
		$offset     = max( 0,            (int) ( $_POST['offset'] ?? 0   ) );

		// Safety guards.
		$state = State::get();
		if ( in_array( $state['status'], [ State::STATUS_RUNNING, State::STATUS_PAUSED ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Migração em andamento. Finalize antes de limpar.', 'ml-gallery-migrator-pro' ) ] );
		}
		$scan = self::scan();
		if ( $scan['plugin_active'] ) {
			wp_send_json_error( [ 'message' => __( 'Plugin de origem ainda ativo. Desative-o antes de limpar.', 'ml-gallery-migrator-pro' ) ] );
		}

		ob_start();
		global $wpdb;

		$removed   = 0;
		$remaining = 0;
		$done      = false;

		switch ( $step ) {

			case 'options':
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$names = $wpdb->get_col( $wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options}
					 WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s
					    OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s
					    OR option_name LIKE %s OR option_name LIKE %s
					 LIMIT %d OFFSET %d",
					'ngg_%', 'nextgen_%', 'photocrati_%', 'widget_ngg%',
					'_transient_ngg_%', '_transient_photocrati_%',
					'_site_transient_ngg_%', '_site_transient_photocrati_%',
					$batch_size, $offset
				) );
				// phpcs:enable
				foreach ( (array) $names as $name ) {
					if ( delete_option( $name ) )           { $removed++; }
					if ( is_multisite() && delete_site_option( $name ) ) { $removed++; }
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$remaining = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options}
					 WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s
					    OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s
					    OR option_name LIKE %s OR option_name LIKE %s",
					'ngg_%', 'nextgen_%', 'photocrati_%', 'widget_ngg%',
					'_transient_ngg_%', '_transient_photocrati_%',
					'_site_transient_ngg_%', '_site_transient_photocrati_%'
				) );
				$done = count( (array) $names ) < $batch_size;
				break;

			case 'usermeta':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ids = $wpdb->get_col( $wpdb->prepare(
					"SELECT umeta_id FROM {$wpdb->usermeta}
					 WHERE meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s
					 LIMIT %d",
					'ngg_%', 'nextgen_%', 'photocrati_%', $batch_size
				) );
				foreach ( (array) $ids as $umeta_id ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					if ( $wpdb->delete( $wpdb->usermeta, [ 'umeta_id' => (int) $umeta_id ], [ '%d' ] ) ) {
						$removed++;
					}
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$remaining = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->usermeta}
					 WHERE meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s",
					'ngg_%', 'nextgen_%', 'photocrati_%'
				) );
				$done = count( (array) $ids ) < $batch_size;
				break;

			case 'cron':
				foreach ( self::find_cron_hooks() as $hook ) {
					while ( $timestamp = wp_next_scheduled( $hook ) ) {
						if ( wp_unschedule_event( $timestamp, $hook ) ) { $removed++; } else { break; }
					}
				}
				$remaining = count( self::find_cron_hooks() );
				$done      = true;
				break;

			case 'tables':
				foreach ( self::find_tables() as $table ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
					$removed++;
				}
				$remaining = count( self::find_tables() );
				$done      = true;
				break;

			case 'dirs':
				if ( $allow_dirs ) {
					foreach ( self::find_dirs() as $dir ) {
						if ( self::rrmdir( $dir ) ) { $removed++; }
					}
				}
				$remaining = count( self::find_dirs() );
				$done      = true;
				break;
		}

		$label_map = [
			'options'  => __( 'Options/Transients', 'ml-gallery-migrator-pro' ),
			'usermeta' => __( 'Usermeta',            'ml-gallery-migrator-pro' ),
			'cron'     => __( 'Cron hooks',          'ml-gallery-migrator-pro' ),
			'tables'   => __( 'Tabelas',             'ml-gallery-migrator-pro' ),
			'dirs'     => __( 'Pastas',              'ml-gallery-migrator-pro' ),
		];

		ob_end_clean();
		wp_send_json_success( [
			'step'      => $step,
			'label'     => $label_map[ $step ] ?? $step,
			'removed'   => $removed,
			'remaining' => $remaining,
			'done'      => $done,
		] );
	}

		private function redirect( string $status, array $result = [] ): void {
		$url = add_query_arg(
			[
				'page'               => MLGMP_SLUG,
				'mlgmp_cleanup'      => $status,
				'mlgmp_cleanup_data' => rawurlencode( wp_json_encode( $result ) ),
			],
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
