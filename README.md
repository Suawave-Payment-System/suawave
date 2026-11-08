# Suawave

Stablecoin payments for online stores

Accept stablecoin payments such as USDT through the Suawave API. An order is paid only after a signed callback verifies.

Suawave adds a stablecoin payment method to your store. At checkout the integration asks the Suawave API for a deposit address and shows the customer the exact amount, the address and a QR code.
The order is marked paid only when the API sends a callback whose RSA signature verifies against the public key you configured. A pending callback never pays an order.
The payout address is your own wallet. Every deposit is forwarded there. The integration uses the public API, so it needs no account and no API key.

## In this repository

| Path | What it is |
|---|---|
| `sdk/go` | Go client, module `github.com/Suawave-Payment-System/suawave/sdk/go` |
| `sdk/js` | TypeScript client with callback signature verification, npm `@suawave/gateway-client` |
| `integrations/woocommerce` | WordPress and WooCommerce plugin |
| `integrations/magento` | Magento 2 payment module |
| `integrations/opencart` | OpenCart 4 payment extension |
| `integrations/prestashop` | PrestaShop 8 and 1.7.8 payment module |
| `cmd/mcp/npm` | npm launcher for the Suawave MCP server binary, npm `@suawave/mcp` |

Each folder has its own README with installation and configuration steps. The API documentation is at https://suawave.com/docs.
