# Beltoft Scanner

Scan product barcodes with the phone camera straight into the WooCommerce cart.

The scanner is a **core that other plugins plug into**. On its own it shows nowhere and answers nobody: a plugin decides on which pages it appears, who may use it, and which products that person may order. On mindthetrash the B2B ordering list (`beltoft-commerce`) and the shop till (`beltoft-pos`) use it.

## Features

- Reads EAN/GTIN and product SKUs with the camera (ZXing, bundled; no external service), with a torch toggle and manual entry as a fallback.
- Looks the code up by SKU or by the product's GTIN (`_global_unique_id`), variations included.
- Adds to the WooCommerce cart in one call per scan, with quantity steps, undo and removal in the panel; the page's cart fragments are refreshed after each change.
- Keeps working through a dropped connection: scans are queued in the browser and sent when it is back, and recovered after a session expires.
- Explains why a code cannot be ordered (not found, out of stock, discontinued with a replacement offered) and lets the user report a code or ask to be notified when it is back in stock.
- Logs every code that did not give an orderable product (**WooCommerce → Unresolved codes**), so missing barcodes can be fixed at the source.

## Requirements

- WordPress 6.5+, PHP 8.1+, WooCommerce with the classic cart fragments.
- A plugin that registers at least one context (see below), or the scanner stays hidden.
- Optional: Bricks (for the scan button element), `beltoft-in-stock-notifier` (for "Notify me").

## Putting the button on a page

- **Bricks:** add the **Scan barcode** element. By default it only shows below the Bricks *mobile landscape* breakpoint; the element has a setting to show it on large screens too.
- **Anywhere else:** the `[b2b_scan_button]` shortcode, optional `label="…"`.

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

Return `null` to allow, `'not_visible'` to hide the product (the user is told the code was not found, so a catalogue they cannot see is not revealed; the log records the difference) or `'not_purchasable'`. Any other value is ignored. After your rules the scanner still checks that WooCommerce can sell the product and that it is in stock.

### Discontinued products — `b2b_scanner_replacement_for`

```php
add_filter( 'b2b_scanner_replacement_for', function ( $replacement, WC_Product $product ) {
	return (int) $product->get_meta( '_replaced_by' ) ?: $replacement; // product, id or null
}, 10, 2 );
```

When a replacement is returned, the scan answers "discontinued" and offers the replacement.

## REST API

Namespace `b2b/v1`, cookie-authenticated (`X-WP-Nonce`), every route behind the context check above.

| Route | Method | Parameters | Does |
|---|---|---|---|
| `/lookup` | GET | `code` (required), `add` (bool) | Finds the product for a code and says whether it can be ordered; with `add=1` also adds it and returns the cart. |
| `/cart` | GET | — | The current cart lines and subtotal. |
| `/cart` | POST | `product_id` (required), `delta` (default 1) or `set` | Changes a line's quantity; returns the cart. |
| `/report` | POST | `code` (required) | Flags a code for the shop to look at. |
| `/notify` | POST | `product_id` (required), `quantity` | Subscribes the user to a back-in-stock alert (needs `beltoft-in-stock-notifier`). |

## Translations

Source strings are English; text domain `beltoft-scanner`. `languages/` ships the `.pot` and Portuguese (Portugal) as `.po`, `.mo` and `.l10n.php`. After changing strings, from the plugin folder:

```bash
wp i18n make-pot . languages/beltoft-scanner.pot --domain=beltoft-scanner --exclude=vendor,tests,assets/lib
# update languages/beltoft-scanner-pt_PT.po, then:
wp i18n make-mo languages/beltoft-scanner-pt_PT.po
wp i18n make-php languages/beltoft-scanner-pt_PT.po
```

## Development

- Code: `B2BScanner\` PSR-4 under `src/` with the plugin's own autoloader (no Composer at runtime). `src/Support/Context.php` asks the contexts, `src/Catalog/ProductLookup.php` finds and assesses products, `src/Rest/Routes.php` is the API, `src/Ui/` the button, panel and assets, `src/Log/` and `src/Admin/` the unresolved-codes log.
- Tests: PHPUnit 9.6 with the WordPress test suite. `composer install`, point `WP_TESTS_DIR` at a WordPress test install, then `vendor/bin/phpunit`. The suite loads **only WooCommerce and this plugin** — it must keep passing without any consumer plugin, which is what keeps the core independent of them.
- The scanner never references a consuming plugin's classes; consumers only use the filters above.
