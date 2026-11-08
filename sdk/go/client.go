package suawave

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

const (
	DefaultBaseURL   = "https://api.suawave.com"
	DefaultTimeout   = 30 * time.Second
	DefaultUserAgent = "suawave-go/1"
	maxResponseBytes = 8 << 20
	maxErrorSnippet  = 512
)

type Client struct {
	base      *url.URL
	apiKey    string
	http      *http.Client
	userAgent string
	now       func() time.Time
}

type Option func(*Client)

func WithHTTPClient(h *http.Client) Option {
	return func(c *Client) {
		if h != nil {
			c.http = h
		}
	}
}

func WithTimeout(d time.Duration) Option {
	return func(c *Client) {
		if d > 0 {
			clone := *c.http
			clone.Timeout = d
			c.http = &clone
		}
	}
}

func WithUserAgent(ua string) Option {
	return func(c *Client) {
		if ua != "" {
			c.userAgent = ua
		}
	}
}

func New(baseURL, apiKey string, opts ...Option) (*Client, error) {
	baseURL = strings.TrimSpace(baseURL)
	if baseURL == "" {
		baseURL = DefaultBaseURL
	}
	u, err := url.Parse(baseURL)
	if err != nil || (u.Scheme != "https" && u.Scheme != "http") || u.Host == "" {
		return nil, fmt.Errorf("%w: base URL must be an absolute http(s) URL", ErrInvalidArgument)
	}
	u.RawQuery = ""
	u.Fragment = ""
	u.Path = strings.TrimRight(u.Path, "/")
	c := &Client{
		base:      u,
		apiKey:    apiKey,
		http:      &http.Client{Timeout: DefaultTimeout},
		userAgent: DefaultUserAgent,
		now:       time.Now,
	}
	for _, opt := range opts {
		opt(c)
	}
	return c, nil
}

type request struct {
	method         string
	path           string
	query          url.Values
	body           any
	auth           bool
	idempotencyKey string
}

func (c *Client) do(ctx context.Context, r request, out any) (http.Header, error) {
	if r.auth && c.apiKey == "" {
		return nil, ErrNoAPIKey
	}
	target := c.base.String() + r.path
	if len(r.query) > 0 {
		target += "?" + r.query.Encode()
	}
	var reader io.Reader
	if r.body != nil {
		payload, err := json.Marshal(r.body)
		if err != nil {
			return nil, fmt.Errorf("suawave: encode request: %w", err)
		}
		reader = bytes.NewReader(payload)
	}
	req, err := http.NewRequestWithContext(ctx, r.method, target, reader)
	if err != nil {
		return nil, fmt.Errorf("suawave: build request: %w", err)
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", c.userAgent)
	if r.body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	if r.auth {
		req.Header.Set("Authorization", "Bearer "+c.apiKey)
	}
	if r.idempotencyKey != "" {
		req.Header.Set("Idempotency-Key", r.idempotencyKey)
	}
	resp, err := c.http.Do(req)
	if err != nil {
		return nil, err
	}
	defer func() { _ = resp.Body.Close() }()
	raw, err := io.ReadAll(io.LimitReader(resp.Body, maxResponseBytes+1))
	if err != nil {
		return nil, err
	}
	if len(raw) > maxResponseBytes {
		return nil, malformed(errors.New("response too large"))
	}
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		return resp.Header, c.apiError(resp, raw)
	}
	if out == nil {
		return resp.Header, nil
	}
	if err := json.Unmarshal(raw, out); err != nil {
		return resp.Header, malformed(err)
	}
	return resp.Header, nil
}

func (c *Client) apiError(resp *http.Response, raw []byte) *Error {
	apiErr := &Error{
		Status:     resp.StatusCode,
		RequestID:  resp.Header.Get("X-Request-Id"),
		RetryAfter: parseRetryAfter(resp.Header.Get("Retry-After"), c.now()),
	}
	var envelope struct {
		Error json.RawMessage `json:"error"`
	}
	if json.Unmarshal(raw, &envelope) == nil && len(envelope.Error) > 0 {
		var structured struct {
			Code      string `json:"code"`
			Message   string `json:"message"`
			RequestID string `json:"request_id"`
		}
		if json.Unmarshal(envelope.Error, &structured) == nil && structured.Code != "" {
			apiErr.Code = structured.Code
			apiErr.Message = structured.Message
			if structured.RequestID != "" {
				apiErr.RequestID = structured.RequestID
			}
			return apiErr
		}
		var plain string
		if json.Unmarshal(envelope.Error, &plain) == nil {
			apiErr.Message = plain
			return apiErr
		}
	}
	snippet := strings.TrimSpace(string(raw))
	if len(snippet) > maxErrorSnippet {
		snippet = snippet[:maxErrorSnippet]
	}
	apiErr.Message = strings.ToValidUTF8(snippet, "")
	return apiErr
}

func newIdempotencyKey() (string, error) {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		return "", fmt.Errorf("suawave: idempotency key: %w", err)
	}
	return hex.EncodeToString(b[:]), nil
}
