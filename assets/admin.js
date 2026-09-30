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
				data.skipped + ' skipped, ' +
				data.failed + ' failed';

			if ( data.errors && data.errors.length ) {
				errorList.innerHTML = '';
				data.errors.forEach( function ( err ) {
					var li = document.createElement( 'li' );
					li.textContent = 'Row ' + err.row + ': ' + err.message;
					errorList.appendChild( li );
				} );
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
							payload.data.skipped + ' skipped, ' +
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

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			bindSamples();
			bindRunner();
		} );
	} else {
		bindSamples();
		bindRunner();
	}
}() );
