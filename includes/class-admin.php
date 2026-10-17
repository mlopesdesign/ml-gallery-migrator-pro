<?php
/**
 * Admin panel registration and rendering.
 *
 * @package MLGalleryMigratorPro
 */

namespace MLGMP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin {

	private static ?Admin $instance = null;

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
		add_action( 'admin_menu',             [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices',          [ $this, 'render_cleanup_notice' ] );
	}

	// -----------------------------------------------------------------------
	// Menu
	// -----------------------------------------------------------------------

	public function register_menu(): void {
		add_menu_page(
			__( 'ML Gallery Migrator Pro', 'ml-gallery-migrator-pro' ),
			__( 'ML Migrator NGG', 'ml-gallery-migrator-pro' ),
			'manage_options',
			MLGMP_SLUG,
			[ $this, 'render_page' ],
			'dashicons-format-gallery',
			58.7
		);
	}

	// -----------------------------------------------------------------------
	// Assets
	// -----------------------------------------------------------------------

	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_' . MLGMP_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'mlgmp-admin',
			MLGMP_URL . 'assets/css/admin.css',
			[],
			MLGMP_VERSION
		);

		wp_enqueue_script(
			'mlgmp-admin',
			MLGMP_URL . 'assets/js/admin.js',
			[],
			MLGMP_VERSION,
			true
		);

		wp_localize_script(
			'mlgmp-admin',
			'MLGMP',
			[
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'mlgmp_ajax' ),
				'version'    => MLGMP_VERSION,
				'strings'    => [
					'confirm_reset'   => __( 'Isso apagará TODO o histórico desta sessão de migração. Tem certeza?', 'ml-gallery-migrator-pro' ),
					'confirm_cancel'  => __( 'Interromper a migração agora?', 'ml-gallery-migrator-pro' ),
					'ngg_missing'     => __( 'NextGEN Gallery não encontrado neste site.', 'ml-gallery-migrator-pro' ),
					'mlgp_missing'    => __( 'ML Gallery Pro não encontrado. Instale e ative primeiro.', 'ml-gallery-migrator-pro' ),
					'stage_galleries' => __( 'Galerias', 'ml-gallery-migrator-pro' ),
					'stage_albums'    => __( 'Álbuns', 'ml-gallery-migrator-pro' ),
					'stage_images'    => __( 'Imagens', 'ml-gallery-migrator-pro' ),
					'stage_thumbs'    => __( 'Miniaturas', 'ml-gallery-migrator-pro' ),
					'stage_shortcodes'=> __( 'Shortcodes', 'ml-gallery-migrator-pro' ),
					'stage_done'      => __( 'Concluído', 'ml-gallery-migrator-pro' ),
				],
			]
		);
	}

	// -----------------------------------------------------------------------
	// Render
	// -----------------------------------------------------------------------


	public function render_cleanup_notice(): void {
		if ( ! isset( $_GET['page'] ) || MLGMP_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		if ( empty( $_GET['mlgmp_cleanup'] ) ) {
			return;
		}
		$status = sanitize_key( wp_unslash( $_GET['mlgmp_cleanup'] ) );
		$kind   = ! empty( $_GET['mlgmp_cleanup_kind'] ) ? sanitize_key( wp_unslash( $_GET['mlgmp_cleanup_kind'] ) ) : 'ngg';
		$label  = 'photo_gallery' === $kind ? __( 'Photo Gallery', 'ml-gallery-migrator-pro' ) : __( 'NextGEN', 'ml-gallery-migrator-pro' );
		$data   = [];
		if ( ! empty( $_GET['mlgmp_cleanup_data'] ) ) {
			$json = wp_unslash( rawurldecode( (string) $_GET['mlgmp_cleanup_data'] ) );
			$decoded = json_decode( $json, true );
			if ( is_array( $decoded ) ) {
				$data = $decoded;
			}
		}
		echo '<div class="notice ' . ( 'done' === $status ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>';
		switch ( $status ) {
			case 'done':
				printf(
					esc_html__( 'Limpeza de %1$s concluída. Tabelas: %2$d | options/transients: %3$d | usermeta: %4$d | cron: %5$d | pastas: %6$d', 'ml-gallery-migrator-pro' ),
					esc_html( $label ),
					(int) ( $data['tables'] ?? 0 ),
					(int) ( $data['options'] ?? 0 ),
					(int) ( $data['usermeta'] ?? 0 ),
					(int) ( $data['cron'] ?? 0 ),
					(int) ( $data['dirs'] ?? 0 )
				);
				break;
			case 'active':
				printf( esc_html__( 'A limpeza foi bloqueada porque %s ainda está ativo.', 'ml-gallery-migrator-pro' ), esc_html( $label ) );
				break;
			case 'busy':
				echo esc_html__( 'A limpeza foi bloqueada porque a migração está em execução ou pausada.', 'ml-gallery-migrator-pro' );
				break;
		}
		echo '</p></div>';
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permissão negada.', 'ml-gallery-migrator-pro' ) );
		}

		// Get current active tab.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'migration';

		$state       = State::get();
		$analysis    = $state['analysis'];
		$analyses    = isset( $state['analyses'] ) && is_array( $state['analyses'] ) ? $state['analyses'] : [ 'ngg' => null, 'photo_gallery' => null ];
		$status      = $state['status'];
		$source      = isset( $state['source'] ) && in_array( $state['source'], [ 'ngg', 'photo_gallery' ], true ) ? $state['source'] : 'ngg';
		$source_ok   = $this->source_active( $source );
		$source_meta = $this->source_meta( $source );
		$mlgp_ok     = $this->mlgp_active();
		$reliability_reports = [
			'ngg'           => Reliability::build_report( 'ngg', $state ),
			'photo_gallery' => Reliability::build_report( 'photo_gallery', $state ),
		];
		$cleanup_scans = [
			'ngg'           => NGGCleaner::scan(),
			'photo_gallery' => PhotoGalleryCleaner::scan(),
		];
		$license       = License::summary();

		?>
		<div class="wrap mlgp-shell">
			<div class="mlgp-shell__hero">
				<div class="mlgp-shell__brand">
					<div class="mlgp-shell__mark">
						<?php if ( $mlgp_ok ) : ?>
							<img src="<?php echo esc_url( WP_PLUGIN_URL . '/ml-gallery-pro/assets/images/logo.png' ); ?>" alt="ML Gallery Pro">
						<?php else : ?>
							<span style="font-size: 32px;">⚡</span>
						<?php endif; ?>
					</div>
					<div class="mlgp-shell__copy">
						<span class="mlgp-shell__eyebrow"><?php esc_html_e( 'Utilitário Complementar', 'ml-gallery-migrator-pro' ); ?></span>
						<h1><?php esc_html_e( 'ML Gallery Migrator Pro', 'ml-gallery-migrator-pro' ); ?></h1>
						<p><?php esc_html_e( 'Copiador oficial de acervo do NextGEN Gallery e Photo Gallery para a estrutura inteligente do ML Gallery Pro.', 'ml-gallery-migrator-pro' ); ?></p>
					</div>
				</div>
				<div class="mlgp-shell__meta">
					<span class="mlgp-shell__version">v<?php echo esc_html( MLGMP_VERSION ); ?></span>
					<div class="mlgp-shell__tags">
						<span class="mlgp-shell__tag"><?php echo esc_html( sprintf( __( 'Licença: %s', 'ml-gallery-migrator-pro' ), $license['label'] ) ); ?></span>
						<span class="mlgp-shell__tag"><?php echo esc_html( sprintf( __( 'Origem atual: %s', 'ml-gallery-migrator-pro' ), $source_meta['label'] ) ); ?></span>
					</div>
				</div>
			</div>

			<nav class="mlgp-shell__tabs" aria-label="<?php esc_attr_e( 'Navegação do plugin', 'ml-gallery-migrator-pro' ); ?>">
				<?php if ( $mlgp_ok ) : ?>
					<a class="mlgp-shell__tab" href="<?php echo esc_url( admin_url( 'admin.php?page=mlgp-dashboard' ) ); ?>">&larr; <?php esc_html_e( 'Voltar para o Painel ML Gallery', 'ml-gallery-migrator-pro' ); ?></a>
				<?php endif; ?>
				<a class="mlgp-shell__tab <?php echo 'migration' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . MLGMP_SLUG . '&tab=migration' ) ); ?>"><?php esc_html_e( 'Motor de Cópia', 'ml-gallery-migrator-pro' ); ?></a>
				<a class="mlgp-shell__tab <?php echo 'config' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . MLGMP_SLUG . '&tab=config' ) ); ?>"><?php esc_html_e( 'Configuração', 'ml-gallery-migrator-pro' ); ?></a>
				<a class="mlgp-shell__tab <?php echo 'repair' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . MLGMP_SLUG . '&tab=repair' ) ); ?>"><?php esc_html_e( 'Reparo', 'ml-gallery-migrator-pro' ); ?></a>
				<a class="mlgp-shell__tab <?php echo 'license' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( License::tab_url() ); ?>"><?php esc_html_e( 'Licença', 'ml-gallery-migrator-pro' ); ?></a>
			</nav>

			<div class="mlgp-shell__content" id="mlgmp-app">
			<?php
			if ( 'license' !== $tab && License::is_locked() ) {
				$this->render_locked_banner();
			}
			if ( 'license' === $tab ) {
				$this->render_license_tab( $license );
			} elseif ( 'config' === $tab ) {
				$this->render_config_tab();
			} elseif ( 'repair' === $tab ) {
				$this->render_repair_tab( $source );
			} else {
				// Migration tab (default).
				?>
				<?php $this->render_notices( $source, $source_ok, $mlgp_ok ); ?>
				<div class="mlgmp-status-bar" id="mlgmp-status-bar">
					<div class="mlgmp-status-indicator" id="mlgmp-status-indicator" data-status="<?php echo esc_attr( $status ); ?>">
						<span class="mlgmp-status-dot"></span>
						<span class="mlgmp-status-label" id="mlgmp-status-label"><?php echo esc_html( $this->status_label( $status ) ); ?></span>
					</div>
					<div class="mlgmp-stage-badge" id="mlgmp-stage-badge"><?php if ( $status !== State::STATUS_IDLE ) { echo esc_html( $this->stage_label( $state['current_stage'] ) ); } ?></div>
				</div>
				<div class="mlgmp-progress-wrap" id="mlgmp-progress-wrap">
					<?php $grand_total = State::grand_total( $state ); $processed = State::total_processed( $state ); $pct = $grand_total > 0 ? min( 100, round( $processed / $grand_total * 100 ) ) : 0; ?>
					<div class="mlgmp-progress-bar-outer"><div class="mlgmp-progress-bar-inner" id="mlgmp-progress-bar" style="width:<?php echo esc_attr( $pct ); ?>%"></div></div>
					<div class="mlgmp-progress-meta"><span id="mlgmp-progress-pct"><?php echo esc_html( $pct ); ?>%</span><span id="mlgmp-progress-count"><?php echo esc_html( $processed . ' / ' . $grand_total ); ?></span></div>
				</div>
				<div class="mlgmp-operational-section">
					<?php $this->render_analysis_panel( $analyses, $source ); ?>
					<div class="mlgp-grid-two">
						<div><?php $this->render_controls( $status, $source, $source_ok, $mlgp_ok, $state, $cleanup_scans ); ?></div>
						<div><?php $this->render_counters( $state ); ?></div>
					</div>
				</div>
				<div class="mlgmp-reliability-section" style="margin-top: 20px;"><?php $this->render_reliability_panel( $reliability_reports, $source ); ?></div>
				<div class="mlgmp-logs-section" style="margin-top: 20px;"><?php $this->render_log_panel( $state ); ?></div>
				<?php
			}
			?>
			</div>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Render sub-sections
	// -----------------------------------------------------------------------

	private function render_notices( string $source, bool $source_ok, bool $mlgp_ok ): void {
		$meta      = $this->source_meta( $source );
		$detection = 'photo_gallery' === $source ? PhotoGalleryReader::detect() : NGGReader::detect();
		echo '<div class="mlgp-notice-stack" id="mlgmp-notice-stack">';
		if ( $source_ok ) {
			echo '<div class="mlgp-notice is-success"><strong>' . esc_html( sprintf( __( '✅ %s detectado.', 'ml-gallery-migrator-pro' ), $meta['label'] ) ) . '</strong> <small>' . sprintf( esc_html__( 'Tabelas: %s', 'ml-gallery-migrator-pro' ), implode( ', ', $detection['tables_found'] ) ) . '</small></div>';
		}
		echo '</div>';
		if ( ! $source_ok ) {
			echo '<div class="mlgp-notice is-error" style="margin-bottom: 20px;">';
			echo '<strong>' . esc_html( sprintf( __( '%s não detectado.', 'ml-gallery-migrator-pro' ), $meta['label'] ) ) . '</strong><br><small>';
			echo sprintf( esc_html__( 'Plugin ativo: %s', 'ml-gallery-migrator-pro' ), ( $detection['plugin_active'] ? __( 'SIM', 'ml-gallery-migrator-pro' ) : __( 'NÃO', 'ml-gallery-migrator-pro' ) ) ) . ' &nbsp;|&nbsp; ';
			echo sprintf( esc_html__( 'Tabelas encontradas: %s', 'ml-gallery-migrator-pro' ), ( $detection['tables_found'] ? implode( ', ', $detection['tables_found'] ) : __( 'nenhuma', 'ml-gallery-migrator-pro' ) ) ) . ' &nbsp;|&nbsp; ';
			echo sprintf( esc_html__( 'Tabelas ausentes: %s', 'ml-gallery-migrator-pro' ), ( $detection['tables_missing'] ? implode( ', ', $detection['tables_missing'] ) : __( 'nenhuma', 'ml-gallery-migrator-pro' ) ) ) . ' &nbsp;|&nbsp; ';
			echo sprintf( esc_html__( 'BD: %s', 'ml-gallery-migrator-pro' ), $detection['db_name'] );
			echo '</small></div>';
		}
		if ( ! $mlgp_ok ) {
			echo '<div class="mlgp-notice is-error" style="margin-bottom: 20px;"><strong>' . esc_html__( 'ML Gallery Pro não encontrado.', 'ml-gallery-migrator-pro' ) . '</strong> ' . esc_html__( 'Instale e ative o ML Gallery Pro antes de migrar.', 'ml-gallery-migrator-pro' ) . '</div>';
		}
	}

	private function render_analysis_panel( array $analyses, string $source ): void {
		$tabs = [
			'ngg'           => $this->source_meta( 'ngg' ),
			'photo_gallery' => $this->source_meta( 'photo_gallery' ),
		];
		?>
		<div class="mlgp-panel" id="mlgmp-analysis-panel">
			<div class="mlgmp-analysis-head">
				<div>
					<h2 class="mlgmp-analysis-title"><?php esc_html_e( 'Análise de Acervo', 'ml-gallery-migrator-pro' ); ?></h2>
					<p class="mlgmp-analysis-subtitle"><?php esc_html_e( 'Cada origem com sua própria leitura de acervo, sem misturar contadores.', 'ml-gallery-migrator-pro' ); ?></p>
				</div>
				<div class="mlgmp-analysis-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Abas de análise', 'ml-gallery-migrator-pro' ); ?>">
					<?php foreach ( $tabs as $tab_key => $tab_meta ) : ?>
						<button
							type="button"
							class="mlgmp-analysis-tab <?php echo $tab_key === $source ? 'is-active' : ''; ?>"
							data-analysis-tab="<?php echo esc_attr( $tab_key ); ?>"
							role="tab"
							aria-selected="<?php echo $tab_key === $source ? 'true' : 'false'; ?>"
						>
							<?php echo esc_html( $tab_meta['label'] ); ?>
						</button>
					<?php endforeach; ?>
				</div>
			</div>
			<?php foreach ( $tabs as $tab_key => $tab_meta ) : ?>
				<div
					class="mlgmp-analysis-pane <?php echo $tab_key === $source ? 'is-active' : ''; ?>"
					id="mlgmp-analysis-pane-<?php echo esc_attr( $tab_key ); ?>"
					data-analysis-pane="<?php echo esc_attr( $tab_key ); ?>"
				>
					<div class="mlgmp-analysis-pane__bar">
						<div>
							<h3 class="mlgmp-analysis-pane__title"><?php echo esc_html( sprintf( __( 'Análise do Acervo %s', 'ml-gallery-migrator-pro' ), $tab_meta['short'] ) ); ?></h3>
							<p class="mlgmp-analysis-pane__desc"><?php echo esc_html( sprintf( __( 'Leitura exclusiva da origem %s.', 'ml-gallery-migrator-pro' ), $tab_meta['label'] ) ); ?></p>
						</div>
						<button type="button" class="mlgp-button mlgp-button--ghost mlgmp-btn-analyse-source" data-source="<?php echo esc_attr( $tab_key ); ?>">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg><?php esc_html_e( 'Analisar', 'ml-gallery-migrator-pro' ); ?>
						</button>
					</div>
					<div class="mlgmp-analysis-grid" id="mlgmp-analysis-grid-<?php echo esc_attr( $tab_key ); ?>"><?php $this->render_analysis_numbers( $analyses[ $tab_key ] ?? null ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_analysis_numbers( ?array $analysis ): void {
		if ( ! $analysis ) {
			echo '<p class="mlgmp-muted">' . esc_html__( 'Clique em "Analisar" para inspecionar o acervo.', 'ml-gallery-migrator-pro' ) . '</p>';
			return;
		}
		if ( isset( $analysis['error'] ) ) {
			echo '<p class="mlgmp-error">' . esc_html( $analysis['error'] ) . '</p>';
			return;
		}
		$items = [
			[ 'label' => __( 'Galerias', 'ml-gallery-migrator-pro' ),   'key' => 'galleries',  'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>' ],
			[ 'label' => __( 'Álbuns', 'ml-gallery-migrator-pro' ),     'key' => 'albums',     'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>' ],
			[ 'label' => __( 'Imagens', 'ml-gallery-migrator-pro' ),    'key' => 'images',     'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>' ],
			[ 'label' => __( 'Miniaturas', 'ml-gallery-migrator-pro' ), 'key' => 'thumbs',     'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><rect x="8" y="8" width="8" height="8"></rect></svg>' ],
			[ 'label' => __( 'Shortcodes', 'ml-gallery-migrator-pro' ), 'key' => 'shortcodes', 'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>' ],
		];
		foreach ( $items as $item ) {
			$n = isset( $analysis[ $item['key'] ] ) ? (int) $analysis[ $item['key'] ] : 0;
			echo '<div class="mlgmp-stat"><span class="mlgmp-stat__icon">' . $item['icon'] . '</span><span class="mlgmp-stat__num" id="mlgmp-stat-' . esc_attr( $item['key'] ) . '">' . esc_html( number_format_i18n( $n ) ) . '</span><span class="mlgmp-stat__label">' . esc_html( $item['label'] ) . '</span></div>';
		}
	}

	private function render_controls( string $status, string $source, bool $source_ok, bool $mlgp_ok, array $state, array $cleanup_scans ): void {
		$enabled    = $source_ok && $mlgp_ok;
		$is_idle    = in_array( $status, [ State::STATUS_IDLE, State::STATUS_DONE, State::STATUS_CANCELLED, State::STATUS_ERROR ], true );
		$is_running = $status === State::STATUS_RUNNING;
		$is_paused  = $status === State::STATUS_PAUSED;
		$meta               = $this->source_meta( $source );
		$cleanup_scan       = $cleanup_scans[ $source ] ?? NGGCleaner::scan();
		$cleanup_meta_map   = [
			'ngg'           => $this->source_meta( 'ngg' ),
			'photo_gallery' => $this->source_meta( 'photo_gallery' ),
		];
		?>
		<div class="mlgp-panel">
			<div style="margin-bottom: 24px;">
				<h2 style="margin: 0; font-size: 16px; font-weight: 600;"><?php esc_html_e( 'Controles', 'ml-gallery-migrator-pro' ); ?></h2>
			</div>

			<div class="mlgmp-field-row" style="flex-direction: column; align-items: flex-start; gap: 8px; margin-bottom: 12px;">
				<label><strong><?php esc_html_e( 'Origem:', 'ml-gallery-migrator-pro' ); ?></strong></label>
				<div style="display:flex; gap:10px; flex-wrap:wrap;">
					<label class="mlgmp-radio-label"><input type="radio" name="mlgmp_source" value="ngg" <?php checked( 'ngg', $source ); ?> /> <?php esc_html_e( 'NextGEN Gallery', 'ml-gallery-migrator-pro' ); ?></label>
					<label class="mlgmp-radio-label"><input type="radio" name="mlgmp_source" value="photo_gallery" <?php checked( 'photo_gallery', $source ); ?> /> <?php esc_html_e( 'Photo Gallery', 'ml-gallery-migrator-pro' ); ?></label>
				</div>
			</div>

			<!--  Mode selector  -->
			<div class="mlgmp-mode-row">
				<label class="mlgmp-radio-label">
					<input type="radio" name="mlgmp_mode" value="continuous"
						<?php checked( $state['mode'], 'continuous' ); ?> />
					<?php esc_html_e( 'Modo contínuo', 'ml-gallery-migrator-pro' ); ?>
				</label>
				<label class="mlgmp-radio-label">
					<input type="radio" name="mlgmp_mode" value="single"
						<?php checked( $state['mode'], 'single' ); ?> />
					<?php esc_html_e( 'Rodar 1 lote', 'ml-gallery-migrator-pro' ); ?>
				</label>
			</div>

			<!--  Batch size  -->
			<div class="mlgmp-field-row">
				<label for="mlgmp-batch-size"><?php esc_html_e( 'Itens por lote:', 'ml-gallery-migrator-pro' ); ?></label>
				<input type="number" id="mlgmp-batch-size" min="1" max="5000"
					value="<?php echo esc_attr( (int) $state['batch_size'] ); ?>"
					class="small-text" />
			</div>

			<!--  Stages selection  -->
			<div class="mlgmp-field-row" style="flex-direction: column; align-items: flex-start; gap: 5px;">
				<label><strong><?php esc_html_e( 'O que migrar:', 'ml-gallery-migrator-pro' ); ?></strong></label>
				<div style="display: flex; flex-wrap: wrap; gap: 10px; margin-left: 5px;">
					<?php
					$stages_list = [
						'galleries'  => __( 'Galerias', 'ml-gallery-migrator-pro' ),
						'albums'     => __( 'Álbuns', 'ml-gallery-migrator-pro' ),
						'images'     => __( 'Imagens', 'ml-gallery-migrator-pro' ),
						'thumbs'     => __( 'Miniaturas', 'ml-gallery-migrator-pro' ),
						'shortcodes' => __( 'Shortcodes', 'ml-gallery-migrator-pro' ),
					];
					foreach ( $stages_list as $s_key => $s_label ) :
						$checked = ! empty( $state['stages_enabled'][ $s_key ] ) ? 'checked' : '';
						?>
						<label class="mlgmp-radio-label">
							<input type="checkbox" name="mlgmp_stages[]" value="<?php echo esc_attr( $s_key ); ?>" <?php echo $checked; ?> />
							<?php echo esc_html( $s_label ); ?>
						</label>
					<?php endforeach; ?>
					<label class="mlgmp-radio-label" style="border-left: 1px solid #ccc; padding-left: 10px;">
						<input type="checkbox" id="mlgmp-all-stages" checked />
						<strong><?php esc_html_e( 'Tudo', 'ml-gallery-migrator-pro' ); ?></strong>
					</label>
				</div>
			</div>

			<!--  Duplicate mode  -->
			<div class="mlgmp-field-row" style="margin-top: 10px;">
				<label><strong><?php esc_html_e( 'Se já existir:', 'ml-gallery-migrator-pro' ); ?></strong></label>
				<label class="mlgmp-radio-label">
					<input type="radio" name="mlgmp_dup_mode" value="ignore"
						<?php checked( empty( $state['duplicate_mode'] ) || 'ignore' === $state['duplicate_mode'] ); ?> />
					<?php esc_html_e( 'Ignorar', 'ml-gallery-migrator-pro' ); ?>
				</label>
				<label class="mlgmp-radio-label">
					<input type="radio" name="mlgmp_dup_mode" value="overwrite"
						<?php checked( 'overwrite' === ( $state['duplicate_mode'] ?? '' ) ); ?> />
					<?php esc_html_e( 'Sobrescrever', 'ml-gallery-migrator-pro' ); ?>
				</label>
			</div>


			<div class="mlgp-panel mlgmp-cleanup-card"
				 data-current-source="<?php echo esc_attr( $source ); ?>"
				 data-meta="<?php echo esc_attr( wp_json_encode( $cleanup_meta_map ) ); ?>"
				 data-scans="<?php echo esc_attr( wp_json_encode( $cleanup_scans ) ); ?>">
				<div class="mlgmp-cleanup-card__head">
					<div class="mlgmp-cleanup-card__top">
						<h3 id="mlgmp-cleanup-title" class="mlgmp-cleanup-card__title"><?php echo esc_html( sprintf( __( 'Limpeza final do %s', 'ml-gallery-migrator-pro' ), $meta['label'] ) ); ?></h3>
						<p id="mlgmp-cleanup-desc" class="mlgmp-cleanup-card__desc"><?php echo esc_html( $meta['cleanup_desc'] ); ?></p>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="mlgmp-cleanup-form" class="mlgmp-cleanup-card__form" onsubmit="return window.confirm(this.dataset.confirm || '<?php echo esc_js( $meta['cleanup_confirm'] ); ?>');">
							<input type="hidden" name="action" id="mlgmp-cleanup-action" value="<?php echo esc_attr( $meta['cleanup_action'] ); ?>">
							<?php wp_nonce_field( $meta['cleanup_nonce'], 'mlgmp_cleanup_nonce', false ); ?>
							<button type="submit" id="mlgmp-cleanup-button" class="mlgp-button mlgp-button--danger mlgmp-cleanup-card__button" <?php echo ( $is_running || $is_paused || $cleanup_scan['plugin_active'] ) ? 'disabled' : ''; ?>>
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14H6L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M9 6V4h6v2"></path></svg>
								<?php echo esc_html( $meta['cleanup_button'] ); ?>
							</button>
						</form>

						<?php if ( $cleanup_scan['plugin_active'] ) : ?>
							<div id="mlgmp-cleanup-message" class="mlgmp-cleanup-card__alert"><?php echo esc_html( sprintf( __( 'Bloqueado: o %s ainda está ativo.', 'ml-gallery-migrator-pro' ), $meta['label'] ) ); ?></div>
						<?php elseif ( $is_running || $is_paused ) : ?>
							<div id="mlgmp-cleanup-message" class="mlgmp-cleanup-card__alert"><?php esc_html_e( 'Bloqueado: finalize ou cancele a migração antes de limpar.', 'ml-gallery-migrator-pro' ); ?></div>
						<?php else : ?>
							<div id="mlgmp-cleanup-message" class="mlgmp-cleanup-card__hint"><?php echo esc_html( sprintf( __( 'Liberado para limpeza definitiva do %s.', 'ml-gallery-migrator-pro' ), $meta['label'] ) ); ?></div>
						<?php endif; ?>

						<label style="display:flex;align-items:center;gap:7px;margin-top:10px;font-size:12px;color:var(--mlgp-muted);cursor:pointer;">
							<input type="checkbox" id="mlgmp-cleanup-allow-dirs" value="1">
							<?php esc_html_e( 'Remover também as pastas NGG (wp-content/gallery, cache/ngg)', 'ml-gallery-migrator-pro' ); ?>
						</label>

						<div id="mlgmp-cleanup-progress" style="display:none;margin-top:12px;padding:12px 14px;border:1px solid var(--mlgp-line);border-radius:10px;background:var(--mlgp-surface-soft);font-size:12px;">
							<strong id="mlgmp-cleanup-prog-label" style="display:block;margin-bottom:8px;color:var(--mlgp-ink);"><?php esc_html_e( 'Iniciando limpeza…', 'ml-gallery-migrator-pro' ); ?></strong>
							<div style="display:flex;gap:16px;flex-wrap:wrap;color:var(--mlgp-muted);">
								<span><?php esc_html_e( 'Options:', 'ml-gallery-migrator-pro' ); ?> <strong id="mlgmp-cprog-options">—</strong></span>
								<span><?php esc_html_e( 'Usermeta:', 'ml-gallery-migrator-pro' ); ?> <strong id="mlgmp-cprog-usermeta">—</strong></span>
								<span><?php esc_html_e( 'Cron:', 'ml-gallery-migrator-pro' ); ?> <strong id="mlgmp-cprog-cron">—</strong></span>
								<span><?php esc_html_e( 'Tabelas:', 'ml-gallery-migrator-pro' ); ?> <strong id="mlgmp-cprog-tables">—</strong></span>
								<span><?php esc_html_e( 'Pastas:', 'ml-gallery-migrator-pro' ); ?> <strong id="mlgmp-cprog-dirs">—</strong></span>
							</div>
						</div>
					</div>

					<div class="mlgmp-cleanup-card__stats">
						<div class="mlgmp-cleanup-stat"><span id="mlgmp-cleanup-tables" class="mlgmp-cleanup-stat__value"><?php echo esc_html( (string) $cleanup_scan['tables_count'] ); ?></span><span class="mlgmp-cleanup-stat__label"><?php esc_html_e( 'Tabelas', 'ml-gallery-migrator-pro' ); ?></span></div>
						<div class="mlgmp-cleanup-stat"><span id="mlgmp-cleanup-options" class="mlgmp-cleanup-stat__value"><?php echo esc_html( (string) $cleanup_scan['options_count'] ); ?></span><span class="mlgmp-cleanup-stat__label"><?php esc_html_e( 'Options / Transients', 'ml-gallery-migrator-pro' ); ?></span></div>
						<div class="mlgmp-cleanup-stat"><span id="mlgmp-cleanup-usermeta" class="mlgmp-cleanup-stat__value"><?php echo esc_html( (string) $cleanup_scan['usermeta_count'] ); ?></span><span class="mlgmp-cleanup-stat__label"><?php esc_html_e( 'Usermeta', 'ml-gallery-migrator-pro' ); ?></span></div>
						<div class="mlgmp-cleanup-stat"><span id="mlgmp-cleanup-cron" class="mlgmp-cleanup-stat__value"><?php echo esc_html( (string) $cleanup_scan['cron_count'] ); ?></span><span class="mlgmp-cleanup-stat__label"><?php esc_html_e( 'Cron Hooks', 'ml-gallery-migrator-pro' ); ?></span></div>
						<div class="mlgmp-cleanup-stat"><span id="mlgmp-cleanup-dirs" class="mlgmp-cleanup-stat__value"><?php echo esc_html( (string) $cleanup_scan['dirs_count'] ); ?></span><span class="mlgmp-cleanup-stat__label"><?php esc_html_e( 'Pastas', 'ml-gallery-migrator-pro' ); ?></span></div>
					</div>
				</div>
			</div>

			<!--  Action buttons  -->
			<div class="mlgmp-btn-row" style="margin-top: 24px; display: flex; gap: 8px; flex-wrap: wrap;">
				<button type="button" id="mlgmp-btn-start"
					class="mlgp-button mlgp-button--accent"
					<?php echo ( ! $is_idle || ! $enabled ) ? 'disabled' : ''; ?>>
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
					<span class="mlgmp-btn-label"><?php esc_html_e( 'Iniciar', 'ml-gallery-migrator-pro' ); ?></span>
					<span class="mlgmp-btn-count" id="mlgmp-btn-start-count"></span>
				</button>

				<button type="button" id="mlgmp-btn-pause"
					class="mlgp-button"
					<?php echo ! $is_running ? 'disabled' : ''; ?>>
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px"><rect x="6" y="4" width="4" height="16"></rect><rect x="14" y="4" width="4" height="16"></rect></svg>
					<span class="mlgmp-btn-label"><?php esc_html_e( 'Pausar', 'ml-gallery-migrator-pro' ); ?></span>
					<span class="mlgmp-btn-count" id="mlgmp-btn-pause-count"></span>
				</button>

				<button type="button" id="mlgmp-btn-resume"
					class="mlgp-button mlgp-button--accent"
					<?php echo ! $is_paused ? 'disabled' : ''; ?>>
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
					<span class="mlgmp-btn-label"><?php esc_html_e( 'Retomar', 'ml-gallery-migrator-pro' ); ?></span>
					<span class="mlgmp-btn-count" id="mlgmp-btn-resume-count"></span>
				</button>

				<button type="button" id="mlgmp-btn-cancel"
					class="mlgp-button mlgp-button--danger"
					<?php echo ( ! $is_running && ! $is_paused ) ? 'disabled' : ''; ?>>
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
					<?php esc_html_e( 'Cancelar', 'ml-gallery-migrator-pro' ); ?>
				</button>

				<button type="button" id="mlgmp-btn-reset"
					class="mlgp-button mlgp-button--ghost"
					<?php echo $is_running ? 'disabled' : ''; ?>>
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.22-10.27l-5.12 5.12"></path></svg>
					<?php esc_html_e( 'Reset', 'ml-gallery-migrator-pro' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	private function render_counters( array $state ): void {
		$stages = [
			State::STAGE_GALLERIES  => __( 'Galerias', 'ml-gallery-migrator-pro' ),
			State::STAGE_ALBUMS     => __( 'Álbuns', 'ml-gallery-migrator-pro' ),
			State::STAGE_IMAGES     => __( 'Imagens', 'ml-gallery-migrator-pro' ),
			State::STAGE_THUMBS     => __( 'Miniaturas', 'ml-gallery-migrator-pro' ),
			State::STAGE_SHORTCODES => __( 'Shortcodes', 'ml-gallery-migrator-pro' ),
		];
		?>
		<div class="mlgp-panel" id="mlgmp-counters">
			<div style="margin-bottom: 24px;">
				<h2 style="margin: 0; font-size: 16px; font-weight: 600;"><?php esc_html_e( 'Progresso por Etapa', 'ml-gallery-migrator-pro' ); ?></h2>
			</div>
			<div style="flex: 1; display: flex; flex-direction: column; justify-content: center;">
				<div class="mlgmp-table-scroll">
					<table class="mlgmp-table">
						<thead>
						<tr>
							<th><?php esc_html_e( 'Etapa', 'ml-gallery-migrator-pro' ); ?></th>
							<th><?php esc_html_e( 'Convertidos', 'ml-gallery-migrator-pro' ); ?></th>
							<th><?php esc_html_e( 'Órfãos', 'ml-gallery-migrator-pro' ); ?></th>
							<th><?php esc_html_e( 'Ignorados (Rev/Draft)', 'ml-gallery-migrator-pro' ); ?></th>
							<th><?php esc_html_e( 'Erros Reais', 'ml-gallery-migrator-pro' ); ?></th>
						</tr>
						</thead>
						<tbody id="mlgmp-counters-body">
						<?php foreach ( $stages as $key => $label ) :
							$ctr = $state['counters'][ $key ] ?? [];
							$active = $state['current_stage'] === $key ? ' mlgmp-row--active' : '';
							
							// Agregados para o UI simplificado
							$processed = $ctr['processed'] ?? 0;
							$orphans   = $ctr['ignored_mapping'] ?? ( $ctr['orphans'] ?? 0 );
							$ignored   = ( $ctr['ignored_revision'] ?? 0 ) + ( $ctr['ignored_draft'] ?? 0 ) + ( $ctr['ignored_widget_orphan'] ?? 0 ) + ( $ctr['skipped'] ?? 0 );
							$errors    = $ctr['errors'] ?? 0;
							?>
							<tr class="mlgmp-counter-row<?php echo esc_attr( $active ); ?>"
								data-stage="<?php echo esc_attr( $key ); ?>">
								<td class="mlgmp-counter-stage"><?php echo esc_html( $label ); ?></td>
								<td class="mlgmp-counter-processed"><?php echo esc_html( (int) $processed ); ?></td>
								<td class="mlgmp-counter-orphans"><?php echo esc_html( (int) $orphans ); ?></td>
								<td class="mlgmp-counter-ignored"><?php echo esc_html( (int) $ignored ); ?></td>
								<td class="mlgmp-counter-errors"><?php echo esc_html( (int) $errors ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_reliability_panel( array $reports, string $source ): void {
		$tabs = [
			'ngg'           => $this->source_meta( 'ngg' ),
			'photo_gallery' => $this->source_meta( 'photo_gallery' ),
		];
		?>
		<div class="mlgp-panel" id="mlgmp-reliability-panel">
			<div class="mlgmp-analysis-head">
				<div>
					<h2 class="mlgmp-analysis-title"><?php esc_html_e( 'Reliability Layer', 'ml-gallery-migrator-pro' ); ?></h2>
					<p class="mlgmp-analysis-subtitle"><?php esc_html_e( 'Leitura paralela e somente-para-auditoria da origem, do destino e da conciliação desta sessão.', 'ml-gallery-migrator-pro' ); ?></p>
				</div>
				<div class="mlgmp-analysis-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Abas de confiabilidade', 'ml-gallery-migrator-pro' ); ?>">
					<?php foreach ( $tabs as $tab_key => $tab_meta ) : ?>
						<button
							type="button"
							class="mlgmp-reliability-tab <?php echo $tab_key === $source ? 'is-active' : ''; ?>"
							data-reliability-tab="<?php echo esc_attr( $tab_key ); ?>"
							role="tab"
							aria-selected="<?php echo $tab_key === $source ? 'true' : 'false'; ?>"
						>
							<?php echo esc_html( $tab_meta['label'] ); ?>
						</button>
					<?php endforeach; ?>
				</div>
			</div>
			<?php foreach ( $reports as $report_source => $report ) : ?>
				<?php
				$source_card = $report['source_card'];
				$dest_card   = $report['dest_card'];
				$recon_card  = $report['recon_card'];
				$status_card = $report['status_card'];
				$source_metrics = $source_card['metrics'];
				$dest_metrics   = $dest_card['metrics'];
				$mapped         = $recon_card['mapped'];
				$remaining      = $recon_card['remaining'];
				?>
				<div
					class="mlgmp-reliability-pane <?php echo $report_source === $source ? 'is-active' : ''; ?>"
					data-reliability-pane="<?php echo esc_attr( $report_source ); ?>"
				>
					<div class="mlgmp-reliability-grid">
						<article class="mlgmp-reliability-card">
							<div class="mlgmp-reliability-card__head">
								<h3 class="mlgmp-reliability-card__title"><?php esc_html_e( 'Source Snapshot', 'ml-gallery-migrator-pro' ); ?></h3>
								<span class="mlgmp-reliability-pill <?php echo ! empty( $source_card['detected'] ) ? 'is-success' : 'is-danger'; ?>"><?php echo esc_html( ! empty( $source_card['detected'] ) ? __( 'Detectado', 'ml-gallery-migrator-pro' ) : __( 'Ausente', 'ml-gallery-migrator-pro' ) ); ?></span>
							</div>
							<p class="mlgmp-reliability-card__desc"><?php echo esc_html( sprintf( __( 'Leitura direta da origem %s com foco em inventário e presença estrutural.', 'ml-gallery-migrator-pro' ), $source_card['label'] ) ); ?></p>
							<div class="mlgmp-reliability-metrics">
								<?php $this->render_reliability_metric( __( 'Galerias', 'ml-gallery-migrator-pro' ), (int) $source_metrics['galleries'] ); ?>
								<?php $this->render_reliability_metric( __( 'Álbuns', 'ml-gallery-migrator-pro' ), (int) $source_metrics['albums'] ); ?>
								<?php $this->render_reliability_metric( __( 'Imagens', 'ml-gallery-migrator-pro' ), (int) $source_metrics['images'] ); ?>
								<?php $this->render_reliability_metric( __( 'Shortcodes', 'ml-gallery-migrator-pro' ), (int) $source_metrics['shortcodes'] ); ?>
							</div>
							<div class="mlgmp-reliability-tags">
								<?php $this->render_reliability_tag( __( 'Plugin ativo', 'ml-gallery-migrator-pro' ), ! empty( $source_card['active'] ) ? __( 'Sim', 'ml-gallery-migrator-pro' ) : __( 'Não', 'ml-gallery-migrator-pro' ) ); ?>
								<?php $this->render_reliability_tag( __( 'Tabelas encontradas', 'ml-gallery-migrator-pro' ), (string) count( $source_card['tables_found'] ) ); ?>
								<?php $this->render_reliability_tag( __( 'Tabelas ausentes', 'ml-gallery-migrator-pro' ), (string) count( $source_card['tables_missing'] ) ); ?>
							</div>
						</article>

						<article class="mlgmp-reliability-card">
							<div class="mlgmp-reliability-card__head">
								<h3 class="mlgmp-reliability-card__title"><?php esc_html_e( 'Destination Snapshot', 'ml-gallery-migrator-pro' ); ?></h3>
								<span class="mlgmp-reliability-pill <?php echo ! empty( $dest_card['ready'] ) ? 'is-success' : 'is-danger'; ?>"><?php echo esc_html( ! empty( $dest_card['ready'] ) ? __( 'Pronto', 'ml-gallery-migrator-pro' ) : __( 'Incompleto', 'ml-gallery-migrator-pro' ) ); ?></span>
							</div>
							<p class="mlgmp-reliability-card__desc"><?php esc_html_e( 'Visão consolidada do destino ML Gallery Pro sem interferir na escrita do motor de migração.', 'ml-gallery-migrator-pro' ); ?></p>
							<div class="mlgmp-reliability-metrics">
								<?php $this->render_reliability_metric( __( 'Galerias MLGP', 'ml-gallery-migrator-pro' ), (int) $dest_metrics['galleries'] ); ?>
								<?php $this->render_reliability_metric( __( 'Álbuns MLGP', 'ml-gallery-migrator-pro' ), (int) $dest_metrics['albums'] ); ?>
								<?php
								$_items_raw     = (int) $dest_metrics['items'];
								$_items_logical = (int) ( $dest_metrics['items_logical'] ?? $_items_raw );
								if ( $_items_logical < $_items_raw ) {
									$this->render_reliability_metric(
										sprintf( __( 'Itens MLGP (raw: %d)', 'ml-gallery-migrator-pro' ), $_items_raw ),
										$_items_logical
									);
								} else {
									$this->render_reliability_metric( __( 'Itens MLGP', 'ml-gallery-migrator-pro' ), $_items_raw );
								}
								?>
								<?php $this->render_reliability_metric( __( 'Pastas mídia', 'ml-gallery-migrator-pro' ), (int) $dest_metrics['gallery_dirs'] ); ?>
							</div>
							<div class="mlgmp-reliability-tags">
								<?php $this->render_reliability_tag( __( 'Tabela galleries', 'ml-gallery-migrator-pro' ), ! empty( $dest_card['available']['galleries'] ) ? __( 'OK', 'ml-gallery-migrator-pro' ) : __( 'Ausente', 'ml-gallery-migrator-pro' ) ); ?>
								<?php $this->render_reliability_tag( __( 'Tabela items', 'ml-gallery-migrator-pro' ), ! empty( $dest_card['available']['items'] ) ? __( 'OK', 'ml-gallery-migrator-pro' ) : __( 'Ausente', 'ml-gallery-migrator-pro' ) ); ?>
								<?php $this->render_reliability_tag( __( 'Raiz de mídia', 'ml-gallery-migrator-pro' ), wp_basename( $dest_card['media_root'] ) ); ?>
							</div>
						</article>

						<article class="mlgmp-reliability-card">
							<div class="mlgmp-reliability-card__head">
								<h3 class="mlgmp-reliability-card__title"><?php esc_html_e( 'Reconciliation Summary', 'ml-gallery-migrator-pro' ); ?></h3>
								<span class="mlgmp-reliability-pill <?php echo ! empty( $recon_card['session_matches'] ) ? 'is-success' : 'is-neutral'; ?>"><?php echo esc_html( ! empty( $recon_card['session_matches'] ) ? __( 'Sessão vinculada', 'ml-gallery-migrator-pro' ) : __( 'Sem sessão desta origem', 'ml-gallery-migrator-pro' ) ); ?></span>
							</div>
							<p class="mlgmp-reliability-card__desc"><?php esc_html_e( 'Conciliação somente-leitura entre o inventário da origem e os mapas persistidos da sessão atual.', 'ml-gallery-migrator-pro' ); ?></p>
							<div class="mlgmp-reliability-metrics">
								<?php $this->render_reliability_metric( __( 'Mapeadas', 'ml-gallery-migrator-pro' ), (int) $mapped['gallery'], __( 'Galerias', 'ml-gallery-migrator-pro' ) ); ?>
								<?php $this->render_reliability_metric( __( 'Mapeados', 'ml-gallery-migrator-pro' ), (int) $mapped['album'], __( 'Álbuns', 'ml-gallery-migrator-pro' ) ); ?>
								<?php $this->render_reliability_metric( __( 'Mapeadas', 'ml-gallery-migrator-pro' ), (int) $mapped['image'], __( 'Imagens', 'ml-gallery-migrator-pro' ) ); ?>
								<?php $this->render_reliability_metric( __( 'Convertidos', 'ml-gallery-migrator-pro' ), (int) $recon_card['shortcodes'], __( 'Shortcodes', 'ml-gallery-migrator-pro' ) ); ?>
							</div>
							<div class="mlgmp-reliability-tags">
								<?php $this->render_reliability_tag( __( 'Cobertura galerias', 'ml-gallery-migrator-pro' ), (string) $recon_card['coverage']['galleries'] . '%' ); ?>
								<?php $this->render_reliability_tag( __( 'Cobertura imagens', 'ml-gallery-migrator-pro' ), (string) $recon_card['coverage']['images'] . '%' ); ?>
								<?php $this->render_reliability_tag( __( 'Órfãos', 'ml-gallery-migrator-pro' ), (string) $mapped['orphan'] ); ?>
								<?php $this->render_reliability_tag( __( 'Restantes', 'ml-gallery-migrator-pro' ), (string) $remaining['images'] . ' ' . __( 'imagens', 'ml-gallery-migrator-pro' ) ); ?>
							</div>
						</article>

						<article class="mlgmp-reliability-card">
							<div class="mlgmp-reliability-card__head">
								<h3 class="mlgmp-reliability-card__title"><?php esc_html_e( 'Integrity Status', 'ml-gallery-migrator-pro' ); ?></h3>
								<span class="mlgmp-reliability-pill <?php echo 'is-' . esc_attr( $status_card['overall_tone'] ); ?>"><?php echo esc_html( $status_card['headline'] ); ?></span>
							</div>
							<p class="mlgmp-reliability-card__desc"><?php esc_html_e( 'Sinais de integridade operacional para revisão antes da validação final do acervo.', 'ml-gallery-migrator-pro' ); ?></p>
							<div class="mlgmp-reliability-status-list">
								<?php foreach ( $status_card['rows'] as $row ) : ?>
									<div class="mlgmp-reliability-status-row">
										<div>
											<strong><?php echo esc_html( $row['label'] ); ?></strong>
											<span class="mlgmp-reliability-status-copy"><?php echo esc_html( $row['value'] ); ?></span>
										</div>
										<span class="mlgmp-reliability-pill is-<?php echo esc_attr( $row['tone'] ); ?>"><?php echo esc_html( $row['value'] ); ?></span>
									</div>
								<?php endforeach; ?>
							</div>
						</article>
					</div>

					<div class="mlgmp-reliability-report">
						<div class="mlgmp-reliability-report__head">
							<div>
								<h3 class="mlgmp-reliability-report__title"><?php esc_html_e( 'Final Integrity Report', 'ml-gallery-migrator-pro' ); ?></h3>
								<p class="mlgmp-reliability-report__desc"><?php esc_html_e( 'Resumo executivo de integridade para fechamento da validação da migração.', 'ml-gallery-migrator-pro' ); ?></p>
							</div>
							<button type="button" class="mlgp-button mlgp-button--ghost mlgmp-btn-copy-report" data-report-text="<?php echo esc_attr( $report['report_text'] ); ?>">
								<?php esc_html_e( 'Copiar relatório', 'ml-gallery-migrator-pro' ); ?>
							</button>
						</div>
						<div class="mlgmp-reliability-report__grid">
							<div class="mlgmp-reliability-report__block">
								<span class="mlgmp-reliability-report__eyebrow"><?php esc_html_e( 'Origem', 'ml-gallery-migrator-pro' ); ?></span>
								<p><?php echo esc_html( sprintf( __( '%1$s possui %2$d galerias, %3$d álbuns, %4$d imagens e %5$d shortcodes identificados.', 'ml-gallery-migrator-pro' ), $source_card['label'], $source_metrics['galleries'], $source_metrics['albums'], $source_metrics['images'], $source_metrics['shortcodes'] ) ); ?></p>
							</div>
							<div class="mlgmp-reliability-report__block">
								<span class="mlgmp-reliability-report__eyebrow"><?php esc_html_e( 'Destino', 'ml-gallery-migrator-pro' ); ?></span>
								<p><?php
$_it_r = (int) $dest_metrics['items'];
$_it_l = (int) ( $dest_metrics['items_logical'] ?? $_it_r );
if ( $_it_l < $_it_r ) {
    echo esc_html( sprintf(
        __( 'O destino registra %1$d galerias, %2$d álbuns, %3$d itens brutos (%4$d lógicos após deduplicação WebP) e %5$d pastas de mídia do ML Gallery.', 'ml-gallery-migrator-pro' ),
        $dest_metrics['galleries'], $dest_metrics['albums'], $_it_r, $_it_l, $dest_metrics['gallery_dirs']
    ) );
} else {
    echo esc_html( sprintf(
        __( 'O destino registra %1$d galerias, %2$d álbuns, %3$d itens e %4$d pastas de mídia do ML Gallery.', 'ml-gallery-migrator-pro' ),
        $dest_metrics['galleries'], $dest_metrics['albums'], $_it_r, $dest_metrics['gallery_dirs']
    ) );
}
?></p>
							</div>
							<div class="mlgmp-reliability-report__block">
								<span class="mlgmp-reliability-report__eyebrow"><?php esc_html_e( 'Reconciliação', 'ml-gallery-migrator-pro' ); ?></span>
								<p><?php echo esc_html( sprintf( __( 'A sessão atual reconciliou %1$d galerias, %2$d álbuns, %3$d imagens e %4$d shortcodes; restam %5$d imagens pendentes nesta origem.', 'ml-gallery-migrator-pro' ), $mapped['gallery'], $mapped['album'], $mapped['image'], $recon_card['shortcodes'], $remaining['images'] ) ); ?></p>
							</div>
							<div class="mlgmp-reliability-report__block">
								<span class="mlgmp-reliability-report__eyebrow"><?php esc_html_e( 'Parecer', 'ml-gallery-migrator-pro' ); ?></span>
								<p><?php echo esc_html( $status_card['headline'] ); ?></p>
							</div>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_reliability_metric( string $label, int $value, string $suffix = '' ): void {
		?>
		<div class="mlgmp-reliability-metric">
			<span class="mlgmp-reliability-metric__value"><?php echo esc_html( number_format_i18n( $value ) ); ?></span>
			<span class="mlgmp-reliability-metric__label"><?php echo esc_html( $label . ( $suffix ? ' ' . $suffix : '' ) ); ?></span>
		</div>
		<?php
	}

	private function render_reliability_tag( string $label, string $value ): void {
		?>
		<span class="mlgmp-reliability-tag"><strong><?php echo esc_html( $label ); ?>:</strong> <?php echo esc_html( $value ); ?></span>
		<?php
	}

	private function render_log_panel( array $state ): void {
		$entries = $state['session_id']
			? Logger::get_entries( $state['session_id'], 100 )
			: [];
		?>
		<div class="mlgp-panel" style="display: flex; flex-direction: column; height: 100%;">
			<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 8px;">
				<h2 style="margin: 0; font-size: 16px; font-weight: 600;"><?php esc_html_e( 'Log em Tempo Real', 'ml-gallery-migrator-pro' ); ?></h2>
				<div class="mlgmp-log-filters">
					<label><input type="checkbox" class="mlgmp-log-filter" value="info" checked> Info</label>
					<label><input type="checkbox" class="mlgmp-log-filter" value="warning" checked> Aviso</label>
					<label><input type="checkbox" class="mlgmp-log-filter" value="error" checked> Erro</label>
					<label><input type="checkbox" class="mlgmp-log-filter" value="orphan" checked> Órfão</label>
					<label><input type="checkbox" class="mlgmp-log-filter" value="debug"> Debug</label>
				</div>
				<div style="display: flex; gap: 8px;">
					<button type="button" id="mlgmp-btn-copy-log" class="mlgp-button mlgp-button--accent">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
						<?php esc_html_e( 'Copiar', 'ml-gallery-migrator-pro' ); ?>
					</button>
					<button type="button" id="mlgmp-btn-clear-log" class="mlgp-button mlgp-button--ghost">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
						<?php esc_html_e( 'Limpar', 'ml-gallery-migrator-pro' ); ?>
					</button>
				</div>
			</div>
			<div class="mlgmp-log-wrap" id="mlgmp-log-wrap">
				<div class="mlgmp-log" id="mlgmp-log">
					<?php foreach ( array_reverse( $entries ) as $entry ) : ?>
						<div class="mlgmp-log__entry mlgmp-log__entry--<?php echo esc_attr( $entry['level'] ); ?>">
							<span class="mlgmp-log__time"><?php echo esc_html( $entry['created_at'] ); ?></span>
							<span class="mlgmp-log__badge mlgmp-badge--<?php echo esc_attr( $entry['level'] ); ?>">
								<?php echo esc_html( strtoupper( $entry['level'] ) ); ?>
							</span>
							<span class="mlgmp-log__stage">[<?php echo esc_html( $entry['stage'] ); ?>]</span>
							<span class="mlgmp-log__msg"><?php echo esc_html( $entry['message'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="mlgmp-log-footer">
				<span id="mlgmp-log-count"><?php printf( esc_html( _n( '%d entrada', '%d entradas', count( $entries ), 'ml-gallery-migrator-pro' ) ), count( $entries ) ); ?></span>
				<label class="mlgmp-autoscroll-label">
					<input type="checkbox" id="mlgmp-autoscroll" checked>
					<?php esc_html_e( 'Auto-scroll', 'ml-gallery-migrator-pro' ); ?>
				</label>
				<span id="mlgmp-last-id" data-id="<?php echo esc_attr( ! empty( $entries ) ? (int) $entries[0]['id'] : 0 ); ?>"></span>
			</div>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Label helpers
	// -----------------------------------------------------------------------

	private function status_label( string $status ): string {
		$map = [
			State::STATUS_IDLE      => __( 'Aguardando', 'ml-gallery-migrator-pro' ),
			State::STATUS_RUNNING   => __( 'Executando…', 'ml-gallery-migrator-pro' ),
			State::STATUS_PAUSED    => __( 'Pausado', 'ml-gallery-migrator-pro' ),
			State::STATUS_READY     => __( 'Pronto (aguardando próximo lote)', 'ml-gallery-migrator-pro' ),
			State::STATUS_DONE      => __( 'Concluído ✓', 'ml-gallery-migrator-pro' ),
			State::STATUS_CANCELLED => __( 'Cancelado', 'ml-gallery-migrator-pro' ),
			State::STATUS_ERROR     => __( 'Erro ✗', 'ml-gallery-migrator-pro' ),
		];
		return $map[ $status ] ?? $status;
	}

	private function stage_label( string $stage ): string {
		$map = [
			State::STAGE_GALLERIES  => __( 'Etapa: Galerias', 'ml-gallery-migrator-pro' ),
			State::STAGE_ALBUMS     => __( 'Etapa: Álbuns', 'ml-gallery-migrator-pro' ),
			State::STAGE_IMAGES     => __( 'Etapa: Imagens', 'ml-gallery-migrator-pro' ),
			State::STAGE_THUMBS     => __( 'Etapa: Miniaturas', 'ml-gallery-migrator-pro' ),
			State::STAGE_SHORTCODES => __( 'Etapa: Shortcodes', 'ml-gallery-migrator-pro' ),
			State::STAGE_DONE       => __( 'Tudo concluído', 'ml-gallery-migrator-pro' ),
		];
		return $map[ $stage ] ?? $stage;
	}

	// -----------------------------------------------------------------------
	// Render: Configuration Tab
	// -----------------------------------------------------------------------

	private function render_config_tab(): void {
		$settings = Config::get_all();
		$stages   = [
			'galleries'  => __( 'Galerias', 'ml-gallery-migrator-pro' ),
			'albums'     => __( 'Álbuns', 'ml-gallery-migrator-pro' ),
			'images'     => __( 'Imagens', 'ml-gallery-migrator-pro' ),
			'thumbs'     => __( 'Miniaturas', 'ml-gallery-migrator-pro' ),
			'shortcodes' => __( 'Shortcodes', 'ml-gallery-migrator-pro' ),
		];
		?>
		<div class="mlgmp-operational-section">

			<div class="mlgp-panel" id="mlgmp-config-panel">

				<div style="margin-bottom: 24px; padding-bottom: 18px; border-bottom: 1px solid var(--mlgp-line);">
					<h2 style="margin: 0 0 6px; font-size: 16px; font-weight: 600; color: var(--mlgp-ink);"><?php esc_html_e( 'Configuração de Migração', 'ml-gallery-migrator-pro' ); ?></h2>
					<p style="margin: 0; font-size: 13px; color: var(--mlgp-muted);"><?php esc_html_e( 'Defina os parâmetros padrão do motor de migração. Estas configurações são lidas exclusivamente no momento de iniciar ou retomar a migração.', 'ml-gallery-migrator-pro' ); ?></p>
				</div>

				<form id="mlgmp-config-form">
					<?php wp_nonce_field( 'mlgmp_ajax', 'mlgmp_config_nonce' ); ?>
					<input type="hidden" name="nonce" id="mlgmp-config-nonce" value="<?php echo esc_attr( wp_create_nonce( 'mlgmp_ajax' ) ); ?>">

					<!-- Batch size -->
					<div class="mlgmp-field-row" style="flex-direction: column; align-items: flex-start; gap: 8px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid var(--mlgp-line);">
						<label for="mlgmp-config-batch" style="font-weight: 600; font-size: 13px; color: var(--mlgp-ink);"><?php esc_html_e( 'Itens por lote', 'ml-gallery-migrator-pro' ); ?></label>
						<div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
							<input
								type="number"
								id="mlgmp-config-batch"
								name="batch_size"
								min="1"
								max="500"
								value="<?php echo esc_attr( (int) $settings['batch_size'] ); ?>"
								style="min-height: 42px; padding: 10px 12px; border: 1px solid var(--mlgp-line); border-radius: 10px; background: var(--mlgp-surface-soft); color: var(--mlgp-ink); font-size: 14px; width: 100px;"
							>
							<span style="font-size: 13px; color: var(--mlgp-muted);"><?php esc_html_e( 'itens por execução de lote (1 – 500). Valores menores são mais seguros em servidores com limite de tempo.', 'ml-gallery-migrator-pro' ); ?></span>
						</div>
					</div>

					<!-- Duplicate mode -->
					<div class="mlgmp-field-row" style="flex-direction: column; align-items: flex-start; gap: 10px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid var(--mlgp-line);">
						<label style="font-weight: 600; font-size: 13px; color: var(--mlgp-ink);"><?php esc_html_e( 'Se o arquivo já existir no destino:', 'ml-gallery-migrator-pro' ); ?></label>
						<div class="mlgmp-mode-row" style="margin-bottom: 0;">
							<label class="mlgmp-radio-label">
								<input type="radio" name="duplicate_mode" value="ignore" <?php checked( $settings['duplicate_mode'], 'ignore' ); ?>>
								<span>
									<strong><?php esc_html_e( 'Ignorar', 'ml-gallery-migrator-pro' ); ?></strong>
									<span style="color: var(--mlgp-muted); font-size: 12px; display: block;"><?php esc_html_e( 'Pula arquivos já existentes (recomendado)', 'ml-gallery-migrator-pro' ); ?></span>
								</span>
							</label>
							<label class="mlgmp-radio-label">
								<input type="radio" name="duplicate_mode" value="overwrite" <?php checked( $settings['duplicate_mode'], 'overwrite' ); ?>>
								<span>
									<strong><?php esc_html_e( 'Sobrescrever', 'ml-gallery-migrator-pro' ); ?></strong>
									<span style="color: var(--mlgp-muted); font-size: 12px; display: block;"><?php esc_html_e( 'Substitui arquivos existentes no destino', 'ml-gallery-migrator-pro' ); ?></span>
								</span>
							</label>
						</div>
					</div>

					<!-- Enabled stages -->
					<div class="mlgmp-field-row" style="flex-direction: column; align-items: flex-start; gap: 10px; margin-bottom: 24px;">
						<label style="font-weight: 600; font-size: 13px; color: var(--mlgp-ink);"><?php esc_html_e( 'Etapas habilitadas:', 'ml-gallery-migrator-pro' ); ?></label>
						<div style="display: flex; flex-wrap: wrap; gap: 10px;">
							<?php foreach ( $stages as $stage_key => $stage_label ) : ?>
								<label class="mlgmp-radio-label" style="background: var(--mlgp-surface-soft); border: 1px solid var(--mlgp-line); border-radius: 10px; padding: 8px 14px; cursor: pointer;">
									<input
										type="checkbox"
										name="enabled_stages[]"
										value="<?php echo esc_attr( $stage_key ); ?>"
										<?php checked( ! empty( $settings['enabled_stages'][ $stage_key ] ) ); ?>
									>
									<?php echo esc_html( $stage_label ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					</div>

					<!-- Save button + status -->
					<div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
						<button type="submit" id="mlgmp-save-config" class="mlgp-button mlgp-button--accent">
							<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:7px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
							<?php esc_html_e( 'Salvar configuração', 'ml-gallery-migrator-pro' ); ?>
						</button>
						<span id="mlgmp-config-status" style="font-size: 13px; font-weight: 600;"></span>
					</div>

				</form>
			</div>

		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Render: License
	// -----------------------------------------------------------------------

	/** Aviso discreto nas telas do plugin quando não há teste nem licença. */
	private function render_locked_banner(): void {
		?>
		<div class="mlgmp-license-banner" role="status">
			<span class="mlgmp-reliability-pill is-warning"><?php esc_html_e( 'Sem licença', 'ml-gallery-migrator-pro' ); ?></span>
			<span class="mlgmp-license-banner__text"><?php esc_html_e( 'Iniciar a migração, rodar reparos e salvar a configuração estão travados. As galerias já migradas continuam no ar e nada é apagado.', 'ml-gallery-migrator-pro' ); ?></span>
			<a class="mlgp-button mlgp-button--accent" href="<?php echo esc_url( License::tab_url() ); ?>"><?php esc_html_e( 'Ativar licença', 'ml-gallery-migrator-pro' ); ?></a>
		</div>
		<?php
	}

	private function render_license_tab( array $license ): void {
		$notice      = isset( $_GET['mlgmp_lic_msg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['mlgmp_lic_msg'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice_type = isset( $_GET['mlgmp_lic_type'] ) && 'error' === sanitize_key( wp_unslash( $_GET['mlgmp_lic_type'] ) ) ? 'is-error' : 'is-success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_url    = admin_url( 'admin-post.php' );
		?>
		<?php if ( '' !== $notice ) : ?>
			<div class="mlgp-notice-stack"><div class="mlgp-notice <?php echo esc_attr( $notice_type ); ?>"><?php echo esc_html( $notice ); ?></div></div>
		<?php endif; ?>

		<div class="mlgmp-license">
			<div class="mlgmp-reliability-metrics mlgmp-license__kpis">
				<div class="mlgmp-reliability-metric"><span class="mlgmp-reliability-metric__value"><?php echo esc_html( $license['label'] ); ?></span><span class="mlgmp-reliability-metric__label"><?php esc_html_e( 'Situação', 'ml-gallery-migrator-pro' ); ?></span></div>
				<div class="mlgmp-reliability-metric"><span class="mlgmp-reliability-metric__value"><?php echo esc_html( $license['plan_label'] ); ?></span><span class="mlgmp-reliability-metric__label"><?php esc_html_e( 'Plano', 'ml-gallery-migrator-pro' ); ?></span></div>
				<div class="mlgmp-reliability-metric"><span class="mlgmp-reliability-metric__value"><?php echo esc_html( $license['valid_label'] ); ?></span><span class="mlgmp-reliability-metric__label"><?php esc_html_e( 'Válida até', 'ml-gallery-migrator-pro' ); ?></span></div>
				<div class="mlgmp-reliability-metric"><span class="mlgmp-reliability-metric__value"><?php echo esc_html( $license['days_label'] ); ?></span><span class="mlgmp-reliability-metric__label"><?php esc_html_e( 'Dias restantes', 'ml-gallery-migrator-pro' ); ?></span></div>
			</div>

			<div class="mlgp-grid-two mlgmp-license__grid">
				<div>
					<div class="mlgp-panel">
						<div class="mlgmp-reliability-card__head">
							<h2 class="mlgmp-reliability-card__title"><?php esc_html_e( 'Licença', 'ml-gallery-migrator-pro' ); ?></h2>
							<span class="mlgmp-reliability-pill is-<?php echo esc_attr( $license['tone'] ); ?>"><?php echo esc_html( $license['label'] ); ?></span>
						</div>
						<p class="mlgmp-reliability-card__desc"><?php echo esc_html( $license['message'] ); ?><br><small><?php echo esc_html( sprintf( __( 'Site: %s', 'ml-gallery-migrator-pro' ), $license['domain'] ) ); ?></small></p>

						<?php if ( 'license' === $license['mode'] && '' !== $license['key_masked'] ) : ?>
							<p class="mlgmp-license__key"><span><?php esc_html_e( 'Chave neste site', 'ml-gallery-migrator-pro' ); ?></span> <code><?php echo esc_html( $license['key_masked'] ); ?></code></p>
							<div class="mlgmp-license__actions">
								<form method="post" action="<?php echo esc_url( $post_url ); ?>">
									<?php wp_nonce_field( 'mlgmp_sync_license' ); ?>
									<input type="hidden" name="action" value="mlgmp_sync_license">
									<input type="hidden" name="mlgmp_op" value="check">
									<button type="submit" class="mlgp-button mlgp-button--accent"><?php esc_html_e( 'Conferir agora', 'ml-gallery-migrator-pro' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( $post_url ); ?>">
									<?php wp_nonce_field( 'mlgmp_remove_license' ); ?>
									<input type="hidden" name="action" value="mlgmp_remove_license">
									<button type="submit" class="mlgp-button mlgp-button--danger"><?php esc_html_e( 'Remover deste site', 'ml-gallery-migrator-pro' ); ?></button>
								</form>
							</div>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="mlgmp-license__form">
								<?php wp_nonce_field( 'mlgmp_activate_license' ); ?>
								<input type="hidden" name="action" value="mlgmp_activate_license">
								<label for="mlgmp-license-key"><?php esc_html_e( 'Chave da licença', 'ml-gallery-migrator-pro' ); ?></label>
								<div class="mlgmp-license__row">
									<input type="text" id="mlgmp-license-key" name="license_key" value="" placeholder="<?php esc_attr_e( 'Cole aqui a sua chave', 'ml-gallery-migrator-pro' ); ?>" autocomplete="off" spellcheck="false">
									<button type="submit" class="mlgp-button mlgp-button--accent"><?php esc_html_e( 'Ativar licença', 'ml-gallery-migrator-pro' ); ?></button>
								</div>
							</form>
							<div class="mlgmp-license__actions">
								<?php if ( $license['can_start_trial'] ) : ?>
									<form method="post" action="<?php echo esc_url( $post_url ); ?>">
										<?php wp_nonce_field( 'mlgmp_sync_license' ); ?>
										<input type="hidden" name="action" value="mlgmp_sync_license">
										<input type="hidden" name="mlgmp_op" value="trial">
										<button type="submit" class="mlgp-button"><?php esc_html_e( 'Começar teste grátis de 30 dias', 'ml-gallery-migrator-pro' ); ?></button>
									</form>
								<?php elseif ( $license['is_trial'] ) : ?>
									<form method="post" action="<?php echo esc_url( $post_url ); ?>">
										<?php wp_nonce_field( 'mlgmp_sync_license' ); ?>
										<input type="hidden" name="action" value="mlgmp_sync_license">
										<input type="hidden" name="mlgmp_op" value="check">
										<button type="submit" class="mlgp-button"><?php esc_html_e( 'Conferir agora', 'ml-gallery-migrator-pro' ); ?></button>
									</form>
								<?php endif; ?>
								<a class="mlgp-button mlgp-button--ghost" href="<?php echo esc_url( License::BUY_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Comprar licença', 'ml-gallery-migrator-pro' ); ?></a>
								<a class="mlgp-button mlgp-button--ghost" href="<?php echo esc_url( License::ACCOUNT_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Minha conta', 'ml-gallery-migrator-pro' ); ?></a>
							</div>
						<?php endif; ?>
					</div>
				</div>
				<div>
					<div class="mlgp-panel">
						<div class="mlgmp-reliability-card__head">
							<h2 class="mlgmp-reliability-card__title"><?php esc_html_e( 'Como funciona', 'ml-gallery-migrator-pro' ); ?></h2>
							<span class="mlgmp-reliability-pill is-neutral">tools.mlopesdesign.com.br</span>
						</div>
						<div class="mlgmp-table-scroll">
							<table class="mlgmp-table mlgmp-license__table">
								<tbody>
									<tr><th><?php esc_html_e( 'Teste grátis', 'ml-gallery-migrator-pro' ); ?></th><td><?php esc_html_e( '30 dias com tudo liberado, uma vez por site. Reinstalar não reinicia.', 'ml-gallery-migrator-pro' ); ?></td></tr>
									<tr><th><?php esc_html_e( 'Licença anual', 'ml-gallery-migrator-pro' ); ?></th><td><?php esc_html_e( 'Vale em 1 site. Para usar em outro, remova deste site.', 'ml-gallery-migrator-pro' ); ?></td></tr>
									<tr><th><?php esc_html_e( 'Pacote Agência', 'ml-gallery-migrator-pro' ); ?></th><td><?php esc_html_e( 'Uma chave para todos os plugins ML em 5, 10 ou 50 sites.', 'ml-gallery-migrator-pro' ); ?></td></tr>
									<tr><th><?php esc_html_e( 'Sem licença', 'ml-gallery-migrator-pro' ); ?></th><td><?php esc_html_e( 'As galerias já migradas continuam no ar e nada é apagado. Só ficam travados iniciar a migração, rodar reparos e salvar a configuração.', 'ml-gallery-migrator-pro' ); ?></td></tr>
								</tbody>
							</table>
						</div>
						<p class="mlgmp-reliability-card__desc mlgmp-license__note"><?php esc_html_e( 'A licença é conferida uma vez por dia. Sem internet, o site continua liberado por até 7 dias.', 'ml-gallery-migrator-pro' ); ?></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Render: Repair Tab
	// -----------------------------------------------------------------------

	private function render_repair_tab( string $source ): void {
		$ops = [
			'repair_album_links'    => [
				'label' => __( 'Reparar vínculos', 'ml-gallery-migrator-pro' ),
				'desc'  => __( 'Valida relações álbum ↔ galeria e corrige mapeamentos quebrados no id_map.', 'ml-gallery-migrator-pro' ),
				'icon'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
			],
			'repair_missing_images' => [
				'label' => __( 'Reprocessar imagens', 'ml-gallery-migrator-pro' ),
				'desc'  => __( 'Verifica arquivos de imagem no destino e recopía os ausentes usando o FileCopier.', 'ml-gallery-migrator-pro' ),
				'icon'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>',
			],
			'repair_missing_thumbs' => [
				'label' => __( 'Reprocessar miniaturas', 'ml-gallery-migrator-pro' ),
				'desc'  => __( 'Valida o diretório de miniaturas de cada galeria e recopía as ausentes.', 'ml-gallery-migrator-pro' ),
				'icon'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><rect x="8" y="8" width="8" height="8"/></svg>',
			],
			'fix_orphans'           => [
				'label' => __( 'Corrigir órfãos', 'ml-gallery-migrator-pro' ),
				'desc'  => __( 'Detecta imagens sem galeria e galerias sem álbum; marca ou reatribui corretamente.', 'ml-gallery-migrator-pro' ),
				'icon'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
			],
			'recover_incomplete_galleries' => [
				'label'  => __( 'Recuperar galerias vazias/incompletas', 'ml-gallery-migrator-pro' ),
				'desc'   => __( 'Detecta e reinsere imagens em galerias destino com 0 itens ou menos itens que a fonte NGG. Idempotente.', 'ml-gallery-migrator-pro' ),
				'icon'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
				'accent' => true,
			],
			'revalidate_integrity'  => [
				'label' => __( 'Revalidar integridade', 'ml-gallery-migrator-pro' ),
				'desc'  => __( 'Recomputa totais migrados, ativos ausentes e problemas de validação em toda a sessão.', 'ml-gallery-migrator-pro' ),
				'icon'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
			],
		];
		?>
		<div class="mlgmp-operational-section">

			<div class="mlgp-panel" id="mlgmp-repair-panel">

				<div style="margin-bottom: 24px; padding-bottom: 18px; border-bottom: 1px solid var(--mlgp-line);">
					<h2 style="margin: 0 0 6px; font-size: 16px; font-weight: 600; color: var(--mlgp-ink);"><?php esc_html_e( 'Motor de Reparo', 'ml-gallery-migrator-pro' ); ?></h2>
					<p style="margin: 0; font-size: 13px; color: var(--mlgp-muted);"><?php esc_html_e( 'Ferramentas de validação e recuperação de integridade da migração. Cada operação é independente e registrada no log.', 'ml-gallery-migrator-pro' ); ?></p>
				</div>

				<!-- Operation buttons -->
				<div style="display: grid; gap: 10px; margin-bottom: 24px;">
					<?php foreach ( $ops as $op_key => $op ) : ?>
						<div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 16px; border: 1px solid var(--mlgp-line); border-radius: 12px; background: var(--mlgp-surface-soft); flex-wrap: wrap;">
							<div style="min-width: 0; flex: 1;">
								<strong style="display: block; font-size: 13px; color: var(--mlgp-ink); margin-bottom: 3px;"><?php echo esc_html( $op['label'] ); ?></strong>
								<span style="font-size: 12px; color: var(--mlgp-muted);"><?php echo esc_html( $op['desc'] ); ?></span>
							</div>
							<button
								type="button"
								class="mlgp-button <?php echo ! empty( $op['accent'] ) ? 'mlgp-button--accent' : 'mlgp-button--ghost'; ?> mlgmp-repair-btn"
								data-operation="<?php echo esc_attr( $op_key ); ?>"
								data-source="<?php echo esc_attr( $source ); ?>"
								style="flex-shrink: 0;"
							>
								<?php echo $op['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<span style="margin-left: 6px;"><?php echo esc_html( $op['label'] ); ?></span>
							</button>
						</div>
					<?php endforeach; ?>
				</div>

				<!-- Scoped repair panel -->
				<div style="margin-bottom: 20px; padding: 16px; border: 1px solid var(--mlgp-line); border-radius: 12px; background: var(--mlgp-surface-soft);" id="mlgmp-scope-panel">
					<div style="margin-bottom: 12px;">
						<strong style="font-size: 13px; color: var(--mlgp-ink); display: block; margin-bottom: 4px;"><?php esc_html_e( 'Escopo da operação', 'ml-gallery-migrator-pro' ); ?></strong>
						<span style="font-size: 12px; color: var(--mlgp-muted);"><?php esc_html_e( 'Define quais galerias serão processadas. Global processa todas.', 'ml-gallery-migrator-pro' ); ?></span>
					</div>
					<div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px;" role="group">
						<label class="mlgmp-radio-label" id="mlgmp-scope-lbl-global" style="font-size: 12px; border: 1px solid var(--mlgp-brand); border-radius: 8px; padding: 6px 12px; cursor: pointer; background: rgba(var(--mlgp-brand-rgb,99,102,241),.08);">
							<input type="radio" name="mlgmp_scope_type" value="global" checked style="display:none;" id="mlgmp-scope-global">
							<span><?php esc_html_e( 'Global', 'ml-gallery-migrator-pro' ); ?></span>
						</label>
						<label class="mlgmp-radio-label" id="mlgmp-scope-lbl-gallery" style="font-size: 12px; border: 1px solid var(--mlgp-line); border-radius: 8px; padding: 6px 12px; cursor: pointer;">
							<input type="radio" name="mlgmp_scope_type" value="gallery" style="display:none;" id="mlgmp-scope-gallery">
							<span><?php esc_html_e( 'Galeria específica', 'ml-gallery-migrator-pro' ); ?></span>
						</label>
						<label class="mlgmp-radio-label" id="mlgmp-scope-lbl-album" style="font-size: 12px; border: 1px solid var(--mlgp-line); border-radius: 8px; padding: 6px 12px; cursor: pointer;">
							<input type="radio" name="mlgmp_scope_type" value="album" style="display:none;" id="mlgmp-scope-album">
							<span><?php esc_html_e( 'Álbum específico', 'ml-gallery-migrator-pro' ); ?></span>
						</label>
						<label class="mlgmp-radio-label" id="mlgmp-scope-lbl-filtered" style="font-size: 12px; border: 1px solid var(--mlgp-line); border-radius: 8px; padding: 6px 12px; cursor: pointer;">
							<input type="radio" name="mlgmp_scope_type" value="filtered" style="display:none;" id="mlgmp-scope-filtered">
							<span><?php esc_html_e( 'Filtrado', 'ml-gallery-migrator-pro' ); ?></span>
						</label>
					</div>

					<!-- Gallery fields -->
					<div id="mlgmp-scope-fields-gallery" style="display:none; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
						<div>
							<label style="font-size:12px;color:var(--mlgp-muted);display:block;margin-bottom:3px;"><?php esc_html_e( 'ID destino (mlgp)', 'ml-gallery-migrator-pro' ); ?></label>
							<input type="number" id="mlgmp-scope-dest-gal" min="1" placeholder="ex: 42" style="min-height:36px;padding:6px 10px;border:1px solid var(--mlgp-line);border-radius:8px;background:var(--mlgp-surface-soft);color:var(--mlgp-ink);font-size:13px;width:120px;">
						</div>
						<div>
							<label style="font-size:12px;color:var(--mlgp-muted);display:block;margin-bottom:3px;"><?php esc_html_e( 'ID fonte (NGG)', 'ml-gallery-migrator-pro' ); ?></label>
							<input type="number" id="mlgmp-scope-src-gal" min="1" placeholder="ex: 7" style="min-height:36px;padding:6px 10px;border:1px solid var(--mlgp-line);border-radius:8px;background:var(--mlgp-surface-soft);color:var(--mlgp-ink);font-size:13px;width:120px;">
						</div>
						<div>
							<label style="font-size:12px;color:var(--mlgp-muted);display:block;margin-bottom:3px;"><?php esc_html_e( 'Slug', 'ml-gallery-migrator-pro' ); ?></label>
							<input type="text" id="mlgmp-scope-slug" placeholder="ex: my-gallery" style="min-height:36px;padding:6px 10px;border:1px solid var(--mlgp-line);border-radius:8px;background:var(--mlgp-surface-soft);color:var(--mlgp-ink);font-size:13px;width:180px;">
						</div>
					</div>

					<!-- Album fields -->
					<div id="mlgmp-scope-fields-album" style="display:none; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
						<div>
							<label style="font-size:12px;color:var(--mlgp-muted);display:block;margin-bottom:3px;"><?php esc_html_e( 'ID álbum destino (mlgp)', 'ml-gallery-migrator-pro' ); ?></label>
							<input type="number" id="mlgmp-scope-dest-alb" min="1" placeholder="ex: 3" style="min-height:36px;padding:6px 10px;border:1px solid var(--mlgp-line);border-radius:8px;background:var(--mlgp-surface-soft);color:var(--mlgp-ink);font-size:13px;width:120px;">
						</div>
						<div>
							<label style="font-size:12px;color:var(--mlgp-muted);display:block;margin-bottom:3px;"><?php esc_html_e( 'Slug do álbum', 'ml-gallery-migrator-pro' ); ?></label>
							<input type="text" id="mlgmp-scope-alb-slug" placeholder="ex: my-album" style="min-height:36px;padding:6px 10px;border:1px solid var(--mlgp-line);border-radius:8px;background:var(--mlgp-surface-soft);color:var(--mlgp-ink);font-size:13px;width:180px;">
						</div>
					</div>

					<!-- Filter options -->
					<div id="mlgmp-scope-fields-filtered" style="display:none; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
						<label class="mlgmp-radio-label" style="font-size:12px;">
							<input type="radio" name="mlgmp_scope_filter" value="empty_only" checked>
							<span><?php esc_html_e( 'Apenas galerias vazias (0 itens no destino)', 'ml-gallery-migrator-pro' ); ?></span>
						</label>
						<label class="mlgmp-radio-label" style="font-size:12px;">
							<input type="radio" name="mlgmp_scope_filter" value="incomplete_only">
							<span><?php esc_html_e( 'Apenas galerias incompletas (destino &lt; fonte)', 'ml-gallery-migrator-pro' ); ?></span>
						</label>
						<label class="mlgmp-radio-label" style="font-size:12px;">
							<input type="radio" name="mlgmp_scope_filter" value="all">
							<span><?php esc_html_e( 'Todas as galerias com imagens ausentes (vazia ou incompleta)', 'ml-gallery-migrator-pro' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Gallery mismatch audit panel -->
				<div class="mlgp-panel" style="margin-bottom: 20px;" id="mlgmp-audit-panel">
					<div style="margin-bottom: 16px; padding-bottom: 14px; border-bottom: 1px solid var(--mlgp-line); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
						<div>
							<strong style="font-size: 14px; color: var(--mlgp-ink); display: block; margin-bottom: 3px;"><?php esc_html_e( 'Auditoria de Galerias', 'ml-gallery-migrator-pro' ); ?></strong>
							<span style="font-size: 12px; color: var(--mlgp-muted);"><?php esc_html_e( 'Detecta galerias onde o destino tem menos imagens que a fonte NGG.', 'ml-gallery-migrator-pro' ); ?></span>
						</div>
						<div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
							<select id="mlgmp-audit-filter" style="min-height: 36px; padding: 6px 10px; border: 1px solid var(--mlgp-line); border-radius: 8px; background: var(--mlgp-surface-soft); color: var(--mlgp-ink); font-size: 12px;">
								<option value="all"><?php esc_html_e( 'Todas com problemas', 'ml-gallery-migrator-pro' ); ?></option>
								<option value="empty_only"><?php esc_html_e( 'Apenas vazias (0 itens)', 'ml-gallery-migrator-pro' ); ?></option>
								<option value="incomplete_only"><?php esc_html_e( 'Apenas incompletas', 'ml-gallery-migrator-pro' ); ?></option>
							</select>
							<button type="button" id="mlgmp-audit-run" class="mlgp-button mlgp-button--ghost" data-source="<?php echo esc_attr( $source ); ?>" style="font-size: 12px; min-height: 36px; padding: 0 14px;">
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:5px"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
								<?php esc_html_e( 'Auditar galerias', 'ml-gallery-migrator-pro' ); ?>
							</button>
						</div>
					</div>

					<!-- Audit list -->
					<div id="mlgmp-audit-list-wrap" style="display:none;">
						<div style="display: flex; gap: 8px; margin-bottom: 10px; align-items: center; flex-wrap: wrap;">
							<label style="font-size: 12px; display: flex; align-items: center; gap: 5px; cursor: pointer;">
								<input type="checkbox" id="mlgmp-audit-select-all">
								<span><?php esc_html_e( 'Selecionar tudo', 'ml-gallery-migrator-pro' ); ?></span>
							</label>
							<span id="mlgmp-audit-count" style="font-size: 12px; color: var(--mlgp-muted); margin-left: 6px;"></span>
							<div style="margin-left: auto; display: flex; gap: 8px;">
								<button type="button" id="mlgmp-recover-selected" class="mlgp-button mlgp-button--ghost" data-source="<?php echo esc_attr( $source ); ?>" style="font-size: 12px; min-height: 36px; padding: 0 14px;" disabled>
									<?php esc_html_e( 'Recuperar selecionadas', 'ml-gallery-migrator-pro' ); ?>
								</button>
								<button type="button" id="mlgmp-recover-all-filtered" class="mlgp-button mlgp-button--accent" data-source="<?php echo esc_attr( $source ); ?>" style="font-size: 12px; min-height: 36px; padding: 0 14px;">
									<?php esc_html_e( 'Recuperar todas filtradas', 'ml-gallery-migrator-pro' ); ?>
								</button>
							</div>
						</div>

						<div id="mlgmp-recover-progress" style="display:none; margin-bottom:10px; padding: 10px 14px; background: rgba(99,102,241,.06); border: 1px solid rgba(99,102,241,.2); border-radius: 10px; font-size: 12px; color: var(--mlgp-ink);">
							<strong id="mlgmp-recover-prog-label"><?php esc_html_e( 'Recuperando...', 'ml-gallery-migrator-pro' ); ?></strong>
							<div style="margin-top: 5px; display: flex; gap: 16px; flex-wrap: wrap;">
								<span><?php esc_html_e( 'Galerias:', 'ml-gallery-migrator-pro' ); ?> <strong id="mlgmp-rprog-gals">0</strong></span>
								<span><?php esc_html_e( 'Inseridas:', 'ml-gallery-migrator-pro' ); ?> <strong id="mlgmp-rprog-ins">0</strong></span>
								<span><?php esc_html_e( 'Ignoradas:', 'ml-gallery-migrator-pro' ); ?> <strong id="mlgmp-rprog-skip">0</strong></span>
								<span><?php esc_html_e( 'Reparadas:', 'ml-gallery-migrator-pro' ); ?> <strong id="mlgmp-rprog-rep">0</strong></span>
								<span><?php esc_html_e( 'Falhas:', 'ml-gallery-migrator-pro' ); ?> <strong id="mlgmp-rprog-fail">0</strong></span>
							</div>
						</div>

						<div style="overflow-x: auto;">
							<table style="width:100%; border-collapse:collapse; font-size:12px;" id="mlgmp-audit-table">
								<thead>
									<tr style="border-bottom: 1px solid var(--mlgp-line); text-align:left;">
										<th style="padding:6px 8px; font-weight:600; width:30px;"></th>
										<th style="padding:6px 8px; font-weight:600;"><?php esc_html_e( 'Galeria fonte (NGG)', 'ml-gallery-migrator-pro' ); ?></th>
										<th style="padding:6px 8px; font-weight:600;"><?php esc_html_e( 'Destino (slug)', 'ml-gallery-migrator-pro' ); ?></th>
										<th style="padding:6px 8px; font-weight:600; text-align:right;"><?php esc_html_e( 'Fonte', 'ml-gallery-migrator-pro' ); ?></th>
										<th style="padding:6px 8px; font-weight:600; text-align:right;"><?php esc_html_e( 'Destino', 'ml-gallery-migrator-pro' ); ?></th>
										<th style="padding:6px 8px; font-weight:600; text-align:right;"><?php esc_html_e( 'Faltando', 'ml-gallery-migrator-pro' ); ?></th>
										<th style="padding:6px 8px; font-weight:600;"><?php esc_html_e( 'Status', 'ml-gallery-migrator-pro' ); ?></th>
									</tr>
								</thead>
								<tbody id="mlgmp-audit-tbody"></tbody>
							</table>
						</div>
					</div>

					<div id="mlgmp-audit-empty" style="display:none; font-size:13px; color:var(--mlgp-muted); padding: 20px 0; text-align:center;">
						<?php esc_html_e( 'Nenhuma galeria com problemas encontrada. Todas as galerias estão completas.', 'ml-gallery-migrator-pro' ); ?>
					</div>
				</div>

				<!-- Result area (initially hidden) -->
				<div id="mlgmp-repair-status" style="display: none;">
					<div id="mlgmp-repair-progress" style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px; font-size: 13px; color: var(--mlgp-muted);">
						<span class="mlgmp-loading" style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: var(--mlgp-brand);"></span>
						<span id="mlgmp-repair-operation-label"></span>
					</div>
					<div id="mlgmp-repair-result"></div>
				</div>

			</div>

			<!-- Orphan / incomplete gallery audit panel -->
			<div class="mlgp-panel" style="margin-bottom: 20px;" id="mlgmp-orphan-panel">
				<div style="margin-bottom: 16px; padding-bottom: 14px; border-bottom: 1px solid var(--mlgp-line); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
					<div>
						<strong style="font-size: 14px; color: var(--mlgp-ink); display: block; margin-bottom: 3px;"><?php esc_html_e( 'Auditoria de Órfãos', 'ml-gallery-migrator-pro' ); ?></strong>
						<span style="font-size: 12px; color: var(--mlgp-muted);"><?php esc_html_e( 'Detecta galerias recuperáveis via DB, mapeamento ou pasta física NGG.', 'ml-gallery-migrator-pro' ); ?></span>
					</div>
					<div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
						<select id="mlgmp-orphan-filter" style="min-height: 36px; padding: 6px 10px; border: 1px solid var(--mlgp-line); border-radius: 8px; background: var(--mlgp-surface-soft); color: var(--mlgp-ink); font-size: 12px;">
							<option value="all"><?php esc_html_e( 'Todos com problemas', 'ml-gallery-migrator-pro' ); ?></option>
							<option value="empty_only"><?php esc_html_e( 'Apenas vazias (0 itens)', 'ml-gallery-migrator-pro' ); ?></option>
							<option value="incomplete_only"><?php esc_html_e( 'Apenas incompletas', 'ml-gallery-migrator-pro' ); ?></option>
							<option value="no_dest"><?php esc_html_e( 'Sem destino mapeado', 'ml-gallery-migrator-pro' ); ?></option>
						</select>
						<button type="button" id="mlgmp-orphan-audit-run" class="mlgp-button mlgp-button--ghost" data-source="<?php echo esc_attr( $source ); ?>" style="font-size: 12px; min-height: 36px; padding: 0 14px;">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:5px"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
							<?php esc_html_e( 'Auditar órfãos', 'ml-gallery-migrator-pro' ); ?>
						</button>
					</div>
				</div>

				<div id="mlgmp-orphan-list-wrap" style="display:none;">
					<div style="display: flex; gap: 8px; margin-bottom: 10px; align-items: center; flex-wrap: wrap;">
						<label style="font-size: 12px; display: flex; align-items: center; gap: 5px; cursor: pointer;">
							<input type="checkbox" id="mlgmp-orphan-select-all">
							<span><?php esc_html_e( 'Selecionar tudo', 'ml-gallery-migrator-pro' ); ?></span>
						</label>
						<span id="mlgmp-orphan-count" style="font-size: 12px; color: var(--mlgp-muted); margin-left: 6px;"></span>
						<div style="margin-left: auto; display: flex; gap: 8px;">
							<button type="button" id="mlgmp-orphan-recover-selected" class="mlgp-button mlgp-button--ghost" data-source="<?php echo esc_attr( $source ); ?>" style="font-size: 12px; min-height: 36px; padding: 0 14px;" disabled>
								<?php esc_html_e( 'Recuperar selecionadas', 'ml-gallery-migrator-pro' ); ?>
							</button>
							<button type="button" id="mlgmp-orphan-recover-all" class="mlgp-button mlgp-button--accent" data-source="<?php echo esc_attr( $source ); ?>" style="font-size: 12px; min-height: 36px; padding: 0 14px;">
								<?php esc_html_e( 'Recuperar todas filtradas', 'ml-gallery-migrator-pro' ); ?>
							</button>
						</div>
					</div>

					<div id="mlgmp-orphan-progress" style="display:none; margin-bottom:10px; padding: 10px 14px; background: rgba(99,102,241,.06); border: 1px solid rgba(99,102,241,.2); border-radius: 10px; font-size: 12px; color: var(--mlgp-ink);">
						<strong id="mlgmp-orphan-prog-label"><?php esc_html_e( 'Recuperando...', 'ml-gallery-migrator-pro' ); ?></strong>
						<div style="margin-top: 5px; display: flex; gap: 16px; flex-wrap: wrap;">
							<span><?php esc_html_e( 'Galerias:', 'ml-gallery-migrator-pro' ); ?> <strong id="mlgmp-oprog-gals">0</strong></span>
							<span><?php esc_html_e( 'Inseridas:', 'ml-gallery-migrator-pro' ); ?> <strong id="mlgmp-oprog-ins">0</strong></span>
							<span><?php esc_html_e( 'Ignoradas:', 'ml-gallery-migrator-pro' ); ?> <strong id="mlgmp-oprog-skip">0</strong></span>
							<span><?php esc_html_e( 'Reparadas:', 'ml-gallery-migrator-pro' ); ?> <strong id="mlgmp-oprog-rep">0</strong></span>
							<span><?php esc_html_e( 'Falhas:', 'ml-gallery-migrator-pro' ); ?> <strong id="mlgmp-oprog-fail">0</strong></span>
						</div>
					</div>

					<div style="overflow-x: auto;">
						<table style="width:100%; border-collapse:collapse; font-size:12px;" id="mlgmp-orphan-table">
							<thead>
								<tr style="border-bottom: 1px solid var(--mlgp-line); text-align:left;">
									<th style="padding:6px 8px; font-weight:600; width:30px;"></th>
									<th style="padding:6px 8px; font-weight:600;"><?php esc_html_e( 'Galeria (NGG)', 'ml-gallery-migrator-pro' ); ?></th>
									<th style="padding:6px 8px; font-weight:600;"><?php esc_html_e( 'Destino', 'ml-gallery-migrator-pro' ); ?></th>
									<th style="padding:6px 8px; font-weight:600; text-align:right;"><?php esc_html_e( 'Fonte', 'ml-gallery-migrator-pro' ); ?></th>
									<th style="padding:6px 8px; font-weight:600; text-align:right;"><?php esc_html_e( 'Destino', 'ml-gallery-migrator-pro' ); ?></th>
									<th style="padding:6px 8px; font-weight:600; text-align:right;"><?php esc_html_e( 'Faltando', 'ml-gallery-migrator-pro' ); ?></th>
									<th style="padding:6px 8px; font-weight:600;"><?php esc_html_e( 'Status', 'ml-gallery-migrator-pro' ); ?></th>
									<th style="padding:6px 8px; font-weight:600;"><?php esc_html_e( 'Evidência', 'ml-gallery-migrator-pro' ); ?></th>
								</tr>
							</thead>
							<tbody id="mlgmp-orphan-tbody"></tbody>
						</table>
					</div>
				</div>

				<div id="mlgmp-orphan-empty" style="display:none; font-size:13px; color:var(--mlgp-muted); padding: 20px 0; text-align:center;">
					<?php esc_html_e( 'Nenhuma galeria órfã ou incompleta encontrada.', 'ml-gallery-migrator-pro' ); ?>
				</div>
			</div>

			<!-- Repair log panel -->
			<div class="mlgp-panel" style="margin-top: 20px; display: flex; flex-direction: column; min-height: 280px;" id="mlgmp-repair-log-panel">
				<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 14px; border-bottom: 1px solid var(--mlgp-line);">
					<h2 style="margin: 0; font-size: 16px; font-weight: 600; color: var(--mlgp-ink);"><?php esc_html_e( 'Log de Reparo', 'ml-gallery-migrator-pro' ); ?></h2>
					<button type="button" id="mlgmp-repair-clear-log" class="mlgp-button mlgp-button--ghost" style="font-size: 12px; min-height: 34px; padding: 0 12px;">
						<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:5px"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
						<?php esc_html_e( 'Limpar', 'ml-gallery-migrator-pro' ); ?>
					</button>
				</div>
				<div class="mlgmp-log-wrap" id="mlgmp-repair-log-list" style="flex: 1; min-height: 200px;">
					<div class="mlgmp-log" id="mlgmp-repair-log">
						<div class="mlgmp-log__entry">
							<span class="mlgmp-log__msg" style="color: #64748b; font-style: italic;"><?php esc_html_e( 'Nenhuma operação de reparo executada ainda.', 'ml-gallery-migrator-pro' ); ?></span>
						</div>
					</div>
				</div>
			</div>

		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Dependency check
	// -----------------------------------------------------------------------


	private function source_active( string $source ): bool {
		return 'photo_gallery' === $source ? PhotoGalleryReader::active() : NGGReader::ngg_active();
	}

	private function source_meta( string $source ): array {
		if ( 'photo_gallery' === $source ) {
			return [
				'label'           => __( 'Photo Gallery', 'ml-gallery-migrator-pro' ),
				'short'           => __( 'Photo Gallery', 'ml-gallery-migrator-pro' ),
				'cleanup_action'  => 'mlgmp_photo_gallery_cleanup',
				'cleanup_nonce'   => 'mlgmp_photo_gallery_cleanup',
				'cleanup_nonce_value' => wp_create_nonce( 'mlgmp_photo_gallery_cleanup' ),
				'cleanup_button'  => __( 'Limpar vestígios do Photo Gallery', 'ml-gallery-migrator-pro' ),
				'cleanup_desc'    => __( 'Use somente depois de validar a migração e remover o Photo Gallery. A limpeza apaga tabelas bwg_*, options/transients, cron, usermeta residual e pastas conhecidas do plugin.', 'ml-gallery-migrator-pro' ),
				'cleanup_confirm' => __( 'Esta limpeza vai apagar definitivamente todos os vestígios do Photo Gallery neste site. Continuar?', 'ml-gallery-migrator-pro' ),
			];
		}
		return [
			'label'           => __( 'NextGEN', 'ml-gallery-migrator-pro' ),
			'short'           => __( 'NGG', 'ml-gallery-migrator-pro' ),
			'cleanup_action'  => 'mlgmp_ngg_cleanup',
			'cleanup_nonce'   => 'mlgmp_ngg_cleanup',
			'cleanup_nonce_value' => wp_create_nonce( 'mlgmp_ngg_cleanup' ),
			'cleanup_button'  => __( 'Limpar vestígios do NGG', 'ml-gallery-migrator-pro' ),
			'cleanup_desc'    => __( 'Use somente depois de validar a migração e remover o NGG. A limpeza apaga tabelas, options/transients, cron, usermeta residual e as pastas wp-content/gallery e wp-content/cache/ngg.', 'ml-gallery-migrator-pro' ),
			'cleanup_confirm' => __( 'Esta limpeza vai apagar definitivamente todos os vestígios do NextGEN neste site. Continuar?', 'ml-gallery-migrator-pro' ),
		];
	}

	private function mlgp_active(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT TABLE_NAME FROM information_schema.TABLES
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s LIMIT 1',
				DB_NAME,
				$wpdb->prefix . 'mlgp_galleries'
			)
		);
		return (bool) $exists;
	}
}
