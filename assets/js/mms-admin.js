/* global jQuery */
( function ( $ ) {
	'use strict';

	function reindex( $table, group ) {
		var re = new RegExp( group + '\\[\\d+\\]', 'g' );

		$table.find( 'tbody tr' ).each( function ( i ) {
			var $row = $( this );
			$row.find( '.mms-row-no' ).text( i + 1 );
			$row.find( 'input, select, textarea' ).each( function () {
				var name = $( this ).attr( 'name' );
				if ( ! name ) {
					return;
				}
				$( this ).attr( 'name', name.replace( re, group + '[' + i + ']' ) );
			} );
		} );
	}

	$( function () {
		$( '.mms-settings-form' ).on( 'click', '[data-mms-add]', function () {
			var $button = $( this );
			var tableId = $button.data( 'mms-add' );
			var templateId = $button.data( 'mms-template' );
			var $table = $( '#' + tableId );
			var $template = $( '#' + templateId );

			if ( ! $table.length || ! $template.length ) {
				return;
			}

			var group = tableId === 'mms-types-table' ? 'types' : 'docs';
			var html = $template.html().replace( /__i__/g, $table.find( 'tbody tr' ).length );
			$table.find( 'tbody' ).append( html );
			reindex( $table, group );
			$table.find( 'tbody tr:last-child .mms-input-slug' ).trigger( 'focus' );
		} );

		$( '.mms-settings-form' ).on( 'click', '.mms-remove-row', function () {
			var $table = $( this ).closest( 'table.mms-repeat' );
			$( this ).closest( 'tr' ).remove();
			reindex( $table, $table.attr( 'id' ) === 'mms-types-table' ? 'types' : 'docs' );
		} );

		var $bulkRows = $( '.mms-check-row' );

		if ( $bulkRows.length ) {
			var $bulkAll = $( '#mms-check-all' );
			var $bulkApply = $( '.mms-bulk-apply' );
			var $bulkCount = $( '.mms-bulk-count' );

			var updateBulkState = function () {
				var selected = $bulkRows.filter( ':checked' ).length;
				$bulkCount.text( selected + ' selected' );
				$bulkApply.prop( 'disabled', 0 === selected );
				$bulkAll.prop( 'checked', selected > 0 && selected === $bulkRows.length );
			};

			$bulkAll.on( 'change', function () {
				$bulkRows.prop( 'checked', this.checked );
				updateBulkState();
			} );

			$bulkRows.on( 'change', updateBulkState );
			updateBulkState();
		}

		var $settingsForm = $( '.mms-settings-form' );
		var $tabs = $settingsForm.find( '.mms-tab' );

		if ( $tabs.length ) {
			var $panels = $settingsForm.find( '.mms-tab-panel' );
			var storageKey = 'mmsSettingsTab';

			var activateTab = function ( key, persist ) {
				var $tab = $tabs.filter( '[data-mms-tab="' + key + '"]' );

				if ( ! $tab.length ) {
					return;
				}

				$tabs.removeClass( 'is-active' ).attr( { 'aria-selected': 'false', tabindex: '-1' } );
				$tab.addClass( 'is-active' ).attr( { 'aria-selected': 'true', tabindex: '0' } );
				$panels.removeClass( 'is-active' );
				$( '#' + $tab.attr( 'aria-controls' ) ).addClass( 'is-active' );

				if ( persist ) {
					try {
						window.localStorage.setItem( storageKey, key );
					} catch ( e ) {}

					if ( window.history && window.history.replaceState ) {
						window.history.replaceState( null, '', '#' + $tab.attr( 'aria-controls' ) );
					}
				}

				window.setTimeout( function () {
					window.dispatchEvent( new Event( 'resize' ) );

					if ( window.tinymce && window.tinymce.editors ) {
						$.each( window.tinymce.editors, function ( i, editor ) {
							try {
								if ( editor.iframeElement && editor.iframeElement.offsetParent !== null ) {
									editor.fire( 'ResizeEditor' );
								}
							} catch ( e ) {}
						} );
					}
				}, 50 );
			};

			$tabs.on( 'click', function () {
				activateTab( $( this ).data( 'mms-tab' ), true );
			} );

			$tabs.on( 'keydown', function ( event ) {
				var index = $tabs.index( this );
				var next = null;

				if ( 37 === event.which ) {
					next = ( index - 1 + $tabs.length ) % $tabs.length;
				} else if ( 39 === event.which ) {
					next = ( index + 1 ) % $tabs.length;
				} else if ( 36 === event.which ) {
					next = 0;
				} else if ( 35 === event.which ) {
					next = $tabs.length - 1;
				}

				if ( null === next ) {
					return;
				}

				event.preventDefault();
				$tabs.eq( next ).trigger( 'focus' );
				activateTab( $tabs.eq( next ).data( 'mms-tab' ), true );
			} );

			var initialKey = '';
			var hash = window.location.hash.replace( '#', '' );

			if ( hash ) {
				var $hashTab = $tabs.filter( function () {
					return $( this ).attr( 'aria-controls' ) === hash;
				} );

				if ( $hashTab.length ) {
					initialKey = $hashTab.data( 'mms-tab' );
				}
			}

			if ( ! initialKey ) {
				try {
					initialKey = window.localStorage.getItem( storageKey ) || '';
				} catch ( e ) {}
			}

			if ( ! initialKey || ! $tabs.filter( '[data-mms-tab="' + initialKey + '"]' ).length ) {
				initialKey = $tabs.filter( '.is-active' ).data( 'mms-tab' ) || $tabs.first().data( 'mms-tab' );
			}

			$settingsForm.addClass( 'mms-tabs-ready' );
			activateTab( initialKey, false );
		}

		var $companyForm = $( '.mms-company-form' );

		if ( $companyForm.length && window.mmsAdmin ) {
			var $accountToggle = $( '#mms-create-account' );
			var $accountFields = $companyForm.find( '.mms-account-fields' );
			var $username = $( '#mms-account-username' );
			var $usernameStatus = $( '#mms-username-status' );
			var $password = $( '#mms-account-password' );
			var $email = $( '#mms-pic-email' );
			var usernameTimer = null;
			var usernameTouched = '' !== $.trim( $username.val() );
			var lastQuery = '';

			var defaultUsernameHint = function () {
				return $username.attr( 'data-mms-default-hint' ) || '';
			};

			var setUsernameStatus = function ( state, text ) {
				$usernameStatus.removeClass( 'is-ok is-bad is-checking' ).addClass( state ? 'is-' + state : '' ).text( text );
			};

			var checkUsername = function () {
				var value = $.trim( $username.val() );

				if ( ! value ) {
					setUsernameStatus( '', defaultUsernameHint() );
					return;
				}

				if ( value === lastQuery ) {
					return;
				}

				lastQuery = value;
				setUsernameStatus( 'checking', window.mmsAdmin.checkingText );

				$.post( window.mmsAdmin.ajaxUrl, {
					action: 'mms_check_username',
					nonce: window.mmsAdmin.usernameNonce,
					username: value
				} ).done( function ( response ) {
					if ( $.trim( $username.val() ) !== value ) {
						return;
					}

					if ( ! response || ! response.success || ! response.data ) {
						setUsernameStatus( 'bad', window.mmsAdmin.errorText );
						return;
					}

					$username
						.attr( 'data-mms-available', response.data.available ? '1' : '0' )
						.attr( 'data-mms-checked', response.data.username );

					setUsernameStatus( response.data.available ? 'ok' : 'bad', response.data.message );
				} ).fail( function () {
					setUsernameStatus( 'bad', window.mmsAdmin.errorText );
				} );
			};

			var suggestUsername = function () {
				var value = $.trim( $email.val() ).toLowerCase();
				var at = value.indexOf( '@' );

				if ( at < 1 ) {
					return '';
				}

				return value
					.slice( 0, at )
					.replace( /[^a-z0-9._-]/g, '' )
					.replace( /^[._-]+|[._-]+$/g, '' )
					.substring( 0, 60 );
			};

			var toggleAccountFields = function () {
				var enabled = $accountToggle.is( ':checked' );

				$accountFields.toggleClass( 'is-disabled', ! enabled );
				$accountFields.find( 'input' ).prop( 'disabled', ! enabled );
			};

			$username.on( 'input', function () {
				usernameTouched = true;
				lastQuery = '';

				$username.removeAttr( 'data-mms-available data-mms-checked' );
				window.clearTimeout( usernameTimer );

				if ( ! $.trim( $username.val() ) ) {
					setUsernameStatus( '', defaultUsernameHint() );
					return;
				}

				setUsernameStatus( 'checking', window.mmsAdmin.checkingText );
				usernameTimer = window.setTimeout( checkUsername, 400 );
			} );

			$email.on( 'input', function () {
				if ( usernameTouched || $.trim( $username.val() ) ) {
					return;
				}

				var suggestion = suggestUsername();

				if ( suggestion ) {
					$username.val( suggestion );
					lastQuery = '';
					checkUsername();
				}
			} );

			$( '#mms-generate-password' ).on( 'click', function () {
				var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
				var result = '';

				if ( window.crypto && window.crypto.getRandomValues ) {
					var values = new Uint32Array( 16 );
					window.crypto.getRandomValues( values );

					$.each( values, function ( i, value ) {
						result += chars.charAt( value % chars.length );
					} );
				} else {
					for ( var i = 0; i < 16; i++ ) {
						result += chars.charAt( Math.floor( Math.random() * chars.length ) );
					}
				}

				$password.val( result ).trigger( 'focus' );
			} );

			$accountToggle.on( 'change', toggleAccountFields );
			toggleAccountFields();

			if ( $.trim( $username.val() ) ) {
				checkUsername();
			}

			$companyForm.on( 'submit', function ( event ) {
				if ( ! $accountToggle.is( ':checked' ) ) {
					return;
				}

				if ( '0' === $username.attr( 'data-mms-available' ) ) {
					event.preventDefault();
					$username.trigger( 'focus' );
				}
			} );
		}
	} );
} )( jQuery );
