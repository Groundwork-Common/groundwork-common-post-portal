/**
 * Repeater rows: clone the template, renumber, remove.
 *
 * Hand-written ES5 against the DOM, with no build step and no dependencies —
 * see README.md. The blank row itself is rendered by PHP into a <template>, so
 * nothing here knows what a row looks like; it only knows how to copy one and
 * change the index in its input names.
 */
( function () {
	'use strict';

	/**
	 * The next free index for a repeater.
	 *
	 * Read off the existing rows rather than counted, because rows can be
	 * removed: three rows numbered 0, 1, 2 with the middle one deleted leaves
	 * 0 and 2, and a count would produce 2 again — two rows sharing an index,
	 * of which PHP keeps one.
	 *
	 * @param {Element} rows The rows container.
	 * @return {number} An index no current row is using.
	 */
	function nextIndex( rows ) {
		var highest = -1;

		rows.querySelectorAll( '[name*="[rows]["]' ).forEach( function ( input ) {
			var found = input.getAttribute( 'name' ).match( /\[rows\]\[(\d+)\]/ );
			if ( found ) {
				highest = Math.max( highest, parseInt( found[ 1 ], 10 ) );
			}
		} );

		return highest + 1;
	}

	/**
	 * Replace every __i__ in a subtree's names and ids.
	 *
	 * @param {Element} row   The cloned row.
	 * @param {number}  index Its index.
	 */
	function renumber( row, index ) {
		row.querySelectorAll( '[name], [id], [for]' ).forEach( function ( el ) {
			[ 'name', 'id', 'for' ].forEach( function ( attr ) {
				var value = el.getAttribute( attr );
				if ( value && value.indexOf( '__i__' ) !== -1 ) {
					el.setAttribute( attr, value.split( '__i__' ).join( String( index ) ) );
				}
			} );
		} );
	}

	/**
	 * Enable or disable Add according to the row limit.
	 *
	 * @param {Element} repeater The repeater.
	 */
	function updateAdd( repeater ) {
		var rows = repeater.querySelector( '[data-gwcpp-rows]' );
		var add = repeater.querySelector( '[data-gwcpp-add]' );
		var max = parseInt( repeater.getAttribute( 'data-max' ), 10 ) || 50;

		if ( ! rows || ! add ) {
			return;
		}

		var count = rows.querySelectorAll( '[data-gwcpp-row]' ).length;
		add.disabled = count >= max;
	}

	/**
	 * Say what just happened, for anybody not watching the screen.
	 *
	 * The wording comes off data attributes because PHP put it there already
	 * translated — see the note beside the live region in inc/field-repeater.php.
	 * A missing region is not an error: the announcement is an improvement on
	 * silence, not something the repeater depends on.
	 *
	 * @param {Element} repeater The repeater.
	 * @param {string}  which    'added' or 'removed'.
	 */
	function announce( repeater, which ) {
		var status = repeater.querySelector( '[data-gwcpp-status]' );
		if ( ! status ) {
			return;
		}

		var text = status.getAttribute( 'data-gwcpp-' + which ) || '';
		var rows = repeater.querySelector( '[data-gwcpp-rows]' );
		var count = rows ? rows.querySelectorAll( '[data-gwcpp-row]' ).length : 0;

		/* Cleared first, then set. A live region whose text does not change is
		 * not re-announced, so removing two rows in a row would say nothing the
		 * second time. The row count varies anyway, but not when the limit is
		 * reached — and that is exactly when somebody is pressing repeatedly. */
		status.textContent = '';
		status.textContent = text + ' ' + count;
	}

	function init( repeater ) {
		var rows = repeater.querySelector( '[data-gwcpp-rows]' );
		var template = repeater.querySelector( '[data-gwcpp-row-template]' );
		var add = repeater.querySelector( '[data-gwcpp-add]' );

		if ( ! rows || ! template || ! add ) {
			return;
		}

		add.addEventListener( 'click', function () {
			var max = parseInt( repeater.getAttribute( 'data-max' ), 10 ) || 50;
			if ( rows.querySelectorAll( '[data-gwcpp-row]' ).length >= max ) {
				return;
			}

			var row = template.content.firstElementChild.cloneNode( true );
			renumber( row, nextIndex( rows ) );
			rows.appendChild( row );
			updateAdd( repeater );

			// Move focus into what was just added, or a keyboard user presses
			// Add and nothing appears to happen.
			var first = row.querySelector( 'input, select, textarea' );
			if ( first ) {
				first.focus();
			}

			announce( repeater, 'added' );
		} );

		// Delegated, so it applies to rows added after this runs.
		rows.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-gwcpp-remove]' );
			if ( ! button ) {
				return;
			}

			var row = button.closest( '[data-gwcpp-row]' );
			if ( ! row ) {
				return;
			}

			/* Removed outright rather than hidden. The rows that remain are
			 * renumbered by nobody — PHP reindexes on save, and leaving gaps in
			 * the submitted indexes is harmless because it iterates values
			 * rather than counting. */
			row.parentNode.removeChild( row );
			updateAdd( repeater );
			announce( repeater, 'removed' );
		} );

		updateAdd( repeater );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-gwcpp-repeater]' ).forEach( init );
	} );
}() );
