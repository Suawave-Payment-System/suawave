//go:build integration

package suawave

import (
	"fmt"
	"os"
	"testing"
	"time"
)

func TestEndToEndMockPayment(t *testing.T) {
	base := os.Getenv("SUAWAVE_E2E_BASE_URL")
	key := os.Getenv("SUAWAVE_E2E_API_KEY")
	if base == "" || key == "" {
		t.Skip("set SUAWAVE_E2E_BASE_URL and SUAWAVE_E2E_API_KEY to a mock-mode API and a merchant key")
	}
	c, err := New(base, key, WithTimeout(15*time.Second))
	if err != nil {
		t.Fatal(err)
	}
	ctx := t.Context()

	networks, err := c.ListNetworks(ctx)
	if err != nil {
		t.Fatal(err)
	}
	var network, asset, minimum string
	for _, n := range networks {
		if n.Paused || len(n.Assets) == 0 {
			continue
		}
		network, asset, minimum = n.Network, n.Assets[0].Asset, n.Assets[0].MinAmount
		break
	}
	if network == "" {
		t.Fatal("no unpaused network with an enabled asset")
	}

	externalID := fmt.Sprintf("sdk-e2e-%d", time.Now().UnixNano())
	params := CreatePaymentParams{ExternalID: externalID, Network: network, Asset: asset, Amount: minimum, IdempotencyKey: externalID}
	created, err := c.CreatePayment(ctx, params)
	if err != nil {
		t.Fatal(err)
	}
	if created.DepositAddress == "" || created.Status != PaymentWaiting || created.Amount != minimum || created.ExternalID != externalID {
		t.Fatalf("created payment %+v", created)
	}

	fetched, err := c.GetPayment(ctx, created.ID)
	if err != nil {
		t.Fatal(err)
	}
	if fetched.DepositAddress != created.DepositAddress || fetched.Status != PaymentWaiting || fetched.ReceivedAmount == "" {
		t.Fatalf("fetched payment %+v", fetched)
	}

	byExternal, err := c.GetPaymentByExternalID(ctx, externalID)
	if err != nil || byExternal.ID != created.ID {
		t.Fatalf("%v %+v", err, byExternal)
	}

	replayed, err := c.CreatePayment(ctx, params)
	if err != nil || replayed.ID != created.ID || !replayed.Replayed {
		t.Fatalf("%v %+v", err, replayed)
	}

	page, err := c.ListPayments(ctx, ListPaymentsParams{Limit: 5, Status: PaymentWaiting})
	if err != nil || len(page.Data) == 0 {
		t.Fatalf("%v %+v", err, page)
	}

	if _, err := c.ListFees(ctx); err != nil {
		t.Fatal(err)
	}

	_, err = c.GetPayment(ctx, "pay_00000000-0000-7000-8000-000000000000")
	if apiErr, ok := AsError(err); !ok || apiErr.Status != 404 || apiErr.Code != ErrCodePaymentNotFound {
		t.Fatalf("got %v", err)
	}

	bad, err := New(base, "not-a-real-key")
	if err != nil {
		t.Fatal(err)
	}
	_, err = bad.ListFees(ctx)
	if apiErr, ok := AsError(err); !ok || apiErr.Status != 401 || apiErr.Code != ErrCodeUnauthorized {
		t.Fatalf("got %v", err)
	}
}
