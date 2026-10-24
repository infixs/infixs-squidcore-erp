/**
 * NCM field of the product editor: "Use this" copies the NCM WebmaniaBR has for the product into the SquidCore field.
 * Nothing is saved until the store owner updates the product.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '[data-isq-use-ncm]' ) : null;
		var input = document.getElementById( '_infixs_squidcore_ncm' );

		if ( ! button || ! input ) {
			return;
		}

		event.preventDefault();
		input.value = button.getAttribute( 'data-isq-use-ncm' ) || '';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		input.focus();
	} );
} )();
