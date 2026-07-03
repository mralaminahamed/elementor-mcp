/* global emcpToolsModules */
( function () {
	'use strict';
	var cfg = window.emcpToolsModules;
	if ( ! cfg ) { return; }

	var optimizeBtn = document.getElementById( 'emcp-bulk-optimize' );
	var restoreBtn  = document.getElementById( 'emcp-bulk-restore' );
	var progress    = document.querySelector( '.emcp-bulk-progress' );
	var bar         = document.querySelector( '.emcp-bulk-bar span' );
	var status      = document.querySelector( '.emcp-bulk-status' );

	function post( action, extra ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );
		if ( extra ) {
			Object.keys( extra ).forEach( function ( k ) { body.set( k, extra[ k ] ); } );
		}
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) { return r.json(); } );
	}

	function setBar( pct ) {
		if ( bar ) { bar.style.width = pct + '%'; }
	}

	function runBatch() {
		post( cfg.batchAction, { batch: cfg.batchSize } ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				if ( status ) { status.textContent = ( res && res.data && res.data.message ) || 'Error'; }
				optimizeBtn.disabled = false;
				return;
			}
			var d = res.data;
			setBar( d.percent );
			if ( status ) { status.textContent = d.processed + ' / ' + d.total; }
			if ( d.done ) {
				if ( status ) { status.textContent = cfg.done + ' — ' + d.total; }
				optimizeBtn.disabled = false;
			} else {
				runBatch();
			}
		} ).catch( function () {
			optimizeBtn.disabled = false;
		} );
	}

	if ( optimizeBtn ) {
		optimizeBtn.addEventListener( 'click', function () {
			optimizeBtn.disabled = true;
			if ( progress ) { progress.hidden = false; }
			if ( status ) { status.textContent = cfg.optimizing; }
			setBar( 0 );
			runBatch();
		} );
	}

	if ( restoreBtn ) {
		restoreBtn.addEventListener( 'click', function () {
			restoreBtn.disabled = true;
			if ( progress ) { progress.hidden = false; }
			if ( status ) { status.textContent = cfg.restoring; }
			setBar( 0 );
			post( cfg.restoreAction ).then( function ( res ) {
				setBar( 100 );
				if ( status ) {
					status.textContent = ( res && res.success )
						? ( cfg.done + ' — ' + res.data.restored )
						: 'Error';
				}
				restoreBtn.disabled = false;
			} ).catch( function () { restoreBtn.disabled = false; } );
		} );
	}
} )();
