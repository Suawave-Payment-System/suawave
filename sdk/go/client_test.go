package suawave

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

const paymentJSON = `{"id":"pay_0192f5d6-0000-7000-8000-000000000001","external_id":"order_1","network":"TRON","asset":"USDT","amount":"100.500000","received_amount":"0.000000","deposit_address":"TDeposit","settlement_address":"TSettle","status":"waiting","outcome":null,"expires_at":"2026-10-28T12:00:00Z","confirmed_at":null,"created_at":"2026-10-28T11:00:00Z","transfers":[],"settlement":{"status":"pending","tx_hash":null,"net_amount":"99.000000","service_fee":"1.000000","network_fee":"0.500000","confirmed_at":null}}`

func newTestClient(t *testing.T, h http.HandlerFunc, opts ...Option) *Client {
	t.Helper()
	srv := httptest.NewServer(h)
	t.Cleanup(srv.Close)
	c, err := New(srv.URL, "key_test", opts...)
	if err != nil {
		t.Fatal(err)
	}
	return c
}

func writeJSON(w http.ResponseWriter, status int, body string) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_, _ = io.WriteString(w, body)
}

func TestNewRejectsBadBaseURL(t *testing.T) {
	for _, base := range []string{"ftp://x", "/relative", "http://"} {
		if _, err := New(base, "k"); !errors.Is(err, ErrInvalidArgument) {
			t.Fatalf("%q: got %v", base, err)
		}
	}
}

func TestCreatePayment(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost || r.URL.Path != "/v1/payments" {
			t.Errorf("unexpected %s %s", r.Method, r.URL.Path)
		}
		if r.Header.Get("Authorization") != "Bearer key_test" {
			t.Errorf("auth header %q", r.Header.Get("Authorization"))
		}
		if r.Header.Get("Idempotency-Key") != "idem-1" {
			t.Errorf("idempotency key %q", r.Header.Get("Idempotency-Key"))
		}
		var got map[string]any
		if err := json.NewDecoder(r.Body).Decode(&got); err != nil {
			t.Error(err)
		}
		if got["amount"] != "100.500000" || got["external_id"] != "order_1" {
			t.Errorf("body %v", got)
		}
		if _, ok := got["settlement_address"]; ok {
			t.Error("empty settlement_address must be omitted")
		}
		if _, ok := got["IdempotencyKey"]; ok {
			t.Error("idempotency key leaked into body")
		}
		w.Header().Set("Idempotent-Replayed", "true")
		writeJSON(w, 201, paymentJSON)
	})
	p, err := c.CreatePayment(t.Context(), CreatePaymentParams{ExternalID: "order_1", Network: "TRON", Asset: "USDT", Amount: "100.500000", IdempotencyKey: "idem-1"})
	if err != nil {
		t.Fatal(err)
	}
	if p.ID != "pay_0192f5d6-0000-7000-8000-000000000001" || p.Amount != "100.500000" || p.Status != PaymentWaiting {
		t.Fatalf("payment %+v", p)
	}
	if !p.Replayed || p.Outcome != "" || p.ConfirmedAt != nil || p.Settlement == nil || p.Settlement.NetAmount != "99.000000" {
		t.Fatalf("payment details %+v", p)
	}
}

func TestCreatePaymentGeneratesIdempotencyKey(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		if len(r.Header.Get("Idempotency-Key")) != 32 {
			t.Errorf("generated key %q", r.Header.Get("Idempotency-Key"))
		}
		writeJSON(w, 201, paymentJSON)
	})
	p, err := c.CreatePayment(t.Context(), CreatePaymentParams{ExternalID: "o", Network: "TRON", Asset: "USDT", Amount: "1"})
	if err != nil || p.Replayed {
		t.Fatalf("%v %+v", err, p)
	}
}

func TestCreatePaymentValidatesLocally(t *testing.T) {
	c := newTestClient(t, func(http.ResponseWriter, *http.Request) { t.Error("request must not be sent") })
	for _, p := range []CreatePaymentParams{
		{Network: "TRON", Asset: "USDT", Amount: "1"},
		{ExternalID: strings.Repeat("a", 256), Network: "TRON", Asset: "USDT", Amount: "1"},
		{ExternalID: "o", Asset: "USDT", Amount: "1"},
	} {
		if _, err := c.CreatePayment(t.Context(), p); !errors.Is(err, ErrInvalidArgument) {
			t.Fatalf("got %v", err)
		}
	}
}

func TestGetPayment(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet || r.URL.Path != "/v1/payments/pay_0192f5d6-0000-7000-8000-000000000001" {
			t.Errorf("unexpected %s %s", r.Method, r.URL.Path)
		}
		writeJSON(w, 200, paymentJSON)
	})
	p, err := c.GetPayment(t.Context(), "pay_0192f5d6-0000-7000-8000-000000000001")
	if err != nil || p.DepositAddress != "TDeposit" {
		t.Fatalf("%v %+v", err, p)
	}
	if _, err := c.GetPayment(t.Context(), ""); !errors.Is(err, ErrInvalidArgument) {
		t.Fatalf("got %v", err)
	}
}

func TestGetPaymentByExternalID(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v1/payments" || r.URL.Query().Get("external_id") != "order 1&x" || len(r.URL.Query()) != 1 {
			t.Errorf("unexpected %s", r.URL.String())
		}
		writeJSON(w, 200, paymentJSON)
	})
	p, err := c.GetPaymentByExternalID(t.Context(), "order 1&x")
	if err != nil || p.ExternalID != "order_1" {
		t.Fatalf("%v %+v", err, p)
	}
}

func TestListPayments(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		q := r.URL.Query()
		if q.Get("limit") != "5" || q.Get("cursor") != "abc" || q.Get("status") != "settled" {
			t.Errorf("query %v", q)
		}
		writeJSON(w, 200, `{"data":[`+paymentJSON+`],"has_more":true,"next_cursor":"next"}`)
	})
	page, err := c.ListPayments(t.Context(), ListPaymentsParams{Limit: 5, Cursor: "abc", Status: PaymentSettled})
	if err != nil || len(page.Data) != 1 || !page.HasMore || page.NextCursor != "next" {
		t.Fatalf("%v %+v", err, page)
	}
	if _, err := c.ListPayments(t.Context(), ListPaymentsParams{Limit: 101}); !errors.Is(err, ErrInvalidArgument) {
		t.Fatalf("got %v", err)
	}
}

func TestListPaymentsLastPage(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		if len(r.URL.Query()) != 0 {
			t.Errorf("query %v", r.URL.Query())
		}
		writeJSON(w, 200, `{"data":[],"has_more":false,"next_cursor":null}`)
	})
	page, err := c.ListPayments(t.Context(), ListPaymentsParams{})
	if err != nil || page.HasMore || page.NextCursor != "" {
		t.Fatalf("%v %+v", err, page)
	}
}

func TestListNetworksAndFees(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/v1/networks":
			writeJSON(w, 200, `{"data":[{"network":"TRON","display_name":"Tron","paused":false,"assets":[{"asset":"USDT","contract_address":"TR7","decimals":6,"min_amount":"10.000000","max_amount":"50000.000000"}]}]}`)
		case "/v1/fees":
			writeJSON(w, 200, `{"data":[{"network":"TRON","asset":"USDT","decimals":6,"profile_id":"7f9d0d3e-1111-4222-8333-444455556666","scope":"default","service_fee_bps":50,"minimum_service_fee":"1.000000","network_fee_mode":"fixed","network_fee":"2.000000","effective_from":"2026-10-01T00:00:00Z"}]}`)
		default:
			t.Errorf("unexpected path %s", r.URL.Path)
		}
	})
	networks, err := c.ListNetworks(t.Context())
	if err != nil || len(networks) != 1 || networks[0].Assets[0].MinAmount != "10.000000" || networks[0].Assets[0].Decimals != 6 {
		t.Fatalf("%v %+v", err, networks)
	}
	fees, err := c.ListFees(t.Context())
	if err != nil || len(fees) != 1 || fees[0].ServiceFeeBps != 50 || fees[0].NetworkFee != "2.000000" {
		t.Fatalf("%v %+v", err, fees)
	}
}

func TestTypedError4xx(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("X-Request-Id", "hdr-req")
		writeJSON(w, 400, `{"error":{"code":"AMOUNT_BELOW_MINIMUM","message":"amount below minimum","request_id":"req_1"}}`)
	})
	_, err := c.CreatePayment(t.Context(), CreatePaymentParams{ExternalID: "o", Network: "TRON", Asset: "USDT", Amount: "1"})
	apiErr, ok := AsError(err)
	if !ok {
		t.Fatalf("got %v", err)
	}
	if apiErr.Status != 400 || apiErr.Code != ErrCodeAmountBelowMinimum || apiErr.RequestID != "req_1" || apiErr.Retryable() || apiErr.IsRateLimited() {
		t.Fatalf("error %+v", apiErr)
	}
	if !strings.Contains(err.Error(), "AMOUNT_BELOW_MINIMUM") {
		t.Fatalf("message %q", err.Error())
	}
}

func TestTypedError404(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, 404, `{"error":{"code":"PAYMENT_NOT_FOUND","message":"payment not found","request_id":"r"}}`)
	})
	_, err := c.GetPayment(t.Context(), "pay_x")
	if apiErr, ok := AsError(err); !ok || apiErr.Status != 404 || apiErr.Code != ErrCodePaymentNotFound {
		t.Fatalf("got %v", err)
	}
}

func TestRateLimitedRetryHint(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Retry-After", "60")
		writeJSON(w, 429, `{"error":{"code":"RATE_LIMITED","message":"rate limit exceeded","request_id":"r"}}`)
	})
	_, err := c.ListFees(t.Context())
	apiErr, ok := AsError(err)
	if !ok || !apiErr.IsRateLimited() || !apiErr.Retryable() || apiErr.Code != ErrCodeRateLimited || apiErr.RetryAfter != time.Minute {
		t.Fatalf("got %v %+v", err, apiErr)
	}
}

func TestRetryAfterHTTPDate(t *testing.T) {
	now := time.Date(2026, 10, 28, 12, 0, 0, 0, time.UTC)
	if d := parseRetryAfter(now.Add(90*time.Second).Format(http.TimeFormat), now); d != 90*time.Second {
		t.Fatalf("got %v", d)
	}
	for _, v := range []string{"", "-5", "soon", now.Add(-time.Hour).Format(http.TimeFormat)} {
		if d := parseRetryAfter(v, now); d != 0 {
			t.Fatalf("%q: got %v", v, d)
		}
	}
}

func TestPublicErrorEnvelope(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Retry-After", "7")
		writeJSON(w, 429, `{"status":"error","error":"Too many requests"}`)
	})
	_, err := c.PublicInfo(t.Context(), "trc20/usdt")
	apiErr, ok := AsError(err)
	if !ok || apiErr.Status != 429 || apiErr.Code != "" || apiErr.Message != "Too many requests" || apiErr.RetryAfter != 7*time.Second {
		t.Fatalf("got %v %+v", err, apiErr)
	}
}

func TestPlainTextError(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/plain")
		w.WriteHeader(503)
		_, _ = io.WriteString(w, "public key unavailable")
	})
	_, err := c.PublicCallbackKey(t.Context())
	apiErr, ok := AsError(err)
	if !ok || apiErr.Status != 503 || apiErr.Message != "public key unavailable" {
		t.Fatalf("got %v", err)
	}
}

func TestMalformedBody(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, 200, `{"id": "pay_1", "amount": `)
	})
	_, err := c.GetPayment(t.Context(), "pay_1")
	if !errors.Is(err, ErrMalformedResponse) {
		t.Fatalf("got %v", err)
	}
	if _, ok := AsError(err); ok {
		t.Fatal("malformed body must not be an API error")
	}
}

func TestMalformedErrorBodyStillTyped(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, 502, `<html>bad gateway</html>`)
	})
	_, err := c.ListNetworks(t.Context())
	apiErr, ok := AsError(err)
	if !ok || apiErr.Status != 502 || !apiErr.Retryable() || !strings.Contains(apiErr.Message, "bad gateway") {
		t.Fatalf("got %v", err)
	}
}

func TestContextCancellation(t *testing.T) {
	release := make(chan struct{})
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		select {
		case <-release:
		case <-r.Context().Done():
		}
	})
	defer close(release)
	ctx, cancel := context.WithCancel(t.Context())
	go func() {
		time.Sleep(20 * time.Millisecond)
		cancel()
	}()
	_, err := c.GetPayment(ctx, "pay_1")
	if !errors.Is(err, context.Canceled) {
		t.Fatalf("got %v", err)
	}
}

func TestTimeoutOption(t *testing.T) {
	release := make(chan struct{})
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		select {
		case <-release:
		case <-r.Context().Done():
		}
	}, WithTimeout(30*time.Millisecond))
	defer close(release)
	if _, err := c.ListFees(t.Context()); err == nil {
		t.Fatal("expected timeout")
	}
}

func TestMissingAPIKey(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(http.ResponseWriter, *http.Request) { t.Error("must not be sent") }))
	defer srv.Close()
	c, err := New(srv.URL, "")
	if err != nil {
		t.Fatal(err)
	}
	if _, err := c.ListFees(t.Context()); !errors.Is(err, ErrNoAPIKey) {
		t.Fatalf("got %v", err)
	}
}

func TestBaseURLPathPrefix(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/gateway/v1/fees" {
			t.Errorf("path %s", r.URL.Path)
		}
		writeJSON(w, 200, `{"data":[]}`)
	}))
	defer srv.Close()
	c, err := New(srv.URL+"/gateway/", "k", WithUserAgent("shop/1"))
	if err != nil {
		t.Fatal(err)
	}
	if _, err := c.ListFees(t.Context()); err != nil {
		t.Fatal(err)
	}
}

func TestPublicOperations(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "" {
			t.Errorf("public call sent credentials on %s", r.URL.Path)
		}
		q := r.URL.Query()
		switch r.URL.Path {
		case "/trc20/usdt/create/":
			if q.Get("callback") != "https://shop.example/hook?order=1&n=2" || q.Get("address") != "TPayout" || q.Get("pending") != "1" || q.Get("json") != "1" || q.Get("post") != "" || q.Get("convert") != "" {
				t.Errorf("create query %v", q)
			}
			writeJSON(w, 200, `{"status":"success","address_in":"TIn","address_out":"TPayout","callback_url":"https://shop.example/hook?order=1&n=2","minimum_transaction_coin":"10.000000"}`)
		case "/trc20/usdt/info/":
			writeJSON(w, 200, `{"status":"success","coin":"USDT","ticker":"trc20/usdt","minimum_transaction_coin":"10.000000"}`)
		case "/trc20/usdt/logs/":
			if q.Get("callback") == "" {
				t.Error("callback missing")
			}
			writeJSON(w, 200, `{"status":"success","address_in":"TIn","address_out":"TOut","callback_url":"https://shop.example/hook","notify_pending":true,"notify_confirmations":19,"priority":"default","callbacks":[{"txid_in":"a","txid_out":"b","value_coin":"100.000000","value_forwarded_coin":"98.200000","confirmations":21,"last_update":"2026-10-25T12:00:00Z","result":"done","fee_percent":"0.50","fee_coin":"1.800000","logs":[{"request_url":"https://shop.example/hook?x=1","response_status":200,"responses":"*ok*","next_try":null,"pending":0,"confirmed":1}]}]}`)
		case "/trc20/usdt/estimate/":
			writeJSON(w, 200, `{"status":"success","estimated_cost":"15.000000"}`)
		case "/trc20/usdt/qrcode/":
			if q.Get("address") != "TIn" || q.Get("value") != "25.5" || q.Get("size") != "256" {
				t.Errorf("qr query %v", q)
			}
			writeJSON(w, 200, `{"status":"success","qr_code":"data:image/png;base64,AAAA","payment_uri":"TIn"}`)
		case "/convert/":
			if q.Get("value") != "100" || q.Get("from") != "eur" || q.Get("to") != "usd" {
				t.Errorf("convert query %v", q)
			}
			writeJSON(w, 200, `{"status":"success","value_coin":"108.69","exchange_rate":"1.086956521739"}`)
		case "/trc20/usdt/convert/":
			writeJSON(w, 200, `{"status":"success","value_coin":"108.695652","exchange_rate":"1.086956521739"}`)
		case "/pubkey/":
			pem, err := testFile("callback_public.pubkey")
			if err != nil {
				t.Error(err)
			}
			body, _ := json.Marshal(map[string]string{"status": "success", "pubkey": string(pem)})
			writeJSON(w, 200, string(body))
		default:
			t.Errorf("unexpected path %s", r.URL.Path)
			w.WriteHeader(404)
		}
	})
	ctx := t.Context()
	order, err := c.PublicCreate(ctx, "trc20/usdt", PublicCreateParams{Callback: "https://shop.example/hook?order=1&n=2", Address: "TPayout", Pending: true, JSON: true})
	if err != nil || order.AddressIn != "TIn" || order.MinimumTransactionCoin != "10.000000" {
		t.Fatalf("%v %+v", err, order)
	}
	info, err := c.PublicInfo(ctx, "trc20/usdt")
	if err != nil || info.Coin != "USDT" {
		t.Fatalf("%v %+v", err, info)
	}
	logs, err := c.PublicLogs(ctx, "trc20/usdt", "https://shop.example/hook")
	if err != nil || !logs.NotifyPending || logs.Callbacks[0].Result != "done" || logs.Callbacks[0].Logs[0].NextTry != nil || logs.Callbacks[0].ValueForwardedCoin != "98.200000" {
		t.Fatalf("%v %+v", err, logs)
	}
	est, err := c.PublicEstimate(ctx, "trc20/usdt")
	if err != nil || est.EstimatedCost != "15.000000" {
		t.Fatalf("%v %+v", err, est)
	}
	qr, err := c.PublicQRCode(ctx, "trc20/usdt", PublicQRCodeParams{Address: "TIn", Value: "25.5", Size: 256})
	if err != nil || qr.PaymentURI != "TIn" {
		t.Fatalf("%v %+v", err, qr)
	}
	conv, err := c.PublicConvert(ctx, "100", "eur", "usd")
	if err != nil || conv.ValueCoin != "108.69" {
		t.Fatalf("%v %+v", err, conv)
	}
	tconv, err := c.PublicTickerConvert(ctx, "trc20/usdt", "100", "usd")
	if err != nil || tconv.ExchangeRate != "1.086956521739" {
		t.Fatalf("%v %+v", err, tconv)
	}
	key, err := c.PublicCallbackKey(ctx)
	if err != nil || key == nil {
		t.Fatalf("%v", err)
	}
}

func TestPublicTickerValidation(t *testing.T) {
	c := newTestClient(t, func(http.ResponseWriter, *http.Request) { t.Error("request must not be sent") })
	for _, ticker := range []string{"", "/", "trc20//usdt"} {
		if _, err := c.PublicInfo(t.Context(), ticker); !errors.Is(err, ErrInvalidArgument) {
			t.Fatalf("%q: got %v", ticker, err)
		}
	}
}

func TestNewUsesSuawaveDefaults(t *testing.T) {
	c, err := New("", "")
	if err != nil {
		t.Fatal(err)
	}
	if c.base.String() != "https://api.suawave.com" || c.userAgent != "suawave-go/1" {
		t.Fatalf("unexpected defaults: %s %s", c.base, c.userAgent)
	}
}
