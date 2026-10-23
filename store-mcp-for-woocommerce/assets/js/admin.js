/* global wcmcpAdmin */
( function () {
	'use strict';

	var settings = window.wcmcpAdmin || { i18n: {} };
	var i18n = settings.i18n;

	function copyText( text, button ) {
		var done = function () {
			var original = button.textContent;
			button.textContent = i18n.copied;
			window.setTimeout( function () {
				button.textContent = original;
			}, 1500 );
		};

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done );
			return;
		}

		var scratch = document.createElement( 'textarea' );
		scratch.value = text;
		scratch.setAttribute( 'readonly', '' );
		scratch.style.position = 'fixed';
		scratch.style.opacity = '0';
		document.body.appendChild( scratch );
		scratch.select();
		document.execCommand( 'copy' );
		document.body.removeChild( scratch );
		done();
	}

	function renderResults( list, checks ) {
		list.textContent = '';
		checks.forEach( function ( check ) {
			var item = document.createElement( 'li' );
			item.className = 'wcmcp-result wcmcp-result-' + check.status;

			var badge = document.createElement( 'span' );
			badge.className = 'wcmcp-badge';
			badge.textContent = i18n[ check.status ] || check.status;

			var label = document.createElement( 'strong' );
			label.textContent = check.label;

			var message = document.createElement( 'span' );
			message.className = 'wcmcp-result-message';
			message.textContent = check.message;

			item.appendChild( badge );
			item.appendChild( label );
			item.appendChild( message );
			list.appendChild( item );
		} );
	}

	function runTest( button ) {
		var list = document.getElementById( 'wcmcp-test-results' );
		var body = new window.FormData();
		body.append( 'action', 'wcmcp_test_connection' );
		body.append( 'nonce', settings.nonce );
		body.append( 'consumer_key', document.getElementById( 'wcmcp_test_ck' ).value.trim() );
		body.append( 'consumer_secret', document.getElementById( 'wcmcp_test_cs' ).value.trim() );

		button.disabled = true;
		button.textContent = i18n.testing;
		list.textContent = '';

		window.fetch( settings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( 'failed' );
				}
				renderResults( list, json.data.checks );
			} )
			.catch( function () {
				renderResults( list, [ { status: 'fail', label: i18n.test, message: i18n.failed } ] );
			} )
			.then( function () {
				button.disabled = false;
				button.textContent = i18n.test;
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var copy = event.target.closest( '.wcmcp-copy' );
		if ( copy ) {
			var target = document.getElementById( copy.getAttribute( 'data-copy-target' ) );
			if ( target ) {
				copyText( 'value' in target && target.tagName !== 'CODE' ? target.value : target.textContent, copy );
			}
			return;
		}

		if ( event.target.closest( '.wcmcp-revoke' ) && ! window.confirm( i18n.confirmRevoke ) ) {
			event.preventDefault();
			return;
		}

		if ( event.target.closest( '.wcmcp-clear-logs' ) && ! window.confirm( i18n.confirmClear ) ) {
			event.preventDefault();
			return;
		}

		var test = event.target.closest( '#wcmcp-test-button' );
		if ( test ) {
			runTest( test );
		}
	} );

	// The section sits inside WooCommerce's settings form, so Enter would press the first submit
	// button on the page (possibly a Revoke button). Route it to the right action instead.
	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key !== 'Enter' || event.target.tagName !== 'INPUT' ) {
			return;
		}

		// On the Logs section, Enter in a setting must save, not reach the Clear logs button first.
		if ( document.querySelector( '.wcmcp-logs' ) ) {
			var save = document.querySelector( '#mainform button[name="save"]' );
			if ( save ) {
				event.preventDefault();
				save.click();
			}
			return;
		}

		if ( ! event.target.closest( '.wcmcp-connection' ) ) {
			return;
		}
		event.preventDefault();

		if ( event.target.id === 'wcmcp_key_label' ) {
			document.getElementById( 'wcmcp-create-button' ).click();
		} else if ( event.target.id === 'wcmcp_test_ck' || event.target.id === 'wcmcp_test_cs' ) {
			document.getElementById( 'wcmcp-test-button' ).click();
		}
	} );
}() );
