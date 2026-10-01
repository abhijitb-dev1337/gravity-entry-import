/**
 * Drives the batched import from the progress screen.
 *
 * @package GEI
 */

( function () {
	'use strict';

	/**
	 * Shows the sample value for whichever column each mapping row selects.
	 *
	 * @return {void}
	 */
	function bindSamples() {
		var sample = window.geiSample || {};
		var selects = document.querySelectorAll( '.gei-map-select' );

		if ( ! selects.length ) {
			return;
		}

		Array.prototype.forEach.call( selects, function ( select ) {
			var target = select.getAttribute( 'data-target' );
			var cell = document.querySelector( '.gei-sample[data-target="' + CSS.escape( target ) + '"]' );

			if ( ! cell ) {
				return;
			}

			var update = function () {
				var value = select.value;
				cell.textContent = ( '' !== value && undefined !== sample[ value ] ) ? sample[ value ] : '';
			};

			select.addEventListener( 'change', update );
			update();
		} );
	}

	/**
	 * Runs the import, one batch per request, until the server reports done.
	 *
	 * @return {void}
	 */
	function bindRunner() {
		var runner = document.getElementById( 'gei-runner' );

		if ( ! runner ) {
			return;
		}

		var button = document.getElementById( 'gei-start' );
		var bar = runner.querySelector( '.gei-progress-bar' );
		var status = runner.querySelector( '.gei-status' );
		var errorList = runner.querySelector( '.gei-errors' );
		var failedDownload = runner.querySelector( '.gei-failed-download' );
		var jobId = runner.getAttribute( 'data-job' );
		var running = false;

		/**
		 * Renders the latest progress payload.
		 *
		 * @param {Object} data Progress data from the server.
		 * @return {void}
		 */
		function render( data ) {
			bar.style.width = data.percent + '%';

			status.textContent = gei.i18n.importing + ' ' +
				data.processed + ' / ' + data.total +
				' — ' + data.imported + ' imported, ' +
				data.updated + ' updated, ' +
				data.skipped + ' skipped, ' +
				data.filtered + ' filtered, ' +
				data.failed + ' failed';

			if ( data.errors && data.errors.length ) {
				errorList.innerHTML = '';
				data.errors.forEach( function ( err ) {
					var li = document.createElement( 'li' );
					li.textContent = 'Row ' + err.row + ': ' + err.message;
					errorList.appendChild( li );
				} );
			}

			// The download link's href is a real, already-nonce'd URL rendered
			// server-side (see GEI_Admin::render_run_step()); this only ever
			// toggles whether it's shown, so the gated download itself stays a
			// plain PHP request rather than something this script constructs.
			if ( failedDownload ) {
				failedDownload.style.display = data.failed_csv ? 'block' : 'none';
			}
		}

		/**
		 * Requests the next batch.
		 *
		 * @return {void}
		 */
		function step() {
			var body = new FormData();
			body.append( 'action', 'gei_process_batch' );
			body.append( 'nonce', gei.nonce );
			body.append( 'job', jobId );

			fetch( gei.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					if ( ! payload || ! payload.success ) {
						var message = ( payload && payload.data && payload.data.message ) ?
							payload.data.message :
							gei.i18n.failed;

						status.textContent = message;
						button.disabled = false;
						running = false;
						return;
					}

					render( payload.data );

					if ( payload.data.complete ) {
						status.textContent = gei.i18n.done + ' ' +
							payload.data.imported + ' imported, ' +
							payload.data.updated + ' updated, ' +
							payload.data.skipped + ' skipped, ' +
							payload.data.filtered + ' filtered, ' +
							payload.data.failed + ' failed.';
						button.disabled = false;
						button.style.display = 'none';
						running = false;
						return;
					}

					step();
				} )
				.catch( function () {
					status.textContent = gei.i18n.failed;
					button.disabled = false;
					running = false;
				} );
		}

		button.addEventListener( 'click', function () {
			if ( running ) {
				return;
			}

			if ( ! window.confirm( gei.i18n.confirm ) ) {
				return;
			}

			running = true;
			button.disabled = true;
			errorList.innerHTML = '';
			step();
		} );
	}

	/**
	 * Drives the dry-run validation scan, one batch per request, reloading the
	 * page once the server reports the scan complete.
	 *
	 * Unlike bindRunner(), this starts itself automatically rather than
	 * waiting on a confirmed button click: a validation pass never writes
	 * anything, so there is nothing here that needs the same "are you sure"
	 * gate the real import's Start button has.
	 *
	 * Reloading on completion, rather than rendering the summary table here
	 * too, keeps the summary's markup and counting logic in one place -
	 * GEI_Admin::render_validation_summary(), reading the same job state this
	 * request just finished writing - instead of duplicating it in both PHP
	 * and JS.
	 *
	 * @return {void}
	 */
	function bindValidator() {
		var validator = document.getElementById( 'gei-validator' );

		if ( ! validator ) {
			return;
		}

		var bar = validator.querySelector( '.gei-progress-bar' );
		var status = validator.querySelector( '.gei-status' );
		var jobId = validator.getAttribute( 'data-job' );

		/**
		 * Requests the next validation batch.
		 *
		 * @return {void}
		 */
		function step() {
			var body = new FormData();
			body.append( 'action', 'gei_process_validation_batch' );
			body.append( 'nonce', gei.nonce );
			body.append( 'job', jobId );

			fetch( gei.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					if ( ! payload || ! payload.success ) {
						var message = ( payload && payload.data && payload.data.message ) ?
							payload.data.message :
							gei.i18n.failed;

						status.textContent = message;
						return;
					}

					bar.style.width = payload.data.percent + '%';
					status.textContent = gei.i18n.validating + ' ' +
						payload.data.processed + ' / ' + payload.data.total;

					if ( payload.data.complete ) {
						window.location.reload();
						return;
					}

					step();
				} )
				.catch( function () {
					status.textContent = gei.i18n.failed;
				} );
		}

		step();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			bindSamples();
			bindRunner();
			bindValidator();
		} );
	} else {
		bindSamples();
		bindRunner();
		bindValidator();
	}
}() );
