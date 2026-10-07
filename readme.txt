=== MBR Critical CSS Generator ===
Contributors: robertpalmer
Tags: critical css, performance, above the fold, page speed
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A self-hosted Critical CSS generator. Your server fetches the page; the visitor's own browser renders it and extracts the above-the-fold CSS.

== Description ==

Add the [mbr_critical_css] shortcode to any page to give visitors a Critical CSS generator with URL, viewport width and viewport height inputs.

How it works:

1. The visitor enters a URL and a viewport size.
2. Your server fetches the page HTML and every stylesheet (including @import), makes url() paths absolute, strips scripts and event handlers, and returns one self-contained HTML document.
3. The visitor's browser loads that document into a sandboxed iframe at the exact viewport size. The sandbox allows same-origin access but not scripts, so nothing on the fetched page can run.
4. JavaScript walks every CSS rule and keeps those whose selectors match an element visible above the fold. Matching @media queries, @supports, @layer and @container wrappers are preserved, along with @font-face rules for fonts in use and @keyframes for animations in use.

No Node.js, no headless Chrome, no external services, no telemetry. Works on shared hosting.

= Shortcode =

[mbr_critical_css]
[mbr_critical_css width="375" height="812"]

The attributes set the starting viewport; visitors can change it.

= Security =

* Only public http/https addresses on standard ports can be fetched. Private, loopback and reserved IP ranges are refused, including after redirects (WordPress core's wp_safe_remote_get).
* Every job runs inside fixed budgets: a 30-second deadline checked throughout fetching and processing (with a guard that stops before PHP's memory limit), 60 stylesheet requests (failed requests count too), 10 MB of CSS downloaded, 12 MB of CSS written into the document, charged as it is produced, so URLs that grow when made absolute count too (repeated and imported stylesheets count every time), 25,000 resource references, URLs up to 2,048 characters, 100 @import expansions with circular imports detected, and a 16 MB final document.
* A site-wide cap on simultaneous jobs (default 3) stops the tool tying up your PHP workers. Extra visitors are asked to try again in a few seconds.
* Per-IP rate limiting with an atomic database counter, so simultaneous requests can't slip past it. Configurable under Settings > Critical CSS Generator. Administrators are exempt.
* Requests must carry this site's exact Origin (scheme, host and port). This is a supplementary check; the rate limit and job cap do the real work. For a busy public service, add rate limiting at your host or CDN as well.
* CSS is processed with a tokenizer rather than pattern matching, so only real url(), image-set() and @import references are touched; text inside strings and comments is left alone.
* The preview runs in a sandboxed frame without scripts and with a no-referrer policy. Image and font URLs pointing at private or local addresses, or at your own site, are removed, and the frame's Content Security Policy allows images and fonts only from the exact hosts that passed those checks. Browsers enforce that policy on every request, including redirects.
* Anonymous visitors are sent no REST nonce, so cached pages can't serve a stale one. Logged-in users get a nonce that refreshes itself if it expires.
* Optional "logged-in users only" mode.

= Limitations =

* Scripts on the target page don't run, so layouts built entirely by JavaScript (some sliders, client-rendered apps) may produce limited results. Common lazy-load attributes (data-src, data-srcset, data-lazy-src) are promoted so images still take up space.
* Web fonts served without CORS headers may not load in the preview. Layout is then measured with fallback fonts, which can slightly change what counts as above the fold. The @font-face rules are still included in the output.
* Iframes on the target page keep their size but aren't loaded.

= Styling =

The interface inherits your theme's font and text colour. Override the custom properties on .mbr-ccss to change the accent, for example:

.mbr-ccss { --mbr-ccss-accent: #7a3e9d; }

== Changelog ==

= 1.2.1 =
* Initial public release.
