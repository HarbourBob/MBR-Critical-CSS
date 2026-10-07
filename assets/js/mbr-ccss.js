/*!
 * MBR Critical CSS Generator 1.2.0
 * Renders the fetched page in a sandboxed iframe at the chosen viewport and keeps
 * only the CSS rules that apply to elements visible above the fold.
 * License: GPL-2.0-or-later
 */
( function () {
	'use strict';

	var CONFIG = window.mbrCcssConfig || {};

	/* ------------------------------------------------------------------
	 * Small helpers
	 * ------------------------------------------------------------------ */

	// Pseudo-elements and interaction states can't be matched with querySelectorAll, so they
	// are removed before testing (the original selector is always what gets output).
	var DROP_PSEUDO = /^(?:before|after|first-line|first-letter|placeholder|selection|marker|backdrop|file-selector-button|cue|cue-region|spelling-error|grammar-error|target-text|highlight|part|slotted|view-transition[\w-]*|hover|focus|focus-within|focus-visible|active|visited|link|any-link|target|target-within|playing|paused|user-invalid|user-valid|-webkit-[\w-]+|-moz-[\w-]+|-ms-[\w-]+)$/i;
	var RECURSE_PSEUDO = /^(?:not|is|where|matches|has|-webkit-any|-moz-any)$/i;
	// Declarations kept for elements that are hidden or parked off-screen near the top of the
	// page: without them, the element would briefly appear before the full stylesheet loads.
	var PLACEMENT_PROPS = /^(?:display|visibility|content-visibility|opacity|position|inset(?:-[a-z-]+)?|top|right|bottom|left|transform|translate|width|max-width|min-width|height|max-height|min-height|overflow(?:-[xy])?|clip|clip-path|z-index)$/i;
	var QUOTED = /("(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*')/;

	function clamp( n, min, max, fallback ) {
		n = parseInt( n, 10 );
		if ( isNaN( n ) ) {
			return fallback;
		}
		return Math.min( max, Math.max( min, n ) );
	}

	// Split on a separator at the top level only (ignores separators in quotes, () and []).
	function splitTop( text, sep ) {
		var out = [], depth = 0, quote = '', start = 0, ch, i;
		for ( i = 0; i < text.length; i++ ) {
			ch = text.charAt( i );
			if ( quote ) {
				if ( ch === '\\' ) {
					i++;
				} else if ( ch === quote ) {
					quote = '';
				}
				continue;
			}
			if ( ch === '"' || ch === "'" ) {
				quote = ch;
			} else if ( ch === '\\' ) {
				i++;
			} else if ( ch === '(' || ch === '[' || ch === '{' ) {
				depth++;
			} else if ( ( ch === ')' || ch === ']' || ch === '}' ) && depth > 0 ) {
				depth--;
			} else if ( ch === sep && depth === 0 ) {
				out.push( text.slice( start, i ).trim() );
				start = i + 1;
			}
		}
		out.push( text.slice( start ).trim() );
		return out.filter( Boolean );
	}

	// Apply fn to the parts of a string that are outside quoted strings.
	function mapUnquoted( str, fn ) {
		return str.split( QUOTED ).map( function ( part, i ) {
			return i % 2 ? part : fn( part );
		} ).join( '' );
	}

	// Copy a quoted string starting at i (which is the quote); returns the index after it.
	function skipString( text, i ) {
		var q = text.charAt( i ), j = i + 1;
		while ( j < text.length ) {
			if ( text.charAt( j ) === '\\' ) {
				j += 2;
			} else if ( text.charAt( j ) === q ) {
				return j + 1;
			} else {
				j++;
			}
		}
		return j;
	}

	// Index just after the bracket that closes the one at i, honouring strings and escapes.
	function skipBlock( text, i ) {
		var open = text.charAt( i ), close = open === '[' ? ']' : ')', depth = 0, j = i, ch;
		while ( j < text.length ) {
			ch = text.charAt( j );
			if ( ch === '\\' ) {
				j += 2;
				continue;
			}
			if ( ch === '"' || ch === "'" ) {
				j = skipString( text, j );
				continue;
			}
			if ( ch === open ) {
				depth++;
			} else if ( ch === close ) {
				depth--;
				if ( depth === 0 ) {
					return j + 1;
				}
			}
			j++;
		}
		return j;
	}

	/**
	 * Syntax-aware selector cleaning. Removes pseudo-elements and interaction states so the
	 * selector can be tested with querySelectorAll, without touching attribute values,
	 * strings or escaped characters. Returns '' when nothing testable remains.
	 */
	function stripPseudos( sel ) {
		var out = '', i = 0, ch, start, name, arg, end, parts;
		while ( i < sel.length ) {
			ch = sel.charAt( i );
			if ( ch === '\\' ) {
				out += sel.substr( i, 2 );
				i += 2;
			} else if ( ch === '"' || ch === "'" ) {
				end = skipString( sel, i );
				out += sel.slice( i, end );
				i = end;
			} else if ( ch === '[' ) {
				end = skipBlock( sel, i );
				out += sel.slice( i, end );
				i = end;
			} else if ( ch === ':' ) {
				start = i;
				i += sel.charAt( i + 1 ) === ':' ? 2 : 1;
				name = '';
				while ( i < sel.length && /[\w-]/.test( sel.charAt( i ) ) ) {
					name += sel.charAt( i++ );
				}
				arg = null;
				if ( sel.charAt( i ) === '(' ) {
					end = skipBlock( sel, i );
					arg = sel.slice( i + 1, end - 1 );
					i = end;
				}
				if ( DROP_PSEUDO.test( name ) ) {
					continue; // Removed.
				}
				if ( arg !== null && RECURSE_PSEUDO.test( name ) ) {
					if ( /^not$/i.test( name ) && splitTop( arg, ',' ).some( function ( p ) {
						return stripPseudos( p ) !== p.trim();
					} ) ) {
						// A negated state can't be tested: drop the whole :not() so the test matches
						// more broadly rather than becoming impossible (.a:not(.a:hover) must not become .a:not(.a)).
						continue;
					}
					parts = splitTop( arg, ',' ).map( stripPseudos );
					if ( parts.some( function ( p ) {
						return ! p;
					} ) ) {
						continue; // Something inside became "anything", so drop the whole condition.
					}
					out += ':' + name + '(' + parts.join( ', ' ) + ')';
					continue;
				}
				out += sel.slice( start, i ); // Structural pseudo-classes are kept as written.
			} else {
				out += ch;
				i++;
			}
		}
		return out.trim();
	}

	function cleanSelector( sel ) {
		var s = stripPseudos( sel );
		if ( ! s ) {
			return '*';
		}
		if ( /[>+~]$/.test( s ) ) {
			s += ' *';
		}
		return s;
	}

	function minSelector( sel ) {
		return mapUnquoted( sel, function ( s ) {
			return s.replace( /\s+/g, ' ' ).replace( /(?<!\\)\s*([>+~])\s*/g, '$1' );
		} ).trim();
	}

	function minValue( v ) {
		return mapUnquoted( v, function ( s ) {
			return s.replace( /\s+/g, ' ' )
				.replace( /\s*,\s*/g, ',' )
				.replace( /\(\s+/g, '(' )
				.replace( /\s+\)/g, ')' )
				.replace( /\s*!\s*important/gi, '!important' );
		} ).trim();
	}

	function minDecl( d ) {
		var idx = d.indexOf( ':' );
		if ( idx < 0 ) {
			return d.trim();
		}
		return d.slice( 0, idx ).trim() + ':' + minValue( d.slice( idx + 1 ) );
	}

	function collapse( text ) {
		return mapUnquoted( text, function ( s ) {
			return s.replace( /\s+/g, ' ' );
		} ).trim();
	}

	function indent( text ) {
		return text.split( '\n' ).map( function ( line ) {
			return line ? '\t' + line : line;
		} ).join( '\n' );
	}

	function escapeRe( s ) {
		return s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}

	function formatBytes( n ) {
		if ( n < 1024 ) {
			return n + ' B';
		}
		if ( n < 1048576 ) {
			return ( n / 1024 ).toFixed( 1 ) + ' KB';
		}
		return ( n / 1048576 ).toFixed( 2 ) + ' MB';
	}

	function byteLength( s ) {
		return new Blob( [ s ] ).size;
	}

	function normaliseUrl( value ) {
		var v = ( value || '' ).trim(), u;
		if ( ! v ) {
			return '';
		}
		if ( /^[a-z][a-z0-9+.-]*:\/\//i.test( v ) ) {
			if ( ! /^https?:\/\//i.test( v ) ) {
				return '';
			}
		} else {
			v = 'https://' + v.replace( /^\/+/, '' );
		}
		try {
			u = new URL( v );
		} catch ( e ) {
			return '';
		}
		return ( u.protocol === 'http:' || u.protocol === 'https:' ) && u.hostname.indexOf( '.' ) > 0 ? u.href : '';
	}

	function wait( ms ) {
		return new Promise( function ( resolve ) {
			setTimeout( resolve, ms );
		} );
	}

	/* ------------------------------------------------------------------
	 * Extraction
	 * ------------------------------------------------------------------ */

	function extractCritical( win, doc, opts ) {
		var vw = opts.width;
		var vh = opts.height;
		var minify = opts.minify;
		var stateCache = new Map(); // element -> 'above' | 'near' | ''
		var selectorCache = new Map(); // cleaned selector -> 'above' | 'near' | ''
		var stats = { total: 0, kept: 0, partial: 0, untestable: 0 };
		var warnings = [];
		var phase = 'collect'; // First pass decides; second pass emits.
		var haystack = '';
		var usedFonts = new Set();
		var usedAnimations = new Set();
		var NON_RENDERED = /^(?:HEAD|META|TITLE|STYLE|SCRIPT|LINK|BASE|TEMPLATE|NOSCRIPT)$/;
		var supports = ( window.CSS && window.CSS.supports ) ? function ( c ) {
			try {
				return window.CSS.supports( c );
			} catch ( e ) {
				return true;
			}
		} : function () {
			return true;
		};

		function is( rule, name ) {
			if ( typeof win[ name ] === 'function' && rule instanceof win[ name ] ) {
				return true;
			}
			return !! ( rule.constructor && rule.constructor.name === name );
		}

		function rendered( el ) {
			return el.getClientRects().length > 0;
		}

		// 'above': visible in the first viewport.
		// 'near': hidden (display:none) or parked off-screen, but sitting in the area the visitor
		//         sees first, so the rules that keep it hidden or in place are critical too.
		function elementState( el ) {
			if ( stateCache.has( el ) ) {
				return stateCache.get( el );
			}
			var state = '', r, p;
			if ( NON_RENDERED.test( el.tagName ) || ( el.closest && el.closest( 'head' ) ) ) {
				state = '';
			} else if ( rendered( el ) ) {
				r = el.getBoundingClientRect();
				if ( r.top < vh && r.bottom >= 0 && r.left < vw && r.right >= 0 ) {
					state = 'above';
				} else if ( r.top < vh && ( r.right < 0 || r.left >= vw || r.bottom < 0 ) ) {
					state = 'near'; // Off-canvas beside or above the first view.
				}
			} else {
				p = el.parentElement;
				while ( p && ! rendered( p ) ) {
					p = p.parentElement;
				}
				state = p && elementState( p ) === 'above' ? 'near' : '';
			}
			stateCache.set( el, state );
			return state;
		}

		function selectorState( sel ) {
			var clean = cleanSelector( sel ), state = '', els, i, st;
			if ( selectorCache.has( clean ) ) {
				return selectorCache.get( clean );
			}
			try {
				els = doc.querySelectorAll( clean );
				for ( i = 0; i < els.length; i++ ) {
					st = elementState( els[ i ] );
					if ( st === 'above' ) {
						state = 'above';
						break;
					}
					if ( st === 'near' ) {
						state = 'near';
					}
				}
			} catch ( e ) {
				// The browser accepted the rule but we can't test it: keep it, to be safe.
				state = 'above';
				if ( phase === 'collect' ) {
					stats.untestable++;
				}
			}
			selectorCache.set( clean, state );
			return state;
		}

		function mediaMatches( text ) {
			if ( ! text || text === 'all' ) {
				return true;
			}
			try {
				return win.matchMedia( text ).matches;
			} catch ( e ) {
				return false;
			}
		}

		function prelude( rule ) {
			var t = rule.cssText;
			return t.slice( 0, t.indexOf( '{' ) ).trim();
		}

		function minPrelude( head ) {
			return mapUnquoted( head, function ( s ) {
				return s.replace( /\s+/g, ' ' ).replace( /\(\s*([\w-]+)\s*:\s*/g, '($1:' ).replace( /\s*\)/g, ')' ).replace( /\s*,\s*/g, ',' );
			} ).trim();
		}

		function wrap( head, inner ) {
			if ( ! inner.length ) {
				return '';
			}
			if ( minify ) {
				return minPrelude( head ) + '{' + inner.join( '' ) + '}';
			}
			return head + ' {\n' + indent( inner.join( '\n' ) ) + '\n}';
		}

		function fmtBlock( head, decls, nested ) {
			if ( ! decls.length && ! nested.length ) {
				return '';
			}
			if ( minify ) {
				return head + '{' + decls.map( minDecl ).join( ';' ) +
					( nested.length ? ( decls.length ? ';' : '' ) + nested.map( collapse ).join( '' ) : '' ) + '}';
			}
			var body = decls.map( function ( d ) {
				return '\t' + d + ';';
			} );
			nested.forEach( function ( n ) {
				body.push( indent( n ) );
			} );
			return head + ' {\n' + body.join( '\n' ) + '\n}';
		}

		function fmtStyle( selectors, decls, nested ) {
			return fmtBlock( minify ? selectors.map( minSelector ).join( ',' ) : selectors.join( ',\n' ), decls, nested );
		}

		function propName( decl ) {
			var i = decl.indexOf( ':' );
			return i < 0 ? '' : decl.slice( 0, i ).trim();
		}

		function familyOf( rule ) {
			return ( rule.style.getPropertyValue( 'font-family' ) || '' ).replace( /^\s*["']|["']\s*$/g, '' ).trim().toLowerCase();
		}

		function fontUsed( family ) {
			if ( ! family ) {
				return false;
			}
			if ( usedFonts.has( family ) ) {
				return true;
			}
			return haystack.indexOf( family ) !== -1;
		}

		function animationUsed( name ) {
			name = ( name || '' ).toLowerCase();
			if ( ! name ) {
				return false;
			}
			if ( usedAnimations.has( name ) ) {
				return true;
			}
			return new RegExp( '(^|[^\\w-])' + escapeRe( name ) + '($|[^\\w-])' ).test( haystack );
		}

		function walk( rules ) {
			var out = [], i, text;
			for ( i = 0; i < rules.length; i++ ) {
				text = processRule( rules[ i ] );
				if ( text ) {
					out.push( text );
				}
			}
			return out;
		}

		function processRule( rule ) {
			var selectors, above, near, decls, nested, i, inner, parts, m, frames, kf;

			if ( is( rule, 'CSSStyleRule' ) ) {
				if ( phase === 'collect' ) {
					stats.total++;
				}
				selectors = splitTop( rule.selectorText, ',' );
				above = [];
				near = [];
				selectors.forEach( function ( sel ) {
					var st = selectorState( sel );
					if ( st === 'above' ) {
						above.push( sel );
					} else if ( st === 'near' ) {
						near.push( sel );
					}
				} );
				if ( ! above.length && ! near.length ) {
					return '';
				}
				decls = splitTop( rule.style.cssText, ';' );
				nested = [];
				if ( rule.cssRules && rule.cssRules.length ) { // CSS nesting: keep children as written.
					for ( i = 0; i < rule.cssRules.length; i++ ) {
						nested.push( rule.cssRules[ i ].cssText );
					}
				}
				parts = [];
				if ( above.length ) {
					parts.push( fmtStyle( above, decls, nested ) );
				}
				if ( near.length ) {
					// Only the declarations that keep the element hidden or in place.
					// Custom properties are kept too, so a value such as display: var(--state) still
					// resolves. Ancestors of a hidden element are either visible (rules kept whole) or
					// hidden themselves (custom properties kept here), so inherited values survive as well.
					var placement = decls.filter( function ( d ) {
						var n = propName( d );
						return n.indexOf( '--' ) === 0 || PLACEMENT_PROPS.test( n );
					} );
					if ( placement.length ) {
						parts.push( fmtStyle( near, placement, [] ) );
					}
				}
				parts = parts.filter( Boolean );
				if ( parts.length && phase === 'collect' ) {
					stats.kept++;
					if ( ! above.length ) {
						stats.partial++;
					}
				}
				return parts.join( minify ? '' : '\n' );
			}

			if ( is( rule, 'CSSMediaRule' ) ) {
				return mediaMatches( rule.media.mediaText ) ? wrap( '@media ' + rule.media.mediaText, walk( rule.cssRules ) ) : '';
			}

			if ( is( rule, 'CSSSupportsRule' ) ) {
				return supports( rule.conditionText ) ? wrap( '@supports ' + rule.conditionText, walk( rule.cssRules ) ) : '';
			}

			if ( is( rule, 'CSSImportRule' ) ) {
				try {
					inner = rule.styleSheet ? walk( rule.styleSheet.cssRules ) : [];
				} catch ( e ) {
					if ( phase === 'collect' ) {
						warnings.push( 'An @import could not be read: ' + rule.href );
					}
					return '';
				}
				m = rule.media && rule.media.mediaText;
				if ( m && m !== 'all' ) {
					return mediaMatches( m ) ? wrap( '@media ' + m, inner ) : '';
				}
				return inner.join( minify ? '' : '\n' );
			}

			// Fonts and animations stay exactly where they were defined, inside any @layer,
			// @media or @supports, and are only included when something above the fold uses them.
			if ( is( rule, 'CSSFontFaceRule' ) ) {
				if ( phase === 'collect' || ! opts.fonts || ! fontUsed( familyOf( rule ) ) ) {
					return '';
				}
				decls = splitTop( ( rule.style && rule.style.cssText ) || '', ';' );
				return decls.length ? fmtBlock( '@font-face', decls, [] ) : ( minify ? collapse( rule.cssText ) : rule.cssText );
			}

			if ( is( rule, 'CSSKeyframesRule' ) ) {
				if ( phase === 'collect' || ! animationUsed( rule.name ) ) {
					return '';
				}
				frames = [];
				for ( i = 0; i < rule.cssRules.length; i++ ) {
					kf = rule.cssRules[ i ];
					frames.push( fmtStyle( [ kf.keyText ], splitTop( kf.style.cssText, ';' ), [] ) );
				}
				return wrap( '@keyframes ' + rule.name, frames.filter( Boolean ) );
			}

			// Small at-rules that are cheap and affect rendering: keep as written.
			if ( is( rule, 'CSSLayerStatementRule' ) || is( rule, 'CSSPropertyRule' ) || is( rule, 'CSSNamespaceRule' ) ||
				is( rule, 'CSSCounterStyleRule' ) || is( rule, 'CSSFontFeatureValuesRule' ) || is( rule, 'CSSFontPaletteValuesRule' ) ) {
				return minify ? collapse( rule.cssText ) : rule.cssText;
			}

			if ( is( rule, 'CSSPageRule' ) ) {
				return '';
			}

			// Any other grouping rule (@layer blocks, @container, @scope, @starting-style…).
			if ( rule.cssRules ) {
				return wrap( prelude( rule ), walk( rule.cssRules ) );
			}

			return '';
		}

		function walkSheets() {
			var chunks = [], sheets = doc.styleSheets, s, sheet, rules, media;
			for ( s = 0; s < sheets.length; s++ ) {
				sheet = sheets[ s ];
				if ( sheet.disabled ) {
					continue;
				}
				try {
					rules = sheet.cssRules;
				} catch ( e ) {
					if ( phase === 'collect' ) {
						warnings.push( 'A stylesheet could not be read: ' + ( sheet.href || 'inline style' ) );
					}
					continue;
				}
				media = sheet.media && sheet.media.mediaText;
				if ( media && media !== 'all' ) {
					if ( mediaMatches( media ) ) {
						chunks.push( wrap( '@media ' + media, walk( rules ) ) );
					}
				} else {
					Array.prototype.push.apply( chunks, walk( rules ) );
				}
			}
			return chunks.filter( Boolean ).join( minify ? '' : '\n\n' );
		}

		// Fonts and animations actually in use above the fold, read from rendered styles, so
		// a font set only in an inline style attribute still counts.
		function collectComputed() {
			var all = doc.body ? doc.body.querySelectorAll( '*' ) : [], i, el;
			function add( cs ) {
				if ( ! cs ) {
					return;
				}
				( cs.fontFamily || '' ).split( ',' ).forEach( function ( f ) {
					f = f.replace( /^\s*["']|["']\s*$/g, '' ).trim().toLowerCase();
					if ( f ) {
						usedFonts.add( f );
					}
				} );
				( cs.animationName || '' ).split( ',' ).forEach( function ( a ) {
					a = a.trim().toLowerCase();
					if ( a && a !== 'none' ) {
						usedAnimations.add( a );
					}
				} );
			}
			add( win.getComputedStyle( doc.documentElement ) );
			if ( doc.body ) {
				add( win.getComputedStyle( doc.body ) );
			}
			for ( i = 0; i < all.length; i++ ) {
				el = all[ i ];
				if ( elementState( el ) === 'above' ) {
					add( win.getComputedStyle( el ) );
					add( win.getComputedStyle( el, '::before' ) );
					add( win.getComputedStyle( el, '::after' ) );
				}
			}
		}

		collectComputed();
		haystack = walkSheets().toLowerCase(); // Pass 1: which style rules are kept.
		phase = 'emit';
		var css = walkSheets(); // Pass 2: the output, with fonts and keyframes in place.

		if ( stats.untestable ) {
			warnings.push( stats.untestable + ' selector' + ( stats.untestable === 1 ? '' : 's' ) +
				' could not be tested in this browser, so ' + ( stats.untestable === 1 ? 'its rule was' : 'their rules were' ) + ' kept to be safe.' );
		}

		return {
			css: css,
			stats: stats,
			warnings: warnings
		};
	}

	/* ------------------------------------------------------------------
	 * Fidelity check: render the page again with only the critical CSS and
	 * compare the first screen against the full render.
	 * ------------------------------------------------------------------ */

	function describe( el ) {
		var d = el.tagName.toLowerCase();
		if ( el.id ) {
			d += '#' + el.id;
		} else if ( typeof el.className === 'string' && el.className.trim() ) {
			d += '.' + el.className.trim().split( /\s+/ ).slice( 0, 2 ).join( '.' );
		}
		return d;
	}

	function buildCriticalOnly( html, css ) {
		var parsed = new DOMParser().parseFromString( html, 'text/html' );
		parsed.querySelectorAll( 'style' ).forEach( function ( st ) {
			st.textContent = ''; // Emptied rather than removed, so element order is unchanged.
		} );
		var style = parsed.createElement( 'style' );
		style.textContent = css;
		parsed.head.appendChild( style );
		return '<!DOCTYPE html>\n' + parsed.documentElement.outerHTML;
	}

	// Properties compared for every element in the first view, and for its ::before/::after.
	var CHECK_PROPS = [ 'display', 'visibility', 'opacity', 'color', 'background-color', 'background-image',
		'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
		'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style',
		'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
		'font-family', 'font-size', 'font-weight', 'font-style', 'text-transform', 'text-decoration-line' ];
	var PSEUDO_PROPS = [ 'content', 'display', 'visibility', 'opacity', 'color', 'background-color', 'background-image' ];

	// Run every CSS animation and transition to its end (or cancel endless ones) so both renders
	// are compared in the same resting state.
	function restAnimations( doc ) {
		try {
			doc.getAnimations().forEach( function ( a ) {
				try {
					a.finish();
				} catch ( e ) {
					try {
						a.cancel();
					} catch ( e2 ) {}
				}
			} );
		} catch ( e ) {}
	}

	function styleDifference( winA, winB, a, b ) {
		var sa = winA.getComputedStyle( a ), sb = winB.getComputedStyle( b ), i, p, pa, pb, k, pseudo;
		for ( i = 0; i < CHECK_PROPS.length; i++ ) {
			p = CHECK_PROPS[ i ];
			if ( sa.getPropertyValue( p ) !== sb.getPropertyValue( p ) ) {
				return p;
			}
		}
		for ( k = 0; k < 2; k++ ) {
			pseudo = k ? '::after' : '::before';
			pa = winA.getComputedStyle( a, pseudo );
			pb = winB.getComputedStyle( b, pseudo );
			if ( pa.getPropertyValue( 'content' ) === 'none' && pb.getPropertyValue( 'content' ) === 'none' ) {
				continue;
			}
			for ( i = 0; i < PSEUDO_PROPS.length; i++ ) {
				p = PSEUDO_PROPS[ i ];
				if ( pa.getPropertyValue( p ) !== pb.getPropertyValue( p ) ) {
					return pseudo + ' ' + p;
				}
			}
		}
		return '';
	}

	function compareRenders( fullDoc, critDoc, w, h ) {
		var winA = fullDoc.defaultView, winB = critDoc.defaultView;
		var a = fullDoc.body ? fullDoc.body.querySelectorAll( '*' ) : [];
		var b = critDoc.body ? critDoc.body.querySelectorAll( '*' ) : [];
		var n = Math.min( a.length, b.length ), i, ra, rb, aShown, bShown, aIn, bIn, why;
		var compared = 0, differ = [];
		function inView( r ) {
			return r.top < h && r.bottom >= 0 && r.left < w && r.right >= 0;
		}
		restAnimations( fullDoc );
		restAnimations( critDoc );
		for ( i = 0; i < n; i++ ) {
			aShown = a[ i ].getClientRects().length > 0;
			bShown = b[ i ].getClientRects().length > 0;
			ra = aShown ? a[ i ].getBoundingClientRect() : null;
			rb = bShown ? b[ i ].getBoundingClientRect() : null;
			aIn = aShown && inView( ra );
			bIn = bShown && inView( rb );
			if ( ! aIn && ! bIn ) {
				continue; // Not in the first view either way.
			}
			compared++;
			why = '';
			if ( aIn !== bIn ) {
				why = aIn ? 'missing' : 'appears';
			} else if ( Math.abs( ra.top - rb.top ) > 2 || Math.abs( ra.left - rb.left ) > 2 ) {
				why = 'position';
			} else if ( Math.abs( ra.width - rb.width ) > 2 || Math.abs( ra.height - rb.height ) > 2 ) {
				why = 'size';
			} else if ( winA && winB ) {
				why = styleDifference( winA, winB, a[ i ], b[ i ] );
			}
			if ( why ) {
				differ.push( { el: a[ i ], why: why } );
			}
		}
		return { compared: compared, differ: differ };
	}

	function fidelityCheck( html, css, w, h, fullDoc ) {
		var holder = document.createElement( 'div' );
		holder.setAttribute( 'aria-hidden', 'true' );
		holder.style.cssText = 'position:fixed!important;left:-100000px!important;top:0!important;visibility:hidden!important;pointer-events:none!important;';
		document.body.appendChild( holder );
		return renderFrame( holder, buildCriticalOnly( html, css ), w, h, true ).then( function ( frame ) {
			return settle( frame ).then( function () {
				var result = compareRenders( fullDoc, frame.contentDocument, w, h );
				holder.remove();
				return result;
			} );
		} ).catch( function () {
			holder.remove();
			return null;
		} );
	}

	/* ------------------------------------------------------------------
	 * Rendering the page
	 * ------------------------------------------------------------------ */

	function fitStage( stage ) {
		var frame = stage.querySelector( 'iframe' );
		if ( ! frame ) {
			return;
		}
		var w = parseInt( frame.dataset.w, 10 ), h = parseInt( frame.dataset.h, 10 );
		var avail = stage.clientWidth || w;
		var scale = Math.min( 1, avail / w );
		frame.style.setProperty( 'transform', 'scale(' + scale + ')', 'important' );
		frame.style.setProperty( 'transform-origin', '0 0', 'important' );
		stage.style.height = Math.ceil( h * scale ) + 'px';
	}

	function renderFrame( stage, html, w, h, offscreen ) {
		return new Promise( function ( resolve ) {
			var frame = document.createElement( 'iframe' );
			var done = false;
			var timer;

			function finish() {
				var d;
				try {
					d = frame.contentDocument;
				} catch ( e ) {
					d = null;
				}
				if ( done || ! d || d.URL !== 'about:srcdoc' ) {
					return;
				}
				done = true;
				clearTimeout( timer );
				resolve( frame );
			}

			stage.textContent = '';
			// allow-same-origin WITHOUT allow-scripts: nothing in the fetched page can run,
			// but this script can still measure the rendered layout.
			frame.setAttribute( 'sandbox', 'allow-same-origin' );
			frame.setAttribute( 'title', 'Rendered page preview' );
			frame.setAttribute( 'tabindex', '-1' );
			frame.setAttribute( 'scrolling', 'no' );
			frame.setAttribute( 'referrerpolicy', 'no-referrer' );
			frame.className = 'mbr-ccss__frame';
			frame.dataset.w = w;
			frame.dataset.h = h;
			// Lock the size with !important so theme rules such as iframe { max-width: 100% }
			// can't shrink the viewport being measured.
			[
				[ 'width', w + 'px' ], [ 'height', h + 'px' ],
				[ 'min-width', w + 'px' ], [ 'min-height', h + 'px' ],
				[ 'max-width', 'none' ], [ 'max-height', 'none' ],
				[ 'margin', '0' ], [ 'padding', '0' ], [ 'border', '0' ],
				[ 'display', 'block' ], [ 'aspect-ratio', 'auto' ],
				// Out of the page flow, so the full-size frame can't widen the layout around it.
				[ 'position', 'absolute' ], [ 'top', '0' ], [ 'left', '0' ]
			].forEach( function ( p ) {
				frame.style.setProperty( p[ 0 ], p[ 1 ], 'important' );
			} );
			frame.addEventListener( 'load', finish );
			frame.srcdoc = html;
			stage.appendChild( frame );
			if ( ! offscreen ) {
				fitStage( stage );
			}

			// Don't wait forever on slow images: measure what we have after 20s.
			timer = setTimeout( function () {
				if ( ! done ) {
					done = true;
					resolve( frame );
				}
			}, 20000 );
		} );
	}

	function settle( frame ) {
		var d = frame.contentDocument;
		var fonts = d && d.fonts && d.fonts.ready ? d.fonts.ready : Promise.resolve();
		return Promise.race( [ fonts, wait( 4000 ) ] ).then( function () {
			try {
				frame.contentWindow.scrollTo( 0, 0 );
			} catch ( e ) {}
			return wait( 150 ); // Let layout settle after late font swaps.
		} );
	}

	/* ------------------------------------------------------------------
	 * UI
	 * ------------------------------------------------------------------ */

	function init( root ) {
		var form = root.querySelector( '.mbr-ccss__form' );
		var urlInput = form.elements.url;
		var wInput = form.elements.width;
		var hInput = form.elements.height;
		var minifyInput = form.elements.minify;
		var fontsInput = form.elements.fonts;
		var submit = form.querySelector( '.mbr-ccss__submit' );
		var status = root.querySelector( '.mbr-ccss__status' );
		var result = root.querySelector( '.mbr-ccss__result' );
		var output = result.querySelector( 'textarea' );
		var statsEl = result.querySelector( '.mbr-ccss__stats' );
		var notes = result.querySelector( '.mbr-ccss__notes' );
		var notesList = notes.querySelector( 'ul' );
		var stage = result.querySelector( '.mbr-ccss__stage' );
		var copyBtn = result.querySelector( '[data-action="copy"]' );
		var downloadBtn = result.querySelector( '[data-action="download"]' );
		var busy = false;
		var last = null; // { frame, data, url, w, h }

		function setStatus( msg, kind ) {
			status.textContent = msg;
			status.className = 'mbr-ccss__status' + ( kind ? ' is-' + kind : '' );
		}

		function showNotes( list ) {
			notesList.textContent = '';
			list.forEach( function ( msg ) {
				var li = document.createElement( 'li' );
				li.textContent = msg;
				notesList.appendChild( li );
			} );
			notes.hidden = ! list.length;
		}

		function syncPresets() {
			root.querySelectorAll( '.mbr-ccss__preset' ).forEach( function ( btn ) {
				var on = btn.dataset.w === String( wInput.value ) && btn.dataset.h === String( hInput.value );
				btn.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
		}

		function sizeWarning() {
			var vw = last.frame.contentWindow.innerWidth, vh = last.frame.contentWindow.innerHeight;
			if ( Math.abs( vw - last.w ) > 2 || Math.abs( vh - last.h ) > 2 ) {
				return [ 'The page rendered at ' + vw + ' × ' + vh + ' instead of ' + last.w + ' × ' + last.h + '. Something on this page is resizing the preview frame, so the result may not match the viewport you chose.' ];
			}
			return [];
		}

		function extractAndShow() {
			var current = last;
			var out = extractCritical( current.frame.contentWindow, current.frame.contentDocument, {
				width: current.w,
				height: current.h,
				minify: minifyInput.checked,
				fonts: fontsInput.checked
			} );
			var size = byteLength( out.css );
			var notesBase = sizeWarning().concat( current.data.warnings || [], out.warnings );

			output.value = out.css;
			statsEl.textContent = 'Kept ' + out.stats.kept.toLocaleString() + ' of ' + out.stats.total.toLocaleString() +
				' style rules: ' + formatBytes( size ) + ' of critical CSS, from ' + formatBytes( current.data.cssBytes || 0 ) +
				' of CSS on the page (' + ( current.data.stylesheets || 0 ) + ' linked stylesheet' + ( current.data.stylesheets === 1 ? '' : 's' ) +
				( current.data.inlineStyles ? ' plus ' + current.data.inlineStyles + ' inline style block' + ( current.data.inlineStyles === 1 ? '' : 's' ) : '' ) + ').';
			if ( out.stats.partial ) {
				notesBase.push( out.stats.partial + ' rule' + ( out.stats.partial === 1 ? '' : 's' ) + ' for hidden or off-screen elements near the top of the page were kept in part, so those elements stay hidden or in place until the full stylesheet loads.' );
			}
			showNotes( notesBase );

			if ( ! out.css ) {
				setStatus( 'No above-the-fold CSS was found. If the page builds its layout with JavaScript, the result will be limited.', 'error' );
				return Promise.resolve();
			}

			setStatus( 'Checking the result against the full render…', 'busy' );
			return fidelityCheck( current.data.html, out.css, current.w, current.h, current.frame.contentDocument ).then( function ( check ) {
				if ( last !== current ) {
					return; // A newer run has started.
				}
				if ( ! check || ! check.compared ) {
					setStatus( 'Generated critical CSS for ' + current.w + ' × ' + current.h + '.', 'success' );
					return;
				}
				var same = check.compared - check.differ.length;
				var pct = Math.floor( ( same / check.compared ) * 1000 ) / 10;
				statsEl.textContent += ' Layout & style check: ' + same + ' of ' + check.compared + ' above-the-fold elements (' + pct +
					'%) match the full render with only the critical CSS.';
				notesBase.push( 'About the check: it compares each element\u2019s position, size, visibility, colours, backgrounds, borders and fonts, including ::before and ::after, against the page as the generator fetched it (scripts off, animations at rest). It does not compare image pixels, shadows, transforms or anything added by scripts.' );
				if ( check.differ.length ) {
					var names = check.differ.slice( 0, 6 ).map( function ( d ) {
						return describe( d.el ) + ' (' + d.why + ')';
					} ).join( ', ' );
					notesBase.push( 'With only the critical CSS, ' + check.differ.length + ' element' + ( check.differ.length === 1 ? '' : 's' ) +
						' in the first view differed from the full render, for example: ' + names +
						'. Small differences are often caused by fonts loading at different moments; large ones are worth checking before you use this CSS.' );
				}
				showNotes( notesBase );
				setStatus( 'Generated critical CSS for ' + current.w + ' × ' + current.h + '.', check.differ.length ? 'warning' : 'success' );
			} );
		}

		// Fetch from the endpoint. Anonymous visitors send no nonce; logged-in users send one
		// and, if a long-open or cached page has an expired nonce, refresh it once and retry.
		function request( url, retried ) {
			var headers = { 'Content-Type': 'application/json' };
			if ( CONFIG.nonce ) {
				headers[ 'X-WP-Nonce' ] = CONFIG.nonce;
			}
			return fetch( CONFIG.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: headers,
				body: JSON.stringify( { url: url } )
			} ).then( function ( res ) {
				return res.json().catch( function () {
					return {};
				} ).then( function ( data ) {
					if ( res.status === 403 && data.code === 'rest_cookie_invalid_nonce' && CONFIG.nonceRefresh && ! retried ) {
						return fetch( CONFIG.nonceRefresh, { credentials: 'same-origin' } ).then( function ( r ) {
							return r.ok ? r.text() : '';
						} ).then( function ( fresh ) {
							fresh = ( fresh || '' ).trim();
							if ( /^[a-f0-9]{10}$/i.test( fresh ) ) {
								CONFIG.nonce = fresh;
							} else {
								delete CONFIG.nonce; // Logged out since the page loaded.
							}
							return request( url, true );
						} );
					}
					if ( ! res.ok || typeof data.html !== 'string' ) {
						throw new Error( data.message || ( 'The request failed (HTTP ' + res.status + ').' ) );
					}
					return data;
				} );
			} );
		}

		function run() {
			if ( busy ) {
				return;
			}
			var url = normaliseUrl( urlInput.value );
			if ( ! url ) {
				setStatus( 'Enter a full web address, for example https://example.com/.', 'error' );
				urlInput.focus();
				return;
			}
			if ( ! CONFIG.endpoint ) {
				setStatus( 'The generator is not configured on this page.', 'error' );
				return;
			}

			var w = clamp( wInput.value, 240, 3840, 1300 );
			var h = clamp( hInput.value, 240, 2400, 900 );
			urlInput.value = url;
			wInput.value = w;
			hInput.value = h;
			syncPresets();

			busy = true;
			submit.disabled = true;
			root.setAttribute( 'aria-busy', 'true' );
			setStatus( 'Fetching the page and its stylesheets…', 'busy' );

			request( url, false ).then( function ( data ) {
				setStatus( 'Rendering the page at ' + w + ' × ' + h + '…', 'busy' );
				result.hidden = false; // Must be visible before rendering so layout is real.
				return renderFrame( stage, data.html, w, h ).then( function ( frame ) {
					return settle( frame ).then( function () {
						last = { frame: frame, data: data, url: url, w: w, h: h };
					} );
				} );
			} ).then( function () {
				setStatus( 'Picking out the above-the-fold rules…', 'busy' );
				return wait( 30 ); // Let the status paint before the heavy work.
			} ).then( function () {
				return extractAndShow();
			} ).catch( function ( err ) {
				setStatus( ( err && err.message ) || 'Something went wrong. Check the address and try again.', 'error' );
			} ).then( function () {
				busy = false;
				submit.disabled = false;
				root.removeAttribute( 'aria-busy' );
			} );
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			run();
		} );

		root.querySelectorAll( '.mbr-ccss__preset' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				wInput.value = btn.dataset.w;
				hInput.value = btn.dataset.h;
				syncPresets();
			} );
		} );
		wInput.addEventListener( 'input', syncPresets );
		hInput.addEventListener( 'input', syncPresets );
		syncPresets();

		// Changing output options re-extracts from the page already rendered.
		[ minifyInput, fontsInput ].forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				if ( last && ! busy ) {
					busy = true;
					submit.disabled = true;
					extractAndShow().catch( function () {} ).then( function () {
						busy = false;
						submit.disabled = false;
					} );
				}
			} );
		} );

		copyBtn.addEventListener( 'click', function () {
			var label = copyBtn.textContent;
			function done() {
				copyBtn.textContent = 'Copied';
				setTimeout( function () {
					copyBtn.textContent = label;
				}, 1800 );
			}
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( output.value ).then( done, function () {
					output.select();
					document.execCommand( 'copy' );
					done();
				} );
			} else {
				output.select();
				document.execCommand( 'copy' );
				done();
			}
		} );

		downloadBtn.addEventListener( 'click', function () {
			if ( ! last || ! output.value ) {
				return;
			}
			var host = 'page';
			try {
				host = new URL( last.url ).hostname.replace( /[^a-z0-9.-]/gi, '' );
			} catch ( e ) {}
			var blob = new Blob( [ output.value ], { type: 'text/css' } );
			var a = document.createElement( 'a' );
			a.href = URL.createObjectURL( blob );
			a.download = 'critical-' + host + '-' + last.w + 'x' + last.h + '.css';
			document.body.appendChild( a );
			a.click();
			setTimeout( function () {
				URL.revokeObjectURL( a.href );
				a.remove();
			}, 1000 );
		} );

		window.addEventListener( 'resize', function () {
			fitStage( stage );
		} );
	}

	function boot() {
		document.querySelectorAll( '[data-mbr-ccss]' ).forEach( init );
	}

	// Exposed for testing and for anyone wanting to reuse the engine.
	window.mbrCcss = {
		extract: extractCritical,
		_test: {
			splitTop: splitTop,
			cleanSelector: cleanSelector,
			minSelector: minSelector,
			minDecl: minDecl,
			normaliseUrl: normaliseUrl,
			compareRenders: compareRenders
		}
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
