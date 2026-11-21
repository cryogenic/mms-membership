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
	} );
} )( jQuery );
