package suawave

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"
	"time"
)

const (
	WebhookEventIDHeader   = "X-Gateway-Event-ID"
	WebhookTimestampHeader = "X-Gateway-Timestamp"
	WebhookSignatureHeader = "X-Gateway-Signature"
	WebhookTolerance       = 5 * time.Minute
	DefaultMaxWebhookBody  = 1 << 20
)

var (
	ErrMissingSignature   = errors.New("suawave: webhook signature header missing")
	ErrMissingTimestamp   = errors.New("suawave: webhook timestamp header missing")
	ErrInvalidTimestamp   = errors.New("suawave: webhook timestamp malformed")
	ErrStaleTimestamp     = errors.New("suawave: webhook timestamp outside tolerance")
	ErrUnsupportedScheme  = errors.New("suawave: webhook signature has no supported v1 entry")
	ErrInvalidSignature   = errors.New("suawave: webhook signature invalid")
	ErrWebhookBodyTooLong = errors.New("suawave: webhook body too large")
)

type EventType string

const (
	EventPaymentDetected     EventType = "payment.detected"
	EventPaymentConfirmed    EventType = "payment.confirmed"
	EventPaymentUnderpaid    EventType = "payment.underpaid"
	EventPaymentOverpaid     EventType = "payment.overpaid"
	EventPaymentExpired      EventType = "payment.expired"
	EventPaymentLate         EventType = "payment.late"
	EventSettlementBroadcast EventType = "settlement.broadcast"
	EventSettlementConfirmed EventType = "settlement.confirmed"
	EventSettlementFailed    EventType = "settlement.failed"
)

type EventData struct {
	PaymentID        string `json:"payment_id"`
	ExternalID       string `json:"external_id"`
	Network          string `json:"network"`
	Asset            string `json:"asset"`
	Amount           string `json:"amount"`
	TxHash           string `json:"tx_hash"`
	Status           string `json:"status"`
	ReceivedAmount   string `json:"received_amount"`
	Outcome          string `json:"outcome"`
	SettlementAmount string `json:"settlement_amount,omitempty"`
}

type Event struct {
	ID        string    `json:"id"`
	Type      EventType `json:"type"`
	CreatedAt time.Time `json:"created_at"`
	Data      EventData `json:"data"`
}

type WebhookVerifier struct {
	secrets [][]byte
	now     func() time.Time
}

func NewWebhookVerifier(secrets ...string) (*WebhookVerifier, error) {
	if len(secrets) == 0 {
		return nil, fmt.Errorf("%w: at least one webhook secret is required", ErrInvalidArgument)
	}
	v := &WebhookVerifier{now: time.Now}
	for _, s := range secrets {
		if s == "" {
			return nil, fmt.Errorf("%w: empty webhook secret", ErrInvalidArgument)
		}
		v.secrets = append(v.secrets, []byte(s))
	}
	return v, nil
}

func (v *WebhookVerifier) Verify(header http.Header, body []byte, now time.Time) error {
	signatures := header.Get(WebhookSignatureHeader)
	if strings.TrimSpace(signatures) == "" {
		return ErrMissingSignature
	}
	timestamp := header.Get(WebhookTimestampHeader)
	if timestamp == "" {
		return ErrMissingTimestamp
	}
	unix, err := strconv.ParseInt(timestamp, 10, 64)
	if err != nil {
		return ErrInvalidTimestamp
	}
	age := now.Sub(time.Unix(unix, 0))
	if age > WebhookTolerance || age < -WebhookTolerance {
		return ErrStaleTimestamp
	}
	var candidates [][]byte
	for _, part := range strings.Split(signatures, ",") {
		scheme, value, ok := strings.Cut(strings.TrimSpace(part), "=")
		if !ok || scheme != "v1" {
			continue
		}
		decoded, err := hex.DecodeString(value)
		if err != nil {
			continue
		}
		candidates = append(candidates, decoded)
	}
	if len(candidates) == 0 {
		if hasV1Entry(signatures) {
			return ErrInvalidSignature
		}
		return ErrUnsupportedScheme
	}
	matched := false
	for _, secret := range v.secrets {
		mac := hmac.New(sha256.New, secret)
		mac.Write([]byte(timestamp + "."))
		mac.Write(body)
		expected := mac.Sum(nil)
		for _, got := range candidates {
			if hmac.Equal(got, expected) {
				matched = true
			}
		}
	}
	if !matched {
		return ErrInvalidSignature
	}
	return nil
}

func hasV1Entry(signatures string) bool {
	for _, part := range strings.Split(signatures, ",") {
		if scheme, _, ok := strings.Cut(strings.TrimSpace(part), "="); ok && scheme == "v1" {
			return true
		}
	}
	return false
}

func (v *WebhookVerifier) ParseRequest(r *http.Request) (Event, error) {
	return v.ParseRequestLimit(r, DefaultMaxWebhookBody)
}

func (v *WebhookVerifier) ParseRequestLimit(r *http.Request, maxBytes int64) (Event, error) {
	body, err := io.ReadAll(io.LimitReader(r.Body, maxBytes+1))
	if err != nil {
		return Event{}, err
	}
	if int64(len(body)) > maxBytes {
		return Event{}, ErrWebhookBodyTooLong
	}
	if err := v.Verify(r.Header, body, v.now()); err != nil {
		return Event{}, err
	}
	return DecodeEvent(body)
}

func DecodeEvent(body []byte) (Event, error) {
	var ev Event
	if err := json.Unmarshal(body, &ev); err != nil {
		return Event{}, malformed(err)
	}
	if ev.ID == "" || ev.Type == "" {
		return Event{}, malformed(errors.New("event id or type missing"))
	}
	return ev, nil
}
