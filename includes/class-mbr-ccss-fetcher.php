<?php
/**
 * Fetches a page and its stylesheets, inlines the CSS, and strips anything
 * executable so the visitor's browser can render it safely in a sandboxed iframe.
 *
 * Every job runs inside fixed budgets (time, requests, bytes downloaded, bytes
 * inserted, final size) so a hostile page cannot tie up a PHP worker or
 * multiply its output.
 *
 * @package MBR_Critical_CSS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thrown to stop a job that has hit a hard limit (time, memory, size).
 */
final class MBR_CCSS_Abort extends Exception {}

/**
 * Page fetcher.
 */
final class MBR_CCSS_Fetcher {

	const MAX_HTML_BYTES    = 3145728;  // 3 MB page HTML.
	const MAX_CSS_BYTES     = 2097152;  // 2 MB per stylesheet download.
	const MAX_DOWNLOAD_CSS  = 10485760; // 10 MB of CSS downloaded per job.
	const MAX_INSERTED_CSS  = 12582912; // 12 MB of CSS inserted into the document (duplicates included).
	const MAX_OUTPUT_BYTES  = 16777216; // 16 MB final document.
	const MAX_FETCHES       = 60;       // Stylesheet fetch attempts, successful or not, imports included.
	const MAX_IMPORTS       = 100;      // @import expansions per job.
	const MAX_IMPORT_DEPTH  = 3;
	const MAX_HOST_CHECKS   = 40;       // Distinct resource hosts validated per job.
	const MAX_URL_LENGTH    = 2048;     // Longest resource URL kept (longer ones are removed).
	const MAX_URL_OPS       = 25000;    // Resource references processed per job.
	const MEMORY_SHARE      = 0.6;      // Abort before using more than this share of PHP's memory_limit.
	const REQUEST_TIMEOUT   = 15;       // Seconds, per request.
	const JOB_DEADLINE      = 30;       // Seconds; checked throughout fetching and processing.

	/** CSS functions whose string arguments are URLs. */
	const URL_STRING_FUNCS = array( 'url', 'src', 'image-set', '-webkit-image-set', 'image', '-webkit-image' );

	/** @var int Resource references processed. */
	private $url_ops = 0;

	/** @var int Tokenizer steps (for periodic deadline and memory checks). */
	private $ops = 0;

	/** @var float Job start time. */
	private $started;

	/** @var int Bytes of CSS downloaded. */
	private $downloaded = 0;

	/** @var int Bytes of CSS inserted into the document. */
	private $inserted = 0;

	/** @var int Stylesheet fetch attempts. */
	private $attempts = 0;

	/** @var int Stylesheets fetched successfully. */
	private $sheet_count = 0;

	/** @var int Inline <style> blocks found. */
	private $inline_count = 0;

	/** @var int @import rules expanded. */
	private $imports = 0;

	/** @var int Resource references removed from the preview. */
	private $removed_refs = 0;

	/** @var string[] Messages for the user, keyed to avoid repeats. */
	private $warnings = array();

	/** @var array URL => array( css, url ) or null. */
	private $cache = array();

	/** @var array scheme://host:port => bool. */
	private $host_ok = array();

	/** @var string[] Hostnames this WordPress site answers to. */
	private $own_hosts = array();

	/** @var bool Whether the page being analysed is on this site. */
	private $page_is_own = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->started   = microtime( true );
		$this->own_hosts = array_values(
			array_unique(
				array_filter(
					array(
						strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
						strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
					)
				)
			)
		);
	}

	/**
	 * Fetch and prepare a page.
	 *
	 * @param string $url Page URL.
	 * @return array|WP_Error
	 */
	public function fetch_page( $url ) {
		try {
			return $this->run( $url );
		} catch ( MBR_CCSS_Abort $e ) {
			return new WP_Error( 'mbr_ccss_limit', $e->getMessage(), array( 'status' => $e->getCode() ? $e->getCode() : 413 ) );
		}
	}

	/**
	 * The job itself.
	 *
	 * @param string $url Page URL.
	 * @return array|WP_Error
	 * @throws MBR_CCSS_Abort When a hard limit is reached.
	 */
	private function run( $url ) {
		$url = $this->validate_url( $url );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$page = $this->get( $url, 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5', self::MAX_HTML_BYTES );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		if ( '' !== $page['type'] && false === strpos( $page['type'], 'html' ) ) {
			return new WP_Error( 'mbr_ccss_not_html', __( 'That address did not return an HTML page.', 'mbr-critical-css' ), array( 'status' => 422 ) );
		}
		if ( '' === trim( $page['body'] ) ) {
			return new WP_Error( 'mbr_ccss_empty', __( 'That page returned no content.', 'mbr-critical-css' ), array( 'status' => 422 ) );
		}
		if ( strlen( $page['body'] ) >= self::MAX_HTML_BYTES ) {
			$this->warn( 'html-cut', __( 'The page HTML was larger than 3 MB and was cut short.', 'mbr-critical-css' ) );
		}

		$this->page_is_own = in_array( strtolower( (string) wp_parse_url( $page['url'], PHP_URL_HOST ) ), $this->own_hosts, true );

		$doc = $this->load_dom( $page['body'] );
		unset( $page['body'] );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}

		$base_url = $this->document_base( $doc, $page['url'] );

		$this->inline_stylesheets( $doc, $base_url );
		$this->sanitise_dom( $doc );
		$this->promote_lazy_media( $doc );
		$this->filter_resources( $doc, $base_url ); // After promotion, so promoted URLs are checked too.
		$this->inject_head( $doc, $base_url );

		$html = $this->save_html( $doc );
		unset( $doc );

		if ( strlen( $html ) > self::MAX_OUTPUT_BYTES ) {
			return new WP_Error( 'mbr_ccss_too_large', __( 'That page and its stylesheets add up to more than this tool can process in one go.', 'mbr-critical-css' ), array( 'status' => 413 ) );
		}

		if ( $this->removed_refs ) {
			$this->warn(
				'refs',
				sprintf(
					/* translators: %d: number of references */
					_n(
						'%d image, font or media reference pointed at a private, local or blocked address. It was removed from the preview and from the output.',
						'%d image, font or media references pointed at private, local or blocked addresses. They were removed from the preview and from the output.',
						$this->removed_refs,
						'mbr-critical-css'
					),
					$this->removed_refs
				)
			);
		}

		return array(
			'html'         => $html,
			'url'          => $page['url'],
			'stylesheets'  => $this->sheet_count,
			'inlineStyles' => $this->inline_count,
			'cssBytes'     => $this->downloaded + $this->inline_bytes,
			'warnings'     => array_values( $this->warnings ),
		);
	}

	/** @var int Bytes of CSS in inline <style> blocks (for the stats line). */
	private $inline_bytes = 0;

	/* ------------------------------------------------------------------
	 * Budgets
	 * ------------------------------------------------------------------ */

	/**
	 * Seconds left in this job.
	 *
	 * @return float
	 */
	private function time_left() {
		return self::JOB_DEADLINE - ( microtime( true ) - $this->started );
	}

	/**
	 * Record a warning once.
	 *
	 * @param string $key     Dedupe key.
	 * @param string $message Message.
	 */
	private function warn( $key, $message ) {
		$this->warnings[ $key ] = $message;
	}

	/**
	 * Reserve room for CSS about to be inserted into the document.
	 *
	 * @param int $bytes Bytes.
	 * @return bool False when the insertion budget is spent.
	 */
	private function reserve_insert( $bytes ) {
		if ( $this->inserted + $bytes > self::MAX_INSERTED_CSS ) {
			$this->warn( 'insert-cap', __( 'The page includes more CSS than this tool processes in one go (counting repeated stylesheets), so some was left out.', 'mbr-critical-css' ) );
			return false;
		}
		$this->inserted += $bytes;
		return true;
	}

	/* ------------------------------------------------------------------
	 * Fetching
	 * ------------------------------------------------------------------ */

	/**
	 * Validate a user-supplied URL. Only public http(s) addresses get through;
	 * wp_http_validate_url() rejects private, loopback and reserved IP ranges.
	 *
	 * @param string $url URL.
	 * @return string|WP_Error
	 */
	private function validate_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );

		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return new WP_Error( 'mbr_ccss_invalid_url', __( 'Enter a full web address starting with http:// or https://.', 'mbr-critical-css' ), array( 'status' => 400 ) );
		}
		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'mbr_ccss_unsafe_url', __( 'That address cannot be fetched. Only public websites on standard ports are allowed.', 'mbr-critical-css' ), array( 'status' => 400 ) );
		}
		return $url;
	}

	/**
	 * Safe GET request, bounded by the job deadline. wp_safe_remote_get() also
	 * re-validates every redirect.
	 *
	 * @param string $url       URL.
	 * @param string $accept    Accept header.
	 * @param int    $max_bytes Response size cap.
	 * @return array|WP_Error { body, url, type }
	 */
	private function get( $url, $accept, $max_bytes ) {
		$left = $this->time_left();
		if ( $left < 1 ) {
			return new WP_Error( 'mbr_ccss_deadline', __( 'The time limit for this job was reached.', 'mbr-critical-css' ), array( 'status' => 504 ) );
		}

		$user_agent = apply_filters(
			'mbr_ccss_user_agent',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 MBR-Critical-CSS/' . MBR_CCSS_VERSION
		);

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => max( 1, min( self::REQUEST_TIMEOUT, (int) floor( $left ) ) ),
				'redirection'         => 5,
				'limit_response_size' => max( 1, (int) $max_bytes ),
				'user-agent'          => $user_agent,
				'headers'             => array(
					'Accept'          => $accept,
					'Accept-Language' => 'en-GB,en;q=0.9',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mbr_ccss_fetch_failed',
				/* translators: 1: URL, 2: error message */
				sprintf( __( 'Could not fetch %1$s (%2$s).', 'mbr-critical-css' ), $url, $response->get_error_message() ),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'mbr_ccss_bad_status',
				/* translators: 1: URL, 2: HTTP status code */
				sprintf( __( '%1$s responded with HTTP %2$d.', 'mbr-critical-css' ), $url, $code ),
				array( 'status' => 502 )
			);
		}

		// The final URL after redirects, so relative paths resolve against where the file really lives.
		$final = $url;
		if ( isset( $response['http_response'] ) && $response['http_response'] instanceof WP_HTTP_Requests_Response ) {
			$raw = $response['http_response']->get_response_object();
			if ( $raw && ! empty( $raw->url ) && preg_match( '#^https?://#i', $raw->url ) ) {
				$final = $raw->url;
			}
		}

		return array(
			'body' => (string) wp_remote_retrieve_body( $response ),
			'url'  => $final,
			'type' => strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) ),
		);
	}

	/**
	 * Fetch one stylesheet within the job's budgets. Every attempt counts towards
	 * the limit before the request is made, whether or not it succeeds.
	 *
	 * @param string $url Absolute stylesheet URL.
	 * @return array|null array( 'css' => string, 'url' => final URL ), or null.
	 */
	private function fetch_stylesheet( $url ) {
		if ( array_key_exists( $url, $this->cache ) ) {
			return $this->cache[ $url ];
		}

		if ( $this->attempts >= self::MAX_FETCHES ) {
			/* translators: %d: maximum number of stylesheet requests */
			$this->warn( 'max-fetches', sprintf( __( 'Only the first %d stylesheet requests were made.', 'mbr-critical-css' ), self::MAX_FETCHES ) );
			return null;
		}
		$allowance = self::MAX_DOWNLOAD_CSS - $this->downloaded;
		if ( $allowance <= 0 ) {
			$this->warn( 'download-cap', __( 'The page has more than 10 MB of CSS, so some stylesheets were skipped.', 'mbr-critical-css' ) );
			return null;
		}
		if ( $this->time_left() < 2 ) {
			$this->warn( 'deadline', __( 'The time limit for this job was reached, so some stylesheets were skipped.', 'mbr-critical-css' ) );
			return null;
		}
		if ( ! preg_match( '#^https?://#i', $url ) || ! wp_http_validate_url( $url ) ) {
			/* translators: %s: URL */
			$this->warn( 'private-' . md5( $url ), sprintf( __( 'Skipped a stylesheet that is not on a public address: %s', 'mbr-critical-css' ), $url ) );
			$this->cache[ $url ] = null;
			return null;
		}

		++$this->attempts; // Counted before the request, so failures can't bypass the limit.
		$limit = (int) min( self::MAX_CSS_BYTES, $allowance );
		$res   = $this->get( $url, 'text/css,*/*;q=0.1', $limit );

		if ( is_wp_error( $res ) ) {
			$this->warn( 'fail-' . md5( $url ), $res->get_error_message() );
			$this->cache[ $url ] = null;
			return null;
		}

		$this->downloaded += strlen( $res['body'] );

		if ( false !== strpos( $res['type'], 'html' ) ) {
			/* translators: %s: URL */
			$this->warn( 'html-' . md5( $url ), sprintf( __( 'A stylesheet returned a web page instead of CSS and was skipped: %s', 'mbr-critical-css' ), $url ) );
			$this->cache[ $url ] = null;
			return null;
		}
		if ( strlen( $res['body'] ) >= $limit ) {
			/* translators: %s: URL */
			$this->warn( 'cut-' . md5( $url ), sprintf( __( 'A stylesheet hit the size limit and was cut short: %s', 'mbr-critical-css' ), $url ) );
		}

		++$this->sheet_count;
		$this->cache[ $url ] = array(
			'css' => $res['body'],
			'url' => $res['url'],
		);
		return $this->cache[ $url ];
	}

	/* ------------------------------------------------------------------
	 * Resource URL policy
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the preview may load a resource from this URL. Blocks non-web schemes,
	 * private and local addresses, and this site itself (unless the page being
	 * analysed is on this site). Every origin that passes is recorded, and the
	 * preview's Content Security Policy allows exactly those origins and nothing else,
	 * so the browser enforces the same boundary on anything this parser misses and
	 * on every redirect.
	 *
	 * @param string $abs Absolute URL.
	 * @return bool
	 */
	private function resource_allowed( $abs ) {
		if ( 0 === stripos( $abs, 'data:' ) ) {
			return true;
		}
		if ( strlen( $abs ) > self::MAX_URL_LENGTH ) {
			return false;
		}
		$parts = wp_parse_url( $abs );
		if ( ! $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		if ( ! preg_match( '/^[a-z0-9.-]+$|^\[[0-9a-f:.]+\]$/', $host ) ) {
			return false; // Only plain hostnames, so the value is safe to place in the CSP.
		}
		if ( ! $this->page_is_own && in_array( $host, $this->own_hosts, true ) ) {
			return false;
		}

		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$origin = $scheme . '://' . $host . ( $port ? ':' . $port : '' );
		if ( ! array_key_exists( $origin, $this->host_ok ) ) {
			if ( count( $this->host_ok ) >= self::MAX_HOST_CHECKS || $this->time_left() < 1 ) {
				$this->warn( 'host-cap', __( 'The page loads resources from an unusually large number of hosts; some were left out of the preview.', 'mbr-critical-css' ) );
				return false;
			}
			$this->host_ok[ $origin ] = (bool) wp_http_validate_url( $abs );
		}
		return $this->host_ok[ $origin ];
	}

	/**
	 * Resolve and vet one resource reference.
	 *
	 * @param string $ref  Reference as written (already unescaped).
	 * @param string $base Base URL.
	 * @return string|false Absolute URL (or a same-document #fragment), or false if it must not load.
	 */
	private function vet_reference( $ref, $base ) {
		$ref = trim( $ref );
		if ( '' === $ref || '#' === $ref[0] ) {
			return $ref; // Same-document fragment (e.g. SVG filters); no request is made.
		}
		if ( ++$this->url_ops > self::MAX_URL_OPS ) {
			throw new MBR_CCSS_Abort( __( 'This page contains more resource references than this tool can process.', 'mbr-critical-css' ), 413 );
		}
		$abs = preg_match( '#^[a-z][a-z0-9+.-]*:#i', $ref ) ? $ref : WP_Http::make_absolute_url( $ref, $base );
		if ( ! $this->resource_allowed( $abs ) ) {
			++$this->removed_refs;
			return false;
		}
		return $abs;
	}

	/**
	 * Origins the preview's CSP allows images and fonts from.
	 *
	 * @return string
	 */
	private function csp_sources() {
		$allowed = array();
		foreach ( $this->host_ok as $origin => $ok ) {
			if ( $ok ) {
				$allowed[] = $origin;
			}
		}
		return trim( 'data: ' . implode( ' ', $allowed ) );
	}

	/* ------------------------------------------------------------------
	 * CSS processing: a small tokenizer, so only real url(), image-set()
	 * strings and @import rules are touched, never text inside strings or
	 * comments. Output is charged to the budget as it grows.
	 * ------------------------------------------------------------------ */

	/**
	 * Throw if the job has run out of time or is close to PHP's memory limit.
	 *
	 * @throws MBR_CCSS_Abort When a limit is reached.
	 */
	private function checkpoint() {
		if ( $this->time_left() < 0 ) {
			throw new MBR_CCSS_Abort( __( 'Processing this page took too long. Try a simpler page, or try again later.', 'mbr-critical-css' ), 504 );
		}
		static $limit = null;
		if ( null === $limit ) {
			$limit = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) : -1;
		}
		if ( $limit > 0 && memory_get_usage() > $limit * self::MEMORY_SHARE ) {
			throw new MBR_CCSS_Abort( __( 'This page is too large for this tool to process.', 'mbr-critical-css' ), 413 );
		}
	}

	/**
	 * Resolve CSS escapes (\41, \", backslash-newline).
	 *
	 * @param string $s Raw text.
	 * @return string
	 */
	private static function css_unescape( $s ) {
		if ( false === strpos( $s, '\\' ) ) {
			return $s;
		}
		return preg_replace_callback(
			'/\\\\(?:([0-9a-fA-F]{1,6})(?:\r\n|[ \t\r\n\f])?|(\r\n|[\r\n\f])|(.))/s',
			function ( $m ) {
				if ( '' !== $m[1] ) {
					$cp = hexdec( $m[1] );
					if ( 0 === $cp || $cp > 0x10FFFF || ( $cp >= 0xD800 && $cp <= 0xDFFF ) ) {
						$cp = 0xFFFD;
					}
					return self::utf8( $cp );
				}
				if ( isset( $m[2] ) && '' !== $m[2] ) {
					return '';
				}
				return isset( $m[3] ) ? $m[3] : '';
			},
			$s
		);
	}

	/**
	 * Encode a code point as UTF-8 (no mbstring dependency).
	 *
	 * @param int $cp Code point.
	 * @return string
	 */
	private static function utf8( $cp ) {
		if ( $cp < 0x80 ) {
			return chr( $cp );
		}
		if ( $cp < 0x800 ) {
			return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}
		if ( $cp < 0x10000 ) {
			return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}
		return chr( 0xF0 | ( $cp >> 18 ) ) . chr( 0x80 | ( ( $cp >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
	}

	/**
	 * A URL as a double-quoted CSS string.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function css_string( $url ) {
		return '"' . str_replace( array( '\\', '"', "\n", "\r", "\f" ), array( '\\\\', '\\"', '\\a ', '\\d ', '\\c ' ), $url ) . '"';
	}

	/**
	 * Index after the string starting at $i (CSS rules: escapes; an unescaped newline ends it).
	 *
	 * @param string $s CSS.
	 * @param int    $i Index of the opening quote.
	 * @return int
	 */
	private static function string_end( $s, $i ) {
		$q   = $s[ $i ];
		$len = strlen( $s );
		$j   = $i + 1;
		while ( $j < $len ) {
			$c = $s[ $j ];
			if ( '\\' === $c ) {
				$j += 2;
			} elseif ( $c === $q ) {
				return $j + 1;
			} elseif ( "\n" === $c || "\r" === $c || "\f" === $c ) {
				return $j; // Bad string: ends before the newline.
			} else {
				++$j;
			}
		}
		return $len;
	}

	/**
	 * Whether an identifier starts at $i.
	 *
	 * @param string $s CSS.
	 * @param int    $i Index.
	 * @return bool
	 */
	private static function ident_starts( $s, $i ) {
		$len = strlen( $s );
		if ( $i >= $len ) {
			return false;
		}
		$c = $s[ $i ];
		if ( ctype_alpha( $c ) || '_' === $c || ord( $c ) >= 0x80 ) {
			return true;
		}
		if ( '\\' === $c ) {
			return $i + 1 < $len && "\n" !== $s[ $i + 1 ] && "\r" !== $s[ $i + 1 ] && "\f" !== $s[ $i + 1 ];
		}
		if ( '-' === $c && $i + 1 < $len ) {
			$n = $s[ $i + 1 ];
			return ctype_alpha( $n ) || '_' === $n || '-' === $n || ord( $n ) >= 0x80 || ( '\\' === $n && self::ident_starts( $s, $i + 1 ) );
		}
		return false;
	}

	/**
	 * Index after the identifier starting at $i.
	 *
	 * @param string $s CSS.
	 * @param int    $i Index.
	 * @return int
	 */
	private static function ident_end( $s, $i ) {
		$len = strlen( $s );
		while ( $i < $len ) {
			$run = strspn( $s, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-', $i );
			$i  += $run;
			if ( $i < $len && ord( $s[ $i ] ) >= 0x80 ) {
				++$i;
				continue;
			}
			if ( $i + 1 < $len && '\\' === $s[ $i ] && "\n" !== $s[ $i + 1 ] && "\r" !== $s[ $i + 1 ] && "\f" !== $s[ $i + 1 ] ) {
				if ( ctype_xdigit( $s[ $i + 1 ] ) ) {
					$i += 1 + strspn( $s, '0123456789abcdefABCDEF', $i + 1, 6 );
					if ( $i < $len && false !== strpos( " \t\n\r\f", $s[ $i ] ) ) {
						++$i;
					}
				} else {
					$i += 2;
				}
				continue;
			}
			break;
		}
		return $i;
	}

	/**
	 * Index of the ; or { that ends an at-rule prelude starting at $i.
	 *
	 * @param string $s CSS.
	 * @param int    $i Index.
	 * @return int
	 */
	private static function prelude_end( $s, $i ) {
		$len   = strlen( $s );
		$depth = 0;
		while ( $i < $len ) {
			$c = $s[ $i ];
			if ( '"' === $c || "'" === $c ) {
				$i = self::string_end( $s, $i );
				continue;
			}
			if ( '/' === $c && $i + 1 < $len && '*' === $s[ $i + 1 ] ) {
				$e = strpos( $s, '*/', $i + 2 );
				$i = false === $e ? $len : $e + 2;
				continue;
			}
			if ( '\\' === $c ) {
				$i += 2;
				continue;
			}
			if ( '(' === $c ) {
				++$depth;
			} elseif ( ')' === $c && $depth > 0 ) {
				--$depth;
			} elseif ( ( ';' === $c || '{' === $c ) && 0 === $depth ) {
				return $i;
			}
			++$i;
		}
		return $len;
	}

	/**
	 * Charge growth in the output (e.g. a short relative URL becoming a long absolute one).
	 *
	 * @param int $old_len Bytes replaced.
	 * @param int $new_len Bytes written.
	 * @return bool False when the budget is spent.
	 */
	private function charge_growth( $old_len, $new_len ) {
		return $new_len <= $old_len || $this->reserve_insert( $new_len - $old_len );
	}

	/**
	 * Process one stylesheet's text. Returns '' if it can't be fitted into the budget.
	 *
	 * @param string   $css      CSS.
	 * @param string   $base_url URL the CSS really came from (after redirects).
	 * @param string[] $stack    Chain of stylesheet URLs being expanded (cycle detection).
	 * @return string
	 */
	private function process_css( $css, $base_url, array $stack ) {
		$css = str_replace( "\0", "\xEF\xBF\xBD", (string) $css );
		$css = preg_replace( '/^\xEF\xBB\xBF/', '', $css );
		if ( ! $this->reserve_insert( strlen( $css ) ) ) {
			return '';
		}
		$out = $this->transform_css( $css, $base_url, $stack, true );
		return null === $out ? '' : $out;
	}

	/**
	 * Tokenize CSS and rewrite only real resource references.
	 *
	 * @param string   $css          CSS (already charged to the budget).
	 * @param string   $base         Base URL.
	 * @param string[] $stack        Import chain.
	 * @param bool     $allow_import Whether @import / @charset may appear (not in style attributes).
	 * @return string|null Null if the budget ran out part-way.
	 */
	private function transform_css( $css, $base, array $stack, $allow_import ) {
		$len    = strlen( $css );
		$out    = '';
		$i      = 0;
		$fn     = array(); // Open parentheses: function name, or '' for a plain bracket.
		$braces = 0;
		$plain  = "!#$%&*+,./0123456789:;<=>?[]^`|~ \t\n\r\f";

		while ( $i < $len ) {
			if ( 0 === ( ++$this->ops & 1023 ) ) {
				$this->checkpoint();
			}

			// Fast path: characters that can't start anything we care about.
			$run = strspn( $css, $plain, $i );
			if ( $run ) {
				$chunk = substr( $css, $i, $run );
				$cpos  = strpos( $chunk, '/*' );
				if ( false !== $cpos ) {
					$chunk = substr( $chunk, 0, $cpos );
					$run   = $cpos;
				}
				$out .= $chunk;
				$i   += $run;
				if ( $i >= $len ) {
					break;
				}
			}

			$c = $css[ $i ];

			// Comments are copied untouched; nothing inside them is acted on.
			if ( '/' === $c && $i + 1 < $len && '*' === $css[ $i + 1 ] ) {
				$e    = strpos( $css, '*/', $i + 2 );
				$e    = false === $e ? $len : $e + 2;
				$out .= substr( $css, $i, $e - $i );
				$i    = $e;
				continue;
			}

			// Strings: copied as written, unless they sit directly inside a function that
			// takes URLs as strings (image-set("a.png" 1x), url("..."), src("...")).
			if ( '"' === $c || "'" === $c ) {
				$e   = self::string_end( $css, $i );
				$raw = substr( $css, $i, $e - $i );
				$top = end( $fn );
				if ( false !== $top && in_array( $top, self::URL_STRING_FUNCS, true ) ) {
					$inner = substr( $raw, 1, ( strlen( $raw ) > 1 && substr( $raw, -1 ) === $c ) ? -1 : strlen( $raw ) - 1 );
					$abs   = $this->vet_reference( self::css_unescape( $inner ), $base );
					if ( false === $abs ) {
						$rep = '"data:,"';
					} elseif ( '' === $abs || '#' === $abs[0] ) {
						$rep = $raw;
					} else {
						$rep = self::css_string( $abs );
					}
					if ( ! $this->charge_growth( strlen( $raw ), strlen( $rep ) ) ) {
						return null;
					}
					$out .= $rep;
				} else {
					$out .= $raw;
				}
				$i = $e;
				continue;
			}

			// At-rules: @import is inlined, @charset is dropped (only valid at the very start).
			if ( '@' === $c && self::ident_starts( $css, $i + 1 ) ) {
				$e    = self::ident_end( $css, $i + 1 );
				$name = strtolower( self::css_unescape( substr( $css, $i + 1, $e - $i - 1 ) ) );
				if ( $allow_import && 0 === $braces && empty( $fn ) && ( 'import' === $name || 'charset' === $name ) ) {
					$p       = self::prelude_end( $css, $e );
					$prelude = substr( $css, $e, $p - $e );
					$i       = ( $p < $len && ';' === $css[ $p ] ) ? $p + 1 : $p;
					if ( 'import' === $name ) {
						$out .= $this->expand_import_prelude( $prelude, $base, $stack );
					}
					continue;
				}
				$out .= substr( $css, $i, $e - $i );
				$i    = $e;
				continue;
			}

			// Identifiers and functions, including escaped names such as u\72l(.
			if ( self::ident_starts( $css, $i ) ) {
				$e   = self::ident_end( $css, $i );
				$raw = substr( $css, $i, $e - $i );
				if ( $e < $len && '(' === $css[ $e ] ) {
					$name = strtolower( self::css_unescape( $raw ) );
					if ( 'url' === $name ) {
						$k = $e + 1 + strspn( $css, " \t\n\r\f", $e + 1 );
						if ( $k < $len && '"' !== $css[ $k ] && "'" !== $css[ $k ] ) {
							// Unquoted url(...): the whole argument is the URL.
							$end = $this->unquoted_url_end( $css, $k );
							if ( null !== $end ) {
								$arg  = rtrim( substr( $css, $k, $end - $k ), " \t\n\r\f" );
								$abs  = $this->vet_reference( self::css_unescape( $arg ), $base );
								$rep  = false === $abs ? 'url("data:,")' : ( ( '' === $abs || '#' === $abs[0] ) ? substr( $css, $i, $end + 1 - $i ) : 'url(' . self::css_string( $abs ) . ')' );
								$from = $end + 1 - $i;
								if ( ! $this->charge_growth( $from, strlen( $rep ) ) ) {
									return null;
								}
								$out .= $rep;
								$i    = $end + 1;
								continue;
							}
						}
					}
					$fn[] = $name;
					$out .= $raw . '(';
					$i    = $e + 1;
					continue;
				}
				$out .= $raw;
				$i    = $e;
				continue;
			}

			if ( '\\' === $c ) {
				$out .= substr( $css, $i, 2 );
				$i   += 2;
				continue;
			}
			if ( '(' === $c ) {
				$fn[] = '';
			} elseif ( ')' === $c ) {
				array_pop( $fn );
			} elseif ( '{' === $c ) {
				++$braces;
			} elseif ( '}' === $c && $braces > 0 ) {
				--$braces;
			}
			$out .= $c;
			++$i;
		}

		return $out;
	}

	/**
	 * Index of the ')' closing an unquoted url( starting at $k, or null if it's malformed
	 * (a quote, bracket or stray whitespace inside), in which case it's left untouched.
	 *
	 * @param string $s CSS.
	 * @param int    $k Index of the first argument character.
	 * @return int|null
	 */
	private function unquoted_url_end( $s, $k ) {
		$len = strlen( $s );
		while ( $k < $len ) {
			$c = $s[ $k ];
			if ( ')' === $c ) {
				return $k;
			}
			if ( '\\' === $c ) {
				$k += 2;
				continue;
			}
			if ( false !== strpos( " \t\n\r\f", $c ) ) {
				$k += strspn( $s, " \t\n\r\f", $k );
				return ( $k < $len && ')' === $s[ $k ] ) ? $k : null;
			}
			if ( '"' === $c || "'" === $c || '(' === $c ) {
				return null;
			}
			++$k;
		}
		return null;
	}

	/**
	 * Expand one @import from its prelude text: url("x") or "x", then optional
	 * layer / layer(name), supports(...), and a media query list.
	 *
	 * @param string   $prelude  Text after "@import".
	 * @param string   $base_url Base URL of the importing sheet.
	 * @param string[] $stack    Expansion chain.
	 * @return string
	 */
	private function expand_import_prelude( $prelude, $base_url, array $stack ) {
		$p    = ltrim( $prelude, " \t\n\r\f" );
		$href = '';
		$rest = '';
		if ( '' !== $p && ( '"' === $p[0] || "'" === $p[0] ) ) {
			$e    = self::string_end( $p, 0 );
			$href = self::css_unescape( substr( $p, 1, max( 0, $e - 2 ) ) );
			$rest = substr( $p, $e );
		} elseif ( self::ident_starts( $p, 0 ) ) {
			$e = self::ident_end( $p, 0 );
			if ( 'url' === strtolower( self::css_unescape( substr( $p, 0, $e ) ) ) && $e < strlen( $p ) && '(' === $p[ $e ] ) {
				$k = $e + 1 + strspn( $p, " \t\n\r\f", $e + 1 );
				if ( $k < strlen( $p ) && ( '"' === $p[ $k ] || "'" === $p[ $k ] ) ) {
					$se   = self::string_end( $p, $k );
					$href = self::css_unescape( substr( $p, $k + 1, max( 0, $se - $k - 2 ) ) );
					$close = strpos( $p, ')', $se );
				} else {
					$close = $this->unquoted_url_end( $p, $k );
					$href  = null === $close ? '' : self::css_unescape( rtrim( substr( $p, $k, $close - $k ) ) );
				}
				$rest = false === $close || null === $close ? '' : substr( $p, $close + 1 );
			}
		}
		return $this->expand_import( trim( $href ), trim( $rest ), $base_url, $stack );
	}

	/**
	 * Expand one @import.
	 *
	 * @param string   $href     Import URL as written.
	 * @param string   $cond     Conditions after the URL.
	 * @param string   $base_url Base URL of the importing sheet.
	 * @param string[] $stack    Expansion chain.
	 * @return string
	 */
	private function expand_import( $href, $cond, $base_url, array $stack ) {
		if ( '' === $href ) {
			return '';
		}
		if ( count( $stack ) >= self::MAX_IMPORT_DEPTH ) {
			$this->warn( 'import-depth', __( 'Some deeply nested @import rules were skipped.', 'mbr-critical-css' ) );
			return '';
		}
		if ( $this->imports >= self::MAX_IMPORTS ) {
			$this->warn( 'import-cap', __( 'The page uses an unusually large number of @import rules; some were skipped.', 'mbr-critical-css' ) );
			return '';
		}

		$url = WP_Http::make_absolute_url( $href, $base_url );
		if ( in_array( $url, $stack, true ) ) {
			$this->warn( 'import-loop', __( 'A circular @import was found and skipped.', 'mbr-critical-css' ) );
			return '';
		}

		$layer = null;
		if ( preg_match( '/^layer(?:\(\s*([^)]*?)\s*\))?(?=\s|$)\s*/i', $cond, $lm ) || preg_match( '/^layer\(\s*([^)]*?)\s*\)\s*/i', $cond, $lm ) ) {
			$layer = isset( $lm[1] ) ? $lm[1] : '';
			$cond  = substr( $cond, strlen( $lm[0] ) );
		}
		$supports = null;
		if ( preg_match( '/^supports\(((?:[^()]|\((?:[^()]|\([^()]*\))*\))*)\)\s*/i', $cond, $sm ) ) {
			$supports = trim( $sm[1] );
			$cond     = substr( $cond, strlen( $sm[0] ) );
			if ( '' !== $supports && '(' !== $supports[0] && ! preg_match( '/^(not|selector|font-tech|font-format)\b/i', $supports ) ) {
				$supports = '(' . $supports . ')';
			}
		}
		$media = trim( $cond );
		if ( 'print' === strtolower( $media ) ) {
			return '';
		}

		++$this->imports;
		$child = $this->fetch_stylesheet( $url );
		if ( null === $child ) {
			return '';
		}

		$stack[] = $url;
		$inner   = $this->process_css( $child['css'], $child['url'], $stack );
		if ( '' === $inner ) {
			return '';
		}

		if ( null !== $layer ) {
			$inner = '@layer' . ( '' !== $layer ? ' ' . $layer : '' ) . " {\n" . $inner . "\n}";
		}
		if ( '' !== $media && 'all' !== strtolower( $media ) ) {
			$inner = '@media ' . $media . " {\n" . $inner . "\n}";
		}
		if ( null !== $supports && '' !== $supports ) {
			$inner = '@supports ' . $supports . " {\n" . $inner . "\n}";
		}
		return "\n" . $inner . "\n";
	}

	/* ------------------------------------------------------------------
	 * DOM work
	 * ------------------------------------------------------------------ */

	/**
	 * Parse HTML into a DOMDocument.
	 *
	 * @param string $html HTML.
	 * @return DOMDocument|WP_Error
	 */
	private function load_dom( $html ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return new WP_Error( 'mbr_ccss_no_dom', __( 'The PHP DOM extension is required on this server.', 'mbr-critical-css' ), array( 'status' => 500 ) );
		}

		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// The XML encoding hint makes libxml treat the markup as UTF-8.
		$loaded = $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_HTML_NODEFDTD | LIBXML_PARSEHUGE );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return new WP_Error( 'mbr_ccss_parse_failed', __( 'The page HTML could not be read.', 'mbr-critical-css' ), array( 'status' => 422 ) );
		}

		foreach ( iterator_to_array( $doc->childNodes ) as $child ) {
			if ( XML_PI_NODE === $child->nodeType ) {
				$doc->removeChild( $child );
			}
		}

		return $doc;
	}

	/**
	 * Respect an existing <base href> when resolving relative URLs.
	 *
	 * @param DOMDocument $doc      Document.
	 * @param string      $page_url Final page URL.
	 * @return string
	 */
	private function document_base( DOMDocument $doc, $page_url ) {
		$bases = $doc->getElementsByTagName( 'base' );
		if ( $bases->length ) {
			$href = trim( $bases->item( 0 )->getAttribute( 'href' ) );
			if ( '' !== $href ) {
				$abs = WP_Http::make_absolute_url( $href, $page_url );
				if ( preg_match( '#^https?://#i', $abs ) ) {
					return $abs;
				}
			}
		}
		return $page_url;
	}

	/**
	 * Replace every <link rel="stylesheet"> and <style> with an inline, processed <style>,
	 * keeping document order so the cascade is unchanged.
	 *
	 * @param DOMDocument $doc      Document.
	 * @param string      $base_url Base URL.
	 */
	private function inline_stylesheets( DOMDocument $doc, $base_url ) {
		$xpath = new DOMXPath( $doc );
		$nodes = array();
		foreach ( $xpath->query( '//link | //style' ) as $node ) {
			$nodes[] = $node;
		}

		foreach ( $nodes as $node ) {
			$this->checkpoint();
			if ( ! $node->parentNode ) {
				continue;
			}

			if ( 'style' === strtolower( $node->nodeName ) ) {
				$type = strtolower( trim( $node->getAttribute( 'type' ) ) );
				if ( '' !== $type && 'text/css' !== $type ) {
					$node->parentNode->removeChild( $node );
					continue;
				}
				$text = $node->textContent;
				if ( '' !== trim( $text ) ) {
					++$this->inline_count;
					$this->inline_bytes += strlen( $text );
				}
				$css = $this->process_css( $text, $base_url, array() );
				$this->replace_with_style( $doc, $node, $css, $node->getAttribute( 'media' ), '' );
				continue;
			}

			// <link> elements.
			$rel    = ' ' . strtolower( preg_replace( '/\s+/', ' ', $node->getAttribute( 'rel' ) ) ) . ' ';
			$onload = strtolower( $node->getAttribute( 'onload' ) );

			// Common async-CSS pattern: rel="preload" as="style" onload="this.rel='stylesheet'".
			$is_preloaded_css = false !== strpos( $rel, ' preload ' )
				&& 'style' === strtolower( $node->getAttribute( 'as' ) )
				&& false !== strpos( $onload, 'stylesheet' );

			if ( false === strpos( $rel, ' stylesheet ' ) && ! $is_preloaded_css ) {
				continue; // Other <link> types are removed by sanitise_dom().
			}
			if ( false !== strpos( $rel, ' alternate ' ) || $node->hasAttribute( 'disabled' ) ) {
				$node->parentNode->removeChild( $node );
				continue;
			}

			$href = trim( $node->getAttribute( 'href' ) );
			if ( '' === $href ) {
				$node->parentNode->removeChild( $node );
				continue;
			}

			$media = trim( $node->getAttribute( 'media' ) );
			// Common async-CSS pattern: media="print" onload="this.media='all'".
			if ( 'print' === strtolower( $media ) && false !== strpos( $onload, 'media' ) ) {
				$media = 'all';
			}
			if ( 'print' === strtolower( $media ) ) {
				$node->parentNode->removeChild( $node );
				continue;
			}

			$sheet = $this->fetch_stylesheet( WP_Http::make_absolute_url( $href, $base_url ) );
			if ( null === $sheet ) {
				$node->parentNode->removeChild( $node );
				continue;
			}

			$css = $this->process_css( $sheet['css'], $sheet['url'], array( $sheet['url'] ) );
			$this->replace_with_style( $doc, $node, $css, $media, $sheet['url'] );
		}
	}

	/**
	 * Swap a node for an inline <style>.
	 *
	 * @param DOMDocument $doc    Document.
	 * @param DOMNode     $node   Node to replace.
	 * @param string      $css    CSS.
	 * @param string      $media  Media attribute.
	 * @param string      $source Original stylesheet URL, if any.
	 */
	private function replace_with_style( DOMDocument $doc, DOMNode $node, $css, $media, $source ) {
		$media = trim( (string) $media );
		if ( 'print' === strtolower( $media ) || '' === $css ) {
			$node->parentNode->removeChild( $node );
			return;
		}

		// Stop the CSS from closing the <style> element early.
		$css = str_ireplace( '</style', '<\/style', $css );

		$style = $doc->createElement( 'style' );
		if ( '' !== $media && 'all' !== strtolower( $media ) ) {
			$style->setAttribute( 'media', $media );
		}
		if ( '' !== $source ) {
			$style->setAttribute( 'data-mbr-source', $source );
		}
		$style->appendChild( $doc->createTextNode( $css ) );
		$node->parentNode->replaceChild( $style, $node );
	}

	/**
	 * Strip anything executable or navigational. The iframe sandbox already blocks
	 * scripts; this is a second layer of protection.
	 *
	 * @param DOMDocument $doc Document.
	 */
	private function sanitise_dom( DOMDocument $doc ) {
		$xpath = new DOMXPath( $doc );

		$remove = $xpath->query( '//script | //noscript | //template | //object | //embed | //applet | //frame | //frameset | //base | //link | //meta[@http-equiv] | //meta[@name="referrer"]' );
		foreach ( iterator_to_array( $remove ) as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}

		// Keep iframes for layout (e.g. a video embed above the fold) but don't load them.
		foreach ( iterator_to_array( $doc->getElementsByTagName( 'iframe' ) ) as $frame ) {
			$frame->removeAttribute( 'srcdoc' );
			$frame->setAttribute( 'src', 'about:blank' );
		}

		foreach ( $xpath->query( '//*[@*]' ) as $el ) {
			$drop = array();
			foreach ( $el->attributes as $attr ) {
				$name = strtolower( $attr->nodeName );
				if ( 0 === strpos( $name, 'on' ) ) {
					$drop[] = $attr->nodeName;
				} elseif ( in_array( $name, array( 'href', 'action', 'formaction' ), true ) && preg_match( '/^\s*(?:javascript|vbscript|data):/i', $attr->value ) ) {
					$drop[] = $attr->nodeName;
				}
			}
			foreach ( $drop as $name ) {
				$el->removeAttribute( $name );
			}
		}
	}

	/**
	 * Lazy-load scripts can't run, so promote data-src / data-srcset to the real attributes.
	 *
	 * @param DOMDocument $doc Document.
	 */
	private function promote_lazy_media( DOMDocument $doc ) {
		foreach ( array( 'img', 'source' ) as $tag ) {
			foreach ( $doc->getElementsByTagName( $tag ) as $el ) {
				foreach ( array( 'src', 'srcset', 'sizes' ) as $attr ) {
					foreach ( array( 'data-lazy-' . $attr, 'data-' . $attr ) as $lazy ) {
						$value = trim( $el->getAttribute( $lazy ) );
						if ( '' !== $value ) {
							$el->setAttribute( $attr, $value ); // Vetted later by filter_resources().
							$el->removeAttribute( $lazy );
							break;
						}
					}
				}
			}
		}
	}

	/**
	 * Vet every resource URL the preview could request: src, srcset, poster, legacy
	 * background attributes, SVG image/use references and inline style url()s.
	 *
	 * @param DOMDocument $doc      Document.
	 * @param string      $base_url Base URL.
	 */
	private function filter_resources( DOMDocument $doc, $base_url ) {
		$xpath   = new DOMXPath( $doc );
		$single  = array( 'src', 'poster', 'background' );
		$svgrefs = array( 'image', 'use', 'feimage' );
		$count   = 0;

		foreach ( $xpath->query( '//*[@*]' ) as $el ) {
			if ( 0 === ( ++$count & 255 ) ) {
				$this->checkpoint();
			}
			$tag = strtolower( $el->nodeName );

			foreach ( $single as $attr ) {
				if ( ! $el->hasAttribute( $attr ) || 'iframe' === $tag ) {
					continue;
				}
				$this->set_vetted( $el, $attr, $this->vet_reference( self::html_attr_url( $el->getAttribute( $attr ) ), $base_url ) );
			}

			foreach ( array( 'srcset', 'imagesrcset' ) as $attr ) {
				if ( $el->hasAttribute( $attr ) ) {
					$old = $el->getAttribute( $attr );
					$new = $this->filter_srcset( $old, $base_url );
					if ( ! $this->charge_growth( strlen( $old ), strlen( $new ) ) ) {
						throw new MBR_CCSS_Abort( __( 'This page is too large for this tool to process.', 'mbr-critical-css' ), 413 );
					}
					$el->setAttribute( $attr, $new );
				}
			}

			if ( in_array( $tag, $svgrefs, true ) ) {
				foreach ( array( 'href', 'xlink:href' ) as $attr ) {
					if ( $el->hasAttribute( $attr ) ) {
						$this->set_vetted( $el, $attr, $this->vet_reference( self::html_attr_url( $el->getAttribute( $attr ) ), $base_url ) );
					}
				}
			}

			if ( $el->hasAttribute( 'style' ) ) {
				$old = $el->getAttribute( 'style' );
				$new = $this->transform_css( $old, $base_url, array(), false );
				if ( null === $new ) {
					throw new MBR_CCSS_Abort( __( 'This page is too large for this tool to process.', 'mbr-critical-css' ), 413 );
				}
				$el->setAttribute( 'style', $new );
			}
		}
	}

	/**
	 * HTML URL attributes ignore leading/trailing ASCII whitespace.
	 *
	 * @param string $v Attribute value.
	 * @return string
	 */
	private static function html_attr_url( $v ) {
		return trim( (string) $v, " \t\n\r\f" );
	}

	/**
	 * Write a vetted URL back to an attribute, or remove the attribute.
	 *
	 * @param DOMElement   $el   Element.
	 * @param string       $attr Attribute.
	 * @param string|false $abs  Result of vet_reference().
	 */
	private function set_vetted( $el, $attr, $abs ) {
		if ( false === $abs ) {
			$el->removeAttribute( $attr );
			return;
		}
		$old = $el->getAttribute( $attr );
		if ( ! $this->charge_growth( strlen( $old ), strlen( $abs ) ) ) {
			throw new MBR_CCSS_Abort( __( 'This page is too large for this tool to process.', 'mbr-critical-css' ), 413 );
		}
		$el->setAttribute( $attr, $abs );
	}

	/**
	 * Parse a srcset the way browsers do (HTML spec "parse a srcset attribute"): candidates
	 * are separated by commas, with or without whitespace, and a URL may itself contain
	 * commas. Every candidate is vetted individually.
	 *
	 * @param string $srcset   srcset value.
	 * @param string $base_url Base URL.
	 * @return string
	 */
	private function filter_srcset( $srcset, $base_url ) {
		$s   = (string) $srcset;
		$len = strlen( $s );
		$i   = 0;
		$out = array();
		$ws  = " \t\n\r\f";
		while ( true ) {
			$i += strspn( $s, $ws . ',', $i );
			if ( $i >= $len ) {
				break;
			}
			$start = $i;
			$i    += strcspn( $s, $ws, $i );
			$url   = substr( $s, $start, $i - $start );
			$desc  = '';
			if ( ',' === substr( $url, -1 ) ) {
				$url = rtrim( $url, ',' );
			} else {
				$depth  = 0;
				$dstart = $i;
				while ( $i < $len ) {
					$c = $s[ $i ];
					if ( '(' === $c ) {
						++$depth;
					} elseif ( ')' === $c && $depth > 0 ) {
						--$depth;
					} elseif ( ',' === $c && 0 === $depth ) {
						break;
					}
					++$i;
				}
				$desc = trim( substr( $s, $dstart, $i - $dstart ), $ws );
				++$i; // Past the comma.
			}
			if ( '' === $url ) {
				continue;
			}
			$abs = $this->vet_reference( $url, $base_url );
			if ( false !== $abs && '' !== $abs ) {
				$out[] = $abs . ( '' !== $desc ? ' ' . $desc : '' );
			}
		}
		return implode( ', ', $out );
	}

	/**
	 * Add the preview's own head: a restrictive Content Security Policy, a
	 * no-referrer policy and <base href> so relative URLs resolve.
	 *
	 * @param DOMDocument $doc      Document.
	 * @param string      $base_url Base URL.
	 */
	private function inject_head( DOMDocument $doc, $base_url ) {
		$head = $doc->getElementsByTagName( 'head' )->item( 0 );
		if ( ! $head ) {
			$html = $doc->getElementsByTagName( 'html' )->item( 0 );
			if ( ! $html ) {
				return;
			}
			$head = $doc->createElement( 'head' );
			$html->insertBefore( $head, $html->firstChild );
		}

		// Images and fonts only from the exact origins vetted above. The browser enforces this
		// on every request, including redirects, whatever syntax the page used.
		$sources = $this->csp_sources();
		$policy  = "default-src 'none'; style-src 'unsafe-inline'; img-src {$sources}; font-src {$sources}; "
			. "script-src 'none'; connect-src 'none'; frame-src 'none'; child-src 'none'; worker-src 'none'; "
			. "object-src 'none'; media-src 'none'; manifest-src 'none'; form-action 'none'";

		$base = $doc->createElement( 'base' );
		$base->setAttribute( 'href', $base_url );
		$head->insertBefore( $base, $head->firstChild );

		$ref = $doc->createElement( 'meta' );
		$ref->setAttribute( 'name', 'referrer' );
		$ref->setAttribute( 'content', 'no-referrer' );
		$head->insertBefore( $ref, $head->firstChild );

		$csp = $doc->createElement( 'meta' );
		$csp->setAttribute( 'http-equiv', 'Content-Security-Policy' );
		$csp->setAttribute( 'content', $policy );
		$head->insertBefore( $csp, $head->firstChild );
	}

	/**
	 * Serialise the document with a standards-mode doctype.
	 *
	 * @param DOMDocument $doc Document.
	 * @return string
	 */
	private function save_html( DOMDocument $doc ) {
		$html = (string) $doc->saveHTML();
		$html = preg_replace( '/^\s*<!DOCTYPE[^>]*>\s*/i', '', $html );
		return "<!DOCTYPE html>\n" . $html;
	}
}
