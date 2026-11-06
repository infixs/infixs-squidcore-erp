/**
 * NCM field of the product editor:
 * - "Use this" copies the NCM WebmaniaBR has for the product into the SquidCore field;
 * - "Suggest with AI" asks the AI of SquidCore for the NCM of the product and puts it in the field, with its
 *   description and confidence below. It is offered only while the field is empty, and the answer goes into the field
 *   only if it is still empty: an NCM typed while the AI answered stays, and the suggestion is only told below.
 *   "Use this" waits while the AI is asked.
 * Nothing is saved until the store owner updates the product.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
( function () {
	'use strict';

	var config = window.infixsSquidCoreProductNcm || {};
	var i18n = config.i18n || {};
	var busy = false;

	function field() {
		return document.getElementById( '_infixs_squidcore_ncm' );
	}

	function setValue( input, value ) {
		input.value = value;
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	/**
	 * The AI is offered only for an empty field.
	 */
	function toggleSuggest() {
		var block = document.querySelector( '[data-isq-ncm-ai]' );
		var input = field();

		if ( block && input ) {
			block.style.display = input.value.replace( /\D+/g, '' ) === '' ? '' : 'none';
		}
	}

	function showResult( message, isError ) {
		var line = document.querySelector( '[data-isq-ncm-result]' );
		var text = line ? line.querySelector( '.description' ) : null;

		if ( ! line || ! text ) {
			return;
		}

		text.textContent = message || '';
		text.style.color = isError ? '#d63638' : '';
		line.style.display = message ? '' : 'none';
	}

	function isEmpty( input ) {
		return input.value.replace( /\D+/g, '' ) === '';
	}

	function setBusy( button, value ) {
		var spinner = document.querySelector( '[data-isq-ncm-spinner]' );

		busy = value;
		button.disabled = value;

		Array.prototype.forEach.call( document.querySelectorAll( '[data-isq-use-ncm]' ), function ( useButton ) {
			useButton.disabled = value;
		} );

		if ( spinner ) {
			spinner.classList.toggle( 'is-active', value );
		}
	}

	/**
	 * The suggestion as a line of text, for a field that got an NCM while the AI answered: the AI's, or the NCM
	 * SquidCore already uses on the invoices.
	 */
	function keptMessage( suggestion ) {
		var label = suggestion.description ? suggestion.formatted + ' — ' + suggestion.description : suggestion.formatted;
		var text = suggestion.source === 'squidcore' ? i18n.keptSquidcore : i18n.kept;

		return ( text || '%s' ).replace( '%s', suggestion.source === 'squidcore' ? suggestion.formatted : label );
	}

	function suggest( button ) {
		var input = field();

		if ( busy || ! input || ! window.wp || ! window.wp.apiFetch ) {
			return;
		}

		setBusy( button, true );
		showResult( i18n.asking, false );

		window.wp
			.apiFetch( {
				path: ( config.restPath || '' ) + encodeURIComponent( button.getAttribute( 'data-isq-ncm-suggest' ) ) + '/ncm-suggestion',
				method: 'POST',
				data: {},
			} )
			.then( function ( response ) {
				var suggestion = response && response.data ? response.data : null;

				if ( ! suggestion || ! suggestion.formatted ) {
					throw new Error( i18n.error );
				}

				// Typed (or "Use this") while the AI answered: what the store owner chose stays.
				if ( ! isEmpty( input ) ) {
					showResult( keptMessage( suggestion ), false );
					return;
				}

				setValue( input, suggestion.formatted );
				showResult( suggestion.message, false );
				input.focus();
			} )
			.catch( function ( error ) {
				showResult( ( error && error.message ) || i18n.error, true );
			} )
			.then( function () {
				setBusy( button, false );
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target && event.target.closest ? event.target : null;
		var useButton = target ? target.closest( '[data-isq-use-ncm]' ) : null;
		var suggestButton = target ? target.closest( '[data-isq-ncm-suggest]' ) : null;
		var input = field();

		if ( suggestButton ) {
			event.preventDefault();
			suggest( suggestButton );
			return;
		}

		if ( ! useButton || ! input || busy ) {
			return;
		}

		event.preventDefault();
		setValue( input, useButton.getAttribute( 'data-isq-use-ncm' ) || '' );
		input.focus();
	} );

	document.addEventListener( 'input', function ( event ) {
		if ( event.target && event.target.id === '_infixs_squidcore_ncm' ) {
			toggleSuggest();
		}
	} );
} )();
