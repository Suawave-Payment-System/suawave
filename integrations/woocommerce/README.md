# Suawave Payments for WooCommerce

A WooCommerce payment gateway (also usable from plain WordPress) that takes stablecoin payments through your payment gateway. At checkout it creates a deposit address, shows the exact amount, the address and a QR code, and marks the order paid only when the gateway sends a callback whose RSA signature checks out.

## Requirements

- WordPress 6.0 or newer, PHP 8.3 or newer with the `bcmath` and `openssl` extensions.
- WooCommerce 8 or newer for the checkout gateway. Plain WordPress works without it (see the end).
- Your site must be reachable by the gateway over HTTPS (HTTP only against a mock gateway), and your gateway must have public mode enabled.

## Install

1. Run `make integrations-package` in the repository root and take `dist/integrations/suawave-payment.zip`; it contains `suawave-payment/suawave-payment.php`, `readme.txt`, `includes/` and `assets/blocks.js` only. The plugin has no runtime dependencies. The wordpress.org images are in `.wordpress-org/`.
2. WordPress admin, Plugins, Add New, Upload Plugin. Activate it.
3. WooCommerce, Settings, Payments, Suawave payment.

## Settings

| Setting | Meaning |
|---|---|
| Gateway API base URL | For example `https://api.suawave.com`, no trailing slash. |
| Asset and chain | Lowercase ticker with a slash: `trc20/usdt` or `bep20/usdt`. |
| Payout address | Your own wallet address on that chain. Every deposit is forwarded there. |
| Callback public key | The PEM public key of the gateway. Get it once with `curl https://api.suawave.com/pubkey/` and paste the `pubkey` value (turn `\n` into real line breaks). RSA, at least 2048 bits. The gateway signs every callback with the matching private key. |
| Public site origin | Scheme and host (and port) the gateway uses to reach your shop, for example `https://shop.example`. Leave empty to use the WordPress home URL. Behind a proxy or CDN set it explicitly, because callbacks are verified against exactly this origin. |
| Price | `Order total equals the asset amount` treats one unit of your store currency as one token (use for a USD store with a USD stablecoin). `Convert the order total with the gateway rate` calls the gateway's convert endpoint and uses the returned amount. |

The gateway only shows at checkout when the API URL, payout address and a valid public key are set.

## What happens

1. The customer places the order. The plugin calls `GET {base}/{ticker}/create/` with your payout address and a callback URL of the form `https://shop.example/?suawave_callback=1&src=woo&order=<id>&nonce=<random>` and `pending=1`. It checks the order amount against the minimum deposit the gateway returns and refuses a smaller order.
2. The order goes on hold. The thank-you page, the order detail page in the customer account and the order email (HTML and plain text) show the amount, the address, the network, the minimum deposit, the payment deadline and a QR code.
3. The gateway calls the callback URL for each deposit. The plugin verifies `x-ca-signature` first, then handles the event:
   - Pending callback (`pending=1`): adds a note only. The order is never marked paid from it.
   - Confirmed callback (`pending=0`): adds `value_coin` to the amount received so far. When the total reaches the order amount the order is paid with WooCommerce `payment_complete`. A smaller payment leaves the order on hold with a note, and a later deposit (it has its own `uuid`) tops it up.
4. The plugin answers `200` with `*ok*`.

## Payment window

The gateway answers the create call with `expires_at`; the plugin stores it with the order (`_suawave_expires_at`) and shows it in the shop timezone. Once it passes, every surface (thank-you page, order detail page, HTML and plain-text email) shows "payment window closed" with the order number instead of the address and QR code; a paid order shows "Payment received" and never the address. The address stays on the order so an on-time deposit that the gateway finalizes after the deadline still matches.

- When the window closes without a confirmed payment (a single cron event at the deadline, also checked whenever the instructions render), the plugin adds one note for that attempt and moves the order from on hold to pending payment, which releases its stock and makes the customer's Pay link reach the gateway again. If a deposit was already detected on that attempt the order stays on hold with a note instead. WooCommerce's "Hold stock" setting cancels pending orders that stay unpaid longer than its limit.
- Paying again issues a new attempt with a new nonce and a new deposit address. The previous attempt is kept on the order as superseded (`_suawave_previous_attempts`). Supersession runs in the same locked transaction as the callbacks, and a renewal is abandoned when the order was paid in the meantime.
- Callbacks take the order lock first, then match the nonce and `address_in` to an attempt of the order (current or superseded). Inside the lock every decision (attempt match, paid state, expiry note, renewal) uses a fresh read: the order object cache, the post cache and the HPOS datastore caches (`orders_data`, `orders_meta`, through `OrdersTableDataStore::clear_cached_data` and, because that skips the meta cache when no `orders_data` entry existed, `OrdersTableDataStoreMeta::clear_cached_data` directly) are cleared and its meta is read with `read_meta_data(true)`; reads before the lock only decide whether to try. While the order is unpaid, a confirmed deposit to any of its attempts counts and can pay it; the paying attempt is recorded (`_suawave_paid_nonce`, `_suawave_paid_address`). Once the order is paid, every further confirmed callback, for any attempt, only adds a note ("payment received on a previous attempt, reconcile") and never changes the status; a paid order is never paid again, renewed or returned to on hold.
- All plugin meta keys start with an underscore, so WordPress treats them as protected: they are not shown to customers or in the custom fields box. The WooCommerce order REST API that serializes order meta needs shop staff credentials.

Only a confirmed callback pays an order. Nothing the browser sends (return URL, query string) changes an order.

## Callback verification

- `GET` callbacks are verified over `PUBLIC_ORIGIN + REQUEST_URI` byte for byte, `POST` (form or JSON) over the raw body. The scheme is base64 RSA-SHA256, PKCS#1 v1.5, using `openssl_verify`.
- A missing, malformed or wrong signature, a key that cannot be parsed or is shorter than 2048 bits, an unsupported method and a body over 64 KB all answer `401` and change nothing.
- After verification the plugin checks that the order id and nonce in the URL belong to an order paid with this gateway (`404` otherwise) and that `address_in` and `coin` in the signed data match what was stored for that order (`400` otherwise). A confirmed callback whose `value_coin` is not a plain decimal string is `400`.
- Everything for one callback runs in a single database transaction that first locks the order's totals row (`SELECT ... FOR UPDATE`, isolation `READ COMMITTED`): the `uuid` is claimed with a unique insert, the running total and the list of counted deposit transactions are read from that locked row, the new total is computed, the order is updated and the row is written, then `COMMIT`. Two callbacks for one order therefore run one after the other and exactly one of them makes the paid transition. A failure rolls back the whole transaction including the `uuid` claim, so the gateway's retry applies it. The database tables must be InnoDB (the WordPress default).
- A repeated delivery of the same `uuid` is acknowledged with `200 *ok*` and does nothing. The `uuid` is the only deduplication identity: one chain transaction can carry several transfers to the same address, each delivered under its own `uuid` with the same `txid_in`, so a repeated `txid_in` under a new `uuid` is a real deposit and is credited. The `txid_in` values are only recorded in the totals row for operator visibility.
- Answers: `200 *ok*`, `400` malformed or mismatching, `401` bad signature, `404` unknown order, `500` storage failure, `503` the order lock could not be taken (the gateway retries).

## Money handling

Amounts are decimal strings from the order total to the gateway call to the callback. Comparison and addition use `bcmath` on strings. No PHP float, `floatval` or `number_format` touches an amount. A USDT or USDC amount and the minimum deposit are shown rounded up to 6 decimals (never half up) on the thank-you page, the order page and both emails, and the QR code request carries the same shown amount, so a customer who pays it always covers the stored amount (for example a converted `12.011183118544993788` shows as `12.011184`); the callback is matched against the exact stored amount. Received totals and order notes show exact values without trailing zeros.

## QR code

The QR code is a PNG that your gateway renders at `GET {base}/{ticker}/qrcode/` (the gateway's own no-cost endpoint) and is shown as an inline `data:image/png` image after the plugin has checked that it really is a PNG data URI. Nothing is bundled, no third-party service is called and no extra license applies. If the endpoint fails, the page shows the address and amount without the image.

## Test it

Unit tests (PHPUnit, dev dependency only):

```
composer install
vendor/bin/phpunit
```

They cover the signature verifier (known-good GET and POST vectors made with the openssl CLI, bad signature, signature over reordered or re-serialized bytes, wrong origin, missing header, unparseable and too-short keys), idempotent repeated deliveries, `uuid` replays, an unverified callback leaving the order untouched and a pending callback never paying.

Mock-mode end to end, run by hand and not part of `phpunit`:

```
php bin/mock-payment.php <base_url> <api_key|-> --payout=<address> [--ticker=trc20/usdt] [--amount=<decimal>]
```

The public API needs no key, so `<api_key>` is accepted for symmetry and not used; pass `-`. The script fetches `/pubkey/`, listens on `--callback-host`:`--callback-port` (default `127.0.0.1:18089`), creates an address with a callback to itself, waits for the confirmed callback, verifies it with the plugin's own verifier and processor, then checks that a forged signature is refused with `401` and that a replayed `uuid` is acknowledged without a second credit. It exits `0` on success. The gateway must be able to reach the callback host and port, and in mock mode must allow that destination. For the deposit either make it yourself on the mock chain, or let the script do it with `--control-url=http://127.0.0.1:<mock control port> --chain=<chain key> --contract=<token contract> --decimals=<n>`, which posts a `deposit` and an `advance` to `POST /mock/control` (loopback only).

## Plain WordPress

Without WooCommerce, set the same options (`update_option('woocommerce_suawave_settings', [...])` with the keys `api_base_url`, `ticker`, `payout_address`, `callback_public_key`, `external_origin`) and call:

```php
$invoice = suawave_create_invoice('invoice-42', '25.50');
```

It returns the address, amount, minimum and QR data URI to show. When the confirmed deposits cover the amount, the action `suawave_invoice_paid` fires once with the reference and invoice, and `suawave_invoice_is_paid('invoice-42')` returns true. The `suawave_invoice_detected` action fires on a pending callback and must not be used to release goods.

## License

GPL-2.0-or-later.
