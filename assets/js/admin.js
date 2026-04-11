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
	const btnAnalyse  = $( 'mlgmp-btn-analyse' );
	const btnStart    = $( 'mlgmp-btn-start' );
	const btnPause    = $( 'mlgmp-btn-pause' );
	const btnResume   = $( 'mlgmp-btn-resume' );
	const btnCancel   = $( 'mlgmp-btn-cancel' );
	const btnReset    = $( 'mlgmp-btn-reset' );
	const btnClearLog = $( 'mlgmp-btn-clear-log' );
	const btnCopyLog  = $( 'mlgmp-btn-copy-log' );

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

	// Analysis grid.
	const analysisGrid = $( 'mlgmp-analysis-grid' );

	// ── Init ─────────────────────────────────────────────────────────────────
	function init() {
		// Read last log id from PHP-rendered HTML.
		if ( lastIdEl ) {
			lastLogId = parseInt( lastIdEl.dataset.id, 10 ) || 0;
		}

		// Bind buttons.
		if ( btnAnalyse )  btnAnalyse.addEventListener(  'click', onAnalyse );
		if ( btnStart )    btnStart.addEventListener(    'click', onStart );
		if ( btnPause )    btnPause.addEventListener(    'click', onPause );
		if ( btnResume )   btnResume.addEventListener(   'click', onResume );
		if ( btnCancel )   btnCancel.addEventListener(   'click', onCancel );
		if ( btnReset )    btnReset.addEventListener(    'click', onReset );
		if ( btnClearLog ) btnClearLog.addEventListener( 'click', onClearLog );
		if ( btnCopyLog )  btnCopyLog.addEventListener(  'click', onCopyLog );

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

	function onAnalyse() {
		setButtonsDisabled( true );
		analysisGrid.innerHTML = '<p class="mlgmp-muted mlgmp-loading">Analisando acervo…</p>';

		ajax( 'mlgmp_analyse' ).then( res => {
			if ( res.success ) {
				renderAnalysis( res.data.analysis );
				applyState( res.data.state );
			} else {
				analysisGrid.innerHTML = '<p class="mlgmp-error">' + esc( res.data?.message || 'Erro.' ) + '</p>';
			}
		} ).catch( err => {
			analysisGrid.innerHTML = '<p class="mlgmp-error">' + esc( String( err ) ) + '</p>';
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

		ajax( 'mlgmp_start', {
			mode,
			batch_size: batchSize,
			stages_enabled: JSON.stringify( stages ),
			duplicate_mode: dupMode
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

		ajax( 'mlgmp_resume', {
			mode,
			batch_size: batchSize,
			stages_enabled: JSON.stringify( stages ),
			duplicate_mode: dupMode
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
				analysisGrid.innerHTML =
					'<p class="mlgmp-muted">Clique em "Analisar" para inspecionar o acervo.</p>';
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
		syncButtonStates();
	}

	function syncButtonStates() {
		const idle      = [ 'idle', 'done', 'cancelled', 'error' ].includes( currentStatus );
		const running   = currentStatus === 'running';
		const paused    = currentStatus === 'paused';
		const ready     = currentStatus === 'ready';
		const inFlight  = running || paused || ready;

		if ( btnAnalyse ) btnAnalyse.disabled = inFlight;
		if ( btnStart )  btnStart.disabled  = ! ( idle || ready );
		if ( btnPause )  btnPause.disabled  = ! running;
		if ( btnResume ) btnResume.disabled = ! paused;
		if ( btnCancel ) btnCancel.disabled = ! inFlight;
		if ( btnReset )  btnReset.disabled  = running;
	}

	function setButtonsDisabled( disabled ) {
		[ btnAnalyse, btnStart, btnPause, btnResume, btnCancel, btnReset ]
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
		for ( const key in c ) {
			n += ( c[ key ].processed || 0 );
		}
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

	function renderAnalysis( analysis ) {
		if ( ! analysisGrid ) return;

		if ( analysis.error ) {
			analysisGrid.innerHTML = '<p class="mlgmp-error">' + esc( analysis.error ) + '</p>';
			return;
		}

		const items = [
			{ key: 'galleries',  icon: '🖼️', label: 'Galerias' },
			{ key: 'albums',     icon: '📁', label: 'Álbuns' },
			{ key: 'images',     icon: '📷', label: 'Imagens' },
			{ key: 'thumbs',     icon: '🔲', label: 'Miniaturas' },
			{ key: 'shortcodes', icon: '🔗', label: 'Shortcodes' },
		];

		analysisGrid.innerHTML = items.map( it => `
			<div class="mlgmp-stat">
				<span class="mlgmp-stat__icon">${ it.icon }</span>
				<span class="mlgmp-stat__num" id="mlgmp-stat-${ it.key }">${ fmt( analysis[ it.key ] || 0 ) }</span>
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

	// ── Boot ─────────────────────────────────────────────────────────────────
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

} )();
