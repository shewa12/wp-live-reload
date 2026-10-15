<?php
/**
 * Plugin Name: WP Live Reload (dev only)
 * Description: Automatically reloads the browser when JS/CSS files change. Pure PHP + JS, works with any build tool.
 * Version:     1.0.0
 *
 * INSTALL: save as wp-content/mu-plugins/wp-live-reload.php
 *
 * Active only when WP_DEBUG is true (or WPLR_ENABLED is true).
 *
 * Optional settings (define in wp-config.php, above "That's all, stop editing!"):
 *
 *   // Folders to watch. Default: active theme (+ parent theme).
 *   define( 'WPLR_PATHS', [
 *       WP_PLUGIN_DIR . '/my-plugin/build',
 *       get_theme_root() . '/my-theme/build',   // only works if called after theme setup; prefer absolute paths
 *   ] );
 *   define( 'WPLR_EXTENSIONS', [ 'js', 'mjs', 'css', 'json', 'php' ] ); // default: js, mjs, css, json
 *   define( 'WPLR_IGNORE', [ 'node_modules', '.git', 'vendor' ] );      // folder names to skip
 *   define( 'WPLR_INTERVAL', 1000 );   // poll interval in ms (min 300)
 *   define( 'WPLR_ADMIN', true );      // also reload inside wp-admin (set false to protect the block editor)
 *   define( 'WPLR_ENABLED', true );    // force on/off regardless of WP_DEBUG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wplr_enabled = defined( 'WPLR_ENABLED' ) ? (bool) WPLR_ENABLED : ( defined( 'WP_DEBUG' ) && WP_DEBUG );
if ( ! $wplr_enabled ) {
	return;
}

/**
 * Settings, with defaults.
 */
function wplr_config() {
	static $c = null;
	if ( null !== $c ) {
		return $c;
	}

	$default_paths = array_values( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) );

	$raw_paths = array_filter( (array) ( defined( 'WPLR_PATHS' ) ? WPLR_PATHS : $default_paths ) );

	// Resolve relative paths: try WP_CONTENT_DIR first, then ABSPATH.
	$resolved = array();
	foreach ( $raw_paths as $p ) {
		$p = rtrim( $p, '/\\' );
		if ( '' === $p ) {
			continue;
		}
		if ( is_dir( $p ) ) {
			$resolved[] = $p;
		} elseif ( defined( 'WP_CONTENT_DIR' ) && is_dir( WP_CONTENT_DIR . '/' . $p ) ) {
			$resolved[] = WP_CONTENT_DIR . '/' . $p;
		} elseif ( defined( 'ABSPATH' ) && is_dir( ABSPATH . $p ) ) {
			$resolved[] = ABSPATH . $p;
		}
		// else: silently skip genuinely non-existent paths.
	}

	$c = array(
		'paths'      => $resolved,
		'extensions' => array_map( 'strtolower', (array) ( defined( 'WPLR_EXTENSIONS' ) ? WPLR_EXTENSIONS : array( 'js', 'mjs', 'css', 'json' ) ) ),
		'ignore'     => (array) ( defined( 'WPLR_IGNORE' ) ? WPLR_IGNORE : array( 'node_modules', '.git', 'vendor' ) ),
		'interval'   => max( 300, (int) ( defined( 'WPLR_INTERVAL' ) ? WPLR_INTERVAL : 500 ) ),
		'admin'      => defined( 'WPLR_ADMIN' ) ? (bool) WPLR_ADMIN : true,
		'max_files'  => 20000,
	);

	return $c;
}

/**
 * Builds a fingerprint of all watched files (count + newest mtime + sum of mtimes and sizes).
 * It changes whenever a watched file is added, removed, edited or rewritten.
 */
function wplr_signature() {
	$c     = wplr_config();
	$count = 0;
	$max   = 0;
	$sum   = 0;
	$last  = '';

	clearstatcache();

	foreach ( $c['paths'] as $path ) {
		if ( ! is_dir( $path ) ) {
			continue;
		}

		try {
			$dir    = new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS );
			$filter = new RecursiveCallbackFilterIterator(
				$dir,
				function ( $file ) use ( $c ) {
					if ( $file->isDir() ) {
						return ! in_array( $file->getFilename(), $c['ignore'], true );
					}
					return in_array( strtolower( $file->getExtension() ), $c['extensions'], true );
				}
			);

			foreach ( new RecursiveIteratorIterator( $filter ) as $file ) {
				$count++;
				$mtime = $file->getMTime();
				$sum  += $mtime + $file->getSize();
				if ( $mtime > $max ) {
					$max  = $mtime;
					$last = $file->getPathname();
				}
				if ( $count >= $c['max_files'] ) {
					break 2;
				}
			}
		} catch ( Exception $e ) {
			continue;
		}
	}

	return array(
		'sig'  => $count . '-' . $max . '-' . $sum,
		'file' => $last,
	);
}

/**
 * Polling endpoint: /?wplr_poll=1
 */
add_action(
	'init',
	function () {
		if ( ! isset( $_GET['wplr_poll'] ) ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( wplr_signature() );
		exit;
	},
	0
);

/**
 * Client script printed on the front end, login screen and (optionally) wp-admin.
 */
function wplr_print_client() {
	$c    = wplr_config();
	$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	$url  = ( $path ? $path : '/' ) . '?wplr_poll=1';
	?>
<script id="wplr-client">
(function () {
	if (window.__wplr) return;
	window.__wplr = true;

	var url = <?php echo wp_json_encode( $url ); ?>;
	var every = <?php echo (int) $c['interval']; ?>;
	var last = null;     // fingerprint when this page loaded
	var pending = null;  // changed fingerprint waiting to be stable
	var busy = false;

	function tick() {
        
		if (busy || document.hidden) return;
		busy = true;
		fetch(url + '&_=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				busy = false;
				if (last === null) { last = d.sig; return; }
				if (d.sig === last) { pending = null; return; }
				// Changed. Reload only once the files stop changing (build finished).
				if (pending === d.sig) {
					console.log('[WP Live Reload] change detected: ' + d.file);
					location.reload();
				} else {
					pending = d.sig;
				}
			})
			.catch(function () { busy = false; });
	}

	setInterval(tick, every);
	document.addEventListener('visibilitychange', tick);
	tick();
})();
</script>
	<?php
}

add_action( 'wp_footer', 'wplr_print_client', 9999 );
add_action( 'login_footer', 'wplr_print_client', 9999 );
add_action(
	'admin_footer',
	function () {
		if ( wplr_config()['admin'] ) {
			wplr_print_client();
		}
	},
	9999
);
