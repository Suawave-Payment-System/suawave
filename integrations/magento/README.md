# Suawave Payment for Magento 2

Accept stablecoin payments (for example USDT on TRON or BSC) in a Magento 2 store. At checkout the module asks the gateway for a deposit address, shows the amount, the address and a QR code, and marks the order paid and invoices it only when the gateway sends a signed, verified "confirmed" callback.

The module talks to the gateway's public, no-registration API. No account, API key or shared secret is needed.

## Requirements

- Magento Open Source or Adobe Commerce 2.4.4 or newer, PHP 8.3 or newer.
- PHP extensions `bcmath`, `curl`, `json`, `openssl`.
- A gateway you can reach over HTTPS (HTTP only against a mock gateway).
- A store that the gateway can reach over HTTPS from the public internet. Callbacks to private, loopback or link-local addresses are refused by the gateway.
- A payout wallet address on the network of the ticker you choose.

## Install

```
mkdir -p app/code/Suawave/Payment
cp -r <this directory>/. app/code/Suawave/Payment/
rm -rf app/code/Suawave/Payment/vendor app/code/Suawave/Payment/Test
bin/magento module:enable Suawave_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

`make integrations-package` in the repository root builds `dist/integrations/suawave-module-payment-<version>.zip` (module at the zip root, no tests). The merchant guide is `docs/USER_GUIDE.md`.

`setup:upgrade` creates the table `suawave_callback_event`, which remembers processed callback events.

## Configure

Stores > Configuration > Sales > Payment Methods > Suawave payments.

| Field | Meaning |
|---|---|
| Enabled | Turns the method on. |
| Title | Name shown at checkout. |
| Gateway base URL | For example `https://api.suawave.com`, without a trailing slash. |
| Ticker | Token ticker with the slash, lowercase: `trc20/usdt`, `bep20/usdt`. |
| Payout address | Your wallet on the ticker's network. Settlements are paid here. It cannot be changed for an existing order. |
| Public store origin | The HTTPS origin the gateway reaches your store on, for example `https://shop.example`, with no path. |

The store currency must be a fiat code the gateway can convert from (for example USD or EUR). The order total is converted to the ticker's token at checkout; if no fresh price exists, the method refuses the order and the customer picks another method.

## Checkout flow

1. The customer picks the method and places the order. The module converts the order total into the token amount, then creates a deposit address through `GET /{ticker}/create/` with a callback URL `https://<origin>/suawave/callback/index?order=<increment id>&nonce=<random>`, and stores amount, address and nonce on the order payment.
2. The success page shows the amount, the address and a QR code (the gateway's `/{ticker}/qrcode/` endpoint, a PNG data URI; no third-party service and no bundled QR library).
3. The customer sends the exact amount. The order stays `pending` until a confirmed callback arrives.

## Payment window

The gateway answers the create call with `expires_at`. The module stores it with the attempt and shows the deadline in the store timezone on the success page and in the payment information block (order page, admin and order emails).

- Once the deadline passes and the order is unpaid, address and QR code are no longer rendered. The page shows "Payment window closed" with the order number and a "Get a new payment address" button. A paid order shows "Payment received" and never the address again.
- The button posts to `suawave/payment/renew` (form key, the checkout session's last order or an order of the logged-in customer). Under the order row lock the module checks that the order is still unpaid, the window is still closed and the attempt is still the one the customer saw, then moves it to the order's previous attempts and stores a new address, nonce and deadline. A callback that paid the old attempt while the new address was being created wins and the renewal is dropped. The customer is sent to `suawave/payment/index`, which shows the new instructions.
- The callback takes the same lock before it compares nonce, address and coin with the current attempt and the previous ones. While the order is unpaid, a confirmed callback for any of its attempts counts towards that attempt's amount and pays the order once it is reached; the paying attempt's nonce and address are kept in `suawave_paid_by`. Once the order is paid, every further confirmed callback (current or previous attempt) is acknowledged and only adds one order comment "payment received on a previous attempt, reconcile"; the status is never changed again. An on-time deposit that the gateway finalizes after the deadline therefore still pays the order. The old addresses stay on the order so these callbacks can be matched.
- One comment per attempt records that its window closed without payment: written under the lock, keyed by the attempt's nonce, never once the order is paid. A cron job every five minutes pages through unpaid, unnoted Suawave orders with an id cursor (lookback 31 days, the gateway's 720 hour maximum plus a day); the success page notes it as well.

All attempt data lives in the payment's `additional_information`. It is shown to customers only through the module's own blocks; the order REST API is admin only and the customer GraphQL `payment_methods` returns empty `additional_data`.

## Callback verification

The endpoint is `/suawave/callback/index`. It accepts GET, form POST and JSON POST. For each request it:

1. Rejects (401) a missing `x-ca-signature` header.
2. Verifies the base64 RSA-SHA256 PKCS1v15 signature against the gateway public key from `GET /pubkey/` (cached 24 hours; on a mismatch the key is fetched again once, so key rotation heals itself). The signed bytes are the raw body for POST, and for GET the full URL built from the configured Public store origin plus the request URI exactly as received, so the query order is preserved. A key that cannot be parsed, is not RSA or is shorter than 2048 bits answers 503 and changes nothing.
3. Rejects (401) any failed verification. The order is not touched.
4. Acknowledges a signed pending callback (`pending=1`) with `*ok*` and does nothing else. A pending callback never pays an order.
5. For a confirmed callback (`pending=0`) checks that `value_coin` is a positive plain decimal string.
6. Opens a database transaction and takes a row lock (`SELECT ... FOR UPDATE` on the order's `sales_order` row). Everything below happens inside it, so two callbacks for one order, or a callback and a renewal, are processed one after the other and the order is paid and invoiced exactly once. Under the lock it checks that the order exists and that the `nonce`, deposit address and coin match the current attempt (or a previous one, see Payment window).
7. Claims the event `uuid` in `suawave_callback_event` (primary key). The gateway sends one event per credited transfer and reuses the uuid on a retry, so the uuid alone is the identity: a uuid seen before is acknowledged and ignored, a new uuid is a new deposit even when its `txid_in` repeats (one chain transaction can carry several transfers). The `txid_in` is stored with the event for reconciliation only. Any failure rolls the claim back and answers a server error so the gateway retries.
8. Adds `value_coin` to the amount received so far with `bcmath`, re-read under the lock. When the total reaches the expected token amount the order is invoiced offline (status `processing`). Less than expected only adds an order comment; later deposits accumulate.

Answers: `200 *ok*` accepted or duplicate, `400` malformed, `401` signature, `403` order mismatch, `404` unknown order, `503` verification key unavailable. The gateway retries anything that is not `200 *ok*`.

Amounts are decimal strings everywhere; no PHP float is used for money. A USDT or USDC amount is shown on the page and sent to the QR code endpoint rounded up to 6 decimals (never half up), so a customer who pays the shown amount always covers the stored amount; the callback is matched against the exact stored amount. Order comments show deposits and totals exactly, without trailing zeros.

## Quick checks

- `https://<your store>/suawave/callback/index` answers `401 missing signature` to a plain browser request. That means the route is reachable.
- Order comments list every confirmed deposit with the transaction hash and the running total.

## Tests

PHPUnit runs without Magento:

```
composer install
vendor/bin/phpunit -c phpunit.xml.dist
```

They cover the payment attempts against the real order ledger (renewal with a new nonce and address, previous-attempt callbacks paying an unpaid order and only noting a paid one, the renewal and callback races, one expiry note per attempt), the expiry cron's paging, the rendered instructions template for open, closed and paid attempts, the signature verifier (a fixed fixture vector for body and GET URL, bad signature, signature over different bytes, reordered query, another key, unparseable, non-RSA and short keys), the callback processor with in-memory ledger and event store (missing header, rotated and unknown keys, replayed uuid, pending never pays, underpayment, accumulation, wrong nonce, address or coin) and the gateway client.

Not covered without Magento: the checkout observer, invoice creation against a real order, the event table adapter, the cache-backed key provider, the controllers, the layout and checkout JavaScript.

## End-to-end check against a running gateway

Not part of the test command. Run it on a machine the gateway can call back on loopback (mock gateway):

```
php bin/complete-mock-payment.php <gateway-base-url> <payout-address> [ticker] [listen-port] [timeout-seconds]
```

It reads the gateway public key, creates a deposit address with a local callback listener on `127.0.0.1:<listen-port>` (default 18080, ticker default `trc20/usdt`), prints the address and the minimum amount, then waits. Deposit at least that amount to the address on the mock chain. The script verifies callbacks with the same processor the module uses and prints `PASS` once a confirmed callback has been verified and the order would be marked paid, or `FAIL` after the timeout. Callbacks with bad signatures are reported as rejected. The public API takes no API key, so none is asked for.

## License

QR codes come from the gateway itself, so no QR library is bundled and no third-party license applies.
