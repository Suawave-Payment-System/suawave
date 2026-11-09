# Suawave payment extension for OpenCart

Lets customers pay an OpenCart order in a stablecoin (for example USDT on TRON or BSC) through the gateway's public API. At checkout the shop creates a deposit address, shows the amount, the address and a QR code, and marks the order paid only when the gateway sends a signed, confirmed callback.

Targets OpenCart 4.0.2.x. See "OpenCart 3.x" at the end for what differs.

## Requirements

- OpenCart 4.0.2.x on PHP 8.3 or newer with the `bcmath`, `curl`, `openssl` and `json` extensions.
- A gateway public API base URL (the host that serves `/{ticker}/create/` and `/pubkey/`) and a payout address that receives the funds.
- A shop reachable over `https` from the internet (the gateway only calls public `https` callback URLs).
- No composer packages are needed at runtime.

## Install

1. Run `make integrations-package` in the repository root and upload `dist/integrations/suawave.ocmod.zip` with Extensions > Installer (`install.json`, `admin/`, `catalog/` and `system/` at the zip root; the file name must stay `suawave.ocmod.zip` because OpenCart derives the extension code from it). Copying the folder into `extension/` by hand does not work: OpenCart 4 lists only extensions registered by the Installer.
2. In the admin go to Extensions > Extensions, choose "Payments", find "Suawave" and click Install. This creates the tables `suawave_payment` and `suawave_event`. Then click Edit. Updating an existing installation: see "Update without losing settings" below. Install and Save both run the schema routine, which adds missing columns (`expires_at`, `expiry_noted_at`) and the `expires_at` index with `ALTER TABLE` only after an `information_schema` check, so it is safe to repeat.
3. Grant your admin user group access to `extension/suawave/payment/suawave` (System > Users > User Groups) if the Edit page says you lack permission.

### Update without losing settings

The settings live in `oc_setting` under `payment_suawave_*` and the tables `suawave_payment` and `suawave_event` hold the payments. OpenCart 4.0.2.3 cannot update a registered extension through the Installer: uploading the zip again answers "Extension already installed!", Installer > Uninstall is refused while the payment is installed ("There are 1 extensions that need to be uninstalled before this extension can be safely removed!"), and a re-upload also needs Installer > Delete first. So update by copying the files:

1. Unzip the new `suawave.ocmod.zip` over the existing `extension/suawave/` folder of the shop, as the web server user, so every file is replaced and the owner stays the same (for example `sudo -u www-data unzip -o suawave.ocmod.zip -d <shop root>/extension/suawave/`).
2. Open Extensions > Extensions > Payments > Suawave > Edit and click Save once. The schema routine runs again and the saved settings are shown unchanged.

The Installer route (Extensions > Extensions > Payments > Uninstall, then Installer > Uninstall, Delete, upload and Install, then Payments > Install) works too, but Payments > Uninstall deletes every `payment_suawave_*` setting, so note the values first and enter them again on the Edit page. `install()` in the admin controller is safe to repeat: it creates missing tables and columns and fills only status settings that are empty, so an existing configuration is never overwritten.

## Settings

| Setting | Meaning |
|---|---|
| Gateway API base URL | For example `https://api.suawave.com`. No trailing path. |
| Asset and chain (ticker) | Lowercase ticker with the slash, for example `trc20/usdt` or `bep20/usdt`. |
| Payout address | Where the gateway forwards the funds. Immutable per ticker and callback once created. |
| Callback public key | The gateway's RSA public key (PEM, at least 2048 bits). Leave empty and save: the extension fetches it from `GET /pubkey/` and stores it. Paste it yourself if you want to pin it. Saving fails if the key is unparseable, not RSA or shorter than 2048 bits. |
| Shop public origin | The `https://` origin exactly as the gateway reaches your shop, for example `https://shop.example`. Callback signatures cover the full URL, so this must match what the gateway called. |
| Order currency | The currency code in which orders are paid, for example `USD`. The method is offered only for orders in this currency and the token amount equals the order total one to one (a USD stablecoin needs `USD`). There is no exchange rate lookup. |
| Order status while waiting | Status set when the customer starts the payment. |
| Order status after payment | Status set when the confirmed callback arrives. The default is the status named Processing; if the shop has none, the first `config_processing_status` entry. |
| Status, sort order | As for any payment method. |

The page shows the callback endpoint of your shop (`index.php?route=extension/suawave/payment/suawave.callback`). You do not register it anywhere: the extension passes a callback URL with the order id and a random nonce when it creates each address.

## How a payment runs

1. The customer picks "Pay with Suawave" and clicks the button. The extension converts the order total to a decimal string with 2 places using `bcmath` (no floats anywhere), calls `GET {base}/{ticker}/create/?callback=...&address=<payout>&pending=1`, and stores the address, amount, nonce and expected callback in `suawave_payment`. The order gets your "waiting" status. Clicking again for the same amount reuses the stored address. A different amount creates a new address.
2. The page shows the amount, the address and a QR code containing the deposit address. The QR code is drawn in the browser by the bundled `qrcode.js`. Nothing is sent to a QR service.
3. The gateway calls your callback URL. A `pending=1` callback only records "detected" and shows a message. It never marks the order paid. A `pending=0` callback adds `value_coin` to the amount received. When the sum reaches the order amount, the order gets your "paid" status through `model_checkout_order->addHistory()`. A smaller deposit leaves the order waiting and adds an order history entry (amount received, outstanding, transaction hash); later deposits add up, and the completing deposit adds the final entry with the total. A larger deposit also counts as paid.
4. The checkout page polls a local status endpoint (it reads your database only) and goes to the success page once the order is paid. The browser can never mark an order paid.

## Payment window

The create answer carries `expires_at` (24h by default on the gateway). The extension stores it with the payment attempt and shows it as "Pay before" in the shop timezone; the first confirm and every renewal add a customer-visible order history entry with that deadline.

- After the deadline the checkout no longer shows the address or the QR code: the status endpoint answers `closed` with the order id, the page drops the address and enables the pay button again. Clicking it requests a fresh address with a new nonce; the old attempt is marked `superseded` and kept so a late callback can still be matched.
- A paid attempt is never renewed and the confirm step answers `paid` without the address. An attempt with a detected deposit is not renewed either, because a deposit seen before the deadline is still confirmed after it; the page shows "detected" without the address.
- Renewal, callbacks and expiry notes for one order are serialized by a MySQL named lock (`GET_LOCK`) taken before the transaction. Renewal reads the current attempt under the lock after the create request and keeps it if it was paid meanwhile. The confirm step's order history entry (waiting status and deadline) is written inside that same lock from the order status read there, and never for a paid order, so a payment that lands right after the renewal cannot be reverted by `addHistory()`. A callback checks nonce and address under the same lock. The order is marked paid once: the first confirmed full payment on any of its attempts, also a superseded one (an on-time deposit finalized after the renewal), pays it and that `suawave_payment` row becomes `paid`, which records the paying attempt (its address is in the history entry). Once the order is paid, every further confirmed deposit on any attempt only adds the history entry "payment received on a previous attempt, reconcile".
- When an unpaid attempt's deadline passes, one customer-visible history entry (the closed-window text with the order id) is added per attempt; the attempt's `expiry_noted_at` column is the marker. No entry is added once the order is paid or for an attempt that is no longer current.
- Expiry entries are written by page-triggered sweeps (the payment step and its status polling), not by a cron job. A shop without checkout traffic adds the entry for an abandoned order only on the next checkout or status request.

## Callback verification

Per the gateway's public API: header `x-ca-signature` is a base64 RSA-SHA256 (PKCS1 v1.5) signature over the exact full request URL for a GET callback (your configured origin plus the received request URI, query order preserved) or over the raw body for a POST callback (form or JSON). The extension asks for GET callbacks.

- Missing, malformed or wrong signature: HTTP 401, nothing is read or changed, no `*ok*`.
- Valid signature but the address is unknown: acknowledged without effect. The `coin`, `address_out`, `order` and `nonce` of a GET callback must match the stored payment, otherwise HTTP 400.
- Amounts must be plain decimal strings (`1e2`, signs and commas are refused).
- Every event is deduplicated by `uuid` in `suawave_event` (unique key) inside one database transaction together with the order update. A repeated delivery answers `*ok*` and changes nothing. A new `uuid` is a new deposit and is added. If saving the order status fails, the transaction rolls back and HTTP 500 makes the gateway retry.
- Different `uuid`s for the same order are serialized: the transaction starts with `SELECT ... FOR UPDATE` on the order's `suawave_payment` row, and only then claims the `uuid`, adds `value_coin` and decides. A locking read sees the latest committed total even under REPEATABLE READ, so two concurrent confirmed callbacks credit once each and the order becomes paid exactly once. This needs InnoDB (the extension creates its tables as InnoDB).
- Success and duplicates answer HTTP 200 with the body `*ok*`.
- The scheme has no timestamp, so replay protection is the `uuid` dedupe only.

Events treated as paid: only a verified confirmed callback (`pending=0`) whose accumulated `value_coin` is at least the order amount.

## Limits to know about

- One order currency per installation, no conversion.
- The QR code encodes the plain deposit address. The amount is shown next to it and is not part of the QR payload.
- The amount is shown as stored. A USDT or USDC amount with more than 6 decimals would be shown rounded up to 6 (never half up), so paying the shown amount always covers the stored amount; the callback is matched against the exact stored amount. Order history entries show deposits and totals exactly, without trailing zeros.
- Funds sent to the address of a superseded attempt pay the order only while it is unpaid and the attempt's amount still equals the order total; otherwise they are recorded on that attempt and reported in the order history for manual reconciliation.
- Expiry history entries need checkout traffic (see Payment window).
- Make sure PHP's `max_execution_time` and your web server allow the callback to finish within the gateway's 30 second limit.

## Tests

```
cd integrations/opencart
composer install
vendor/bin/phpunit
```

The tests need no OpenCart. They cover a known-good signature vector (GET and JSON POST, `tests/vector.json`), missing, malformed and bad signatures, a signature over different bytes, a reordered query string, a wrong configured origin, unparseable, short and non-RSA keys, a `uuid` replay, partial and over payments, pending callbacks, rollback and retry, the API client request shape and error mapping, and the decimal arithmetic.

They also drive the payment window through `CheckoutService`, `CallbackProcessor` and `ExpiryNotifier` against an in-memory store that enforces the order and row locks: renewal after expiry, a late callback on a superseded attempt, the old callback paying during the renewal's create request, one expiry entry per attempt, the confirm and status payloads (`PaymentPage`) after expiry, and the schema upgrade statements.

`CatalogControllerTest` drives the real catalog controller (`confirm()` and `status()`) with small OpenCart stubs for the registry, loader, config, order model and `addHistory()`: a payment right after the renewal or during its create request keeps the paid status, and no history is written for a paid order.

Not covered without a store: the admin controller and language files, real MySQL locking and SQL execution, and the checkout JavaScript (the template is only checked textually).

## Mock-mode end-to-end script

Run it by hand against a running gateway in mock mode (it is not part of `phpunit`):

```
php scripts/mock_payment.php <base-url> [api-key] \
  --payout=<payout address> --control-url=http://127.0.0.1:<control port>/mock/control \
  --contract=<token contract in the mock registry> [--ticker=trc20/usdt] [--amount=25.00] \
  [--listen=127.0.0.1:8099] [--callback-origin=http://127.0.0.1:8099] \
  [--chain=tron] [--network=mock] [--decimals=6] [--from=<sender address>] [--timeout=60]
```

It fetches the public key, starts a small callback receiver on `--listen`, creates a deposit address with that receiver as the callback, mines and finalizes a mock deposit through the loopback control endpoint, then waits for the signed callback, verifies it with the extension's verifier and checks that a tampered copy is rejected. The public API needs no key, so `api-key` is accepted but not sent. Without `--control-url` it prints the address and waits for you to send funds. The callback host must be accepted by the gateway's callback URL checks (plain `http` loopback is only possible in a mock environment).

## OpenCart 3.x

The classes in `system/library/suawave` need no change (they have no OpenCart dependency except the two small `OpenCart*` adapters); only the `require_once` path in the controllers changes to `DIR_SYSTEM . 'library/suawave/autoload.php'`. What differs:

- Layout: files go to `admin/controller/extension/payment/suawave.php`, `admin/model/...`, `admin/language/en-gb/extension/payment/suawave.php`, `admin/view/template/extension/payment/suawave.twig` (`.tpl` on 3.0.x with PHP templates), and the same under `catalog/`. There is no `extension/suawave` folder and no `install.json`. The library goes to `system/library/suawave`.
- No namespaces: classes are `ControllerExtensionPaymentSuawave` and `ModelExtensionPaymentSuawave`, and routes use `/` instead of `.` (`extension/payment/suawave/callback`).
- The payment method is returned from `getMethod($address, $total)` instead of `getMethods()`, and the confirm step calls `$this->model_checkout_order->addOrderHistory()` instead of `addHistory()`.
- Settings keys are `payment_suawave_*` in 3.x too, saved with `model_setting_setting->editSetting('payment_suawave', ...)`.
- Table creation runs from the admin model's `install()` as in 4.x.

The 3.x port is not included and was not tested.
