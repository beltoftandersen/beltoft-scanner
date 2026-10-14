# Beltoft Scanner

Scan product barcodes with the phone camera straight into the WooCommerce cart.

The scanner is a **core that other plugins plug into**. On its own it shows nowhere and answers nobody: a plugin decides on which pages it appears, who may use it, and which products that person may order. On mindthetrash the B2B ordering list (`beltoft-commerce`) and the shop till (`beltoft-pos`) use it.

## Features

- Reads EAN-13 and EAN-8 barcodes with the camera: the browser's own `BarcodeDetector` where there is one, otherwise the bundled ZXing build (loaded only then, mostly on iOS). No external service. Torch toggle where the camera has one; the screen is kept awake while scanning.
- A manual field takes an EAN or a SKU, for codes the camera cannot read. The camera does not read SKU barcodes (Code 128 etc.) - only EAN.
- Looks the code up by GTIN (`_global_unique_id`, variations first), then by SKU.
- Adds to the WooCommerce cart in one call per scan. The panel shows the whole order, newest first, with −/+, a typed quantity and remove on each line, the subtotal and a link to checkout. Quantities are capped at the stock when backorders are not allowed. The page's cart fragments are refreshed after each change.
- Scans are sent one at a time, in the order they were read; one read while the previous is in flight waits its turn.
- Keeps scans through a dropped connection or an expired session: a scan whose request fails (or made while the device is offline) is queued in the tab (`sessionStorage`) and resent every few seconds, when the connection is back, or after signing in again in the same tab. Each scan carries an id, so one the server had already applied is not added twice.
- Explains why a code cannot be ordered - not found, out of stock (with "Notify me" when a plugin handles it), on backorder (added, with a warning), discontinued (with its replacement offered) - and lets the user report a code that was not found.
- Logs every code that did not give an orderable product (**WooCommerce → Unresolved codes**), so missing barcodes can be fixed at the source.

## Requirements

- PHP 8.1+, WooCommerce 9.2+ (for the GTIN field `_global_unique_id`).
- Cart markup that updates on `wc_fragments_refreshed` (classic cart widgets, Bricks cart elements). The scanner fetches the fragments itself; `wc-cart-fragments.js` need not be loaded.
- A plugin that registers at least one context (see below), or the scanner stays hidden.
- Optional: Bricks (for the scan button element); a plugin handling `beltoft_scanner_notify` (for "Notify me").

## Putting the button on a page

- **Bricks:** add the **Scan barcode** element. By default it only shows below the Bricks *mobile landscape* breakpoint (read from the Bricks settings); the element has a setting to show it on large screens too.
- **Anywhere else:** the `[beltoft_scan_button]` shortcode, optional `label="…"`. It always follows the breakpoint; there is no large-screen option.

Either way the button only renders where a registered context applies and allows the current user. The panel and its assets are printed only on those pages.

## Plugging in

### Where it appears and who may scan — `beltoft_scanner_contexts`

Add one context per place the scanner belongs:

```php
add_filter( 'beltoft_scanner_contexts', function ( array $contexts ) {
	$contexts['my-ordering-page'] = array(
		// Is the current request one of my pages?
		'applies'  => fn() => is_page( 123 ),
		// May this user scan here?
		'may_scan' => fn( WP_User $user ) => user_can( $user, 'read' ) && in_array( 'wholesale', $user->roles, true ),
	);

	return $contexts;
} );
```

- The **button and panel** render when one context both applies to the request and allows the user.
- The **REST endpoints** answer a signed-in user that any context allows (they are not page requests, so `applies` is not asked). Not signed in → `401`; no context allows the user → `403`.
- A context whose callbacks are not callable is ignored. With no contexts, the scanner appears nowhere.

### What may be ordered — `beltoft_scanner_assess`

Apply your own catalog rules to each scanned product:

```php
add_filter( 'beltoft_scanner_assess', function ( $status, WC_Product $product, int $user_id ) {
	if ( null !== $status ) {
		return $status; // Another rule already refused it.
	}

	$parent = $product->get_parent_id() ?: $product->get_id();

	if ( ! my_catalog_shows( $parent, $user_id ) ) {
		return 'not_visible';      // Answered exactly like "code not found".
	}

	if ( ! my_catalog_sells( $parent, $user_id ) ) {
		return 'not_purchasable';
	}

	return null; // Let it through.
}, 10, 3 );
```

Return `null` to allow, `'not_visible'` to hide the product or `'not_purchasable'` to refuse it. Both refusals are answered to the user as "code not found", so they learn nothing about a catalogue they cannot use; the log records which it was. Any other value is ignored.

The order of the checks: your rules first, then the replacement (below), then WooCommerce's own - that the product is purchasable and in stock (or allows backorders).

### Discontinued products — `beltoft_scanner_replacement_for`

```php
add_filter( 'beltoft_scanner_replacement_for', function ( $replacement, WC_Product $product ) {
	return (int) $product->get_meta( '_replaced_by' ) ?: $replacement; // product, id or null
}, 10, 2 );
```

When a replacement is returned, the scan answers "discontinued" and offers the replacement - even if the scanned product itself is no longer purchasable or in stock. The replacement is only offered if the same user could order it: your `beltoft_scanner_assess` rules allow it and it is purchasable and in stock. Otherwise the scanned product is judged as if it had no replacement. A product your rules refuse is never answered with its replacement.

### "Notify me" — `beltoft_scanner_notify`

The scanner keeps no back-in-stock subscriptions. A plugin that does takes them:

```php
add_filter( 'beltoft_scanner_notify', function ( $result, WC_Product $product, WP_User $user, int $quantity ) {
	if ( null !== $result ) {
		return $result; // Another plugin already took it.
	}

	$id = my_alerts_subscribe( $product, $user->user_email, $quantity );

	if ( false === $id ) {
		return new WP_Error( 'my_alerts_failed', __( 'Could not subscribe.' ), array( 'status' => 500 ) );
	}

	return $id ? 'subscribed' : 'already';
}, 10, 4 );
```

Return `'subscribed'`, `'already'` or a `WP_Error` (passed on as is). Without a handler the panel does not offer "Notify me" on an out-of-stock scan, and `/notify` answers `503`. The product has already passed your `beltoft_scanner_assess` rules: one they refuse never reaches the handler.

## REST API

Namespace `beltoft-scanner/v1`, cookie-authenticated (`X-WP-Nonce`), every route behind the context check above. On `401`, or `403 rest_cookie_invalid_nonce` (expired nonce), the panel keeps the scan and offers to sign in; any other `403` is shown as is. Codes are trimmed plain text up to 64 characters - SKUs with dots, slashes or spaces are kept as they are.

| Route | Method | Parameters | Does |
|---|---|---|---|
| `/lookup` | GET | `code` (required) | Finds the product for a code and says whether it can be ordered (`ok`, `backorder`, `out_of_stock`, `discontinued`; not found, `not_visible` and `not_purchasable` → `404`). Misses are logged here. Never changes the cart. |
| `/lookup` | POST | `code` (required), `add` (bool), `scan_id` | The same; with `add=1` an `ok`/`backorder` product is also added and the cart returned. What the panel sends per scan. A `scan_id` already applied for this user (kept a day) adds nothing and returns the order as it stands, so a resent scan is not counted twice. |
| `/cart` | GET | — | The current cart lines and subtotal. |
| `/cart` | POST | `product_id` (required), `delta` (default 1) or `set` | Changes a line's quantity; returns the cart. Judged on the resulting quantity: any increase is refused with `409` unless the product assesses `ok`, and a line can always be reduced or removed. A product not on the order that the user may not see (catalog rules, or not published) gets the same `400` as one that does not exist. |
| `/report` | POST | `code` (required) | Flags the user's latest log entry for that code (or records one) for the shop to look at. |
| `/notify` | POST | `product_id` (required), `quantity` | Hands a back-in-stock subscription for the user to the `beltoft_scanner_notify` handler; no handler → `503`. A product the catalog rules refuse this user gets the same `400` as one that does not exist. |

## Upgrading from the older names

Earlier versions used the shortcode `[b2b_scan_button]`, the Bricks element `b2b-scan-button`, the REST namespace `b2b/v1` and the filter `b2b_scanner_replacement_for`. None of these is answered any more: replace placed shortcodes and elements, and rename hooked filters. The miss log is carried over by itself (table `b2b_scan_misses` → `beltoft_scanner_misses`).

## Translations

Source strings are English; text domain `beltoft-scanner`. `languages/` ships the `.pot` and Portuguese (Portugal) as `.po`, `.mo` and `.l10n.php`. After changing strings, from the plugin folder:

```bash
wp i18n make-pot . languages/beltoft-scanner.pot --domain=beltoft-scanner --exclude=vendor,tests,assets/lib
# update languages/beltoft-scanner-pt_PT.po, then:
wp i18n make-mo languages/beltoft-scanner-pt_PT.po
wp i18n make-php languages/beltoft-scanner-pt_PT.po
```

## Development

- Code: `BeltoftScanner\` PSR-4 under `src/` with the plugin's own autoloader (no Composer at runtime). `src/Support/Context.php` asks the contexts, `src/Catalog/ProductLookup.php` finds and assesses products, `src/Rest/Routes.php` is the API, `src/Ui/` the button, panel and assets, `src/Log/` and `src/Admin/` the unresolved-codes log.
- Tests: PHPUnit 9.6 with the WordPress test suite. `composer install`, then `WP_TESTS_DIR=/path/to/wordpress-tests-lib vendor/bin/phpunit` (default `/tmp/wordpress-tests-lib`) against a throwaway database. `tests/bootstrap.php` loads WooCommerce from `/var/www/staging.mindthetrash.pt/wp-content/plugins` - change `BELTOFT_SITE_PLUGINS` there to run it elsewhere. The suite loads **only WooCommerce and this plugin** — it must keep passing without any consumer plugin, which is what keeps the core independent of them.
- The scanner never references a consuming plugin's classes; consumers only use the filters above.
- Deleting the plugin (`uninstall.php`) drops the miss log and removes its option and the recent scan ids.
