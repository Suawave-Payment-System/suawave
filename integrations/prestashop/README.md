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

1. The customer picks "Pay with Suawave". The module takes the cart total as a decimal string with the shop's price precision, calls `GET {base}/{ticker}/create/?callback=...&address=<payout>&pending=1`, and stores the address, amount, nonce and expected callback in `suawave_payment`. Then PrestaShop creates the order in the status "Awaiting crypto payment" and the customer lands on the order confirmation page. Returning with the same cart and amount reuses the stored address.
2. That page shows the amount, the address and a QR code. The QR code is produced by the gateway: the module calls `GET {base}/{ticker}/qrcode/?address=...&value=...&size=256` and embeds the returned PNG data URI. Nothing is bundled and no third party is contacted. If the call fails the page simply shows the address without a QR code.
3. The gateway calls the callback URL. A `pending=1` callback only records "detected" and never marks anything paid. A `pending=0` callback adds `value_coin` to the amount received. When the sum reaches the order amount the order moves to the shop's standard "Payment accepted" status. A smaller deposit leaves the order waiting and later deposits add up. A larger deposit also counts as paid.
4. The confirmation page does not refresh by itself. The customer reloads it or checks the order history; the browser can never mark an order paid.

## Callback verification

Header `x-ca-signature` is a base64 RSA-SHA256 (PKCS1 v1.5) signature over the exact full request URL for a GET callback (your configured origin plus the received request URI, query order preserved) or over the raw body for a POST callback. The module asks for GET callbacks.

- The key comes from `GET /pubkey/`, is cached in the shop configuration and is fetched again once when a signature does not match. A key that cannot be parsed, is not RSA or is shorter than 2048 bits is refused (HTTP 503, nothing changes).
- Missing, malformed or wrong signature: HTTP 401, nothing is read or changed, no `*ok*`.
- Valid signature but unknown deposit address: acknowledged without effect. For a GET callback the `coin`, `address_out`, `cart` and `nonce` must match the stored payment, otherwise HTTP 400. A confirmed callback that arrives before the order row exists answers 503 so the gateway retries.
- Amounts must be plain decimal strings (`1e2`, signs and commas are refused). All arithmetic uses `bcmath`; the module never converts an amount to a PHP float. The only float in the flow is the cart total PrestaShop itself returns, which is turned into a string with `number_format` at the shop's price precision before anything else happens, and handed back unchanged to `validateOrder()`.
- The only deduplication key is the callback `uuid` (unique key in `suawave_event`). A repeated delivery answers `*ok*` and changes nothing. `txid_in` is deliberately not used: one chain transaction can carry several transfers to the same address, each with its own `uuid`, and each is a real deposit.
- Callbacks for one order are serialized. The database transaction starts with `START TRANSACTION`, then `SELECT ... FOR UPDATE` on the order's `suawave_payment` row (the row that holds the running total) is the first statement, then the `uuid` is claimed (`INSERT IGNORE`), the total is summed, the order status and the row are written, and the transaction commits. A locking read sees the latest committed total even under REPEATABLE READ, so two concurrent confirmed callbacks credit once each and the order becomes paid exactly once. If saving the order status fails the transaction rolls back and HTTP 500 makes the gateway retry.
- Success and duplicates answer HTTP 200 with the body `*ok*`.
- The scheme has no timestamp, so replay protection is the `uuid` dedupe only.

Events treated as paid: only a verified confirmed callback (`pending=0`) whose accumulated `value_coin` is at least the order amount.

## Limits to know about

- One order currency per installation, no conversion.
- The cart total is the amount to pay, including tax and shipping, in the order currency.
- The QR code encodes what the gateway returns for the address and amount (for some chains the plain address, the amount is then only shown next to it).
- Funds sent to an address for an older, different amount of the same cart still count against that older record.
- Make sure PHP's `max_execution_time` and your web server allow the callback to finish within the gateway's 30 second limit.

## Tests

```
cd integrations/prestashop
composer install
vendor/bin/phpunit
```

The tests need no PrestaShop. They cover a known-good signature vector (GET and JSON POST, `tests/vector.json`), missing, malformed and bad signatures, a signature over different bytes, a reordered query string, a wrong configured origin, unparseable, short and non-RSA keys, key rotation with one re-fetch, a `uuid` replay, two different `uuid`s crediting once each with a single paid transition, two `uuid`s sharing one `txid_in` both credited, partial and over payments, pending callbacks, rollback and retry, the exact statement order against a fake database (transaction start, lock, claim, write, commit), the API client request shape and error mapping including the QR call, and the decimal arithmetic.

### Not covered without a shop

The files `suawave.php`, `controllers/front/*`, `views/*` and the `Prestashop*` classes in `src` (configuration page, order creation through `validateOrder()`, order status change through `OrderHistory`, the `Db` wrapper, Smarty template, hook registration). The SQL strings are only checked against a fake connection, not against a real MySQL, so the lock behaviour under real concurrency is untested.

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
