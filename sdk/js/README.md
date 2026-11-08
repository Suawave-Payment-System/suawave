# @suawave/gateway-client

TypeScript/JavaScript client for the gateway API. Zero runtime dependencies: it uses `fetch` and, for signature checks in Node, `node:crypto`. ESM and CommonJS builds with type declarations. Works in Node 20+ and in browsers.

```
npm install @suawave/gateway-client
```

## Amounts are strings

Every amount (`amount`, `received_amount`, fees, minimums) is a decimal string such as `"100.50"`. Never pass or store a JavaScript `number` for money: `0.1 + 0.2` is not `0.3`. The client rejects a non-string or malformed amount before sending anything. If you need arithmetic, use `BigInt` on integer base units:

```js
import { toBaseUnits, fromBaseUnits } from '@suawave/gateway-client';

const units = toBaseUnits('100.50', 6);
const total = fromBaseUnits(units * 3n, 6);
```

## Create a payment

Merchant operations need your API key. Keep it on your server, never in browser code.

```js
import { GatewayClient, ApiError, RateLimitError } from '@suawave/gateway-client';

const client = new GatewayClient({
  baseUrl: process.env.GATEWAY_BASE_URL,
  apiKey: process.env.GATEWAY_API_KEY,
});

try {
  const payment = await client.createPayment(
    { external_id: 'order_1001', network: 'TRON', asset: 'USDT', amount: '100.50' },
    { idempotencyKey: 'order_1001-create' },
  );
  console.log(payment.id, payment.deposit_address, payment.expires_at);

  const current = await client.getPayment(payment.id);
  console.log(current.status, current.received_amount);
} catch (error) {
  if (error instanceof RateLimitError) {
    console.error(`slow down, retry in ${error.retryAfterSeconds ?? 1}s`);
  } else if (error instanceof ApiError) {
    console.error(error.status, error.code, error.message, error.requestId);
  } else {
    throw error;
  }
}
```

Without an `idempotencyKey` the client generates a random one. Pass your own, derived from your order, so that retrying after a timeout cannot create a second payment: the gateway returns the stored response for the same key and request.

## Operations

Merchant (API key required): `createPayment`, `getPayment`, `getPaymentByExternalId`, `listPayments` (cursor pages), `iteratePayments` (async iterator over all pages), `listNetworks`, `listFees`.

No-registration public API (no key): `publicCreate`, `publicInfo`, `publicLogs`, `publicEstimate`, `publicQrCode`, `publicConvert`, `publicTickerConvert`, `publicCallbackKey`. Tickers keep their slash, for example `"trc20/usdt"`.

Every method accepts `{ signal }` to cancel; `timeoutMs` in the constructor (default 30 seconds) bounds each request.

## Errors

| Class | When |
|---|---|
| `ApiError` | HTTP error. `status`, `code` (for example `DUPLICATE_EXTERNAL_ID`), `message`, `requestId`, `body`. |
| `RateLimitError` | HTTP 429, extends `ApiError`. `retryAfterSeconds` from the `Retry-After` header, or `undefined`. |
| `NetworkError` | Connection failure, timeout or abort. `aborted` is `true` for timeout and abort. |
| `MalformedResponseError` | A success status with a body that is not the expected JSON. |
| `InvalidAmountError` | An amount that is not a plain decimal string. |

## Verify callbacks (Node only)

Signature checks live in a separate entry point, `@suawave/gateway-client/verify`, because it imports `node:crypto`. The main entry never imports a Node built-in, so bundlers can ship it to browsers.

Two schemes exist; use the one that matches how you registered.

**Merchant webhooks** (`X-Gateway-Signature: v1=<hex>`, HMAC-SHA256 over `timestamp + "." + raw body`, 5 minute tolerance). Express needs the raw bytes, so use `express.raw`:

```js
import express from 'express';
import { constructWebhookEvent, SignatureVerificationError } from '@suawave/gateway-client/verify';

const app = express();

app.post('/webhooks/gateway', express.raw({ type: 'application/json' }), (req, res) => {
  let event;
  try {
    event = constructWebhookEvent({
      secret: process.env.GATEWAY_WEBHOOK_SECRET,
      headers: req.headers,
      rawBody: req.body,
    });
  } catch (error) {
    if (error instanceof SignatureVerificationError) {
      return res.status(401).send(error.reason);
    }
    throw error;
  }
  if (!alreadyProcessed(event.id)) {
    storeAndQueue(event);
  }
  res.sendStatus(200);
});
```

`event.data.amount` and `event.data.received_amount` are decimal strings. Deduplicate on `event.id`, and treat `GET /v1/payments/{id}` as the source of truth. See `docs/api/webhooks.md` for retries and ordering.

**Public API callbacks** (`x-ca-signature`, base64 RSA-SHA256 over the exact full GET URL or the raw POST body). Fetch the key once with `client.publicCallbackKey()` and cache it:

```js
import { verifyCallbackSignature } from '@suawave/gateway-client/verify';

verifyCallbackSignature({
  publicKeyPem: pubkey,
  signature: req.headers['x-ca-signature'],
  signedData: `${process.env.PUBLIC_ORIGIN}${req.originalUrl}`,
});
```

Both functions throw `SignatureVerificationError` with a `reason`: `missing_timestamp`, `invalid_timestamp`, `stale_timestamp`, `missing_signature`, `invalid_signature`, `invalid_public_key` or `invalid_body`.

## Develop

```
npm ci
npm run build
npm test
```

End-to-end check against a running gateway (not part of `npm test`):

```
npm run build
node scripts/e2e.mjs https://api.example.test <api-key>
node scripts/e2e.mjs https://api.example.test <api-key> --wait-for-status settled --timeout-seconds 180
```

The first form creates a payment and exercises the API. With `--wait-for-status` the script prints the deposit address and polls until you deposit on the mock chain and the payment reaches that status.

The API base URL defaults to `https://api.suawave.com` when `baseUrl` is omitted.
