/**
 * "Invoice (NF-e)" box of the order edit screen.
 *
 * Each action calls the order invoice REST route and swaps the box content with the HTML it returns. The page is
 * never reloaded, so unsaved changes of the order are kept.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
( function () {
	'use strict';

	var config = window.infixsSquidCoreOrderInvoice || {};
	var root = document.querySelector( '[data-isq-invoice]' );

	if ( ! root || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	var orderId = root.getAttribute( 'data-order-id' );
	var minLength = parseInt( config.minLength, 10 ) || 15;
	var maxLength = parseInt( config.maxLength, 10 ) || 255;
	var i18n = config.i18n || {};

	/**
	 * Characters as the server counts them (code points), ignoring spaces around.
	 */
	function length( text ) {
		return Array.from( text.trim() ).length;
	}

	function setBusy( busy ) {
		root.classList.toggle( 'is-busy', busy );
		root.setAttribute( 'aria-busy', busy ? 'true' : 'false' );

		root.querySelectorAll( 'button' ).forEach( function ( button ) {
			if ( busy ) {
				button.setAttribute( 'data-isq-was-disabled', button.disabled ? '1' : '0' );
				button.disabled = true;
				return;
			}

			button.disabled = button.getAttribute( 'data-isq-was-disabled' ) === '1';
			button.removeAttribute( 'data-isq-was-disabled' );
		} );

		var spinner = root.querySelector( '.isq-invoice__spinner' );

		if ( spinner ) {
			spinner.classList.toggle( 'is-active', busy );
		}
	}

	function showError( message ) {
		var current = root.querySelector( '.isq-invoice__error' );

		if ( current ) {
			current.remove();
		}

		var notice = document.createElement( 'div' );
		notice.className = 'notice notice-error inline isq-invoice__error';
		notice.setAttribute( 'role', 'alert' );

		var paragraph = document.createElement( 'p' );
		paragraph.textContent = message || i18n.error || '';
		notice.appendChild( paragraph );

		root.insertBefore( notice, root.firstChild );
	}

	function run( action, data ) {
		setBusy( true );

		window.wp
			.apiFetch( {
				path: config.restPath + encodeURIComponent( orderId ) + '/invoice/' + action,
				method: 'POST',
				data: data || {},
			} )
			.then( function ( response ) {
				if ( response && typeof response.html === 'string' ) {
					root.innerHTML = response.html;
				}

				root.classList.remove( 'is-busy' );
				root.setAttribute( 'aria-busy', 'false' );
			} )
			.catch( function ( error ) {
				setBusy( false );
				showError( error && error.message );
			} );
	}

	function cancelPanel() {
		return root.querySelector( '[data-isq-cancel]' );
	}

	function toggleCancel( open ) {
		var panel = cancelPanel();
		var opener = root.querySelector( '[data-isq-action="cancel-open"]' );

		if ( ! panel ) {
			return;
		}

		panel.hidden = ! open;

		if ( opener ) {
			opener.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}

		if ( open ) {
			panel.querySelector( '[data-isq-justification]' ).focus();
		}
	}

	function updateCounter( textarea ) {
		var size = length( textarea.value );
		var counter = root.querySelector( '[data-isq-counter]' );
		var submit = root.querySelector( '[data-isq-action="cancel"]' );

		if ( counter ) {
			counter.textContent = String( size );
		}

		if ( submit ) {
			submit.disabled = size < minLength || size > maxLength;
		}
	}

	root.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-isq-action]' );

		if ( ! button || ! root.contains( button ) || button.disabled ) {
			return;
		}

		event.preventDefault();

		var action = button.getAttribute( 'data-isq-action' );
		var confirmText = button.getAttribute( 'data-confirm' );

		if ( confirmText && ! window.confirm( confirmText ) ) {
			return;
		}

		switch ( action ) {
			case 'cancel-open':
				toggleCancel( true );
				return;

			case 'cancel-close':
				toggleCancel( false );
				return;

			case 'cancel':
				var textarea = root.querySelector( '[data-isq-justification]' );
				var justification = textarea ? textarea.value.trim() : '';

				if ( length( justification ) < minLength || length( justification ) > maxLength ) {
					return;
				}

				run( 'cancel', { justificativa: justification } );
				return;

			case 'emit':
			case 'refresh':
			case 'fill-ncm':
				run( action );
		}
	} );

	root.addEventListener( 'input', function ( event ) {
		if ( event.target.matches( '[data-isq-justification]' ) ) {
			updateCounter( event.target );
		}
	} );
} )();
