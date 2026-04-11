<?php
/**
 * Persistent migration logger — writes to DB and keeps a JSON snapshot
 * in an option for quick UI reads.
 *
 * @package MLGalleryMigratorPro
 */

namespace MLGMP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Logger {

	const LEVEL_INFO    = 'info';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR   = 'error';
	const LEVEL_ORPHAN  = 'orphan';
	const LEVEL_DEBUG   = 'debug';

	/** @var string */
	private $session_id;

	/** @var string */
	private $stage;

	public function __construct( string $session_id, string $stage = '' ) {
		$this->session_id = $session_id;
		$this->stage      = $stage;
	}

	public function set_stage( string $stage ): void {
		$this->stage = $stage;
	}

	// -----------------------------------------------------------------------
	// Entry points
	// -----------------------------------------------------------------------

	public function info( string $message, array $context = [] ): void {
		$this->write( self::LEVEL_INFO, $message, $context );
	}

	public function warning( string $message, array $context = [] ): void {
		$this->write( self::LEVEL_WARNING, $message, $context );
	}

	public function error( string $message, array $context = [] ): void {
		$this->write( self::LEVEL_ERROR, $message, $context );
	}

	public function orphan( string $message, array $context = [] ): void {
		$this->write( self::LEVEL_ORPHAN, $message, $context );
	}

	public function debug( string $message, array $context = [] ): void {
		$this->write( self::LEVEL_DEBUG, $message, $context );
	}

	// -----------------------------------------------------------------------
	// Internal
	// -----------------------------------------------------------------------

	private function write( string $level, string $message, array $context ): void {
		global $wpdb;

		$table = Installer::tables()['migration_log'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			[
				'session_id' => $this->session_id,
				'level'      => $level,
				'stage'      => $this->stage,
				'message'    => $message,
				'context'    => ! empty( $context ) ? wp_json_encode( $context ) : null,
				'created_at' => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	// -----------------------------------------------------------------------
	// Queries for UI
	// -----------------------------------------------------------------------

	/**
	 * Fetch recent log entries for a session.
	 *
	 * @param string   $session_id
	 * @param int      $limit
	 * @param int      $after_id   Only return entries with id > this value.
	 * @param string[] $levels     Filter by levels (empty = all).
	 * @return array<int,array<string,string>>
	 */
	public static function get_entries(
		string $session_id,
		int $limit = 200,
		int $after_id = 0,
		array $levels = []
	): array {
		global $wpdb;

		$table      = Installer::tables()['migration_log'];
		$level_clause = '';

		if ( ! empty( $levels ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $levels ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$level_clause = $wpdb->prepare( " AND level IN ({$placeholders})", ...$levels );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, level, stage, message, context, created_at
				 FROM {$table}
				 WHERE session_id = %s
				   AND id > %d
				   {$level_clause}
				 ORDER BY id DESC
				 LIMIT %d",
				$session_id,
				$after_id,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Delete all log entries for a session.
	 *
	 * @param string $session_id
	 */
	public static function clear( string $session_id ): void {
		global $wpdb;
		$table = Installer::tables()['migration_log'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete( $table, [ 'session_id' => $session_id ], [ '%s' ] );
	}

	/**
	 * Delete ALL log entries (full reset).
	 */
	public static function clear_all(): void {
		global $wpdb;
		$table = Installer::tables()['migration_log'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}
}
