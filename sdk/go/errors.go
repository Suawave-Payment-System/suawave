package suawave

import (
	"errors"
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"time"
)

var (
	ErrMalformedResponse = errors.New("suawave: malformed response body")
	ErrNoAPIKey          = errors.New("suawave: API key required for this operation")
	ErrInvalidArgument   = errors.New("suawave: invalid argument")
)

type Error struct {
	Status     int
	Code       string
	Message    string
	RequestID  string
	RetryAfter time.Duration
}

func (e *Error) Error() string {
	var b strings.Builder
	b.WriteString("suawave: HTTP ")
	b.WriteString(strconv.Itoa(e.Status))
	if e.Code != "" {
		b.WriteString(" ")
		b.WriteString(e.Code)
	}
	if e.Message != "" {
		b.WriteString(": ")
		b.WriteString(e.Message)
	}
	if e.RequestID != "" {
		b.WriteString(" (request ")
		b.WriteString(e.RequestID)
		b.WriteString(")")
	}
	return b.String()
}

func (e *Error) IsRateLimited() bool { return e.Status == http.StatusTooManyRequests }

func (e *Error) Retryable() bool {
	return e.Status == http.StatusTooManyRequests || e.Status >= http.StatusInternalServerError
}

func AsError(err error) (*Error, bool) {
	var apiErr *Error
	if errors.As(err, &apiErr) {
		return apiErr, true
	}
	return nil, false
}

func parseRetryAfter(value string, now time.Time) time.Duration {
	value = strings.TrimSpace(value)
	if value == "" {
		return 0
	}
	if seconds, err := strconv.ParseInt(value, 10, 64); err == nil {
		if seconds < 0 {
			return 0
		}
		return time.Duration(seconds) * time.Second
	}
	if when, err := http.ParseTime(value); err == nil {
		if d := when.Sub(now); d > 0 {
			return d
		}
	}
	return 0
}

func malformed(err error) error {
	return fmt.Errorf("%w: %w", ErrMalformedResponse, err)
}
