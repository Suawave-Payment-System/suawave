# suawave Go client

Go client for Suawave: call the public no-registration endpoints and the authenticated merchant API, and verify the callbacks the gateway sends you. Standard library only, no dependencies. Amounts are always decimal strings (`"100.50"`); the library never converts them to floating point and neither should you.

The module path is `github.com/Suawave-Payment-System/suawave/sdk/go` and the package name is `suawave`. The public source lives in the GitHub organisation `Suawave-Payment-System`. An empty base URL defaults to `https://api.suawave.com`.

```go
import suawave "github.com/Suawave-Payment-System/suawave/sdk/go"
```

Requires Go 1.24 or newer.

## Create a deposit address (public API)

The public endpoints need no registration and no API key. You give the gateway a payout address and a callback URL; it gives you a permanent deposit address and calls your URL when money arrives.

```go
package main

import (
	"context"
	"fmt"
	"log"
	"os"
	"time"

	suawave "github.com/Suawave-Payment-System/suawave/sdk/go"
)

func main() {
	client, err := suawave.New(os.Getenv("GATEWAY_URL"), "")
	if err != nil {
		log.Fatal(err)
	}

	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()

	order, err := client.PublicCreate(ctx, "trc20/usdt", suawave.PublicCreateParams{
		Callback: "https://shop.example/hooks/crypto?order=1001&nonce=7f3a9c",
		Address:  os.Getenv("PAYOUT_ADDRESS"),
		Pending:  true,
		JSON:     true,
	})
	if err != nil {
		log.Fatal(err)
	}
	fmt.Printf("ask the customer to send at least %s USDT to %s\n", order.MinimumTransactionCoin, order.AddressIn)

	logs, err := client.PublicLogs(ctx, "trc20/usdt", order.CallbackURL)
	if err != nil {
		log.Fatal(err)
	}
	fmt.Println(len(logs.Callbacks), "deposits so far")
}
```

Put an unguessable value (the `nonce` above) in the callback URL: the gateway returns the same address for the same ticker and callback pair, so a unique callback per order gives you a unique address per order.

## Handle callbacks (public API, RSA signature)

The gateway calls your callback URL with `GET` (fields in the query string), a form `POST` (`post=1`) or a JSON `POST` (`json=1`). Every value is a string. The header `x-ca-signature` is a base64 RSA-SHA256 (PKCS1 v1.5) signature over the exact full URL for `GET` or the raw body for `POST`.

`VerifyRequest` takes the request as it arrived and reads the raw bytes itself, so there is no way to hand it a re-serialized body. For `GET` it signs `origin + RequestURI()`, in the original query order; pass the external origin your gateway was given in the callback URL (not the host your reverse proxy forwards).

```go
package main

import (
	"context"
	"errors"
	"log"
	"net/http"
	"os"
	"time"

	suawave "github.com/Suawave-Payment-System/suawave/sdk/go"
)

const origin = "https://shop.example"

func main() {
	client, err := suawave.New(os.Getenv("GATEWAY_URL"), "")
	if err != nil {
		log.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	key, err := client.PublicCallbackKey(ctx)
	cancel()
	if err != nil {
		log.Fatal(err)
	}
	seen := suawave.NewReplayGuard(7*24*time.Hour, 100000)

	http.HandleFunc("/hooks/crypto", func(w http.ResponseWriter, r *http.Request) {
		cb, err := key.VerifyRequest(r, origin)
		if err != nil {
			http.Error(w, "invalid callback", http.StatusUnauthorized)
			return
		}
		if err := seen.Claim(cb.UUID); err != nil {
			if errors.Is(err, suawave.ErrReplayedCallback) {
				suawave.AckPublicCallback(w)
				return
			}
			http.Error(w, "try again", http.StatusServiceUnavailable)
			return
		}
		switch cb.Kind() {
		case suawave.CallbackPending:
			log.Printf("deposit of %s seen on %s, wait for the confirmed callback", cb.TxidIn, cb.AddressIn)
		case suawave.CallbackConfirmed:
			log.Printf("order %s paid: %s received, %s forwarded", r.URL.Query().Get("order"), cb.ValueCoin, cb.ValueForwardedCoin)
		}
		suawave.AckPublicCallback(w)
	})

	log.Fatal(http.ListenAndServe(":8080", nil))
}
```

Rules for the handler:

- **Never fulfil an order on a pending callback.** `cb.Kind() == suawave.CallbackPending` means a deposit was seen, nothing more. Fulfil only on `CallbackConfirmed`.
- **`uuid` is the deduplication key.** Delivery is at least once and retries carry the same `uuid`; pending and confirmed events have different ones. `ReplayGuard` is an in-memory guard for a single process. For production keep the `uuid` in a table with a unique index, claim it in the same transaction as your order update, and answer `*ok*` for a `uuid` you already have. Call `Release` if processing fails after `Claim`, so the retry is not mistaken for a replay.
- Answer HTTP 200 with the body `*ok*` (`AckPublicCallback`) within 30 seconds, after storing the event.
- Amounts (`value_coin`, `value_forwarded_coin`, `fee_coin`) are decimal strings. Compare and add them with `math/big` or a decimal library, never `float64`.

Verification rejects, each with its own error for `errors.Is`:

| Case | Error |
|---|---|
| no `x-ca-signature` header | `ErrMissingCallbackSignature` |
| signature not base64, truncated, wrong, or made over different bytes or a different URL (including a reordered query or another origin) | `ErrInvalidCallbackSignature` |
| method other than GET or POST | `ErrUnsupportedCallback` |
| body over the limit (`VerifyRequestLimit`, default 1 MiB) | `ErrCallbackBodyTooLarge` |
| signed but not a valid callback (missing `uuid`, `pending` not `0`/`1`, confirmed without amounts) | `ErrMalformedResponse` |
| a `uuid` already claimed (`ReplayGuard.Claim`) | `ErrReplayedCallback` |

`PublicCallbackKey` refuses a key that is not PEM, not PKIX, not RSA, or shorter than 2048 bits (`ErrInvalidPublicKey`). Fetch the key once with `PublicCallbackKey(ctx)` (or parse your own copy with `ParsePublicCallbackKey`) and keep it in memory; do not fetch it per callback.

## Merchant API: create a payment

With a merchant API key you create payments under `/v1`.

```go
package main

import (
	"context"
	"errors"
	"fmt"
	"log"
	"os"
	"time"

	suawave "github.com/Suawave-Payment-System/suawave/sdk/go"
)

func main() {
	client, err := suawave.New(os.Getenv("GATEWAY_URL"), os.Getenv("GATEWAY_API_KEY"))
	if err != nil {
		log.Fatal(err)
	}

	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()

	payment, err := client.CreatePayment(ctx, suawave.CreatePaymentParams{
		ExternalID:     "order_1001",
		Network:        "TRON",
		Asset:          "USDT",
		Amount:         "100.50",
		IdempotencyKey: "order_1001-create",
	})
	if err != nil {
		var apiErr *suawave.Error
		if errors.As(err, &apiErr) {
			if apiErr.IsRateLimited() {
				log.Fatalf("rate limited, retry in %s", apiErr.RetryAfter)
			}
			log.Fatalf("gateway refused: status %d, code %s, request %s", apiErr.Status, apiErr.Code, apiErr.RequestID)
		}
		log.Fatal(err)
	}
	fmt.Printf("pay %s to %s before %s\n", payment.Amount, payment.DepositAddress, payment.ExpiresAt.Format(time.RFC3339))

	current, err := client.GetPayment(ctx, payment.ID)
	if err != nil {
		log.Fatal(err)
	}
	fmt.Println(current.Status, current.ReceivedAmount)
}
```

Always set `IdempotencyKey` to something derived from your order. If a request times out you can repeat it with the same key and get the stored response back (`payment.Replayed` is then true) instead of a second payment. Without a key the library generates a random one, which does not protect a retry.

### Merchant API webhooks (HMAC)

Merchant-API payments send `X-Gateway-Signature: v1=<hex>` webhooks instead of RSA callbacks. This is a separate scheme, documented in `docs/api/webhooks.md`: HMAC-SHA256 over `X-Gateway-Timestamp + "." + raw body` with your webhook secret. `WebhookVerifier.ParseRequest(r)` verifies and decodes an event; pass both secrets during a rotation (`NewWebhookVerifier(newSecret, oldSecret)`). Timestamps more than 5 minutes away from your clock are rejected.

| Case | Error |
|---|---|
| no `X-Gateway-Signature` header | `ErrMissingSignature` |
| no `X-Gateway-Timestamp` header | `ErrMissingTimestamp` |
| timestamp not an integer | `ErrInvalidTimestamp` |
| timestamp outside +/- 5 minutes (stale or replayed) | `ErrStaleTimestamp` |
| header without any `v1=` entry | `ErrUnsupportedScheme` |
| wrong signature, wrong secret, or different body bytes | `ErrInvalidSignature` |

Deduplicate on `event.ID`. Webhooks are notifications only: confirm with `GetPayment` before you ship goods.

## Operations

| Method | Endpoint |
|---|---|
| `CreatePayment` | `POST /v1/payments` |
| `GetPayment` | `GET /v1/payments/{id}` |
| `GetPaymentByExternalID` | `GET /v1/payments?external_id=` |
| `ListPayments` | `GET /v1/payments` (keyset pagination via `NextCursor`) |
| `ListNetworks` | `GET /v1/networks` |
| `ListFees` | `GET /v1/fees` |
| `PublicCreate` | `GET /{ticker}/create/` |
| `PublicInfo` | `GET /{ticker}/info/` |
| `PublicLogs` | `GET /{ticker}/logs/` |
| `PublicEstimate` | `GET /{ticker}/estimate/` |
| `PublicQRCode` | `GET /{ticker}/qrcode/` |
| `PublicConvert` | `GET /convert/` |
| `PublicTickerConvert` | `GET /{ticker}/convert/` |
| `PublicCallbackKey` | `GET /pubkey/` (returns a parsed key) |

Public tickers contain a slash, for example `trc20/usdt`. The `/v1` calls need an API key; public calls never send it.

## Errors

Every non-2xx answer is a `*suawave.Error` with `Status`, `Code` (for example `AMOUNT_BELOW_MINIMUM`, `DUPLICATE_EXTERNAL_ID`; empty for the public endpoints, whose errors carry only a message), `Message`, `RequestID` and, for `429`, `RetryAfter` taken from the `Retry-After` header. `Retryable()` is true for 429 and 5xx. A body that cannot be decoded gives `ErrMalformedResponse`. Network failures and context cancellation are returned as the underlying error, so `errors.Is(err, context.Canceled)` works.

## Options

```go
client, err := suawave.New(baseURL, apiKey,
	suawave.WithTimeout(10*time.Second),
	suawave.WithHTTPClient(myHTTPClient),
	suawave.WithUserAgent("myshop/1.0"),
)
```

The default timeout is 30 seconds. Pass a `context` with a deadline to bound a single call.
