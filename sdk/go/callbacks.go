package suawave

import (
	"crypto"
	"crypto/rsa"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"mime"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"
)

const (
	PublicCallbackSignatureHeader = "x-ca-signature"
	PublicCallbackAck             = "*ok*"
	DefaultMaxCallbackBody        = 1 << 20
	minRSABits                    = 2048
)

var (
	ErrMissingCallbackSignature = errors.New("suawave: callback signature missing")
	ErrInvalidCallbackSignature = errors.New("suawave: callback signature invalid")
	ErrInvalidPublicKey         = errors.New("suawave: invalid callback public key")
	ErrUnsupportedCallback      = errors.New("suawave: callback must be a GET or POST request")
	ErrCallbackBodyTooLarge     = errors.New("suawave: callback body too large")
	ErrReplayedCallback         = errors.New("suawave: callback uuid already processed")
	ErrReplayGuardFull          = errors.New("suawave: replay guard is full")
)

type PublicCallbackKind string

const (
	CallbackPending   PublicCallbackKind = "pending"
	CallbackConfirmed PublicCallbackKind = "confirmed"
)

type PublicCallbackKey struct {
	key *rsa.PublicKey
}

func ParsePublicCallbackKey(pemBytes []byte) (*PublicCallbackKey, error) {
	block, _ := pem.Decode(pemBytes)
	if block == nil || block.Type != "PUBLIC KEY" {
		return nil, fmt.Errorf("%w: expected a PKIX PUBLIC KEY PEM block", ErrInvalidPublicKey)
	}
	parsed, err := x509.ParsePKIXPublicKey(block.Bytes)
	if err != nil {
		return nil, fmt.Errorf("%w: %w", ErrInvalidPublicKey, err)
	}
	key, ok := parsed.(*rsa.PublicKey)
	if !ok {
		return nil, fmt.Errorf("%w: not an RSA key", ErrInvalidPublicKey)
	}
	if key.N.BitLen() < minRSABits {
		return nil, fmt.Errorf("%w: RSA key shorter than %d bits", ErrInvalidPublicKey, minRSABits)
	}
	return &PublicCallbackKey{key: key}, nil
}

type IncomingCallback struct {
	Method string
	URL    string
	Body   []byte
	Header http.Header
}

func (k *PublicCallbackKey) VerifyCallback(in IncomingCallback) (PublicCallback, error) {
	signature := strings.TrimSpace(in.Header.Get(PublicCallbackSignatureHeader))
	if signature == "" {
		return PublicCallback{}, ErrMissingCallbackSignature
	}
	var signed []byte
	switch in.Method {
	case http.MethodGet:
		signed = []byte(in.URL)
	case http.MethodPost:
		signed = in.Body
	default:
		return PublicCallback{}, ErrUnsupportedCallback
	}
	raw, err := base64.StdEncoding.DecodeString(signature)
	if err != nil {
		return PublicCallback{}, ErrInvalidCallbackSignature
	}
	digest := sha256.Sum256(signed)
	if err := rsa.VerifyPKCS1v15(k.key, crypto.SHA256, digest[:], raw); err != nil {
		return PublicCallback{}, ErrInvalidCallbackSignature
	}
	return decodeVerified(in)
}

func (k *PublicCallbackKey) VerifyRequest(r *http.Request, origin string) (PublicCallback, error) {
	return k.VerifyRequestLimit(r, origin, DefaultMaxCallbackBody)
}

func (k *PublicCallbackKey) VerifyRequestLimit(r *http.Request, origin string, maxBytes int64) (PublicCallback, error) {
	in := IncomingCallback{Method: r.Method, Header: r.Header}
	switch r.Method {
	case http.MethodGet:
		base, err := url.Parse(origin)
		if err != nil || (base.Scheme != "https" && base.Scheme != "http") || base.Host == "" || (base.Path != "" && base.Path != "/") || base.RawQuery != "" {
			return PublicCallback{}, fmt.Errorf("%w: origin must look like https://shop.example", ErrInvalidArgument)
		}
		in.URL = base.Scheme + "://" + base.Host + r.URL.RequestURI()
	case http.MethodPost:
		body, err := io.ReadAll(io.LimitReader(r.Body, maxBytes+1))
		if err != nil {
			return PublicCallback{}, err
		}
		if int64(len(body)) > maxBytes {
			return PublicCallback{}, ErrCallbackBodyTooLarge
		}
		in.Body = body
	default:
		return PublicCallback{}, ErrUnsupportedCallback
	}
	return k.VerifyCallback(in)
}

type PublicCallback struct {
	UUID                      string `json:"uuid"`
	AddressIn                 string `json:"address_in"`
	AddressOut                string `json:"address_out"`
	TxidIn                    string `json:"txid_in"`
	Coin                      string `json:"coin"`
	Pending                   string `json:"pending"`
	Price                     string `json:"price,omitempty"`
	TxidOut                   string `json:"txid_out,omitempty"`
	Confirmations             string `json:"confirmations,omitempty"`
	ValueCoin                 string `json:"value_coin,omitempty"`
	ValueForwardedCoin        string `json:"value_forwarded_coin,omitempty"`
	FeeCoin                   string `json:"fee_coin,omitempty"`
	ValueCoinConvert          string `json:"value_coin_convert,omitempty"`
	ValueForwardedCoinConvert string `json:"value_forwarded_coin_convert,omitempty"`
}

func (p PublicCallback) Kind() PublicCallbackKind {
	if p.Pending == "1" {
		return CallbackPending
	}
	return CallbackConfirmed
}

func (p PublicCallback) IsPending() bool { return p.Kind() == CallbackPending }

func decodeVerified(in IncomingCallback) (PublicCallback, error) {
	if in.Method == http.MethodGet {
		parsed, err := url.Parse(in.URL)
		if err != nil {
			return PublicCallback{}, malformed(err)
		}
		return DecodePublicCallbackValues(parsed.Query())
	}
	mediaType, _, _ := mime.ParseMediaType(in.Header.Get("Content-Type"))
	if mediaType == "application/x-www-form-urlencoded" {
		values, err := url.ParseQuery(string(in.Body))
		if err != nil {
			return PublicCallback{}, malformed(err)
		}
		return DecodePublicCallbackValues(values)
	}
	return DecodePublicCallbackJSON(in.Body)
}

func validateCallback(p PublicCallback) (PublicCallback, error) {
	if p.UUID == "" {
		return PublicCallback{}, malformed(errors.New("uuid missing"))
	}
	switch p.Pending {
	case "1":
	case "0":
		if p.TxidOut == "" || p.ValueCoin == "" || p.ValueForwardedCoin == "" {
			return PublicCallback{}, malformed(errors.New("confirmed callback fields missing"))
		}
	default:
		return PublicCallback{}, malformed(errors.New("pending must be \"0\" or \"1\""))
	}
	return p, nil
}

func DecodePublicCallbackJSON(body []byte) (PublicCallback, error) {
	var out PublicCallback
	if err := json.Unmarshal(body, &out); err != nil {
		return PublicCallback{}, malformed(err)
	}
	return validateCallback(out)
}

func DecodePublicCallbackValues(values url.Values) (PublicCallback, error) {
	return validateCallback(PublicCallback{
		UUID:                      values.Get("uuid"),
		AddressIn:                 values.Get("address_in"),
		AddressOut:                values.Get("address_out"),
		TxidIn:                    values.Get("txid_in"),
		Coin:                      values.Get("coin"),
		Pending:                   values.Get("pending"),
		Price:                     values.Get("price"),
		TxidOut:                   values.Get("txid_out"),
		Confirmations:             values.Get("confirmations"),
		ValueCoin:                 values.Get("value_coin"),
		ValueForwardedCoin:        values.Get("value_forwarded_coin"),
		FeeCoin:                   values.Get("fee_coin"),
		ValueCoinConvert:          values.Get("value_coin_convert"),
		ValueForwardedCoinConvert: values.Get("value_forwarded_coin_convert"),
	})
}

func AckPublicCallback(w http.ResponseWriter) {
	w.Header().Set("Content-Type", "text/plain")
	w.WriteHeader(http.StatusOK)
	_, _ = io.WriteString(w, PublicCallbackAck)
}

type ReplayGuard struct {
	mu   sync.Mutex
	ttl  time.Duration
	max  int
	seen map[string]time.Time
	now  func() time.Time
}

func NewReplayGuard(ttl time.Duration, maxEntries int) *ReplayGuard {
	return &ReplayGuard{ttl: ttl, max: maxEntries, seen: make(map[string]time.Time), now: time.Now}
}

func (g *ReplayGuard) Claim(uuid string) error {
	if uuid == "" {
		return fmt.Errorf("%w: empty uuid", ErrInvalidArgument)
	}
	g.mu.Lock()
	defer g.mu.Unlock()
	now := g.now()
	if at, ok := g.seen[uuid]; ok && now.Sub(at) < g.ttl {
		return ErrReplayedCallback
	}
	if len(g.seen) >= g.max {
		for id, at := range g.seen {
			if now.Sub(at) >= g.ttl {
				delete(g.seen, id)
			}
		}
		if len(g.seen) >= g.max {
			return ErrReplayGuardFull
		}
	}
	g.seen[uuid] = now
	return nil
}

func (g *ReplayGuard) Release(uuid string) {
	g.mu.Lock()
	defer g.mu.Unlock()
	delete(g.seen, uuid)
}
