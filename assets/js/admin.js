/**
 * ML Gallery Migrator Pro — Admin JS
 *
 * Architecture:
 *   - No external dependencies (vanilla JS only).
 *   - State machine: idle → running → paused → running → done | cancelled.
 *   - Continuous mode: after each successful batch, immediately fires the next.
 *   - Single-batch mode: fires one batch then stops (stays in running state
 *     on server, but JS does not fire another batch — user must click again
 *     or switch to continuous).
 *   - Log entries are fetched inside each batch response AND on poll intervals.
 *   - Poll interval is active only when NOT in running/continuous mode
 *     responses.
 *
 * @package MLGalleryMigratorPro
 */

/* global MLGMP */

( function () {
	'use strict';

	// ── Config ──────────────────────────────────────────────────────────────
	const POLL_INTERVAL_MS = 3000;   // Polling interval when idle/paused.
	const BATCH_DELAY_MS   = 100;    // Delay between continuous batches (yield to browser).

	// ── State ───────────────────────────────────────────────────────────────
	let isBatchRunning  = false;
	let pollTimer       = null;
	let lastLogId       = 0;
	let currentStatus   = 'idle';
	let currentMode     = 'continuous';

	// ── DOM refs ─────────────────────────────────────────────────────────────
	const $ = id => document.getElementById( id );
	const $q = sel => document.querySelector( sel );
	const $qa = sel => Array.from( document.querySelectorAll( sel ) );

	// Buttons.
	const analysisButtons = $qa( '.mlgmp-btn-analyse-source' );
	const analysisTabs    = $qa( '.mlgmp-analysis-tab' );
	const analysisPanes   = $qa( '.mlgmp-analysis-pane' );
	const reliabilityTabs  = $qa( '.mlgmp-reliability-tab' );
	const reliabilityPanes = $qa( '.mlgmp-reliability-pane' );
	const reportCopyButtons = $qa( '.mlgmp-btn-copy-report' );
	const btnStart    = $( 'mlgmp-btn-start' );
	const btnPause    = $( 'mlgmp-btn-pause' );
	const btnResume   = $( 'mlgmp-btn-resume' );
	const btnCancel   = $( 'mlgmp-btn-cancel' );
	const btnReset    = $( 'mlgmp-btn-reset' );
	const btnClearLog = $( 'mlgmp-btn-clear-log' );
	const btnCopyLog  = $( 'mlgmp-btn-copy-log' );
	const btnStartCount  = $( 'mlgmp-btn-start-count' );
	const btnPauseCount  = $( 'mlgmp-btn-pause-count' );
	const btnResumeCount = $( 'mlgmp-btn-resume-count' );

	// Progress.
	const progressBar = $( 'mlgmp-progress-bar' );
	const progressPct = $( 'mlgmp-progress-pct' );
	const progressCnt = $( 'mlgmp-progress-count' );

	// Status.
	const statusIndicator = $( 'mlgmp-status-indicator' );
	const statusLabel     = $( 'mlgmp-status-label' );
	const stageBadge      = $( 'mlgmp-stage-badge' );

	// Log.
	const logEl        = $( 'mlgmp-log' );
	const logWrap      = $( 'mlgmp-log-wrap' );
	const logCountEl   = $( 'mlgmp-log-count' );
	const lastIdEl     = $( 'mlgmp-last-id' );
	const autoscrollEl = $( 'mlgmp-autoscroll' );

	// Analysis grids.
	const analysisGrids = {};
	analysisPanes.forEach( pane => {
		analysisGrids[ pane.dataset.analysisPane ] = pane.querySelector( '.mlgmp-analysis-grid' );
	} );
	// Cleanup card.
	const cleanupCard   = $q( '.mlgmp-cleanup-card' );
	const cleanupTitle  = $( 'mlgmp-cleanup-title' );
	const cleanupDesc   = $( 'mlgmp-cleanup-desc' );
	const cleanupForm   = $( 'mlgmp-cleanup-form' );
	const cleanupAction = $( 'mlgmp-cleanup-action' );
	const cleanupButton = $( 'mlgmp-cleanup-button' );
	const cleanupMsg    = $( 'mlgmp-cleanup-message' );


	// ── Init ─────────────────────────────────────────────────────────────────
	function init() {
		// Read last log id from PHP-rendered HTML.
		if ( lastIdEl ) {
			lastLogId = parseInt( lastIdEl.dataset.id, 10 ) || 0;
		}

		// Bind buttons.
		if ( btnStart )    btnStart.addEventListener(    'click', onStart );
		if ( btnPause )    btnPause.addEventListener(    'click', onPause );
		if ( btnResume )   btnResume.addEventListener(   'click', onResume );
		if ( btnCancel )   btnCancel.addEventListener(   'click', onCancel );
		if ( btnReset )    btnReset.addEventListener(    'click', onReset );
		if ( btnClearLog ) btnClearLog.addEventListener( 'click', onClearLog );
		if ( btnCopyLog )  btnCopyLog.addEventListener(  'click', onCopyLog );

		// Intercept cleanup form: block admin-post.php; run batched AJAX instead.
		if ( cleanupForm ) {
			cleanupForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				const confirmMsg = cleanupForm.dataset.confirm || 'Confirmar limpeza definitiva?';
				if ( ! window.confirm( confirmMsg ) ) return;
				const chk = document.getElementById( 'mlgmp-cleanup-allow-dirs' );
				runBatchedCleanup( !! ( chk && chk.checked ) );
			} );
		}
		reportCopyButtons.forEach( btn => btn.addEventListener( 'click', onCopyReport ) );

		$qa( 'input[name="mlgmp_source"]' ).forEach( el => {
			el.addEventListener( 'change', () => {
				syncCleanupCardToSource();
				setActiveAnalysisTab( getSelectedSource() );
				setActiveReliabilityPane( getSelectedSource() );
			} );
		} );
		analysisTabs.forEach( tab => {
			tab.addEventListener( 'click', () => {
				const source = tab.dataset.analysisTab || 'ngg';
				const radio = $q( 'input[name="mlgmp_source"][value="' + source + '"]' );
				if ( radio ) {
					radio.checked = true;
				}
				syncCleanupCardToSource();
				setActiveAnalysisTab( source );
				setActiveReliabilityPane( source );
			} );
		} );
		reliabilityTabs.forEach( tab => {
			tab.addEventListener( 'click', () => {
				const source = tab.dataset.reliabilityTab || 'ngg';
				const radio = $q( 'input[name="mlgmp_source"][value="' + source + '"]' );
				if ( radio ) {
					radio.checked = true;
				}
				syncCleanupCardToSource();
				setActiveAnalysisTab( source );
				setActiveReliabilityPane( source );
			} );
		} );

		analysisButtons.forEach( btn => {
			btn.addEventListener( 'click', () => {
				onAnalyse( btn.dataset.source || getSelectedSource() );
			} );
		} );

		syncCleanupCardToSource();
		setActiveAnalysisTab( getSelectedSource() );
		setActiveReliabilityPane( getSelectedSource() );

		// Mode radio changes.
		$qa( 'input[name="mlgmp_mode"]' ).forEach( el => {
			el.addEventListener( 'change', () => {
				currentMode = el.value;
			} );
		} );

		// All stages checkbox logic.
		const chkAllStages = $( 'mlgmp-all-stages' );
		const chkStages    = $qa( 'input[name="mlgmp_stages[]"]' );
		if ( chkAllStages ) {
			chkAllStages.addEventListener( 'change', () => {
				chkStages.forEach( chk => chk.checked = chkAllStages.checked );
			} );
		}
		chkStages.forEach( chk => {
			chk.addEventListener( 'change', () => {
				if ( ! chk.checked && chkAllStages ) chkAllStages.checked = false;
				if ( chk.checked && chkStages.every( c => c.checked ) && chkAllStages ) chkAllStages.checked = true;
			} );
		} );

		// Read initial mode.
		const checkedMode = $q( 'input[name="mlgmp_mode"]:checked' );
		if ( checkedMode ) currentMode = checkedMode.value;

		// Log filter checkboxes.
		$qa( '.mlgmp-log-filter' ).forEach( el => {
			el.addEventListener( 'change', applyLogFilters );
		} );

		// Start poll for status updates while not running.
		startPoll();

		// Auto-hide success toasts after 10 seconds
		$qa( '.mlgp-notice.is-success' ).forEach( toast => {
			setTimeout( () => {
				toast.classList.add( 'is-leaving' );
				setTimeout( () => toast.remove(), 300 );
			}, 10000 );
		} );
	}


	function getSelectedSource() {
		const sourceEl = $q( 'input[name="mlgmp_source"]:checked' );
		return sourceEl ? sourceEl.value : 'ngg';
	}

	function parseCardData( attrName ) {
		if ( ! cleanupCard ) return {};
		const raw = cleanupCard.getAttribute( attrName );
		if ( ! raw ) return {};
		try {
			return JSON.parse( raw );
		} catch ( e ) {
			return {};
		}
	}

	function syncCleanupCardToSource() {
		if ( ! cleanupCard ) return;
		const source = getSelectedSource();
		const metaMap = parseCardData( 'data-meta' );
		const scans = parseCardData( 'data-scans' );
		const meta = metaMap[ source ] || metaMap.ngg || {};
		const scan = scans[ source ] || scans.ngg || {};
		const isRunning = currentStatus === 'running';
		const isPaused = currentStatus === 'paused';
		const isBlockedByPlugin = !! scan.plugin_active;

		if ( cleanupTitle ) cleanupTitle.textContent = 'Limpeza final do ' + ( meta.label || 'NextGEN' );
		if ( cleanupDesc ) cleanupDesc.textContent = meta.cleanup_desc || '';
		if ( cleanupForm ) cleanupForm.dataset.confirm = meta.cleanup_confirm || '';
		if ( cleanupAction ) cleanupAction.value = meta.cleanup_action || 'mlgmp_ngg_cleanup';
		const nonceField = cleanupForm ? cleanupForm.querySelector( 'input[name="mlgmp_cleanup_nonce"]' ) : null;
		if ( nonceField ) nonceField.value = meta.cleanup_nonce_value || '';
		if ( cleanupButton ) {
			cleanupButton.disabled = isRunning || isPaused || isBlockedByPlugin;
			cleanupButton.lastChild.textContent = ' ' + ( meta.cleanup_button || 'Limpar vestígios do NGG' );
		}
		if ( cleanupMsg ) {
			cleanupMsg.className = 'mlgmp-cleanup-card__hint';
			if ( isBlockedByPlugin ) {
				cleanupMsg.className = 'mlgmp-cleanup-card__alert';
				cleanupMsg.textContent = 'Bloqueado: o ' + ( meta.label || 'plugin de origem' ) + ' ainda está ativo.';
			} else if ( isRunning || isPaused ) {
				cleanupMsg.className = 'mlgmp-cleanup-card__alert';
				cleanupMsg.textContent = 'Bloqueado: finalize ou cancele a migração antes de limpar.';
			} else {
				cleanupMsg.textContent = 'Liberado para limpeza definitiva do ' + ( meta.label || 'plugin de origem' ) + '.';
			}
		}
		const map = {
			tables_count: 'mlgmp-cleanup-tables',
			options_count: 'mlgmp-cleanup-options',
			usermeta_count: 'mlgmp-cleanup-usermeta',
			cron_count: 'mlgmp-cleanup-cron',
			dirs_count: 'mlgmp-cleanup-dirs',
		};
		Object.keys( map ).forEach( key => {
			const el = $( map[ key ] );
			if ( el ) el.textContent = String( scan[ key ] || 0 );
		} );
	}

	// ── AJAX helpers ────────────────────────────────────────────────────────

	function ajax( action, extraData = {} ) {
		const body = new URLSearchParams( {
			action,
			nonce: MLGMP.nonce,
			...extraData,
		} );

		return fetch( MLGMP.ajax_url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).then( r => r.json() );
	}

	// ── Button handlers ─────────────────────────────────────────────────────

	function getAnalysisGrid( source ) {
		return analysisGrids[ source ] || null;
	}

	function setActiveAnalysisTab( source ) {
		analysisTabs.forEach( tab => {
			const active = tab.dataset.analysisTab === source;
			tab.classList.toggle( 'is-active', active );
			tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
		} );
		analysisPanes.forEach( pane => {
			pane.classList.toggle( 'is-active', pane.dataset.analysisPane === source );
		} );
	}

	function setActiveReliabilityPane( source ) {
		reliabilityTabs.forEach( tab => {
			const active = tab.dataset.reliabilityTab === source;
			tab.classList.toggle( 'is-active', active );
			tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
		} );
		reliabilityPanes.forEach( pane => {
			pane.classList.toggle( 'is-active', pane.dataset.reliabilityPane === source );
		} );
	}

	function onAnalyse( forcedSource ) {
		setButtonsDisabled( true );
		const source = forcedSource || getSelectedSource();
		const grid = getAnalysisGrid( source );
		if ( grid ) {
			grid.innerHTML = '<p class="mlgmp-muted mlgmp-loading">Analisando acervo…</p>';
		}
		setActiveAnalysisTab( source );

		ajax( 'mlgmp_analyse', { source } ).then( res => {
			if ( res.success ) {
				renderAnalysis( source, res.data.analysis );
				applyState( res.data.state );
			} else if ( grid ) {
				grid.innerHTML = '<p class="mlgmp-error">' + esc( res.data?.message || 'Erro.' ) + '</p>';
			}
		} ).catch( err => {
			if ( grid ) {
				grid.innerHTML = '<p class="mlgmp-error">' + esc( String( err ) ) + '</p>';
			}
		} ).finally( () => {
			setButtonsDisabled( false );
			syncButtonStates();
		} );
	}

	function onStart() {
		const mode      = currentMode;
		const batchSize = parseInt( $( 'mlgmp-batch-size' ).value, 10 ) || 20;

		// Read stages.
		const stages = $qa( 'input[name="mlgmp_stages[]"]:checked' ).map( el => el.value );
		if ( stages.length === 0 ) {
			alert( 'Por favor, selecione pelo menos uma etapa para migrar.' );
			return;
		}

		// Read duplicate mode.
		const dupEl = $q( 'input[name="mlgmp_dup_mode"]:checked' );
		const dupMode = dupEl ? dupEl.value : 'ignore';

		setButtonsDisabled( true );

		const source = getSelectedSource();

		ajax( 'mlgmp_start', {
			mode,
			batch_size: batchSize,
			stages_enabled: JSON.stringify( stages ),
			duplicate_mode: dupMode,
			source
		} ).then( res => {
			if ( res.success ) {
				applyState( res.data.state );
				// Kick off the continuous run.
				stopPoll();
				scheduleBatch();
			} else {
				alert( res.data?.message || 'Erro ao iniciar.' );
				setButtonsDisabled( false );
				syncButtonStates();
			}
		} ).catch( () => {
			setButtonsDisabled( false );
			syncButtonStates();
		} );
	}

	function onPause() {
		ajax( 'mlgmp_pause' ).then( res => {
			if ( res.success ) {
				applyState( res.data.state );
				stopBatchLoop();
				startPoll();
			}
		} );
	}

	function onResume() {
		const mode      = currentMode;
		const batchSize = parseInt( $( 'mlgmp-batch-size' ).value, 10 ) || 20;

		// Read stages.
		const stages = $qa( 'input[name="mlgmp_stages[]"]:checked' ).map( el => el.value );
		if ( stages.length === 0 ) {
			alert( 'Por favor, selecione pelo menos uma etapa para migrar.' );
			return;
		}

		// Read duplicate mode.
		const dupEl = $q( 'input[name="mlgmp_dup_mode"]:checked' );
		const dupMode = dupEl ? dupEl.value : 'ignore';

		const source = getSelectedSource();

		ajax( 'mlgmp_resume', {
			mode,
			batch_size: batchSize,
			stages_enabled: JSON.stringify( stages ),
			duplicate_mode: dupMode,
			source
		} ).then( res => {
			if ( res.success ) {
				applyState( res.data.state );
				stopPoll();
				scheduleBatch();
			}
		} );
	}

	function onCancel() {
		if ( ! window.confirm( MLGMP.strings.confirm_cancel ) ) return;

		ajax( 'mlgmp_cancel' ).then( res => {
			if ( res.success ) {
				applyState( res.data.state );
				stopBatchLoop();
				startPoll();
			}
		} );
	}

	function onReset() {
		if ( ! window.confirm( MLGMP.strings.confirm_reset ) ) return;

		ajax( 'mlgmp_reset' ).then( res => {
			if ( res.success ) {
				applyState( res.data.state );
				clearLog();
				renderCounters( res.data.state );
				updateProgress( 0, 0 );
				stopBatchLoop();
				startPoll();

				// Reset analysis display.
				Object.values( analysisGrids ).forEach( grid => {
					if ( grid ) {
						grid.innerHTML = '<p class="mlgmp-muted">Clique em "Analisar" para inspecionar o acervo.</p>';
					}
				} );
			}
		} );
	}

	function onClearLog() {
		clearLog();
	}

	// ── Batch loop ───────────────────────────────────────────────────────────

	function scheduleBatch() {
		if ( isBatchRunning ) return;
		setTimeout( runBatch, BATCH_DELAY_MS );
	}

	function runBatch() {
		if ( isBatchRunning ) return;
		if ( currentStatus !== 'running' ) return;

		isBatchRunning = true;

		const batchSize = parseInt( $( 'mlgmp-batch-size' ).value, 10 ) || 20;

		ajax( 'mlgmp_batch', {
			last_log_id: lastLogId,
			batch_size:  batchSize,
		} ).then( res => {
			isBatchRunning = false;

			if ( ! res.success ) {
				// Server says not running (paused/cancelled externally).
				if ( res.data?.state ) {
					applyState( res.data.state );
				}
				startPoll();
				return;
			}

			const data = res.data;
			applyState( data.state );
			appendLogs( data.logs );

		// Continue or stop depending on mode.
		if ( data.batch_result?.all_done ) {
			// Migration finished.
			stopBatchLoop();
			startPoll();
			return;
		}

		if ( currentStatus === 'running' && currentMode === 'continuous' ) {
			// Continuous mode: fire next batch immediately.
			scheduleBatch();
		} else {
			// Single-batch mode: JS stops.
			// Transition server to READY state (not PAUSED).
			stopBatchLoop();
			ajax( 'mlgmp_ready' ).then( readyRes => {
				if ( readyRes.success ) applyState( readyRes.data.state );
				startPoll();
			} ).catch( () => startPoll() );
		}

		} ).catch( () => {
			isBatchRunning = false;
			// Network error — retry after delay.
			setTimeout( runBatch, 2000 );
		} );
	}

	function stopBatchLoop() {
		isBatchRunning = false;
	}

	// ── Polling (idle / paused / done) ──────────────────────────────────────

	function startPoll() {
		stopPoll();
		pollTimer = setInterval( doPoll, POLL_INTERVAL_MS );
	}

	function stopPoll() {
		if ( pollTimer ) clearInterval( pollTimer );
		pollTimer = null;
	}

	function doPoll() {
		ajax( 'mlgmp_poll_state', { last_log_id: lastLogId } ).then( res => {
			if ( res.success ) {
				applyState( res.data.state );
				appendLogs( res.data.logs );

				// If someone externally resumed (e.g. WP-CLI), follow along —
				// but ONLY in continuous mode to avoid breaking single-batch intent.
				if ( currentStatus === 'running' && currentMode === 'continuous' ) {
					stopPoll();
					scheduleBatch();
				}
			}
		} );
	}

	// ── State application ────────────────────────────────────────────────────

	function applyState( state ) {
		if ( ! state ) return;

		currentStatus = state.status;
		window.mlgmpCurrentTotals = state.totals || {};
		window.mlgmpCurrentCounters = state.counters || {};

		// Status indicator.
		if ( statusIndicator ) statusIndicator.dataset.status = state.status;
		if ( statusLabel ) statusLabel.textContent = statusLabelText( state.status );
		if ( stageBadge ) stageBadge.textContent = stageLabel( state.current_stage );

		// Progress.
		const total     = grandTotal( state );
		const processed = totalProcessed( state );
		updateProgress( processed, total );

		// Counters table.
		renderCounters( state );

		// Button states.
		syncActionButtonCounters();
		syncButtonStates();
		syncCleanupCardToSource();
	}

	function syncActionButtonCounters() {
		const total = grandTotal( { totals: window.mlgmpCurrentTotals || {} } );
		const processed = totalProcessed( { counters: window.mlgmpCurrentCounters || {} } );
		const text = total > 0 ? ` ${ processed } / ${ total }` : '';
		if ( btnStartCount ) btnStartCount.textContent = text;
		if ( btnPauseCount ) btnPauseCount.textContent = text;
		if ( btnResumeCount ) btnResumeCount.textContent = text;
	}

	function syncButtonStates() {
		const idle      = [ 'idle', 'done', 'cancelled', 'error' ].includes( currentStatus );
		const running   = currentStatus === 'running';
		const paused    = currentStatus === 'paused';
		const ready     = currentStatus === 'ready';
		const inFlight  = running || paused || ready;

		analysisButtons.forEach( btn => btn.disabled = inFlight );
		if ( btnStart )  btnStart.disabled  = ! ( idle || ready );
		if ( btnPause )  btnPause.disabled  = ! running;
		if ( btnResume ) btnResume.disabled = ! paused;
		if ( btnCancel ) btnCancel.disabled = ! inFlight;
		if ( btnReset )  btnReset.disabled  = running;
	}

	function setButtonsDisabled( disabled ) {
		[ btnStart, btnPause, btnResume, btnCancel, btnReset, ...analysisButtons ]
			.forEach( b => { if ( b ) b.disabled = disabled; } );
	}

	// ── Progress helpers ─────────────────────────────────────────────────────

	function updateProgress( processed, total ) {
		const pct = total > 0 ? Math.min( 100, Math.round( processed / total * 100 ) ) : 0;
		if ( progressBar ) progressBar.style.width = pct + '%';
		if ( progressPct ) progressPct.textContent = pct + '%';
		if ( progressCnt ) progressCnt.textContent = processed + ' / ' + total;
	}

	function grandTotal( state ) {
		const t = state.totals || {};
		return ( t.galleries || 0 ) + ( t.albums || 0 ) + ( t.images || 0 ) +
		       ( t.thumbs || 0 ) + ( t.shortcodes || 0 );
	}

	function totalProcessed( state ) {
		let n = 0;
		const c = state.counters || {};
		[ 'galleries', 'albums', 'images', 'thumbs', 'shortcodes' ].forEach( key => {
			n += ( ( c[ key ] || {} ).processed || 0 );
		} );
		return n;
	}

	// ── Counters table ────────────────────────────────────────────────────────

	function renderCounters( state ) {
		const counters  = state.counters || {};
		const curStage  = state.current_stage;

		$qa( '.mlgmp-counter-row' ).forEach( row => {
			const stage = row.dataset.stage;
			const c     = counters[ stage ] || {};

			// Active highlight.
			row.classList.toggle( 'mlgmp-row--active', stage === curStage );

			// Aggregates for UI.
			const processed = c.processed || 0;
			const orphans   = c.ignored_mapping || c.orphans || 0;
			const ignored   = ( c.ignored_revision || 0 ) + ( c.ignored_draft || 0 ) + ( c.ignored_widget_orphan || 0 ) + ( c.skipped || 0 );
			const errors    = c.errors || 0;

			// Fill cells.
			const setCell = ( cls, val ) => {
				const el = row.querySelector( '.' + cls );
				if ( el ) el.textContent = val;
			};
			setCell( 'mlgmp-counter-processed', processed );
			setCell( 'mlgmp-counter-orphans',   orphans );
			setCell( 'mlgmp-counter-ignored',   ignored );
			setCell( 'mlgmp-counter-errors',    errors );
		} );
	}

	// ── Analysis rendering ────────────────────────────────────────────────────

	function renderAnalysis( source, analysis ) {
		const grid = getAnalysisGrid( source );
		if ( ! grid ) return;

		if ( analysis.error ) {
			grid.innerHTML = '<p class="mlgmp-error">' + esc( analysis.error ) + '</p>';
			return;
		}

		const items = [
			{ key: 'galleries',  icon: '🖼️', label: 'Galerias' },
			{ key: 'albums',     icon: '📁', label: 'Álbuns' },
			{ key: 'images',     icon: '📷', label: 'Imagens' },
			{ key: 'thumbs',     icon: '🔲', label: 'Miniaturas' },
			{ key: 'shortcodes', icon: '🔗', label: 'Shortcodes' },
		];

		grid.innerHTML = items.map( it => `
			<div class="mlgmp-stat">
				<span class="mlgmp-stat__icon">${ it.icon }</span>
				<span class="mlgmp-stat__num">${ fmt( analysis[ it.key ] || 0 ) }</span>
				<span class="mlgmp-stat__label">${ it.label }</span>
			</div>
		` ).join( '' );
	}

	// ── Log rendering ────────────────────────────────────────────────────────

	let logEntryCount = 0;

	function appendLogs( entries ) {
		if ( ! entries || ! entries.length || ! logEl ) return;

		const activeFilters = $qa( '.mlgmp-log-filter:checked' ).map( el => el.value );
		let appended = 0;

		entries.forEach( entry => {
			// Track last id.
			const id = parseInt( entry.id, 10 );
			if ( id > lastLogId ) lastLogId = id;
			if ( lastIdEl ) lastIdEl.dataset.id = lastLogId;

			const row = buildLogRow( entry );
			applyLogFilter( row, activeFilters );
			logEl.appendChild( row );
			appended++;
		} );

		logEntryCount += appended;
		if ( logCountEl ) logCountEl.textContent = logEntryCount + ' entradas';

		if ( autoscrollEl && autoscrollEl.checked && appended > 0 ) {
			logWrap.scrollTop = logWrap.scrollHeight;
		}
	}

	function buildLogRow( entry ) {
		const div = document.createElement( 'div' );
		div.className = 'mlgmp-log__entry mlgmp-log__entry--' + entry.level;
		div.dataset.level = entry.level;
		div.innerHTML = `
			<span class="mlgmp-log__time">${ esc( entry.created_at ) }</span>
			<span class="mlgmp-log__badge mlgmp-badge--${ esc( entry.level ) }">${ esc( entry.level.toUpperCase() ) }</span>
			<span class="mlgmp-log__stage">[${esc( entry.stage )}]</span>
			<span class="mlgmp-log__msg">${ esc( entry.message ) }</span>
		`;
		return div;
	}

	function applyLogFilter( el, activeFilters ) {
		el.style.display = activeFilters.includes( el.dataset.level ) ? '' : 'none';
	}

	function applyLogFilters() {
		const activeFilters = $qa( '.mlgmp-log-filter:checked' ).map( el => el.value );
		$qa( '.mlgmp-log__entry' ).forEach( el => applyLogFilter( el, activeFilters ) );
	}

	function onCopyLog() {
		const visibleEntries = $qa( '.mlgmp-log__entry' ).filter( el => {
			return el.style.display !== 'none' && el.offsetParent !== null;
		} );
		
		const text = visibleEntries.map( el => {
			const time  = el.querySelector( '.mlgmp-log__time' ) ? el.querySelector( '.mlgmp-log__time' ).textContent.trim() : '';
			const level = el.querySelector( '.mlgmp-log__badge' ) ? el.querySelector( '.mlgmp-log__badge' ).textContent.trim() : '';
			const stage = el.querySelector( '.mlgmp-log__stage' ) ? el.querySelector( '.mlgmp-log__stage' ).textContent.trim() : '';
			const msg   = el.querySelector( '.mlgmp-log__msg' )   ? el.querySelector( '.mlgmp-log__msg' ).textContent.trim() : '';
			return `[${time}] ${level} ${stage} ${msg}`;
		} ).join( '\n' );

		if ( ! text ) {
			alert( 'Nenhum log visível para copiar.' );
			return;
		}

		// Robust copy strategy:
		const textArea = document.createElement( 'textarea' );
		textArea.value = text;
		textArea.style.position = 'fixed';
		textArea.style.left = '-9999px';
		textArea.style.top = '0';
		document.body.appendChild( textArea );
		textArea.focus();
		textArea.select();

		let success = false;
		try {
			success = document.execCommand( 'copy' );
		} catch ( err ) {
			success = false;
		}

		document.body.removeChild( textArea );

		if ( success ) {
			const oldText = btnCopyLog.textContent;
			btnCopyLog.textContent = '✅ Copiado!';
			btnCopyLog.classList.add( 'mlgmp-btn--success' );
			setTimeout( () => {
				btnCopyLog.textContent = oldText;
				btnCopyLog.classList.remove( 'mlgmp-btn--success' );
			}, 2000 );
		} else {
			// Second attempt with clipboard API if possible.
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( () => {
					const oldText = btnCopyLog.textContent;
					btnCopyLog.textContent = '✅ Copiado!';
					btnCopyLog.classList.add( 'mlgmp-btn--success' );
					setTimeout( () => {
						btnCopyLog.textContent = oldText;
						btnCopyLog.classList.remove( 'mlgmp-btn--success' );
					}, 2000 );
				} ).catch( () => {
					alert( 'Falha ao copiar log. Verifique as permissões do navegador.' );
				} );
			} else {
				alert( 'Falha ao copiar log. Esse navegador não tem suporte.' );
			}
		}
	}

	function onCopyReport( event ) {
		const button = event.currentTarget;
		const text = button && button.dataset ? button.dataset.reportText || '' : '';
		if ( ! text ) return;

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( () => {
				flashButtonLabel( button, 'Relatório copiado!' );
			} ).catch( () => {
				fallbackCopyText( text, button );
			} );
			return;
		}

		fallbackCopyText( text, button );
	}

	function fallbackCopyText( text, button ) {
		const textArea = document.createElement( 'textarea' );
		textArea.value = text;
		textArea.style.position = 'fixed';
		textArea.style.left = '-9999px';
		document.body.appendChild( textArea );
		textArea.focus();
		textArea.select();

		try {
			document.execCommand( 'copy' );
			flashButtonLabel( button, 'Relatório copiado!' );
		} catch ( err ) {
			alert( 'Falha ao copiar o relatório.' );
		}

		document.body.removeChild( textArea );
	}

	function flashButtonLabel( button, text ) {
		if ( ! button ) return;
		const previous = button.textContent;
		button.textContent = text;
		button.classList.add( 'mlgmp-btn--success' );
		setTimeout( () => {
			button.textContent = previous;
			button.classList.remove( 'mlgmp-btn--success' );
		}, 1800 );
	}

	function clearLog() {
		if ( logEl ) logEl.innerHTML = '';
		logEntryCount = 0;
		lastLogId     = 0;
		if ( logCountEl ) logCountEl.textContent = '0 entradas';
		if ( lastIdEl ) lastIdEl.dataset.id = 0;
	}

	// ── Label helpers ────────────────────────────────────────────────────────

	function statusLabelText( status ) {
		const map = {
			idle:      'Aguardando',
			running:   'Executando…',
			paused:    'Pausado',
			ready:     'Pronto (aguardando próximo lote)',
			done:      'Concluído ✓',
			cancelled: 'Cancelado',
			error:     'Erro ✗',
		};
		return map[ status ] || status;
	}

	function stageLabel( stage ) {
		const map = {
			galleries:  'Etapa: Galerias',
			albums:     'Etapa: Álbuns',
			images:     'Etapa: Imagens',
			thumbs:     'Etapa: Miniaturas',
			shortcodes: 'Etapa: Shortcodes',
			done:       'Tudo concluído',
		};
		return map[ stage ] || stage || '';
	}

	// ── Utilities ────────────────────────────────────────────────────────────

	function esc( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function fmt( n ) {
		return Number( n ).toLocaleString( 'pt-BR' );
	}

	// ── Configuration Tab ────────────────────────────────────────────────────

	function initConfigTab() {
		const form = $( 'mlgmp-config-form' );
		if ( ! form ) return;

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			onSaveConfig();
		} );

		const btn = $( 'mlgmp-save-config' );
		if ( btn ) btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			onSaveConfig();
		} );
	}

	function onSaveConfig() {
		const form = $( 'mlgmp-config-form' );
		if ( ! form ) return;

		const nonceEl   = $( 'mlgmp-config-nonce' );
		const nonce     = nonceEl ? nonceEl.value : ( MLGMP.nonce || '' );
		const statusEl  = $( 'mlgmp-config-status' );
		const submitBtn = $( 'mlgmp-save-config' );

		const batchEl = $( 'mlgmp-config-batch' );
		const batchSize = batchEl ? batchEl.value : '20';

		const dupChecked = form.querySelector( 'input[name="duplicate_mode"]:checked' );
		const duplicateMode = dupChecked ? dupChecked.value : 'ignore';

		const stageBoxes = Array.from( form.querySelectorAll( 'input[name="enabled_stages[]"]:checked' ) );
		const enabledStages = stageBoxes.map( function ( ch ) { return ch.value; } );

		if ( submitBtn ) submitBtn.disabled = true;
		if ( statusEl ) {
			statusEl.textContent = 'Salvando\u2026';
			statusEl.style.color = 'var(--mlgp-muted)';
		}

		const params = new URLSearchParams();
		params.append( 'action', 'mlgmp_save_settings' );
		params.append( 'nonce', nonce );
		params.append( 'batch_size', batchSize );
		params.append( 'duplicate_mode', duplicateMode );
		params.append( 'enabled_stages', JSON.stringify( enabledStages ) );

		const xhr = new XMLHttpRequest();
		xhr.open( 'POST', MLGMP.ajax_url, true );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );

		xhr.onload = function () {
			if ( submitBtn ) submitBtn.disabled = false;
			if ( xhr.status === 200 ) {
				let resp;
				try { resp = JSON.parse( xhr.responseText ); } catch ( e ) { resp = null; }
				if ( resp && resp.success ) {
					if ( statusEl ) {
						statusEl.textContent = '\u2713 Configuração salva com sucesso.';
						statusEl.style.color = 'var(--mlgp-success)';
						setTimeout( function () {
							statusEl.textContent = '';
						}, 3500 );
					}
				} else {
					const msg = ( resp && resp.data && resp.data.message ) ? resp.data.message : 'Erro ao salvar.';
					if ( statusEl ) {
						statusEl.textContent = '\u2715 ' + msg;
						statusEl.style.color = 'var(--mlgp-danger)';
					}
				}
			} else {
				if ( statusEl ) {
					statusEl.textContent = '\u2715 Erro na requisição (' + xhr.status + ').';
					statusEl.style.color = 'var(--mlgp-danger)';
				}
			}
		};

		xhr.onerror = function () {
			if ( submitBtn ) submitBtn.disabled = false;
			if ( statusEl ) {
				statusEl.textContent = '\u2715 Erro de conexão.';
				statusEl.style.color = 'var(--mlgp-danger)';
			}
		};

		xhr.send( params.toString() );
	}

	// ── Repair Tab ───────────────────────────────────────────────────────────

	function initRepairTab() {
		$qa( '.mlgmp-repair-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', onRepairOperation );
		} );

		const clearBtn = $( 'mlgmp-repair-clear-log' );
		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				const logEl = $( 'mlgmp-repair-log' );
				if ( logEl ) logEl.innerHTML = '<div class="mlgmp-log__entry"><span class="mlgmp-log__msg" style="color:#64748b;font-style:italic;">Log limpo.</span></div>';
			} );
		}

		// ── Scope panel toggle ────────────────────────────────────────────
		initScopePanel();

		// ── Gallery mismatch audit panel ─────────────────────────────────
		initAuditPanel();

		// ── Orphan audit panel ────────────────────────────────────────────
		initOrphanPanel();
	}

	// -----------------------------------------------------------------------
	// Gallery mismatch audit + single-gallery recovery loop
	// -----------------------------------------------------------------------

	var _auditRows   = [];   // Full dataset from last audit.
	var _recovering  = false;

	var _orphanRows   = [];
	var _orphanBusy   = false;

	function initOrphanPanel() {
		const runBtn  = $( 'mlgmp-orphan-audit-run' );
		const selAll  = $( 'mlgmp-orphan-select-all' );
		const recSel  = $( 'mlgmp-orphan-recover-selected' );
		const recAll  = $( 'mlgmp-orphan-recover-all' );

		if ( runBtn ) {
			runBtn.addEventListener( 'click', function() {
				runOrphanAudit( runBtn.dataset.source || 'ngg' );
			} );
		}
		if ( selAll ) {
			selAll.addEventListener( 'change', function() {
				$qa( '.mlgmp-orphan-chk' ).forEach( function( c ) { c.checked = selAll.checked; } );
				updateOrphanBtns();
			} );
		}
		if ( recSel ) {
			recSel.addEventListener( 'click', function() {
				const sel = getOrphanSelected();
				if ( sel.length ) runOrphanRecovery( sel, recSel.dataset.source || 'ngg' );
			} );
		}
		if ( recAll ) {
			recAll.addEventListener( 'click', function() {
				runOrphanRecovery( _orphanRows.slice(), recAll.dataset.source || 'ngg' );
			} );
		}
	}

	function runOrphanAudit( source ) {
		const runBtn  = $( 'mlgmp-orphan-audit-run' );
		const wrap    = $( 'mlgmp-orphan-list-wrap' );
		const empty   = $( 'mlgmp-orphan-empty' );
		const tbody   = $( 'mlgmp-orphan-tbody' );
		const countEl = $( 'mlgmp-orphan-count' );
		const filter  = ( $( 'mlgmp-orphan-filter' ) || {} ).value || 'all';

		if ( runBtn ) { runBtn.disabled = true; runBtn.textContent = 'Auditando\u2026'; }
		if ( wrap  ) wrap.style.display  = 'none';
		if ( empty ) empty.style.display = 'none';

		const params = new FormData();
		params.append( 'action',   'mlgmp_audit_orphans' );
		params.append( 'nonce',    MLGMP.nonce );
		params.append( 'source',   source );
		params.append( 'filter',   filter );
		params.append( 'page',     '1' );
		params.append( 'per_page', '200' );

		fetch( MLGMP.ajax_url, { method: 'POST', body: params } )
			.then( function( r ) {
				return r.text().then( function( text ) {
					try { return JSON.parse( text ); }
					catch ( _ ) { throw new Error( 'Resposta inválida: ' + text.substring( 0, 200 ) ); }
				} );
			} )
			.then( function( data ) {
				if ( runBtn ) { runBtn.disabled = false; runBtn.textContent = 'Auditar \u00f3rf\u00e3os'; }

				if ( ! data.success ) {
					repairLog( 'Erro: ' + ( ( data.data && data.data.message ) ? data.data.message : JSON.stringify( data.data ) ), 'error' );
					return;
				}

				_orphanRows = data.data.rows || [];

				if ( ! _orphanRows.length ) {
					if ( empty ) empty.style.display = 'block';
					return;
				}

				if ( tbody ) tbody.innerHTML = '';
				var evidenceLabels = { mapping: 'Mapeamento', filesystem: 'Filesystem', slug_relink: 'Slug/Título' };
				var statusColors   = { empty: '#dc2626', incomplete: '#d97706', no_dest: '#7c3aed', ok: '#16a34a' };

				_orphanRows.forEach( function( row, idx ) {
					const color = statusColors[ row.status ] || '#64748b';
					const evidText = evidenceLabels[ row.evidence ] || row.evidence || '—';
					const destCell = row.dest_id
						? ( 'id=' + row.dest_id + ( row.dest_slug ? ' (' + esc( row.dest_slug ) + ')' : '' ) )
						: '<em style="color:#7c3aed;">sem destino</em>';
					const chkAttr = row.dest_id ? '' : ' disabled title="Sem destino — recuperação indisponível"';
					const tr = document.createElement( 'tr' );
					tr.style.borderBottom = '1px solid var(--mlgp-line)';
					tr.innerHTML =
						'<td style="padding:7px 8px;"><input type="checkbox" class="mlgmp-orphan-chk" data-idx="' + idx + '"' + chkAttr + '></td>' +
						'<td style="padding:7px 8px;"><span style="font-weight:600;">' + esc( row.src_name ) + '</span>' +
							'<br><span style="color:var(--mlgp-muted);font-size:11px;">NGG #' + row.src_id + '</span></td>' +
						'<td style="padding:7px 8px;font-size:11px;color:var(--mlgp-muted);">' + destCell + '</td>' +
						'<td style="padding:7px 8px;text-align:right;">' + row.src_count  + '</td>' +
						'<td style="padding:7px 8px;text-align:right;">' + row.dest_count + '</td>' +
						'<td style="padding:7px 8px;text-align:right;font-weight:600;color:' + color + ';">' + row.diff + '</td>' +
						'<td style="padding:7px 8px;"><span style="font-size:11px;font-weight:600;color:' + color + ';">' + esc( row.status ) + '</span></td>' +
						'<td style="padding:7px 8px;font-size:11px;color:var(--mlgp-muted);">' + esc( evidText ) + '</td>';
					if ( tbody ) tbody.appendChild( tr );
				} );

				$qa( '.mlgmp-orphan-chk' ).forEach( function( c ) {
					c.addEventListener( 'change', updateOrphanBtns );
				} );

				if ( countEl ) countEl.textContent = _orphanRows.length + ' órfã(s) detectada(s)';
				if ( wrap ) wrap.style.display = 'block';
				updateOrphanBtns();
				repairLog( 'Auditoria de órfãos: ' + _orphanRows.length + ' galeria(s) detectada(s).' );
			} )
			.catch( function( err ) {
				if ( runBtn ) { runBtn.disabled = false; runBtn.textContent = 'Auditar \u00f3rf\u00e3os'; }
				repairLog( 'Erro na auditoria: ' + err.message, 'error' );
			} );
	}

	function getOrphanSelected() {
		const out = [];
		$qa( '.mlgmp-orphan-chk:checked' ).forEach( function( c ) {
			const idx = parseInt( c.dataset.idx, 10 );
			if ( _orphanRows[ idx ] ) out.push( _orphanRows[ idx ] );
		} );
		return out;
	}

	function updateOrphanBtns() {
		const recSel = $( 'mlgmp-orphan-recover-selected' );
		const count  = $qa( '.mlgmp-orphan-chk:checked' ).length;
		if ( recSel ) recSel.disabled = ( count === 0 );
	}

	function runOrphanRecovery( galleries, source ) {
		if ( _orphanBusy ) return;
		if ( ! galleries.length ) return;
		_orphanBusy = true;

		const progWrap  = $( 'mlgmp-orphan-progress' );
		const progLabel = $( 'mlgmp-orphan-prog-label' );
		const totals = { gals: 0, inserted: 0, skipped: 0, repaired: 0, broken: 0 };

		function updateProg() {
			const g = $( 'mlgmp-oprog-gals' ), ins = $( 'mlgmp-oprog-ins' ),
			      sk = $( 'mlgmp-oprog-skip' ), rp = $( 'mlgmp-oprog-rep' ), fl = $( 'mlgmp-oprog-fail' );
			if ( g   ) g.textContent   = totals.gals + '/' + galleries.length;
			if ( ins ) ins.textContent = totals.inserted;
			if ( sk  ) sk.textContent  = totals.skipped;
			if ( rp  ) rp.textContent  = totals.repaired;
			if ( fl  ) fl.textContent  = totals.broken;
		}

		if ( progWrap ) progWrap.style.display = 'block';
		updateProg();

		function recoverNext( idx ) {
			if ( idx >= galleries.length ) {
				_orphanBusy = false;
				if ( progLabel ) progLabel.textContent = 'Recupera\u00e7\u00e3o conclu\u00edda.';
				repairLog( 'Órfãos recuperados: ' + totals.gals + ' galerias, ' +
					totals.inserted + ' inseridas, ' + totals.skipped + ' ignoradas, ' +
					totals.repaired + ' reparadas, ' + totals.broken + ' falhas.' );
				return;
			}
			const row = galleries[ idx ];
			if ( ! row.dest_id ) {
				// No destination — skip silently.
				totals.gals++; totals.broken++;
				updateProg();
				setTimeout( function() { recoverNext( idx + 1 ); }, 50 );
				return;
			}
			if ( progLabel ) {
				progLabel.textContent = 'Recuperando ' + ( idx + 1 ) + '/' + galleries.length + ': ' + ( row.src_name || ( 'NGG #' + row.src_id ) );
			}

			const params = new FormData();
			params.append( 'action',  'mlgmp_recover_orphan' );
			params.append( 'nonce',   MLGMP.nonce );
			params.append( 'source',  source );
			params.append( 'src_id',  row.src_id );
			params.append( 'dest_id', row.dest_id );

			fetch( MLGMP.ajax_url, { method: 'POST', body: params } )
				.then( function( r ) {
					return r.text().then( function( text ) {
						try { return JSON.parse( text ); }
						catch ( _ ) { throw new Error( 'Resposta inválida: ' + text.substring( 0, 200 ) ); }
					} );
				} )
				.then( function( data ) {
					totals.gals++;
					if ( data.success && data.data && data.data.result ) {
						const r = data.data.result;
						totals.inserted += r.inserted || 0;
						totals.skipped  += r.skipped  || 0;
						totals.repaired += r.repaired || 0;
						totals.broken   += r.broken   || 0;
						repairLog( 'dest=' + r.dest_id + ' (' + ( r.dest_slug || '' ) + '): ' + r.message );
					} else {
						totals.broken++;
						repairLog( 'Erro: dest=' + row.dest_id + ' — ' + ( ( data.data && data.data.message ) ? data.data.message : JSON.stringify( data.data ) ), 'error' );
					}
					updateProg();
					setTimeout( function() { recoverNext( idx + 1 ); }, 150 );
				} )
				.catch( function( err ) {
					totals.gals++; totals.broken++;
					updateProg();
					repairLog( 'Erro de rede: ' + err.message, 'error' );
					setTimeout( function() { recoverNext( idx + 1 ); }, 500 );
				} );
		}
		recoverNext( 0 );
	}

		function initAuditPanel() {
		const runBtn     = $( 'mlgmp-audit-run' );
		const selAll     = $( 'mlgmp-audit-select-all' );
		const recSel     = $( 'mlgmp-recover-selected' );
		const recAll     = $( 'mlgmp-recover-all-filtered' );

		if ( runBtn ) {
			runBtn.addEventListener( 'click', function() {
				runAudit( runBtn.dataset.source || 'ngg' );
			} );
		}
		if ( selAll ) {
			selAll.addEventListener( 'change', function() {
				$qa( '.mlgmp-audit-chk' ).forEach( function( c ) { c.checked = selAll.checked; } );
				updateRecoverBtns();
			} );
		}
		if ( recSel ) {
			recSel.addEventListener( 'click', function() {
				const selected = getSelectedRows();
				if ( selected.length ) runRecovery( selected, recSel.dataset.source || 'ngg' );
			} );
		}
		if ( recAll ) {
			recAll.addEventListener( 'click', function() {
				runRecovery( _auditRows.slice(), recAll.dataset.source || 'ngg' );
			} );
		}
	}

	function runAudit( source ) {
		const runBtn  = $( 'mlgmp-audit-run' );
		const wrap    = $( 'mlgmp-audit-list-wrap' );
		const empty   = $( 'mlgmp-audit-empty' );
		const tbody   = $( 'mlgmp-audit-tbody' );
		const countEl = $( 'mlgmp-audit-count' );
		const filter  = ( $( 'mlgmp-audit-filter' ) || {} ).value || 'all';

		if ( runBtn ) { runBtn.disabled = true; runBtn.textContent = 'Auditando…'; }
		if ( wrap )  wrap.style.display = 'none';
		if ( empty ) empty.style.display = 'none';

		const params = new FormData();
		params.append( 'action',   'mlgmp_audit_galleries' );
		params.append( 'nonce',    MLGMP.nonce );
		params.append( 'source',   source );
		params.append( 'filter',   filter );
		params.append( 'page',     '1' );
		params.append( 'per_page', '200' );

		fetch( MLGMP.ajax_url, { method: 'POST', body: params } )
			.then( function( r ) {
				// Clone so we can check text if JSON parse fails.
				return r.text().then( function( text ) {
					try {
						return JSON.parse( text );
					} catch ( _ ) {
						const preview = text.substring( 0, 200 ).replace( /[\r\n]+/g, ' ' );
						throw new Error( 'Resposta inválida (não JSON): ' + preview );
					}
				} );
			} )
			.then( function( data ) {
				if ( runBtn ) { runBtn.disabled = false; runBtn.textContent = 'Auditar galerias'; }

				if ( ! data.success ) {
					repairLog( 'Erro na auditoria: ' + ( ( data.data && data.data.message ) ? data.data.message : JSON.stringify( data.data ) ), 'error' );
					return;
				}

				_auditRows = data.data.rows || [];

				if ( ! _auditRows.length ) {
					if ( empty ) empty.style.display = 'block';
					return;
				}

				// Render table rows.
				if ( tbody ) tbody.innerHTML = '';
				_auditRows.forEach( function( row, idx ) {
					const statusColor = row.status === 'empty' ? '#dc2626' : row.status === 'incomplete' ? '#d97706' : '#16a34a';
					const tr = document.createElement( 'tr' );
					tr.style.borderBottom = '1px solid var(--mlgp-line)';
					tr.innerHTML =
						'<td style="padding:7px 8px;">' +
							'<input type="checkbox" class="mlgmp-audit-chk" data-idx="' + idx + '">' +
						'</td>' +
						'<td style="padding:7px 8px;">' +
							'<span style="font-weight:600;">' + esc( row.src_name || ( 'NGG #' + row.src_id ) ) + '</span>' +
							'<br><span style="color:var(--mlgp-muted);font-size:11px;">src=' + row.src_id + ' → dest=' + row.dest_id + '</span>' +
						'</td>' +
						'<td style="padding:7px 8px; color:var(--mlgp-muted); font-size:11px;">' + esc( row.dest_slug || '—' ) + '</td>' +
						'<td style="padding:7px 8px; text-align:right;">' + row.src_count + '</td>' +
						'<td style="padding:7px 8px; text-align:right;">' + row.dest_count + '</td>' +
						'<td style="padding:7px 8px; text-align:right; font-weight:600; color:' + statusColor + ';">' + row.diff + '</td>' +
						'<td style="padding:7px 8px;"><span style="font-size:11px; font-weight:600; color:' + statusColor + ';">' + esc( row.status ) + '</span></td>';
					if ( tbody ) tbody.appendChild( tr );
				} );

				// Wire checkboxes.
				$qa( '.mlgmp-audit-chk' ).forEach( function( chk ) {
					chk.addEventListener( 'change', updateRecoverBtns );
				} );

				if ( countEl ) countEl.textContent = _auditRows.length + ' galeria(s) com problemas';
				if ( wrap ) wrap.style.display = 'block';
				updateRecoverBtns();
				repairLog( 'Auditoria: ' + _auditRows.length + ' galeria(s) com divergência encontradas.' );
			} )
			.catch( function( err ) {
				if ( runBtn ) { runBtn.disabled = false; runBtn.textContent = 'Auditar galerias'; }
				repairLog( 'Erro na auditoria: ' + err.message, 'error' );
			} );
	}

	function getSelectedRows() {
		const out = [];
		$qa( '.mlgmp-audit-chk:checked' ).forEach( function( chk ) {
			const idx = parseInt( chk.dataset.idx, 10 );
			if ( _auditRows[ idx ] ) out.push( _auditRows[ idx ] );
		} );
		return out;
	}

	function updateRecoverBtns() {
		const recSel = $( 'mlgmp-recover-selected' );
		const count  = $qa( '.mlgmp-audit-chk:checked' ).length;
		if ( recSel ) recSel.disabled = ( count === 0 );
	}

	function esc( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	/**
	 * Sequential single-gallery recovery loop.
	 * Calls mlgmp_recover_gallery once per gallery — no 504.
	 */
	function runRecovery( galleries, source ) {
		if ( _recovering ) return;
		if ( ! galleries.length ) return;

		_recovering = true;

		const progWrap  = $( 'mlgmp-recover-progress' );
		const progLabel = $( 'mlgmp-recover-prog-label' );
		const progGals  = $( 'mlgmp-rprog-gals' );
		const progIns   = $( 'mlgmp-rprog-ins' );
		const progSkip  = $( 'mlgmp-rprog-skip' );
		const progRep   = $( 'mlgmp-rprog-rep' );
		const progFail  = $( 'mlgmp-rprog-fail' );

		if ( progWrap ) progWrap.style.display = 'block';

		const totals = { gals: 0, inserted: 0, skipped: 0, repaired: 0, broken: 0 };

		function updateProgress() {
			if ( progGals  ) progGals.textContent  = totals.gals + '/' + galleries.length;
			if ( progIns   ) progIns.textContent   = totals.inserted;
			if ( progSkip  ) progSkip.textContent  = totals.skipped;
			if ( progRep   ) progRep.textContent   = totals.repaired;
			if ( progFail  ) progFail.textContent  = totals.broken;
		}

		function recoverNext( idx ) {
			if ( idx >= galleries.length ) {
				_recovering = false;
				if ( progLabel ) progLabel.textContent = 'Recuperação concluída.';
				repairLog( 'Recuperação concluída: ' + totals.gals + ' galerias, ' +
					totals.inserted + ' inseridas, ' + totals.skipped + ' ignoradas, ' +
					totals.repaired + ' reparadas, ' + totals.broken + ' falhas.' );
				return;
			}

			const row = galleries[ idx ];
			if ( progLabel ) {
				progLabel.textContent = 'Recuperando ' + ( idx + 1 ) + '/' + galleries.length +
					': ' + ( row.src_name || ( 'NGG #' + row.src_id ) );
			}

			const params = new FormData();
			params.append( 'action',  'mlgmp_recover_gallery' );
			params.append( 'nonce',   MLGMP.nonce );
			params.append( 'source',  source );
			params.append( 'src_id',  row.src_id );
			params.append( 'dest_id', row.dest_id );

			fetch( MLGMP.ajax_url, { method: 'POST', body: params } )
				.then( function( r ) {
					return r.text().then( function( text ) {
						try {
							return JSON.parse( text );
						} catch ( _ ) {
							const preview = text.substring( 0, 200 ).replace( /[\r\n]+/g, ' ' );
							throw new Error( 'Resposta inválida: ' + preview );
						}
					} );
				} )
				.then( function( data ) {
					totals.gals++;
					if ( data.success && data.data && data.data.result ) {
						const r = data.data.result;
						totals.inserted += r.inserted || 0;
						totals.skipped  += r.skipped  || 0;
						totals.repaired += r.repaired || 0;
						totals.broken   += r.broken   || 0;
						repairLog( 'dest=' + r.dest_id + ' (' + r.dest_slug + '): ' + r.message );
					} else {
						totals.broken++;
						const errMsg = ( data.data && data.data.message ) ? data.data.message : JSON.stringify( data.data );
						repairLog( 'Erro: dest=' + row.dest_id + ' — ' + errMsg, 'error' );
					}
					updateProgress();
					// Small delay to avoid flooding — then process next.
					setTimeout( function() { recoverNext( idx + 1 ); }, 150 );
				} )
				.catch( function( err ) {
					totals.gals++; totals.broken++;
					updateProgress();
					repairLog( 'Erro de rede: ' + err.message, 'error' );
					setTimeout( function() { recoverNext( idx + 1 ); }, 500 );
				} );
		}

		updateProgress();
		recoverNext( 0 );
	}

	function repairLog( msg, level ) {
		const logEl = $( 'mlgmp-repair-log' );
		if ( ! logEl ) return;
		const entry = document.createElement( 'div' );
		entry.className = 'mlgmp-log__entry';
		const color = level === 'error' ? '#dc2626' : level === 'warn' ? '#d97706' : '#16a34a';
		entry.innerHTML = '<span class="mlgmp-log__msg" style="color:' + color + ';">' + esc( msg ) + '</span>';
		logEl.prepend( entry );
	}

		function initScopePanel() {
		const radios = $qa( 'input[name="mlgmp_scope_type"]' );
		const lbls = {
			global:   $( 'mlgmp-scope-lbl-global' ),
			gallery:  $( 'mlgmp-scope-lbl-gallery' ),
			album:    $( 'mlgmp-scope-lbl-album' ),
			filtered: $( 'mlgmp-scope-lbl-filtered' ),
		};
		const fields = {
			gallery:  $( 'mlgmp-scope-fields-gallery' ),
			album:    $( 'mlgmp-scope-fields-album' ),
			filtered: $( 'mlgmp-scope-fields-filtered' ),
		};
		const activeCss  = 'border-color:var(--mlgp-brand);background:rgba(99,102,241,.08);';
		const inactiveCss= 'border-color:var(--mlgp-line);background:transparent;';

		function updateScope( type ) {
			Object.keys( lbls ).forEach( function( k ) {
				if ( lbls[k] ) lbls[k].style.cssText += ( k === type ? activeCss : inactiveCss );
			} );
			Object.keys( fields ).forEach( function( k ) {
				if ( fields[k] ) fields[k].style.display = ( k === type ) ? 'flex' : 'none';
			} );
		}

		radios.forEach( function( r ) {
			r.addEventListener( 'change', function() { updateScope( this.value ); } );
		} );
		updateScope( 'global' );
	}

	function getScopeParams() {
		const typeEl = document.querySelector( 'input[name="mlgmp_scope_type"]:checked' );
		const type   = typeEl ? typeEl.value : 'global';
		const params = { scope_type: type };

		if ( 'gallery' === type ) {
			params.scope_dest_gal = ( $( 'mlgmp-scope-dest-gal' ) || {} ).value || '';
			params.scope_src_gal  = ( $( 'mlgmp-scope-src-gal'  ) || {} ).value || '';
			params.scope_slug     = ( ( $( 'mlgmp-scope-slug'    ) || {} ).value || '' ).trim();
		} else if ( 'album' === type ) {
			params.scope_dest_alb = ( $( 'mlgmp-scope-dest-alb' ) || {} ).value || '';
			params.scope_slug     = ( ( $( 'mlgmp-scope-alb-slug') || {} ).value || '' ).trim();
		} else if ( 'filtered' === type ) {
			const filterEl = document.querySelector( 'input[name="mlgmp_scope_filter"]:checked' );
			params.scope_filter = filterEl ? filterEl.value : 'empty_only';
		}

		return params;
	}

	// Accumulator for paginated fix_orphans results.
	var _orphanAccum = { orphan_images: 0, orphan_galleries: 0, marked: 0, empty_dest_galleries: 0 };

	function onRepairOperation( e ) {
		const btn       = e.currentTarget;
		const operation = btn.dataset.operation;
		const source    = btn.dataset.source || 'ngg';
		if ( ! operation ) return;

		// Reset orphan accumulator on fresh click.
		if ( operation === 'fix_orphans' ) {
			_orphanAccum = { orphan_images: 0, orphan_galleries: 0, marked: 0 };
		}

		// fix_orphans must NOT run as a single blocking request.
		// Redirect to orphan audit panel.
		if ( operation === 'fix_orphans' ) {
			const orphanPanel = $( 'mlgmp-orphan-panel' );
			if ( orphanPanel ) {
				orphanPanel.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				const filterSel = $( 'mlgmp-orphan-filter' );
				if ( filterSel ) filterSel.value = 'all';
				setTimeout( function() { runOrphanAudit( source ); }, 400 );
			} else {
				runOrphanAudit( source );
			}
			return;
		}

		// recover_incomplete_galleries must NOT run as a single blocking request.
		// Redirect to the audit panel: scroll to it and auto-trigger audit.
		if ( operation === 'recover_incomplete_galleries' ) {
			const auditPanel = $( 'mlgmp-audit-panel' );
			if ( auditPanel ) {
				auditPanel.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				// Set filter to 'all' and auto-run audit after scroll.
				const filterSel = $( 'mlgmp-audit-filter' );
				if ( filterSel ) filterSel.value = 'all';
				setTimeout( function() { runAudit( source ); }, 400 );
			} else {
				runAudit( source );
			}
			return;
		}

		runRepairRequest( btn, operation, source, 0 );
	}

	function runRepairRequest( btn, operation, source, cursor ) {
		const statusEl   = $( 'mlgmp-repair-status' );
		const resultEl   = $( 'mlgmp-repair-result' );
		const progressEl = $( 'mlgmp-repair-progress' );
		const labelEl    = $( 'mlgmp-repair-operation-label' );
		const opLabel    = btn.querySelector( 'span' ) ? btn.querySelector( 'span' ).textContent.trim() : operation;

		$qa( '.mlgmp-repair-btn' ).forEach( function ( b ) { b.disabled = true; } );

		if ( statusEl )   statusEl.style.display   = 'block';
		if ( progressEl ) progressEl.style.display  = 'flex';
		if ( cursor === 0 && resultEl ) resultEl.innerHTML = '';
		if ( labelEl )    labelEl.textContent = 'Executando: ' + opLabel + '\u2026' + ( cursor > 0 ? ' (cursor=' + cursor + ')' : '' );

		const params = new URLSearchParams();
		params.append( 'action',    'mlgmp_repair_run' );
		params.append( 'nonce',     MLGMP.nonce );
		params.append( 'operation', operation );
		params.append( 'source',    source );
		params.append( 'cursor',    String( cursor ) );

		// Append scope params (only when cursor === 0 = first call).
		if ( cursor === 0 ) {
			const scopeP = getScopeParams();
			Object.keys( scopeP ).forEach( function( k ) {
				if ( scopeP[k] !== '' && scopeP[k] !== undefined ) {
					params.append( k, String( scopeP[k] ) );
				}
			} );
		}

		const xhr = new XMLHttpRequest();
		xhr.open( 'POST', MLGMP.ajax_url, true );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );

		xhr.onload = function () {
			if ( xhr.status === 200 ) {
				let resp;
				try { resp = JSON.parse( xhr.responseText ); } catch ( err ) { resp = null; }

				if ( resp && resp.success ) {
					const result = ( resp.data && resp.data.repair_result ) ? resp.data.repair_result : {};

					// fix_orphans is paginated — accumulate and continue if not done.
					if ( operation === 'fix_orphans' ) {
						_orphanAccum.orphan_images    += ( result.orphan_images    || 0 );
						_orphanAccum.orphan_galleries += ( result.orphan_galleries || 0 );
						_orphanAccum.marked           += ( result.marked           || 0 );

						if ( ! result.done && result.next_cursor ) {
							// Continue with next cursor.
							if ( labelEl ) labelEl.textContent = 'Verificando\u2026 ' + _orphanAccum.marked + ' marcados até agora.';
							runRepairRequest( btn, operation, source, result.next_cursor );
							return;
						}

						// Done — display accumulated totals.
						const finalResult = Object.assign( {}, result, {
							orphan_images:    _orphanAccum.orphan_images,
							orphan_galleries: _orphanAccum.orphan_galleries,
							marked:           _orphanAccum.marked,
						empty_dest_galleries: _orphanAccum.empty_dest_galleries,
							message:          'Verificação de órfãos concluída. ' + _orphanAccum.marked + ' itens marcados.',
						} );
						$qa( '.mlgmp-repair-btn' ).forEach( function ( b ) { b.disabled = false; } );
						if ( progressEl ) progressEl.style.display = 'none';
						displayRepairResult( finalResult );
						appendRepairLog( opLabel, finalResult );
						return;
					}

					$qa( '.mlgmp-repair-btn' ).forEach( function ( b ) { b.disabled = false; } );
					if ( progressEl ) progressEl.style.display = 'none';
					displayRepairResult( result );
					appendRepairLog( opLabel, result );
				} else {
					$qa( '.mlgmp-repair-btn' ).forEach( function ( b ) { b.disabled = false; } );
					if ( progressEl ) progressEl.style.display = 'none';
					const msg = ( resp && resp.data && resp.data.message ) ? resp.data.message : 'Erro desconhecido.';
					if ( resultEl ) {
						resultEl.innerHTML = '<div style="padding:12px 14px;border-radius:10px;background:rgba(217,45,32,.08);border:1px solid rgba(217,45,32,.18);color:var(--mlgp-danger);font-size:13px;font-weight:600;">\u2715 ' + esc( msg ) + '</div>';
					}
				}
			} else {
				$qa( '.mlgmp-repair-btn' ).forEach( function ( b ) { b.disabled = false; } );
				if ( progressEl ) progressEl.style.display = 'none';
				if ( resultEl ) {
					resultEl.innerHTML = '<div style="padding:12px 14px;border-radius:10px;background:rgba(217,45,32,.08);border:1px solid rgba(217,45,32,.18);color:var(--mlgp-danger);font-size:13px;font-weight:600;">\u2715 Erro HTTP ' + xhr.status + '</div>';
				}
			}
		};

		xhr.onerror = function () {
			$qa( '.mlgmp-repair-btn' ).forEach( function ( b ) { b.disabled = false; } );
			if ( progressEl ) progressEl.style.display = 'none';
			if ( resultEl ) resultEl.innerHTML = '<div style="padding:12px 14px;border-radius:10px;background:rgba(217,45,32,.08);border:1px solid rgba(217,45,32,.18);color:var(--mlgp-danger);font-size:13px;font-weight:600;">\u2715 Erro de conexão.</div>';
		};

		xhr.send( params.toString() );
	}

	function displayRepairResult( result ) {
		const resultEl = $( 'mlgmp-repair-result' );
		if ( ! resultEl ) return;

		const ok   = result.status === 'success';
		const bg   = ok ? 'rgba(21,153,71,.08)'  : 'rgba(217,45,32,.08)';
		const bd   = ok ? 'rgba(21,153,71,.18)'  : 'rgba(217,45,32,.18)';
		const col  = ok ? 'var(--mlgp-success)'  : 'var(--mlgp-danger)';
		const icon = ok ? '\u2713' : '\u2715';

		// Helper: safe number — never NaN, never undefined.
		function safeNum( v ) {
			const n = parseFloat( v );
			return isNaN( n ) ? 0 : n;
		}

		// Flat stat keys for non-integrity repair ops.
		const statKeys = [
			['checked',               'Verificados'],
			['fixed',                 'Corrigidos'],
			['links_created',         'Links criados'],
			['links_skipped',         'Links já existentes'],
			['albums_valid',          'Álbuns já corretos'],
			['albums_repaired',       'Álbuns reparados'],
			['missing',               'Ausentes'],
			['missing_db',                'Ausentes (DB)'],
			['missing_files',             'Ausentes (arquivo)'],
			['recopied',                  'Recopiados'],
			['galleries_inspected',       'Galerias inspecionadas'],
			['galleries_complete',        'Galerias completas'],
			['galleries_empty',           'Galerias vazias'],
			['galleries_incomplete',      'Galerias incompletas'],
			['galleries_refilled',        'Galerias repreenchidas'],
			['images_inspected',          'Imagens inspecionadas'],
			['images_inserted',           'Imagens inseridas'],
			['images_skipped',            'Imagens já existentes'],
			['images_still_missing',      'Imagens ainda ausentes'],
			['empty_gals_refilled',       'Galerias vazias preenchidas'],
			['galleries_scanned',         'Galerias verificadas'],
			['galleries_complete',        'Galerias completas'],
			['galleries_empty',           'Galerias vazias'],
			['galleries_incomplete',      'Galerias incompletas'],
			['galleries_recovered',       'Galerias recuperadas'],
			['images_repaired',           'Imagens reparadas'],
			['logical_families',          'Famílias lógicas'],
			['raster_skipped',            'Duplicatas raster ignoradas'],
			['files_recopied',            'Arquivos recopiados'],
			['mappings_relinked',         'Mapeamentos revinculados'],
			['galleries_recovered',       'Galerias recuperadas'],
			['incomplete_galleries',      'Galerias incompletas (íncice)'],
			['remaining_broken',          'Ainda quebradas'],
			['marked',                'Marcados'],
			['orphan_images',         'Imagens órfãs'],
			['orphan_galleries',      'Galerias órfãs'],
			['empty_dest_galleries',  'Galerias destino vazias'],
			['remaining_broken',      'Ainda quebrados'],
		];

		let statsHtml = '';
		statKeys.forEach( function ( pair ) {
			const key = pair[0], label = pair[1];
			if ( key in result && result[ key ] !== undefined ) {
				statsHtml += '<span style="display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border:1px solid var(--mlgp-line);border-radius:999px;background:#fff;font-size:12px;color:var(--mlgp-muted);"><strong style="color:var(--mlgp-ink);">' + fmt( safeNum( result[ key ] ) ) + '</strong>' + esc( label ) + '</span>';
			}
		} );

		// Extended block for revalidate_integrity (nested format).
		let integrityHtml = '';
		if ( result.source_snapshot || result.dest_snapshot || result.reconciliation ) {
			const ss  = result.source_snapshot  || {};
			const ds  = result.dest_snapshot    || {};
			const rec = result.reconciliation   || {};
			const lbl = result.integrity_label  || result.integrity_status || '';
			const isOk = result.integrity_status === 'ok';
			const pill = isOk
				? '<span style="padding:2px 8px;border-radius:999px;background:rgba(21,153,71,.12);color:#0a7a38;font-size:11px;font-weight:700;">' + esc( lbl ) + '</span>'
				: '<span style="padding:2px 8px;border-radius:999px;background:rgba(217,45,32,.10);color:var(--mlgp-danger);font-size:11px;font-weight:700;">' + esc( lbl ) + '</span>';

			const row = function( label, val ) {
				// val is already safe — coerce to string.
				const display = ( typeof val === 'number' ) ? fmt( val ) : String( val );
				return '<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid var(--mlgp-line);font-size:12px;">'
					+ '<span style="color:var(--mlgp-muted);">' + esc( label ) + '</span>'
					+ '<strong style="color:var(--mlgp-ink);">' + display + '</strong>'
					+ '</div>';
			};

			const galSrc = ss.gallery || {};
			const imgSrc = ss.image   || {};
			const albSrc = ss.album   || {};

			// Coverage — safeNum prevents NaN.
			const imgCov = safeNum( rec.image_coverage  );
			const galCov = safeNum( rec.gallery_cov_pct );
			const albCov = safeNum( rec.album_cov_pct   );

			integrityHtml =
				'<div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
				'<div style="padding:12px 14px;border:1px solid var(--mlgp-line);border-radius:10px;background:var(--mlgp-surface-soft);">' +
				'<strong style="font-size:12px;color:var(--mlgp-ink);display:block;margin-bottom:8px;">Source Snapshot</strong>' +
				row( 'Galerias mapeadas', safeNum( galSrc.total  ) ) +
				row( 'Galerias OK',       safeNum( galSrc.done   ) ) +
				row( '\u00c1lbuns mapeados',    safeNum( albSrc.total  ) ) +
				row( 'Imagens mapeadas',   safeNum( imgSrc.total  ) ) +
				row( 'Imagens OK',         safeNum( imgSrc.done   ) ) +
				row( 'Imagens com erro',   safeNum( imgSrc.broken ) ) +
				'</div>' +
				'<div style="padding:12px 14px;border:1px solid var(--mlgp-line);border-radius:10px;background:var(--mlgp-surface-soft);">' +
				'<strong style="font-size:12px;color:var(--mlgp-ink);display:block;margin-bottom:8px;">Destination Snapshot</strong>' +
				row( 'Galerias destino',   safeNum( ds.galleries     ) ) +
				row( '\u00c1lbuns destino',     safeNum( ds.albums        ) ) +
				row( 'Itens destino',      safeNum( ds.items         ) ) +
				row( 'Links de \u00e1lbum',    safeNum( ds.album_links   ) ) +
				row( 'Arquivos ausentes',  safeNum( ds.missing_files ) ) +
				'<strong style="font-size:12px;color:var(--mlgp-ink);display:block;margin-top:10px;margin-bottom:8px;">Reconcilia\u00e7\u00e3o</strong>' +
				row( 'Cobertura imagens',  imgCov.toFixed(1) + '%' ) +
				row( 'Cobertura galerias', galCov.toFixed(1) + '%' ) +
				row( 'Cobertura \u00e1lbuns',  albCov.toFixed(1) + '%' ) +
				'<div style="margin-top:8px;">' + pill + '</div>' +
				'</div>' +
				'</div>';

			// Extended audit block (revalidate_integrity).
			const audit = result.audit || {};
			const emptyGals    = safeNum( audit.empty_dest_galleries_with_src_images );
			const missingLinks = safeNum( audit.albums_with_missing_links );
			const missingDB    = safeNum( audit.mapped_images_missing_db_record );
			const missingF     = safeNum( audit.mapped_images_missing_files );
			if ( emptyGals > 0 || missingLinks > 0 || missingDB > 0 || missingF > 0 ) {
				integrityHtml += '<div style="margin-top:12px;padding:12px 14px;border:1px solid rgba(217,100,32,.35);border-radius:10px;background:rgba(217,100,32,.06);">' +
					'<strong style="font-size:12px;color:var(--mlgp-ink);display:block;margin-bottom:8px;">Problemas Detectados</strong>' +
					( emptyGals    > 0 ? row( 'Galerias destino vazias c/ imagens na fonte', emptyGals    ) : '' ) +
					( missingLinks > 0 ? row( '\u00c1lbuns sem links no destino',           missingLinks ) : '' ) +
					( missingDB    > 0 ? row( 'Imagens mapeadas sem registro no DB destino', missingDB    ) : '' ) +
					( missingF     > 0 ? row( 'Imagens mapeadas com arquivo ausente',        missingF     ) : '' ) +
					'</div>';
			}
		}

		let errHtml = '';
		if ( result.errors && result.errors.length > 0 ) {
			errHtml = '<div style="margin-top:10px;font-size:12px;color:var(--mlgp-danger);"><strong>Erros:</strong> ' + result.errors.map( esc ).join( ' \u00b7 ' ) + '</div>';
		}

		resultEl.innerHTML =
			'<div style="margin-bottom:12px;padding:14px 16px;border-radius:12px;background:' + bg + ';border:1px solid ' + bd + ';">' +
			'<strong style="font-size:13px;color:' + col + ';">' + icon + ' ' + esc( result.message || 'Opera\u00e7\u00e3o conclu\u00edda' ) + '</strong>' +
			( statsHtml ? '<div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:6px;">' + statsHtml + '</div>' : '' ) +
			integrityHtml +
			errHtml +
			'</div>';
	}

	function appendRepairLog( opLabel, result ) {
		const logEl = $( 'mlgmp-repair-log' );
		if ( ! logEl ) return;

		const now  = new Date().toLocaleTimeString( 'pt-BR' );
		const ok   = result.status === 'success';
		const lvl  = ok ? 'info' : 'error';
		const msg  = esc( result.message || opLabel );

		const entry = document.createElement( 'div' );
		entry.className = 'mlgmp-log__entry mlgmp-log__entry--' + lvl;
		entry.innerHTML =
			'<span class="mlgmp-log__time">' + now + '</span>' +
			'<span class="mlgmp-log__badge mlgmp-badge--' + lvl + '">' + ( ok ? 'INFO' : 'ERRO' ) + '</span>' +
			'<span class="mlgmp-log__stage">[reparo]</span>' +
			'<span class="mlgmp-log__msg">' + msg + '</span>';

		// Remove placeholder if present.
		const placeholder = logEl.querySelector( '.mlgmp-log__entry:only-child' );
		if ( placeholder ) logEl.innerHTML = '';

		logEl.appendChild( entry );

		// Auto-scroll.
		const wrap = $( 'mlgmp-repair-log-list' );
		if ( wrap ) wrap.scrollTop = wrap.scrollHeight;
	}

	// ── Boot ─────────────────────────────────────────────────────────────────
	if ( document.readyState === 'loading' ) {
		// -----------------------------------------------------------------------
	// Batched AJAX cleanup — one step per request, no gateway timeout
	// -----------------------------------------------------------------------

	var _cleanupBusy   = false;
	var _cleanupTotals = { options: 0, usermeta: 0, cron: 0, tables: 0, dirs: 0 };

	function runBatchedCleanup( allowDirs ) {
		if ( _cleanupBusy ) return;
		_cleanupBusy   = true;
		_cleanupTotals = { options: 0, usermeta: 0, cron: 0, tables: 0, dirs: 0 };

		const prog      = document.getElementById( 'mlgmp-cleanup-progress' );
		const progLabel = document.getElementById( 'mlgmp-cleanup-prog-label' );

		if ( prog )      prog.style.display = 'block';
		if ( cleanupButton ) cleanupButton.disabled = true;

		const steps = [ 'options', 'usermeta', 'cron', 'tables' ];
		if ( allowDirs ) steps.push( 'dirs' );

		function updateUI() {
			const map = { options: 'mlgmp-cprog-options', usermeta: 'mlgmp-cprog-usermeta', cron: 'mlgmp-cprog-cron', tables: 'mlgmp-cprog-tables', dirs: 'mlgmp-cprog-dirs' };
			Object.keys( map ).forEach( function ( k ) {
				const el = document.getElementById( map[ k ] );
				if ( el ) el.textContent = _cleanupTotals[ k ];
			} );
		}

		function finish( success, message ) {
			_cleanupBusy = false;
			if ( cleanupButton ) cleanupButton.disabled = false;
			if ( progLabel ) progLabel.textContent = message;
			if ( cleanupMsg ) {
				cleanupMsg.className = success ? 'mlgmp-cleanup-card__hint' : 'mlgmp-cleanup-card__alert';
				cleanupMsg.textContent = message;
			}
			// Refresh scan badges after cleanup.
			if ( success ) {
				setTimeout( function () {
					const p = new FormData();
					p.append( 'action', 'mlgmp_cleanup_scan' );
					p.append( 'nonce', MLGMP.nonce );
					fetch( MLGMP.ajax_url, { method: 'POST', body: p } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( d ) {
							if ( ! d.success || ! d.data.scan ) return;
							const s   = d.data.scan;
							const ids = { tables_count: 'mlgmp-cleanup-tables', options_count: 'mlgmp-cleanup-options', usermeta_count: 'mlgmp-cleanup-usermeta', cron_count: 'mlgmp-cleanup-cron', dirs_count: 'mlgmp-cleanup-dirs' };
							Object.keys( ids ).forEach( function ( k ) {
								const el = document.getElementById( ids[ k ] );
								if ( el ) el.textContent = String( s[ k ] || 0 );
							} );
						} )
						.catch( function () {} );
				}, 600 );
			}
		}

		function runStep( stepIdx, offset ) {
			if ( stepIdx >= steps.length ) {
				var summary = 'Limpeza concluída — options: ' + _cleanupTotals.options +
					', usermeta: ' + _cleanupTotals.usermeta +
					', cron: ' + _cleanupTotals.cron +
					', tabelas: ' + _cleanupTotals.tables +
					', pastas: ' + _cleanupTotals.dirs + '.';
				finish( true, summary );
				return;
			}

			var step = steps[ stepIdx ];
			if ( progLabel ) progLabel.textContent = 'Limpando ' + step + '…';

			var params = new FormData();
			params.append( 'action',     'mlgmp_cleanup_batch' );
			params.append( 'nonce',      MLGMP.nonce );
			params.append( 'step',       step );
			params.append( 'allow_dirs', allowDirs ? '1' : '0' );
			params.append( 'batch',      '100' );
			params.append( 'offset',     String( offset ) );

			fetch( MLGMP.ajax_url, { method: 'POST', body: params } )
				.then( function ( r ) {
					return r.text().then( function ( txt ) {
						try { return JSON.parse( txt ); }
						catch ( _ ) { throw new Error( 'Resposta inválida: ' + txt.substring( 0, 200 ) ); }
					} );
				} )
				.then( function ( data ) {
					if ( ! data.success ) {
						var msg = ( data.data && data.data.message ) ? data.data.message : JSON.stringify( data.data );
						finish( false, 'Erro: ' + msg );
						return;
					}
					var r = data.data;
					_cleanupTotals[ step ] = ( _cleanupTotals[ step ] || 0 ) + ( r.removed || 0 );
					updateUI();
					if ( r.done ) {
						setTimeout( function () { runStep( stepIdx + 1, 0 ); }, 150 );
					} else {
						setTimeout( function () { runStep( stepIdx, offset + 100 ); }, 150 );
					}
				} )
				.catch( function ( err ) {
					finish( false, 'Erro de rede: ' + err.message );
				} );
		}

		updateUI();
		runStep( 0, 0 );
	}

		document.addEventListener( 'DOMContentLoaded', function () {
			init();
			initConfigTab();
			initRepairTab();
		} );
	} else {
		init();
		initConfigTab();
		initRepairTab();
	}

} )();
