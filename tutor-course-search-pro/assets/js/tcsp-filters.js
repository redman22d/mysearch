/* global tcspConfig, jQuery */
( function ( $ ) {
	'use strict';

	if ( typeof tcspConfig === 'undefined' ) {
		return;
	}

	var cfg = tcspConfig;

	// ------------------------------------------------------------------
	// Resilience: some Tutor LMS themes/setups paginate or filter the
	// course archive over AJAX and swap out the whole loop wrapper. If our
	// filter bar happens to live inside that swapped region, it gets wiped
	// along with it — this is what causes the bar to "disappear" on page 2.
	// We keep a saved copy of the bar (with the request's current filter
	// values already baked in from the initial server render) and put it
	// back if it's ever found missing after any AJAX call or DOM mutation.
	// ------------------------------------------------------------------
	var $bar       = $( '.tcsp-filter-bar' );
	var barHTML    = $bar.length ? $bar[ 0 ].outerHTML : null;
	var $barAnchor = $bar.length ? $bar.parent() : null;
	var restoring  = false;

	function restoreBarIfMissing() {
		if ( restoring || ! barHTML || $( '.tcsp-filter-bar' ).length ) {
			return;
		}
		if ( $barAnchor && $barAnchor.length && document.body.contains( $barAnchor[ 0 ] ) ) {
			restoring = true;
			$barAnchor.prepend( barHTML );
			restoring = false;
		}
	}

	$( document ).on( 'ajaxComplete', function () {
		window.setTimeout( restoreBarIfMissing, 50 );
	} );

	if ( window.MutationObserver && $barAnchor && $barAnchor.length ) {
		new MutationObserver( function () {
			restoreBarIfMissing();
		} ).observe( document.body, { childList: true, subtree: true } );
	}

	// ------------------------------------------------------------------
	// Multiselect (category / tag) dropdown toggles. Delegated on document
	// so they keep working even after restoreBarIfMissing() re-inserts a
	// fresh copy of the bar's markup.
	// ------------------------------------------------------------------
	$( document ).on( 'click', '.tcsp-multiselect-toggle', function ( e ) {
		e.preventDefault();
		var $toggle = $( this );
		var $panel  = $toggle.next( '.tcsp-multiselect-panel' );
		var open    = ! $panel.attr( 'hidden' );

		$( '.tcsp-multiselect-panel' ).not( $panel ).attr( 'hidden', true );
		$( '.tcsp-multiselect-toggle' ).not( $toggle ).attr( 'aria-expanded', 'false' );

		if ( open ) {
			$panel.attr( 'hidden', true );
			$toggle.attr( 'aria-expanded', 'false' );
		} else {
			$panel.removeAttr( 'hidden' );
			$toggle.attr( 'aria-expanded', 'true' );
		}
	} );

	$( document ).on( 'click', function ( e ) {
		if ( ! $( e.target ).closest( '.tcsp-multiselect' ).length ) {
			$( '.tcsp-multiselect-panel' ).attr( 'hidden', true );
			$( '.tcsp-multiselect-toggle' ).attr( 'aria-expanded', 'false' );
		}
	} );

	// ------------------------------------------------------------------
	// Autocomplete suggestions (only when the admin enabled the enhanced
	// suggestions). Delegated so it works after the bar is re-inserted.
	// ------------------------------------------------------------------
	if ( ! cfg.replaceSuggestions ) {
		return;
	}

	var timer    = null;
	var lastTerm = '';
	var xhr      = null;

	function esc( str ) {
		return $( '<div/>' ).text( str == null ? '' : str ).html();
	}

	function getBox( $input ) {
		return $input.closest( '.tcsp-search-wrap' ).find( '.tcsp-suggest-box' );
	}

	function hideBox( $box ) {
		$box.attr( 'hidden', true ).empty();
	}

	function renderItems( $box, items, term ) {
		if ( ! items.length ) {
			$box.html( '<div class="tcsp-suggest-empty">' + esc( cfg.i18n.noResults ) + '</div>' );
			$box.removeAttr( 'hidden' );
			return;
		}

		var html = '';
		items.forEach( function ( item ) {
			var badge = item.promoted
				? '<span class="tcsp-badge tcsp-badge--promoted">' + esc( item.tierLabel || cfg.i18n.promoted ) + '</span>'
				: '';
			var thumb = item.thumb
				? '<img class="tcsp-suggest-thumb" src="' + esc( item.thumb ) + '" alt="" loading="lazy">'
				: '<span class="tcsp-suggest-thumb tcsp-suggest-thumb--empty"></span>';

			html +=
				'<a class="tcsp-suggest-item' + ( item.promoted ? ' is-promoted' : '' ) + '" href="' + esc( item.url ) + '">' +
					thumb +
					'<span class="tcsp-suggest-meta">' +
						'<span class="tcsp-suggest-title">' + esc( item.title ) + badge + '</span>' +
						'<span class="tcsp-suggest-sub">' + esc( item.author ) +
							( item.price ? ' · ' + esc( item.price ) : '' ) +
						'</span>' +
					'</span>' +
				'</a>';
		} );

		var allUrl = '?s=' + encodeURIComponent( term ) + '&post_type=courses';
		html += '<a class="tcsp-suggest-all" href="' + allUrl + '">' + esc( cfg.i18n.viewAll ) + '</a>';

		$box.html( html ).removeAttr( 'hidden' );
	}

	function fetchSuggestions( $input, term ) {
		var $box = getBox( $input );
		if ( xhr && xhr.abort ) {
			xhr.abort();
		}
		$box.html( '<div class="tcsp-suggest-loading">' + esc( cfg.i18n.searching ) + '</div>' ).removeAttr( 'hidden' );

		xhr = $.ajax( {
			url: cfg.ajaxUrl,
			method: 'GET',
			dataType: 'json',
			data: {
				action: cfg.suggestAction,
				nonce: cfg.nonce,
				term: term
			}
		} ).done( function ( res ) {
			if ( res && res.success && res.data ) {
				renderItems( $box, res.data.items || [], term );
			} else {
				hideBox( $box );
			}
		} ).fail( function ( _, status ) {
			if ( status !== 'abort' ) {
				hideBox( $box );
			}
		} );
	}

	$( document ).on( 'input', '.tcsp-search-input', function () {
		var $input = $( this );
		var term   = $.trim( $input.val() );
		if ( term.length < cfg.minChars ) {
			hideBox( getBox( $input ) );
			lastTerm = '';
			return;
		}
		if ( term === lastTerm ) {
			return;
		}
		lastTerm = term;
		window.clearTimeout( timer );
		timer = window.setTimeout( function () {
			fetchSuggestions( $input, term );
		}, 220 );
	} );

	$( document ).on( 'focus', '.tcsp-search-input', function () {
		var $input = $( this );
		var $box   = getBox( $input );
		if ( $box.children().length && $.trim( $input.val() ).length >= cfg.minChars ) {
			$box.removeAttr( 'hidden' );
		}
	} );

	$( document ).on( 'click', function ( e ) {
		if ( ! $( e.target ).closest( '.tcsp-search-wrap' ).length ) {
			$( '.tcsp-suggest-box' ).each( function () {
				hideBox( $( this ) );
			} );
		}
	} );

	// Basic keyboard navigation.
	$( document ).on( 'keydown', '.tcsp-search-input', function ( e ) {
		var $box   = getBox( $( this ) );
		var $items = $box.find( '.tcsp-suggest-item' );
		if ( ! $items.length ) {
			return;
		}
		var $active = $items.filter( '.is-active' );
		var idx     = $items.index( $active );

		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			idx = ( idx + 1 ) % $items.length;
		} else if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			idx = ( idx - 1 + $items.length ) % $items.length;
		} else if ( e.key === 'Enter' && $active.length ) {
			window.location.href = $active.attr( 'href' );
			return;
		} else if ( e.key === 'Escape' ) {
			hideBox( $box );
			return;
		} else {
			return;
		}
		$items.removeClass( 'is-active' );
		$items.eq( idx ).addClass( 'is-active' );
	} );
}( jQuery ) );
