/**
 * Notices of SquidCore on the WordPress admin screens: the X of a notice dismisses it for good through the REST API of
 * the plugin. WordPress removes the notice from the page by itself.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
( function () {
	'use strict';

	var config = window.infixsSquidCoreNotices || {};

	if ( ! config.restPath || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '.notice-dismiss' ) : null;
		var notice = button ? button.closest( '[data-isq-notice]' ) : null;

		if ( ! notice ) {
			return;
		}

		window.wp.apiFetch( {
			path: config.restPath + encodeURIComponent( notice.getAttribute( 'data-isq-notice' ) ) + '/dismiss',
			method: 'POST',
			data: { mode: 'forever' },
		} ).catch( function () {} );
	} );
} )();
