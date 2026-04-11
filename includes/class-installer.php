<?php
/**
 * Installs/removes plugin database tables.
 *
 * @package MLGalleryMigratorPro
 */

namespace MLGMP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Installer {

	// -----------------------------------------------------------------------
	// Table names
	// -----------------------------------------------------------------------

	/**
	 * Returns all plugin table names keyed by logical name.
	 *
	 * @return array<string,string>
	 */
	public static function tables(): array {
		global $wpdb;
		return [
			'migration_log' => $wpdb->prefix . 'mlgmp_migration_log',
			'id_map'        => $wpdb->prefix . 'mlgmp_id_map',
		];
	}

	// -----------------------------------------------------------------------
	// Lifecycle
	// -----------------------------------------------------------------------

	public static function activate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$t       = self::tables();

		// ── Migration log ────────────────────────────────────────────────────
		dbDelta( "CREATE TABLE {$t['migration_log']} (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id VARCHAR(64)  NOT NULL DEFAULT '',
			level      VARCHAR(16)  NOT NULL DEFAULT 'info',
			stage      VARCHAR(64)  NOT NULL DEFAULT '',
			message    TEXT         NOT NULL,
			context    LONGTEXT     NULL,
			created_at DATETIME     NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY level      (level),
			KEY stage      (stage),
			KEY created_at (created_at)
		) {$collate};" );

		// ── Source → destination ID map ──────────────────────────────────────
		dbDelta( "CREATE TABLE {$t['id_map']} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id  VARCHAR(64)  NOT NULL DEFAULT '',
			entity_type VARCHAR(32)  NOT NULL DEFAULT '',
			source_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
			dest_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status      VARCHAR(20)  NOT NULL DEFAULT 'done',
			note        TEXT         NULL,
			created_at  DATETIME     NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY entity_source (session_id, entity_type, source_id),
			KEY session_id  (session_id),
			KEY entity_type (entity_type),
			KEY status      (status)
		) {$collate};" );

		// Seed default options if missing.
		add_option( 'mlgmp_version', MLGMP_VERSION );
		add_option( 'mlgmp_state',   [] );
	}

	public static function deactivate(): void {
		// Intentionally leaves data in place (user may reactivate).
	}

	/**
	 * Hard uninstall — call from uninstall.php if desired.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		global $wpdb;
		$t = self::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $t as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
		delete_option( 'mlgmp_version' );
		delete_option( 'mlgmp_state' );
	}
}
