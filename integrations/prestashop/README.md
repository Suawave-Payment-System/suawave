# Suawave payment module for PrestaShop

Lets customers pay a PrestaShop order in a stablecoin (for example USDT on TRON or BSC) through the gateway's public API. At checkout the shop creates a deposit address, shows the amount, the address and a QR code on the order confirmation page, and marks the order paid only when the gateway sends a signed, confirmed callback.

Written against PrestaShop 8.1 and 1.7.8 (module declares a minimum of 1.7.7.0). It was not run inside a shop, see "Not covered without a shop".

## Requirements

- PrestaShop 8.1.x or 1.7.8.x on PHP 8.3 or newer with the `bcmath`, `curl`, `openssl` and `json` extensions.
- A gateway public API base URL (the host that serves `/{ticker}/create/` and `/pubkey/`) and a payout address that receives the funds.
- A shop reachable over `https` from the internet. The gateway only calls public callback URLs, and it cannot reach a shop that is in maintenance mode.
- MySQL with InnoDB (the module creates its tables as InnoDB, the per-order lock needs it).
- No composer packages are needed at runtime.

## Install

1. Run `make integrations-package` in the repository root and take `dist/integrations/suawave.zip`; the archive contains `suawave/suawave.php` at its top level and no `vendor`, `tests` or `scripts`.
2. In the back office go to Module Manager > Upload a module and upload the zip. Alternatively copy the folder to `modules/suawave` and open Module Manager.
3. Click Install, then Configure. Installing creates the tables `ps_suawave_payment` and `ps_suawave_event` (with your table prefix) and the order status "Awaiting crypto payment". Uninstalling removes the settings but keeps the tables and the status, because they hold payment records.

Updating the files of an existing installation needs no reinstall: the module adds missing columns (today `expires_at`) with an `ALTER TABLE` guarded by an `information_schema` check. It runs on install, on enable, when the Configure page opens and on the first request after an update (the applied schema version is kept in the setting `SUAWAVE_SCHEMA`), and it never touches a column that already exists.

## Settings

| Setting | Meaning |
|---|---|
| Gateway API base URL | For example `https://api.suawave.com`. No trailing path. |
| Asset and chain (ticker) | Lowercase ticker with the slash, for example `trc20/usdt` or `bep20/usdt`. |
| Payout address | Where the gateway forwards the funds. Immutable per ticker and callback once created. |
| Shop public origin | Scheme and host exactly as the gateway reaches your shop, for example `https://shop.example`. No path. Callback signatures cover the full URL, so this must match what the gateway called. |
| Order currency | Three letter code, for example `USD`. The method is offered only for carts in this currency and the token amount equals the cart total one to one (a USD stablecoin needs `USD`). There is no exchange rate lookup. |
| Callback public key | The gateway's RSA public key (PEM, at least 2048 bits). Leave empty and save: the module fetches it from `GET /pubkey/`. Paste it yourself to pin it. Saving fails if the key is unparseable, not RSA or shorter than 2048 bits. |

The callback endpoint is `{origin}/index.php?fc=module&module=suawave&controller=callback`. You do not register it anywhere: the module passes it, with the cart id and a random nonce, when it creates each address. It works with or without friendly URLs.

## How a payment runs

1. The customer picks "Pay with Suawave". The module takes the cart total as a decimal string with the shop's price precision, calls `GET {base}/{ticker}/create/?callback=...&address=<payout>&pending=1`, and stores the address, amount, nonce, the gateway's `expires_at` and expected callback in `suawave_payment`. Then PrestaShop creates the order in the status "Awaiting crypto payment" and the customer lands on the order confirmation page. Returning with the same cart and amount inside the payment window reuses the stored address.
2. That page and the order detail page show the amount, the address, a QR code and the deadline in the shop timezone; the payment label of the order (shown in the order confirmation email and the order history) follows the current attempt: "Suawave - pay before <deadline>" while the window is open, "Suawave - payment window closed" once the expiry is noted, "Suawave - paid" once paid. The module changes only `orders.payment` and `order_payment.payment_method` with a field-limited `UPDATE`, under the cart lock, from the renewal, the expiry note and the paying callback; it never saves a whole `Order` object, so a stale copy cannot overwrite the order status, and a paid order's label is never set back. The QR code is produced by the gateway: the module calls `GET {base}/{ticker}/qrcode/?address=...&value=...&size=256` and embeds the returned PNG data URI. Nothing is bundled and no third party is contacted. If the call fails the page simply shows the address without a QR code.
3. The gateway calls the callback URL. A `pending=1` callback only records "detected" and never marks anything paid. A `pending=0` callback adds `value_coin` to the amount received. When the sum reaches the order amount the order moves to the shop's standard "Payment accepted" status. A smaller deposit leaves the order waiting and later deposits add up. A larger deposit also counts as paid.
4. The confirmation page does not refresh by itself. The customer reloads it or checks the order history; the browser can never mark an order paid.

## Payment window and retries

- Each row of `suawave_payment` is one payment attempt of a cart; the newest row is the current attempt. Once `expires_at` has passed, an unpaid order shows "Payment window closed" with the order reference and id, and neither the address nor the QR code is rendered again; a paid order shows "paid". The address stays stored, because a deposit seen before the deadline can still be confirmed later and its callback must still match.
- The closed page offers "Request a new payment address" while the order is still "Awaiting crypto payment". It posts the order id and the order's secure key to the validation controller, which never calls `validateOrder()` again for a cart that already has an order: it creates a new attempt (new nonce, new address, new deadline) on the existing order, sets the payment label inside the renewal transaction and shows the confirmation page again. A paid order is never renewed.
- Renewal and callbacks take the same lock: `SELECT ... WHERE id_cart = ? FOR UPDATE` over all attempts of the cart. The gateway create request runs before the lock; under the lock the module checks again that no attempt was paid and that nobody renewed meanwhile, marks the previous attempt `superseded` and inserts the new one in the same transaction. If the old attempt was paid while the create request ran, the renewal is dropped and the paid attempt is kept.
- An order is marked paid once. A deposit seen before an attempt's deadline can be confirmed after a renewal: while the order is unpaid, a confirmed callback for that superseded attempt pays the order like the current one, under the same cart lock, and its row becomes `paid` (the paid comment names the address), so the order records which attempt paid it and the pages show "paid". Once the order is paid, any further confirmed callback, for any attempt, only adds the private message "Suawave payment received on a previous attempt, reconcile" (or "... after the order was paid, reconcile" for the paying attempt) with the amount and address, and never changes the order status.
- When the window of the current attempt closes without a confirmed payment, the order gets one private message per attempt; the attempt's status `expired` is the marker, the check and the message happen under the cart lock, and nothing is written once the order is paid or the attempt was superseded.
- Limitation: the expiry message is written by page-triggered work only: a sweep of up to 50 overdue attempts when the payment options are built at checkout or when a cron module fires `actionCronJob`, and a check of the shown order on its confirmation and order detail pages. A shop without traffic and without a cron module adds the message late, on the next such request.

## Callback verification

Header `x-ca-signature` is a base64 RSA-SHA256 (PKCS1 v1.5) signature over the exact full request URL for a GET callback (your configured origin plus the received request URI, query order preserved) or over the raw body for a POST callback. The module asks for GET callbacks.

- The key comes from `GET /pubkey/`, is cached in the shop configuration and is fetched again once when a signature does not match. A key that cannot be parsed, is not RSA or is shorter than 2048 bits is refused (HTTP 503, nothing changes).
- Missing, malformed or wrong signature: HTTP 401, nothing is read or changed, no `*ok*`.
- Valid signature but unknown deposit address: acknowledged without effect. For a GET callback the `coin`, `address_out`, `cart` and `nonce` must match the stored payment, otherwise HTTP 400. A confirmed callback that arrives before the order row exists answers 503 so the gateway retries.
- Amounts must be plain decimal strings (`1e2`, signs and commas are refused). All arithmetic uses `bcmath`; the module never converts an amount to a PHP float. The only float in the flow is the cart total PrestaShop itself returns, which is turned into a string with `number_format` at the shop's price precision before anything else happens, and handed back unchanged to `validateOrder()`. A USDT or USDC amount and the per-transfer minimum are shown rounded up to 6 decimals (never half up), and the QR code request carries the same shown amount, so a customer who pays it always covers the stored amount; the callback is matched against the exact stored amount. The amount received so far and the order messages show exact values without trailing zeros. A paid order email that cannot be sent is logged as "suawave mail failed" (severity 3); the callback itself succeeded and the order is paid.
- The only deduplication key is the callback `uuid` (unique key in `suawave_event`). A repeated delivery answers `*ok*` and changes nothing. `txid_in` is deliberately not used: one chain transaction can carry several transfers to the same address, each with its own `uuid`, and each is a real deposit.
- Callbacks for one order are serialized. The database transaction starts with `START TRANSACTION`, reads the attempt by its address to learn the cart, then `SELECT ... FOR UPDATE` on every `suawave_payment` row of that cart (the rows hold the running totals), checks `coin`, `address_out`, `cart`, `nonce` and whether the attempt is the current one under that lock, then the `uuid` is claimed (`INSERT IGNORE`), the total is summed, the order status and the row are written, and the transaction commits. A locking read sees the latest committed total even under REPEATABLE READ, so two concurrent confirmed callbacks credit once each and the order becomes paid exactly once. If saving the order status fails the transaction rolls back and HTTP 500 makes the gateway retry.
- Success and duplicates answer HTTP 200 with the body `*ok*`.
- The scheme has no timestamp, so replay protection is the `uuid` dedupe only.

Events treated as paid: only a verified confirmed callback (`pending=0`) whose accumulated `value_coin` is at least the order amount.

## Limits to know about

- One order currency per installation, no conversion.
- The cart total is the amount to pay, including tax and shipping, in the order currency.
- The QR code encodes what the gateway returns for the address and amount (for some chains the plain address, the amount is then only shown next to it).
- Funds sent to an older attempt of the same cart (an older amount or an expired window) count against that older attempt: they pay an unpaid order when they reach that attempt's amount, otherwise they are recorded on it; after the order is paid they only add a reconcile message.
- Make sure PHP's `max_execution_time` and your web server allow the callback to finish within the gateway's 30 second limit.

## Tests

```
cd integrations/prestashop
composer install
vendor/bin/phpunit
```

The tests need no PrestaShop. They cover a known-good signature vector (GET and JSON POST, `tests/vector.json`), missing, malformed and bad signatures, a signature over different bytes, a reordered query string, a wrong configured origin, unparseable, short and non-RSA keys, key rotation with one re-fetch, a `uuid` replay, two different `uuid`s crediting once each with a single paid transition, two `uuid`s sharing one `txid_in` both credited, partial and over payments, pending callbacks, rollback and retry, the exact statement order against a fake database (transaction start, lock, claim, write, commit), the API client request shape and error mapping including the QR call, the decimal arithmetic, the payment window (deadline parsing, renewal of an expired attempt with a new nonce and address that supersedes the old one under the cart lock, a superseded attempt paying an unpaid order once and only noting on a paid one, the old attempt paying during the create request, one expiry message per attempt and none once paid), the `payment_return.tpl` output rendered from the real view variables (address and QR hidden after the deadline and for paid orders, order id shown, retry form), the validation controller run against stubbed PrestaShop classes (an existing order is renewed, never validated twice), and the schema upgrade statements.

### Not covered without a shop

The files `suawave.php`, `controllers/front/callback.php` beyond its start-up order, and the `Prestashop*` classes in `src` (configuration page, the real `validateOrder()`, order status change through `OrderHistory`, the `Db` wrapper, hook registration). The template is rendered by a test renderer for the Smarty subset it uses, not by Smarty itself. The SQL strings are only checked against a fake connection, not against a real MySQL, so the lock behaviour under real concurrency is untested.

## Mock-mode end-to-end script

Run it by hand against a running gateway in mock mode (it is not part of `phpunit`):

```
php scripts/mock_payment.php <base-url> <payout-address> \
  --control-url=http://127.0.0.1:<control port>/mock/control \
  --contract=<token contract in the mock registry> [--ticker=trc20/usdt] [--amount=25.00] \
  [--listen=127.0.0.1:8099] [--callback-origin=http://127.0.0.1:8099] \
  [--chain=tron] [--network=mock] [--decimals=6] [--from=<sender address>] [--timeout=60]
```

It fetches the public key, starts a small callback receiver on `--listen`, creates a deposit address with that receiver as the callback, mines and finalizes a mock deposit through the loopback control endpoint, then waits for the signed callback, verifies it with the module's verifier and checks that a tampered copy is rejected. Without `--control-url` it prints the address and waits for you to send funds. The callback host must be accepted by the gateway's callback URL checks (plain `http` loopback is only possible in a mock environment).
