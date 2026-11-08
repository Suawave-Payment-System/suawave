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

type PublicCreateParams struct {
	Callback string
	Address  string
	Pending  bool
	Post     bool
	JSON     bool
	Convert  bool
}

type PublicOrder struct {
	Status                 string `json:"status"`
	AddressIn              string `json:"address_in"`
	AddressOut             string `json:"address_out"`
	CallbackURL            string `json:"callback_url"`
	MinimumTransactionCoin string `json:"minimum_transaction_coin"`
}

type PublicInfo struct {
	Status                 string `json:"status"`
	Coin                   string `json:"coin"`
	Ticker                 string `json:"ticker"`
	MinimumTransactionCoin string `json:"minimum_transaction_coin"`
}

type PublicDeliveryLog struct {
	RequestURL     string     `json:"request_url"`
	ResponseStatus int        `json:"response_status"`
	Responses      string     `json:"responses"`
	NextTry        *time.Time `json:"next_try"`
	Pending        int        `json:"pending"`
	Confirmed      int        `json:"confirmed"`
}

type PublicLogCallback struct {
	TxidIn             string              `json:"txid_in"`
	TxidOut            string              `json:"txid_out"`
	ValueCoin          string              `json:"value_coin"`
	ValueForwardedCoin string              `json:"value_forwarded_coin"`
	Confirmations      int64               `json:"confirmations"`
	LastUpdate         time.Time           `json:"last_update"`
	Result             string              `json:"result"`
	FeePercent         string              `json:"fee_percent"`
	FeeCoin            string              `json:"fee_coin"`
	Logs               []PublicDeliveryLog `json:"logs"`
}

type PublicLogs struct {
	Status              string              `json:"status"`
	AddressIn           string              `json:"address_in"`
	AddressOut          string              `json:"address_out"`
	CallbackURL         string              `json:"callback_url"`
	NotifyPending       bool                `json:"notify_pending"`
	NotifyConfirmations int                 `json:"notify_confirmations"`
	Priority            string              `json:"priority"`
	Callbacks           []PublicLogCallback `json:"callbacks"`
}

type PublicEstimate struct {
	Status        string `json:"status"`
	EstimatedCost string `json:"estimated_cost"`
}

type PublicQRCode struct {
	Status     string `json:"status"`
	QRCode     string `json:"qr_code"`
	PaymentURI string `json:"payment_uri"`
}

type PublicConversion struct {
	Status       string `json:"status"`
	ValueCoin    string `json:"value_coin"`
	ExchangeRate string `json:"exchange_rate"`
}

type PublicQRCodeParams struct {
	Address string
	Value   string
	Size    int
}

func tickerPath(ticker, suffix string) (string, error) {
	ticker = strings.Trim(ticker, "/")
	if ticker == "" {
		return "", fmt.Errorf("%w: ticker is required, for example trc20/usdt", ErrInvalidArgument)
	}
	parts := strings.Split(ticker, "/")
	for i, part := range parts {
		if part == "" {
			return "", fmt.Errorf("%w: malformed ticker %q", ErrInvalidArgument, ticker)
		}
		parts[i] = url.PathEscape(part)
	}
	return "/" + strings.Join(parts, "/") + "/" + suffix + "/", nil
}

func flag(v bool) string {
	if v {
		return "1"
	}
	return "0"
}

func (c *Client) publicGet(ctx context.Context, path string, query url.Values, out any) error {
	_, err := c.do(ctx, request{method: http.MethodGet, path: path, query: query}, out)
	return err
}

func (c *Client) PublicCreate(ctx context.Context, ticker string, p PublicCreateParams) (*PublicOrder, error) {
	path, err := tickerPath(ticker, "create")
	if err != nil {
		return nil, err
	}
	if p.Callback == "" || p.Address == "" {
		return nil, fmt.Errorf("%w: callback and address are required", ErrInvalidArgument)
	}
	query := url.Values{"callback": {p.Callback}, "address": {p.Address}}
	if p.Pending {
		query.Set("pending", flag(true))
	}
	if p.Post {
		query.Set("post", flag(true))
	}
	if p.JSON {
		query.Set("json", flag(true))
	}
	if p.Convert {
		query.Set("convert", flag(true))
	}
	var out PublicOrder
	if err := c.publicGet(ctx, path, query, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

func (c *Client) PublicInfo(ctx context.Context, ticker string) (*PublicInfo, error) {
	path, err := tickerPath(ticker, "info")
	if err != nil {
		return nil, err
	}
	var out PublicInfo
	if err := c.publicGet(ctx, path, nil, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

func (c *Client) PublicLogs(ctx context.Context, ticker, callback string) (*PublicLogs, error) {
	path, err := tickerPath(ticker, "logs")
	if err != nil {
		return nil, err
	}
	if callback == "" {
		return nil, fmt.Errorf("%w: callback is required", ErrInvalidArgument)
	}
	var out PublicLogs
	if err := c.publicGet(ctx, path, url.Values{"callback": {callback}}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

func (c *Client) PublicEstimate(ctx context.Context, ticker string) (*PublicEstimate, error) {
	path, err := tickerPath(ticker, "estimate")
	if err != nil {
		return nil, err
	}
	var out PublicEstimate
	if err := c.publicGet(ctx, path, nil, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

func (c *Client) PublicQRCode(ctx context.Context, ticker string, p PublicQRCodeParams) (*PublicQRCode, error) {
	path, err := tickerPath(ticker, "qrcode")
	if err != nil {
		return nil, err
	}
	if p.Address == "" {
		return nil, fmt.Errorf("%w: address is required", ErrInvalidArgument)
	}
	query := url.Values{"address": {p.Address}}
	if p.Value != "" {
		query.Set("value", p.Value)
	}
	if p.Size != 0 {
		query.Set("size", strconv.Itoa(p.Size))
	}
	var out PublicQRCode
	if err := c.publicGet(ctx, path, query, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

func (c *Client) PublicConvert(ctx context.Context, value, from, to string) (*PublicConversion, error) {
	if value == "" || from == "" || to == "" {
		return nil, fmt.Errorf("%w: value, from and to are required", ErrInvalidArgument)
	}
	var out PublicConversion
	if err := c.publicGet(ctx, "/convert/", url.Values{"value": {value}, "from": {from}, "to": {to}}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

func (c *Client) PublicTickerConvert(ctx context.Context, ticker, value, from string) (*PublicConversion, error) {
	path, err := tickerPath(ticker, "convert")
	if err != nil {
		return nil, err
	}
	if value == "" || from == "" {
		return nil, fmt.Errorf("%w: value and from are required", ErrInvalidArgument)
	}
	var out PublicConversion
	if err := c.publicGet(ctx, path, url.Values{"value": {value}, "from": {from}}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

func (c *Client) PublicCallbackKey(ctx context.Context) (*PublicCallbackKey, error) {
	var out struct {
		PubKey string `json:"pubkey"`
	}
	if err := c.publicGet(ctx, "/pubkey/", nil, &out); err != nil {
		return nil, err
	}
	return ParsePublicCallbackKey([]byte(out.PubKey))
}
