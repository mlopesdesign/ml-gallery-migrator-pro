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
			'dashicons-migration',
			80
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

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permissão negada.', 'ml-gallery-migrator-pro' ) );
		}

		$state    = State::get();
		$analysis = $state['analysis'];
		$status   = $state['status'];
		$ngg_ok   = NGGReader::ngg_active();
		$mlgp_ok  = $this->mlgp_active();

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
						<p><?php esc_html_e( 'Copiador de acervo oficial do NextGEN Gallery para a estrutura inteligente do ML Gallery Pro.', 'ml-gallery-migrator-pro' ); ?></p>
					</div>
				</div>
				<div class="mlgp-shell__meta">
					<span class="mlgp-shell__version">v<?php echo esc_html( MLGMP_VERSION ); ?></span>
					<div class="mlgp-shell__tags">
						<span class="mlgp-shell__tag"><?php esc_html_e( 'Plugin Gratuito', 'ml-gallery-migrator-pro' ); ?></span>
						<span class="mlgp-shell__tag"><?php esc_html_e( 'Motor Seguro em Lotes', 'ml-gallery-migrator-pro' ); ?></span>
					</div>
				</div>
			</div>

			<nav class="mlgp-shell__tabs" aria-label="<?php esc_attr_e( 'Navegação do plugin', 'ml-gallery-migrator-pro' ); ?>">
				<?php if ( $mlgp_ok ) : ?>
					<a class="mlgp-shell__tab" href="<?php echo esc_url( admin_url( 'admin.php?page=mlgp-dashboard' ) ); ?>">
						&larr; <?php esc_html_e( 'Voltar para o Painel ML Gallery', 'ml-gallery-migrator-pro' ); ?>
					</a>
				<?php endif; ?>
				<a class="mlgp-shell__tab is-active" href="<?php echo esc_url( admin_url( 'admin.php?page=' . MLGMP_SLUG ) ); ?>">
					<?php esc_html_e( 'Motor de Cópia NGG', 'ml-gallery-migrator-pro' ); ?>
				</a>
			</nav>

			<div class="mlgp-shell__content" id="mlgmp-app">

			<?php $this->render_notices( $ngg_ok, $mlgp_ok ); ?>

			<!--  ─── STATUS BAR  ─────────────────────────────────────────── -->
			<div class="mlgmp-status-bar" id="mlgmp-status-bar">
				<div class="mlgmp-status-indicator" id="mlgmp-status-indicator" data-status="<?php echo esc_attr( $status ); ?>">
					<span class="mlgmp-status-dot"></span>
					<span class="mlgmp-status-label" id="mlgmp-status-label">
						<?php echo esc_html( $this->status_label( $status ) ); ?>
					</span>
				</div>
				<div class="mlgmp-stage-badge" id="mlgmp-stage-badge">
					<?php if ( $status !== State::STATUS_IDLE ) : ?>
						<?php echo esc_html( $this->stage_label( $state['current_stage'] ) ); ?>
					<?php endif; ?>
				</div>
			</div>

			<!--  ─── PROGRESS BAR  ─────────────────────────────────────────── -->
			<div class="mlgmp-progress-wrap" id="mlgmp-progress-wrap">
				<?php
				$grand_total = State::grand_total( $state );
				$processed   = State::total_processed( $state );
				$pct         = $grand_total > 0 ? min( 100, round( $processed / $grand_total * 100 ) ) : 0;
				?>
				<div class="mlgmp-progress-bar-outer">
					<div class="mlgmp-progress-bar-inner" id="mlgmp-progress-bar"
						style="width:<?php echo esc_attr( $pct ); ?>%"></div>
				</div>
				<div class="mlgmp-progress-meta">
					<span id="mlgmp-progress-pct"><?php echo esc_html( $pct ); ?>%</span>
					<span id="mlgmp-progress-count">
						<?php echo esc_html( $processed . ' / ' . $grand_total ); ?>
					</span>
				</div>
			</div>

			<!--  ─── OPERATIONAL PANELS (ANALYSIS + CONTROLS + PROGRESS) ─────────── -->
			<div class="mlgmp-operational-section">
				<?php $this->render_analysis_panel( $analysis ); ?>
				
				<div class="mlgp-grid-two">
					<div>
						<?php $this->render_controls( $status, $ngg_ok, $mlgp_ok, $state ); ?>
					</div>
					<div>
						<?php $this->render_counters( $state ); ?>
					</div>
				</div>
			</div>

			<!--  ─── LOGS PANEL ────────────────────────── -->
			<div class="mlgmp-logs-section" style="margin-top: 20px;">
				<?php $this->render_log_panel( $state ); ?>
			</div>

			</div><!-- .mlgp-shell__content -->
		</div><!-- .mlgp-shell -->
		<?php
	}

	// -----------------------------------------------------------------------
	// Render sub-sections
	// -----------------------------------------------------------------------

	private function render_notices( bool $ngg_ok, bool $mlgp_ok ): void {
		$detection = NGGReader::detect();

		echo '<div class="mlgp-notice-stack" id="mlgmp-notice-stack">';
		if ( $ngg_ok ) {
			echo '<div class="mlgp-notice is-success">';
			echo '<strong>' . esc_html__( '✅ NextGEN Gallery detectado.', 'ml-gallery-migrator-pro' ) . '</strong> ';
			echo '<small>' . sprintf( esc_html__( 'Tabelas: %s', 'ml-gallery-migrator-pro' ), implode( ', ', $detection['tables_found'] ) ) . '</small>';
			echo '</div>';
		}
		echo '</div>';

		if ( ! $ngg_ok ) {
			echo '<div class="mlgp-notice is-error" style="margin-bottom: 20px;">';
			echo '<strong>' . esc_html__( 'NextGEN Gallery não detectado.', 'ml-gallery-migrator-pro' ) . '</strong><br>';
			echo '<small>';
			echo sprintf( esc_html__( 'Plugin ativo: %s', 'ml-gallery-migrator-pro' ), ( $detection['plugin_active'] ? __( 'SIM', 'ml-gallery-migrator-pro' ) : __( 'NÃO', 'ml-gallery-migrator-pro' ) ) ) . ' &nbsp;|&nbsp; ';
			echo sprintf( esc_html__( 'Tabelas encontradas: %s', 'ml-gallery-migrator-pro' ), ( $detection['tables_found'] ? implode( ', ', $detection['tables_found'] ) : __( 'nenhuma', 'ml-gallery-migrator-pro' ) ) ) . ' &nbsp;|&nbsp; ';
			echo sprintf( esc_html__( 'Tabelas ausentes: %s', 'ml-gallery-migrator-pro' ), ( $detection['tables_missing'] ? implode( ', ', $detection['tables_missing'] ) : __( 'nenhuma', 'ml-gallery-migrator-pro' ) ) ) . ' &nbsp;|&nbsp; ';
			echo sprintf( esc_html__( 'BD: %s', 'ml-gallery-migrator-pro' ), $detection['db_name'] );
			echo '</small>';
			echo '</div>';
		}

		if ( ! $mlgp_ok ) {
			echo '<div class="mlgp-notice is-error" style="margin-bottom: 20px;">';
			echo '<strong>' . esc_html__( 'ML Gallery Pro não encontrado.', 'ml-gallery-migrator-pro' ) . '</strong> ';
			echo esc_html__( 'Instale e ative o ML Gallery Pro antes de migrar.', 'ml-gallery-migrator-pro' );
			echo '</div>';
		}
	}

	private function render_analysis_panel( ?array $analysis ): void {
		?>
		<div class="mlgp-panel" id="mlgmp-analysis-panel">
			<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
				<h2 style="margin: 0; font-size: 16px; font-weight: 600;"><?php esc_html_e( 'Análise do Acervo NGG', 'ml-gallery-migrator-pro' ); ?></h2>
				<button type="button" id="mlgmp-btn-analyse" class="mlgp-button mlgp-button--ghost">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
					<?php esc_html_e( 'Analisar', 'ml-gallery-migrator-pro' ); ?>
				</button>
			</div>
			<div class="mlgmp-analysis-grid" id="mlgmp-analysis-grid">
				<?php $this->render_analysis_numbers( $analysis ); ?>
			</div>
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
			echo '<div class="mlgmp-stat">';
			echo '<span class="mlgmp-stat__icon">' . $item['icon'] . '</span>';
			echo '<span class="mlgmp-stat__num" id="mlgmp-stat-' . esc_attr( $item['key'] ) . '">' . esc_html( number_format_i18n( $n ) ) . '</span>';
			echo '<span class="mlgmp-stat__label">' . esc_html( $item['label'] ) . '</span>';
			echo '</div>';
		}
	}

	private function render_controls( string $status, bool $ngg_ok, bool $mlgp_ok, array $state ): void {
		$enabled = $ngg_ok && $mlgp_ok;
		$is_idle     = in_array( $status, [ State::STATUS_IDLE, State::STATUS_DONE, State::STATUS_CANCELLED, State::STATUS_ERROR ], true );
		$is_running  = $status === State::STATUS_RUNNING;
		$is_paused   = $status === State::STATUS_PAUSED;
		?>
		<div class="mlgp-panel">
			<div style="margin-bottom: 24px;">
				<h2 style="margin: 0; font-size: 16px; font-weight: 600;"><?php esc_html_e( 'Controles', 'ml-gallery-migrator-pro' ); ?></h2>
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

			<!--  Action buttons  -->
			<div class="mlgmp-btn-row" style="margin-top: 24px; display: flex; gap: 8px; flex-wrap: wrap;">
				<button type="button" id="mlgmp-btn-start"
					class="mlgp-button mlgp-button--accent"
					<?php echo ( ! $is_idle || ! $enabled ) ? 'disabled' : ''; ?>>
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
					<?php esc_html_e( 'Iniciar', 'ml-gallery-migrator-pro' ); ?>
				</button>

				<button type="button" id="mlgmp-btn-pause"
					class="mlgp-button"
					<?php echo ! $is_running ? 'disabled' : ''; ?>>
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px"><rect x="6" y="4" width="4" height="16"></rect><rect x="14" y="4" width="4" height="16"></rect></svg>
					<?php esc_html_e( 'Pausar', 'ml-gallery-migrator-pro' ); ?>
				</button>

				<button type="button" id="mlgmp-btn-resume"
					class="mlgp-button mlgp-button--accent"
					<?php echo ! $is_paused ? 'disabled' : ''; ?>>
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
					<?php esc_html_e( 'Retomar', 'ml-gallery-migrator-pro' ); ?>
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
	// Dependency check
	// -----------------------------------------------------------------------

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
