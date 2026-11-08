package suawave

import (
	"context"
	"fmt"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

type PaymentStatus string

const (
	PaymentWaiting           PaymentStatus = "waiting"
	PaymentDetected          PaymentStatus = "detected"
	PaymentConfirming        PaymentStatus = "confirming"
	PaymentConfirmed         PaymentStatus = "confirmed"
	PaymentSettlementPending PaymentStatus = "settlement_pending"
	PaymentSettling          PaymentStatus = "settling"
	PaymentSettled           PaymentStatus = "settled"
	PaymentExpired           PaymentStatus = "expired"
	PaymentLatePayment       PaymentStatus = "late_payment"
	PaymentUnderpaid         PaymentStatus = "underpaid"
	PaymentOverpaid          PaymentStatus = "overpaid"
	PaymentRejected          PaymentStatus = "rejected"
	PaymentManualReview      PaymentStatus = "manual_review"
)

const (
	ErrCodeUnauthorized          = "UNAUTHORIZED"
	ErrCodeRateLimited           = "RATE_LIMITED"
	ErrCodeInvalidRequest        = "INVALID_REQUEST"
	ErrCodeInvalidAmount         = "INVALID_AMOUNT"
	ErrCodeAmountBelowMinimum    = "AMOUNT_BELOW_MINIMUM"
	ErrCodeUnsupportedNetwork    = "UNSUPPORTED_NETWORK"
	ErrCodeUnsupportedAsset      = "UNSUPPORTED_ASSET"
	ErrCodeInvalidSettlementAddr = "INVALID_SETTLEMENT_ADDRESS"
	ErrCodeIdempotencyConflict   = "IDEMPOTENCY_CONFLICT"
	ErrCodeDuplicateExternalID   = "DUPLICATE_EXTERNAL_ID"
	ErrCodePaymentNotFound       = "PAYMENT_NOT_FOUND"
	ErrCodeNotFound              = "NOT_FOUND"
	ErrCodeMethodNotAllowed      = "METHOD_NOT_ALLOWED"
	ErrCodeServiceUnavailable    = "SERVICE_TEMPORARILY_UNAVAILABLE"
	paymentsPath                 = "/v1/payments"
	maxExternalIDLength          = 255
	maxIdempotencyKeyLength      = 255
	maxListLimit                 = 100
)

type CreatePaymentParams struct {
	ExternalID        string `json:"external_id"`
	Network           string `json:"network"`
	Asset             string `json:"asset"`
	Amount            string `json:"amount"`
	SettlementAddress string `json:"settlement_address,omitempty"`
	IdempotencyKey    string `json:"-"`
}

type Transfer struct {
	TxHash     string    `json:"tx_hash"`
	LogIndex   int64     `json:"log_index"`
	Amount     string    `json:"amount"`
	Status     string    `json:"status"`
	ObservedAt time.Time `json:"observed_at"`
}

type Settlement struct {
	Status      string     `json:"status"`
	TxHash      string     `json:"tx_hash"`
	NetAmount   string     `json:"net_amount"`
	ServiceFee  string     `json:"service_fee"`
	NetworkFee  string     `json:"network_fee"`
	ConfirmedAt *time.Time `json:"confirmed_at"`
}

type Payment struct {
	ID                string        `json:"id"`
	ExternalID        string        `json:"external_id"`
	Network           string        `json:"network"`
	Asset             string        `json:"asset"`
	Amount            string        `json:"amount"`
	ReceivedAmount    string        `json:"received_amount"`
	DepositAddress    string        `json:"deposit_address"`
	SettlementAddress string        `json:"settlement_address"`
	Status            PaymentStatus `json:"status"`
	Outcome           string        `json:"outcome"`
	ExpiresAt         time.Time     `json:"expires_at"`
	ConfirmedAt       *time.Time    `json:"confirmed_at"`
	CreatedAt         time.Time     `json:"created_at"`
	Transfers         []Transfer    `json:"transfers"`
	Settlement        *Settlement   `json:"settlement,omitempty"`
	Replayed          bool          `json:"-"`
}

type PaymentPage struct {
	Data       []Payment `json:"data"`
	HasMore    bool      `json:"has_more"`
	NextCursor string    `json:"next_cursor"`
}

type ListPaymentsParams struct {
	Limit  int
	Cursor string
	Status PaymentStatus
}

type NetworkAsset struct {
	Asset           string `json:"asset"`
	ContractAddress string `json:"contract_address"`
	Decimals        int    `json:"decimals"`
	MinAmount       string `json:"min_amount"`
	MaxAmount       string `json:"max_amount"`
}

type Network struct {
	Network     string         `json:"network"`
	DisplayName string         `json:"display_name"`
	Paused      bool           `json:"paused"`
	Assets      []NetworkAsset `json:"assets"`
}

type Fee struct {
	Network           string    `json:"network"`
	Asset             string    `json:"asset"`
	Decimals          int       `json:"decimals"`
	ProfileID         string    `json:"profile_id"`
	Scope             string    `json:"scope"`
	ServiceFeeBps     int       `json:"service_fee_bps"`
	MinimumServiceFee string    `json:"minimum_service_fee"`
	NetworkFeeMode    string    `json:"network_fee_mode"`
	NetworkFee        string    `json:"network_fee"`
	EffectiveFrom     time.Time `json:"effective_from"`
}

func (c *Client) CreatePayment(ctx context.Context, p CreatePaymentParams) (*Payment, error) {
	if p.ExternalID == "" || len(p.ExternalID) > maxExternalIDLength {
		return nil, fmt.Errorf("%w: external_id must be 1 to %d bytes", ErrInvalidArgument, maxExternalIDLength)
	}
	if p.Network == "" || p.Asset == "" || p.Amount == "" {
		return nil, fmt.Errorf("%w: network, asset and amount are required", ErrInvalidArgument)
	}
	key := p.IdempotencyKey
	if key == "" {
		generated, err := newIdempotencyKey()
		if err != nil {
			return nil, err
		}
		key = generated
	}
	if len(key) > maxIdempotencyKeyLength {
		return nil, fmt.Errorf("%w: idempotency key must be 1 to %d bytes", ErrInvalidArgument, maxIdempotencyKeyLength)
	}
	var out Payment
	header, err := c.do(ctx, request{method: http.MethodPost, path: paymentsPath, body: p, auth: true, idempotencyKey: key}, &out)
	if err != nil {
		return nil, err
	}
	out.Replayed = strings.EqualFold(header.Get("Idempotent-Replayed"), "true")
	return &out, nil
}

func (c *Client) GetPayment(ctx context.Context, id string) (*Payment, error) {
	if id == "" {
		return nil, fmt.Errorf("%w: payment id is required", ErrInvalidArgument)
	}
	var out Payment
	if _, err := c.do(ctx, request{method: http.MethodGet, path: paymentsPath + "/" + url.PathEscape(id), auth: true}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

func (c *Client) GetPaymentByExternalID(ctx context.Context, externalID string) (*Payment, error) {
	if externalID == "" {
		return nil, fmt.Errorf("%w: external_id is required", ErrInvalidArgument)
	}
	var out Payment
	query := url.Values{"external_id": {externalID}}
	if _, err := c.do(ctx, request{method: http.MethodGet, path: paymentsPath, query: query, auth: true}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

func (c *Client) ListPayments(ctx context.Context, p ListPaymentsParams) (*PaymentPage, error) {
	if p.Limit < 0 || p.Limit > maxListLimit {
		return nil, fmt.Errorf("%w: limit must be between 1 and %d", ErrInvalidArgument, maxListLimit)
	}
	query := url.Values{}
	if p.Limit > 0 {
		query.Set("limit", strconv.Itoa(p.Limit))
	}
	if p.Cursor != "" {
		query.Set("cursor", p.Cursor)
	}
	if p.Status != "" {
		query.Set("status", string(p.Status))
	}
	var out PaymentPage
	if _, err := c.do(ctx, request{method: http.MethodGet, path: paymentsPath, query: query, auth: true}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

func (c *Client) ListNetworks(ctx context.Context) ([]Network, error) {
	var out struct {
		Data []Network `json:"data"`
	}
	if _, err := c.do(ctx, request{method: http.MethodGet, path: "/v1/networks", auth: true}, &out); err != nil {
		return nil, err
	}
	return out.Data, nil
}

func (c *Client) ListFees(ctx context.Context) ([]Fee, error) {
	var out struct {
		Data []Fee `json:"data"`
	}
	if _, err := c.do(ctx, request{method: http.MethodGet, path: "/v1/fees", auth: true}, &out); err != nil {
		return nil, err
	}
	return out.Data, nil
}
