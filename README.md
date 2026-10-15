# WP Live Reload

**Zero-dependency live reload for WordPress development.**

Automatically reloads the browser whenever watched files (JS, CSS, JSON, PHP, etc.) are added, edited, or deleted. Pure PHP + vanilla JS — no Node.js, no browser extension, no WebSocket server. Works with any build tool or without one.

---

## Quick Start

### 1. Install

Copy the plugin file into your MU plugins directory:

```bash
cp wp-live-reload.php /path/to/your/site/wp-content/mu-plugins/
```

That's it — MU plugins are loaded automatically. No activation required.

### 2. Enable

The plugin activates only when `WP_DEBUG` is `true`. Make sure your `wp-config.php` has:

```php
define( 'WP_DEBUG', true );
```

> **⚠️ Never enable `WP_DEBUG` in production.** This plugin is for local development only.

### 3. Verify

Open your site in the browser, open DevTools → Console, and edit any file in your active theme. Within ~2 seconds you should see:

```
[WP Live Reload] change detected: /path/to/changed-file.css
```

…followed by an automatic page reload.

---

## Configuration

All settings are optional. Define them in `wp-config.php` **above** the line `/* That's all, stop editing! */`.

### `WPLR_PATHS` — Directories to watch

**Default:** Active theme directory (+ parent theme if using a child theme).

```php
define( 'WPLR_PATHS', [
    __DIR__ . '/wp-content/plugins/my-plugin',
    __DIR__ . '/wp-content/themes/my-theme',
] );
```

> **Important:** Always use **absolute paths**. Use `__DIR__` (the directory containing `wp-config.php`, i.e., the WordPress root) to build them.
>
> Relative paths like `'plugins/my-plugin'` are also supported — the plugin will try to resolve them against `wp-content/` and then the WordPress root — but absolute paths are more reliable and recommended.

### `WPLR_EXTENSIONS` — File types to watch

**Default:** `['js', 'mjs', 'css', 'json']`

```php
define( 'WPLR_EXTENSIONS', [ 'js', 'mjs', 'css', 'json', 'php' ] );
```

### `WPLR_IGNORE` — Directory names to skip

**Default:** `['node_modules', '.git', 'vendor']`

```php
define( 'WPLR_IGNORE', [ 'node_modules', '.git', 'vendor', 'dist' ] );
```

### `WPLR_INTERVAL` — Polling interval (ms)

**Default:** `1000` (1 second) &nbsp;|&nbsp; **Minimum:** `300`

```php
define( 'WPLR_INTERVAL', 500 ); // faster detection
```

> Due to the two-tick debounce, actual reload delay = ~2× this value.

### `WPLR_ADMIN` — Enable inside wp-admin

**Default:** `true`

```php
define( 'WPLR_ADMIN', false ); // disable to protect the block editor from mid-edit reloads
```

### `WPLR_ENABLED` — Force on/off

Overrides the `WP_DEBUG` check entirely.

```php
define( 'WPLR_ENABLED', true );  // force on even if WP_DEBUG is false
define( 'WPLR_ENABLED', false ); // force off even if WP_DEBUG is true
```

---

## Full Example

```php
// wp-config.php

define( 'WP_DEBUG', true );

define( 'WPLR_PATHS', [
    __DIR__ . '/wp-content/plugins/my-plugin',
    __DIR__ . '/wp-content/plugins/my-other-plugin',
    __DIR__ . '/wp-content/themes/my-theme',
] );
define( 'WPLR_EXTENSIONS', [ 'js', 'css', 'json', 'php' ] );
define( 'WPLR_IGNORE', [ 'node_modules', '.git', 'vendor' ] );
define( 'WPLR_INTERVAL', 1000 );
define( 'WPLR_ADMIN', true );

/* That's all, stop editing! Happy publishing. */
```

---

## How It Works

```
 ┌──────────────┐    GET /?wplr_poll=1     ┌──────────────────┐
 │  Browser JS  │  ──────────────────────►  │  WordPress PHP   │
 │              │                           │                  │
 │  polls every │  ◄──────────────────────  │  scans files,    │
 │  N seconds   │    { sig, file }          │  returns hash    │
 └──────────────┘                           └──────────────────┘
```

1. **PHP endpoint** — On every poll (`/?wplr_poll=1`), the plugin recursively walks the watched directories, hashes a composite fingerprint from file count + newest mtime + sum of all sizes/mtimes, and returns it as JSON. The endpoint hooks into `init` at priority 0 and calls `exit` immediately — no theme, no DB queries, very lightweight.

2. **JS client** — A tiny `<script>` injected into the page footer polls the endpoint via `fetch()`. On the first response, it stores the signature as a baseline. On subsequent polls:
   - **Same signature** → no change, do nothing.
   - **Different signature, first time** → store as `pending` (build might still be running).
   - **Different signature, matches pending** → signature is stable, build is done → `location.reload()`.

   This **two-tick debounce** prevents reloading mid-build when tools write multiple files in rapid succession.

3. **Visibility optimization** — Polling pauses when the tab is in the background (`document.hidden`) and resumes on `visibilitychange`.

---

## Where It Runs

The reload script is injected into:

| Hook             | Where                        |
|------------------|------------------------------|
| `wp_footer`      | All front-end pages          |
| `login_footer`   | Login / registration screens |
| `admin_footer`   | wp-admin (if `WPLR_ADMIN` is `true`) |

---

## Troubleshooting

### Nothing reloads

1. **Check `WP_DEBUG`** — Is it `true` in `wp-config.php`?
2. **Check paths** — Open `/?wplr_poll=1` in your browser. If the response is `{"sig":"0-0-0","file":""}`, no files are being found. Verify your `WPLR_PATHS` point to existing directories with absolute paths.
3. **Check extensions** — If you're editing `.php` files but `WPLR_EXTENSIONS` only includes `['js', 'css']`, those changes won't be detected.
4. **Check the console** — Open DevTools → Console. If the `<script id="wplr-client">` tag isn't present in the page source, the hook isn't running (e.g., your theme might not call `wp_footer()`).

### Reloads happen during typing in the block editor

Set `WPLR_ADMIN` to `false`:

```php
define( 'WPLR_ADMIN', false );
```

### Polling feels slow

Lower the interval (minimum 300ms):

```php
define( 'WPLR_INTERVAL', 500 );
```

### Too many files — performance impact

Check how many files are being scanned:

```bash
find wp-content/plugins/my-plugin \
  -type f \( -name '*.js' -o -name '*.css' -o -name '*.json' -o -name '*.php' \) \
  | wc -l
```

If the count is very large (10,000+), narrow your `WPLR_PATHS` to specific subdirectories (e.g., `build/`, `assets/`) or add noisy directories to `WPLR_IGNORE`. The plugin caps scanning at 20,000 files.

---

## Uninstall

Delete the file:

```bash
rm wp-content/mu-plugins/wp-live-reload.php
```

Remove any `WPLR_*` constants from `wp-config.php`. That's all.

---

## License

MIT — use it however you like.
