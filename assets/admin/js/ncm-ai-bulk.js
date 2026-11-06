/**
 * Bulk action "Fill NCM with AI" of the products list: before the form goes, asks the plugin how many of the selected
 * products lack the NCM and confirms, saying the AI of the organization answers on the key of the store.
 *
 * @package Infixs\SquidCore
 * @since   1.7.0
 */
( function () {
	'use strict';

	var config = window.infixsSquidCoreNcmAiBulk || {};
	var form = document.getElementById( 'posts-filter' );

	if ( ! form || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	var i18n = config.i18n || {};
	var confirmed = false;
	var checking = false;

	/**
	 * The bulk action asked for: the one of the button clicked, or the top one unless only the bottom one is set.
	 */
	function chosenAction( submitter ) {
		var top = form.querySelector( 'select[name="action"]' );
		var bottom = form.querySelector( 'select[name="action2"]' );

		if ( submitter && submitter.id === 'doaction2' ) {
			return bottom ? bottom.value : '';
		}

		if ( top && top.value !== '-1' ) {
			return top.value;
		}

		return bottom ? bottom.value : '';
	}

	function selectedIds() {
		return Array.prototype.map
			.call( form.querySelectorAll( 'input[name="post[]"]:checked' ), function ( input ) {
				return parseInt( input.value, 10 );
			} )
			.filter( function ( id ) {
				return id > 0;
			} );
	}

	form.addEventListener( 'submit', function ( event ) {
		if ( confirmed || chosenAction( event.submitter ) !== config.action ) {
			return;
		}

		event.preventDefault();

		var ids = selectedIds();
		var submitter = event.submitter;

		if ( ! ids.length ) {
			window.alert( i18n.noSelection || '' );
			return;
		}

		if ( checking ) {
			return;
		}

		checking = true;

		window.wp
			.apiFetch( {
				path: config.previewPath,
				method: 'POST',
				data: { wcProductIds: ids },
			} )
			.then( function ( response ) {
				var targets = response && response.data ? parseInt( response.data.targets, 10 ) || 0 : 0;

				if ( targets <= 0 ) {
					window.alert( i18n.none || '' );
					return;
				}

				var text = ( targets === 1 ? i18n.confirmOne : i18n.confirmMany ) || '';

				if ( ! window.confirm( text.replace( '%s', targets.toLocaleString() ) ) ) {
					return;
				}

				confirmed = true;

				if ( form.requestSubmit && submitter ) {
					form.requestSubmit( submitter );
				} else {
					form.submit();
				}
			} )
			.catch( function ( error ) {
				window.alert( ( error && error.message ) || i18n.error || '' );
			} )
			.then( function () {
				checking = false;
			} );
	} );
} )();
