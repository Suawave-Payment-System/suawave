package suawave

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

const (
	fixtureSecret    = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
	fixtureTimestamp = "1792670400"
)

func testFile(name string) ([]byte, error) {
	return os.ReadFile(filepath.Join("testdata", name))
}

func mustFile(t *testing.T, name string) []byte {
	t.Helper()
	b, err := testFile(name)
	if err != nil {
		t.Fatal(err)
	}
	return b
}

func fixtureNow() time.Time { return time.Unix(1792670400, 0).Add(30 * time.Second) }

func webhookHeader(t *testing.T) http.Header {
	t.Helper()
	h := http.Header{}
	h.Set(WebhookTimestampHeader, fixtureTimestamp)
	h.Set(WebhookSignatureHeader, "v1="+string(mustFile(t, "webhook_signature_1792670400.txt")))
	return h
}

func signHex(secret, timestamp string, body []byte) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(timestamp + "."))
	mac.Write(body)
	return hex.EncodeToString(mac.Sum(nil))
}

func TestWebhookKnownGoodVector(t *testing.T) {
	body := mustFile(t, "webhook_body.json")
	v, err := NewWebhookVerifier(fixtureSecret)
	if err != nil {
		t.Fatal(err)
	}
	if err := v.Verify(webhookHeader(t), body, fixtureNow()); err != nil {
		t.Fatalf("known-good vector rejected: %v", err)
	}
	if got := signHex(fixtureSecret, fixtureTimestamp, body); got != string(mustFile(t, "webhook_signature_1792670400.txt")) {
		t.Fatalf("fixture drift: %s", got)
	}
	ev, err := DecodeEvent(body)
	if err != nil {
		t.Fatal(err)
	}
	if ev.Type != EventPaymentConfirmed || ev.Data.Amount != "100.000000" || ev.Data.ReceivedAmount != "100.000000" || ev.Data.Outcome != "" || ev.Data.ExternalID != "order_123" {
		t.Fatalf("event %+v", ev)
	}
}

func TestWebhookRejections(t *testing.T) {
	body := mustFile(t, "webhook_body.json")
	v, _ := NewWebhookVerifier(fixtureSecret)
	good := string(mustFile(t, "webhook_signature_1792670400.txt"))
	cases := []struct {
		name   string
		mutate func(h http.Header) []byte
		now    time.Time
		want   error
	}{
		{"bad signature", func(h http.Header) []byte {
			h.Set(WebhookSignatureHeader, "v1="+strings.Repeat("0", 64))
			return body
		}, fixtureNow(), ErrInvalidSignature},
		{"different bytes", func(h http.Header) []byte { return append([]byte(nil), append(body, ' ')...) }, fixtureNow(), ErrInvalidSignature},
		{"missing signature header", func(h http.Header) []byte {
			h.Del(WebhookSignatureHeader)
			return body
		}, fixtureNow(), ErrMissingSignature},
		{"missing timestamp header", func(h http.Header) []byte {
			h.Del(WebhookTimestampHeader)
			return body
		}, fixtureNow(), ErrMissingTimestamp},
		{"malformed timestamp", func(h http.Header) []byte {
			h.Set(WebhookTimestampHeader, "yesterday")
			return body
		}, fixtureNow(), ErrInvalidTimestamp},
		{"unknown scheme", func(h http.Header) []byte {
			h.Set(WebhookSignatureHeader, "v2="+good)
			return body
		}, fixtureNow(), ErrUnsupportedScheme},
		{"non hex signature", func(h http.Header) []byte {
			h.Set(WebhookSignatureHeader, "v1=zzzz")
			return body
		}, fixtureNow(), ErrInvalidSignature},
		{"stale timestamp", func(h http.Header) []byte { return body }, fixtureNow().Add(10 * time.Minute), ErrStaleTimestamp},
		{"future timestamp", func(h http.Header) []byte { return body }, fixtureNow().Add(-10 * time.Minute), ErrStaleTimestamp},
		{"replay with fresh timestamp but old signature", func(h http.Header) []byte {
			h.Set(WebhookTimestampHeader, "1792670460")
			return body
		}, fixtureNow(), ErrInvalidSignature},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			h := webhookHeader(t)
			b := tc.mutate(h)
			if err := v.Verify(h, b, tc.now); !errors.Is(err, tc.want) {
				t.Fatalf("got %v want %v", err, tc.want)
			}
		})
	}
}

func TestWebhookWrongSecretRejected(t *testing.T) {
	v, _ := NewWebhookVerifier(strings.Repeat("f", 64))
	if err := v.Verify(webhookHeader(t), mustFile(t, "webhook_body.json"), fixtureNow()); !errors.Is(err, ErrInvalidSignature) {
		t.Fatalf("got %v", err)
	}
}

func TestWebhookToleranceBoundary(t *testing.T) {
	v, _ := NewWebhookVerifier(fixtureSecret)
	body := mustFile(t, "webhook_body.json")
	at := time.Unix(1792670400, 0)
	if err := v.Verify(webhookHeader(t), body, at.Add(WebhookTolerance)); err != nil {
		t.Fatalf("exactly at tolerance: %v", err)
	}
	if err := v.Verify(webhookHeader(t), body, at.Add(WebhookTolerance+time.Second)); !errors.Is(err, ErrStaleTimestamp) {
		t.Fatalf("got %v", err)
	}
}

func TestWebhookRotationAcceptsEitherSecret(t *testing.T) {
	body := mustFile(t, "webhook_body.json")
	oldSecret := strings.Repeat("a", 64)
	newSecret := fixtureSecret
	h := http.Header{}
	h.Set(WebhookTimestampHeader, fixtureTimestamp)
	h.Set(WebhookSignatureHeader, "v1="+signHex(newSecret, fixtureTimestamp, body)+", v1="+signHex(oldSecret, fixtureTimestamp, body))
	for _, secrets := range [][]string{{newSecret}, {oldSecret}, {newSecret, oldSecret}} {
		v, _ := NewWebhookVerifier(secrets...)
		if err := v.Verify(h, body, fixtureNow()); err != nil {
			t.Fatalf("%d secrets: %v", len(secrets), err)
		}
	}
	v, _ := NewWebhookVerifier(strings.Repeat("b", 64))
	if err := v.Verify(h, body, fixtureNow()); !errors.Is(err, ErrInvalidSignature) {
		t.Fatalf("got %v", err)
	}
}

func TestNewWebhookVerifierValidation(t *testing.T) {
	if _, err := NewWebhookVerifier(); !errors.Is(err, ErrInvalidArgument) {
		t.Fatalf("got %v", err)
	}
	if _, err := NewWebhookVerifier(""); !errors.Is(err, ErrInvalidArgument) {
		t.Fatalf("got %v", err)
	}
}

func TestParseRequest(t *testing.T) {
	v, _ := NewWebhookVerifier(fixtureSecret)
	body := mustFile(t, "webhook_body.json")
	v.now = fixtureNow
	req := httptest.NewRequestWithContext(t.Context(), http.MethodPost, "/hook", strings.NewReader(string(body)))
	req.Header = webhookHeader(t)
	ev, err := v.ParseRequest(req)
	if err != nil || ev.ID != "evt_0192f5d6-8a2b-7c3d-9e4f-a1b2c3d4e5f6" {
		t.Fatalf("%v %+v", err, ev)
	}
	big := httptest.NewRequestWithContext(t.Context(), http.MethodPost, "/hook", strings.NewReader(string(body)))
	big.Header = webhookHeader(t)
	if _, err := v.ParseRequestLimit(big, 10); !errors.Is(err, ErrWebhookBodyTooLong) {
		t.Fatalf("got %v", err)
	}
	bad := httptest.NewRequestWithContext(t.Context(), http.MethodPost, "/hook", strings.NewReader(string(body)))
	if _, err := v.ParseRequest(bad); !errors.Is(err, ErrMissingSignature) {
		t.Fatalf("got %v", err)
	}
}

func TestDecodeEventMalformed(t *testing.T) {
	for _, body := range []string{"", "{", `{"data":{}}`} {
		if _, err := DecodeEvent([]byte(body)); !errors.Is(err, ErrMalformedResponse) {
			t.Fatalf("%q: got %v", body, err)
		}
	}
}

func loadKey(t *testing.T) *PublicCallbackKey {
	t.Helper()
	key, err := ParsePublicCallbackKey(mustFile(t, "callback_public.pubkey"))
	if err != nil {
		t.Fatal(err)
	}
	return key
}

const callbackOrigin = "https://shop.example"

func getRequest(t *testing.T, rawURL, signature string) *http.Request {
	t.Helper()
	parsed, err := url.Parse(rawURL)
	if err != nil {
		t.Fatal(err)
	}
	req := httptest.NewRequestWithContext(t.Context(), http.MethodGet, parsed.RequestURI(), nil)
	if signature != "" {
		req.Header.Set(PublicCallbackSignatureHeader, signature)
	}
	return req
}

func postRequest(t *testing.T, body []byte, contentType, signature string) *http.Request {
	t.Helper()
	req := httptest.NewRequestWithContext(t.Context(), http.MethodPost, "/hook", strings.NewReader(string(body)))
	req.Header.Set("Content-Type", contentType)
	if signature != "" {
		req.Header.Set(PublicCallbackSignatureHeader, signature)
	}
	return req
}

func TestPublicCallbackKnownGoodVectors(t *testing.T) {
	key := loadKey(t)
	rawURL := string(mustFile(t, "callback_get_url.txt"))
	cb, err := key.VerifyRequest(getRequest(t, rawURL, string(mustFile(t, "callback_get_signature.txt"))), callbackOrigin)
	if err != nil {
		t.Fatalf("GET vector: %v", err)
	}
	if cb.UUID != "8c4e2a61-5b3d-47f9-a0e2-1d6c9b7f3e45" || cb.Coin != "trc20_usdt" || cb.Kind() != CallbackConfirmed {
		t.Fatalf("GET fields %+v", cb)
	}
	body := mustFile(t, "callback_body.json")
	fromBody, err := key.VerifyRequest(postRequest(t, body, "application/json", string(mustFile(t, "callback_body_signature.txt"))), "")
	if err != nil {
		t.Fatalf("body vector: %v", err)
	}
	if fromBody.UUID != cb.UUID || fromBody.ValueCoin != "100.000000" || fromBody.IsPending() {
		t.Fatalf("body fields %+v", fromBody)
	}
}

func TestPublicCallbackVerifyCallbackLowLevel(t *testing.T) {
	key := loadKey(t)
	in := IncomingCallback{Method: http.MethodGet, URL: string(mustFile(t, "callback_get_url.txt")), Header: http.Header{}}
	in.Header.Set(PublicCallbackSignatureHeader, string(mustFile(t, "callback_get_signature.txt")))
	if _, err := key.VerifyCallback(in); err != nil {
		t.Fatal(err)
	}
	in.Method = http.MethodPut
	if _, err := key.VerifyCallback(in); !errors.Is(err, ErrUnsupportedCallback) {
		t.Fatalf("got %v", err)
	}
}

func TestPublicCallbackRejections(t *testing.T) {
	key := loadKey(t)
	rawURL := string(mustFile(t, "callback_get_url.txt"))
	sig := string(mustFile(t, "callback_get_signature.txt"))
	bodySig := string(mustFile(t, "callback_body_signature.txt"))
	body := mustFile(t, "callback_body.json")
	flipped := []byte(sig)
	if flipped[10] == 'A' {
		flipped[10] = 'B'
	} else {
		flipped[10] = 'A'
	}
	raw, _ := base64.StdEncoding.DecodeString(sig)
	cases := []struct {
		name string
		req  *http.Request
		want error
	}{
		{"missing signature header GET", getRequest(t, rawURL, ""), ErrMissingCallbackSignature},
		{"missing signature header POST", postRequest(t, body, "application/json", ""), ErrMissingCallbackSignature},
		{"bad signature", getRequest(t, rawURL, string(flipped)), ErrInvalidCallbackSignature},
		{"not base64", getRequest(t, rawURL, "%%%"), ErrInvalidCallbackSignature},
		{"truncated signature", getRequest(t, rawURL, base64.StdEncoding.EncodeToString(raw[:100])), ErrInvalidCallbackSignature},
		{"signature over different url", getRequest(t, strings.Replace(rawURL, "pending=0", "pending=1", 1), sig), ErrInvalidCallbackSignature},
		{"reordered query", getRequest(t, strings.Replace(rawURL, "order=1001&nonce=7f3a9c", "nonce=7f3a9c&order=1001", 1), sig), ErrInvalidCallbackSignature},
		{"signature over different bytes", postRequest(t, append(append([]byte(nil), body...), '\n'), "application/json", bodySig), ErrInvalidCallbackSignature},
		{"url signature presented for body", postRequest(t, body, "application/json", sig), ErrInvalidCallbackSignature},
		{"unsupported method", httptest.NewRequestWithContext(t.Context(), http.MethodPut, "/hook", nil), ErrUnsupportedCallback},
	}
	for _, tc := range cases {
		if _, err := key.VerifyRequest(tc.req, callbackOrigin); !errors.Is(err, tc.want) {
			t.Errorf("%s: got %v want %v", tc.name, err, tc.want)
		}
	}
	if _, err := key.VerifyRequest(getRequest(t, rawURL, sig), "https://other.example"); !errors.Is(err, ErrInvalidCallbackSignature) {
		t.Errorf("wrong origin: got %v", err)
	}
	for _, origin := range []string{"", "shop.example", "https://shop.example/path"} {
		if _, err := key.VerifyRequest(getRequest(t, rawURL, sig), origin); !errors.Is(err, ErrInvalidArgument) {
			t.Errorf("origin %q: got %v", origin, err)
		}
	}
	if _, err := key.VerifyRequestLimit(postRequest(t, body, "application/json", bodySig), "", 10); !errors.Is(err, ErrCallbackBodyTooLarge) {
		t.Errorf("oversized body: got %v", err)
	}
}

func TestParsePublicCallbackKeyRejections(t *testing.T) {
	short, err := os.ReadFile(filepath.Join("testdata", "short_public.pubkey"))
	if err != nil {
		t.Fatal(err)
	}
	ecdsa, err := os.ReadFile(filepath.Join("testdata", "ec_public.pubkey"))
	if err != nil {
		t.Fatal(err)
	}
	for name, input := range map[string]string{
		"empty":        "",
		"not pem":      "hello",
		"wrong block":  "-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n",
		"bad der":      "-----BEGIN PUBLIC KEY-----\nAAAA\n-----END PUBLIC KEY-----\n",
		"1024 bit rsa": string(short),
		"ec key":       string(ecdsa),
	} {
		if _, err := ParsePublicCallbackKey([]byte(input)); !errors.Is(err, ErrInvalidPublicKey) {
			t.Errorf("%s: got %v", name, err)
		}
	}
}

func TestPublicCallbackKinds(t *testing.T) {
	pending, err := DecodePublicCallbackJSON([]byte(`{"uuid":"u1","address_in":"a","address_out":"b","txid_in":"t","coin":"trc20_usdt","pending":"1","price":"1.0002"}`))
	if err != nil || pending.Kind() != CallbackPending || !pending.IsPending() {
		t.Fatalf("%v %+v", err, pending)
	}
	form, err := DecodePublicCallbackValues(url.Values{"uuid": {"u2"}, "pending": {"0"}, "txid_out": {"x"}, "value_coin": {"1.5"}, "value_forwarded_coin": {"1.4"}})
	if err != nil || form.Kind() != CallbackConfirmed || form.ValueForwardedCoin != "1.4" {
		t.Fatalf("%v %+v", err, form)
	}
}

func TestPublicCallbackFormPost(t *testing.T) {
	key := loadKey(t)
	body := mustFile(t, "callback_body.json")
	req := postRequest(t, body, "application/x-www-form-urlencoded", string(mustFile(t, "callback_body_signature.txt")))
	if _, err := key.VerifyRequest(req, ""); !errors.Is(err, ErrMalformedResponse) {
		t.Fatalf("a signed JSON body declared as a form must not decode: %v", err)
	}
}

func TestDecodePublicCallbackMalformed(t *testing.T) {
	for _, body := range []string{"{", "{}", `{"uuid":"u","pending":"2"}`, `{"uuid":"u","pending":"0"}`, `{"uuid":"u","pending":1}`} {
		if _, err := DecodePublicCallbackJSON([]byte(body)); !errors.Is(err, ErrMalformedResponse) {
			t.Fatalf("%q: got %v", body, err)
		}
	}
	if _, err := DecodePublicCallbackValues(url.Values{}); !errors.Is(err, ErrMalformedResponse) {
		t.Fatalf("got %v", err)
	}
}

func TestReplayGuard(t *testing.T) {
	g := NewReplayGuard(time.Hour, 2)
	clock := time.Unix(1_000_000, 0)
	g.now = func() time.Time { return clock }
	if err := g.Claim("u1"); err != nil {
		t.Fatal(err)
	}
	if err := g.Claim("u1"); !errors.Is(err, ErrReplayedCallback) {
		t.Fatalf("replay: %v", err)
	}
	if err := g.Claim("u2"); err != nil {
		t.Fatal(err)
	}
	if err := g.Claim("u3"); !errors.Is(err, ErrReplayGuardFull) {
		t.Fatalf("full: %v", err)
	}
	g.Release("u1")
	if err := g.Claim("u3"); err != nil {
		t.Fatalf("after release: %v", err)
	}
	clock = clock.Add(2 * time.Hour)
	if err := g.Claim("u2"); err != nil {
		t.Fatalf("after ttl: %v", err)
	}
	if err := g.Claim(""); !errors.Is(err, ErrInvalidArgument) {
		t.Fatalf("empty: %v", err)
	}
}

func TestAckPublicCallback(t *testing.T) {
	rec := httptest.NewRecorder()
	AckPublicCallback(rec)
	if rec.Code != 200 || rec.Body.String() != "*ok*" {
		t.Fatalf("%d %q", rec.Code, rec.Body.String())
	}
}
