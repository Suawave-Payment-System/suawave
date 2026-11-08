<p align="center">
  <img src="integrations/woocommerce/.wordpress-org/icon-256x256.png" width="96" height="96" alt="Suawave">
</p>

<h1 align="center">Suawave</h1>

<p align="center"><strong>Stablecoin payments for online stores.</strong><br>
Your customer pays USDT from any wallet. Your store marks the order paid only after a signed, confirmed callback.</p>

<p align="center">
  <a href="https://suawave.com/docs"><img alt="Docs" src="https://img.shields.io/badge/docs-suawave.com-0099FF"></a>
  <img alt="Go" src="https://img.shields.io/badge/Go-SDK-00ADD8?logo=go&logoColor=white">
  <img alt="TypeScript" src="https://img.shields.io/badge/TypeScript-SDK-3178C6?logo=typescript&logoColor=white">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white">
  <img alt="Networks" src="https://img.shields.io/badge/TRON%20%7C%20BNB%20Smart%20Chain-USDT-26A17B">
  <img alt="Dependencies" src="https://img.shields.io/badge/runtime%20deps-0-success">
</p>

---

## ✨ Why Suawave

- 🔑 **No account, no API key** for the store plugins. They use the public API, which needs no registration.
- 🔏 **Signed callbacks.** Every callback is verified with the API's RSA public key before a single field is read. A pending callback never pays an order.
- 💰 **Your wallet, your money.** The payout address is yours. Every deposit is forwarded there.
- 🧮 **Exact decimals.** Amounts are decimal strings end to end, never floating point.
- 🔁 **Partial payments handled.** A smaller deposit keeps the order on hold; later deposits add up until the total is reached.
- 📦 **Zero bundled code.** No third-party libraries, no runtime dependencies in any package.

## 🧩 What is in this repository

| | Package | Install |
|---|---|---|
| 🛒 | [**WooCommerce / WordPress**](integrations/woocommerce) | Upload `suawave-payment.zip` in Plugins |
| 🏬 | [**Magento 2**](integrations/magento) | `composer require suawave/module-payment` |
| 🛍️ | [**OpenCart 4**](integrations/opencart) | Upload `suawave.ocmod.zip` in Extensions |
| 🏪 | [**PrestaShop 8 / 1.7.8**](integrations/prestashop) | Upload `suawave.zip` in Modules |
| 🐹 | [**Go client**](sdk/go) | `go get github.com/Suawave-Payment-System/suawave/sdk/go` |
| 🟨 | [**TypeScript / JavaScript client**](sdk/js) | `npm install @suawave/gateway-client` |
| 🤖 | [**MCP server launcher**](cmd/mcp/npm) | `npx @suawave/mcp` |

Each folder has its own README with configuration steps, and `integrations/listing.json` holds the one description every package and store listing is rendered from.

## 🚀 Quick start

<details open>
<summary><strong>Go</strong></summary>

```go
import suawave "github.com/Suawave-Payment-System/suawave/sdk/go"

client, _ := suawave.New("https://api.suawave.com", "")
order, err := client.PublicCreate(ctx, "trc20/usdt", suawave.PublicCreateParams{
	Callback: "https://shop.example/hooks/crypto?order=1001&nonce=7f3a9c",
	Address:  payoutAddress,
	Pending:  true,
	JSON:     true,
})
fmt.Printf("send at least %s USDT to %s\n", order.MinimumTransactionCoin, order.AddressIn)
```
</details>

<details>
<summary><strong>TypeScript / JavaScript</strong></summary>

```ts
import { GatewayClient } from '@suawave/gateway-client';

const client = new GatewayClient({ baseUrl: 'https://api.suawave.com', apiKey: process.env.SUAWAVE_KEY });
const payment = await client.createPayment(
  { external_id: 'order_1001', network: 'TRON', asset: 'USDT', amount: '100.50' },
  { idempotencyKey: 'order_1001-create' },
);
console.log(payment.deposit_address, payment.expires_at);
```
</details>

<details>
<summary><strong>Store plugins</strong></summary>

1. Install the package for your platform from the table above.
2. Enter the gateway URL `https://api.suawave.com`, the ticker (`trc20/usdt` or `bep20/usdt`) and your payout wallet address.
3. Paste the callback public key from `curl https://api.suawave.com/pubkey/`.
4. Place a test order. The thank-you page shows the amount, the address and a QR code; the order turns paid after the confirmed callback.
</details>

## 🔄 How a payment flows

```
customer checks out ──▶ plugin asks the API for a deposit address ──▶ amount + address + QR shown
                                                                              │
                        order paid ◀── signature verified ◀── signed callback ◀── deposit confirmed on chain
```

## ❓ FAQ

**Do I need an account or an API key?**
No. The store plugins use the public API, which needs no registration and no key. The merchant API used by the SDKs takes a key for merchant operations.

**Where do I get the callback public key?**
Run `curl https://api.suawave.com/pubkey/` once and paste the `pubkey` value into the settings, turning `\n` into real line breaks.

**Can a customer pay less than the order total?**
A smaller confirmed deposit leaves the order on hold with a note. A later deposit is added to the running total, and the order is paid when the total reaches the order amount.

**Which tokens and networks are supported?**
You choose the asset and chain in the settings, for example `trc20/usdt` on TRON or `bep20/usdt` on BNB Smart Chain.

## 📚 Links

- 📖 Documentation: https://suawave.com/docs
- 🌐 Website: https://suawave.com
- 💬 Support: support@suawave.com (help pages at https://suawave.com/docs)

<p align="center"><sub>Built by Suawave. The gateway itself is a separate, private service; this repository holds everything a merchant or developer installs.</sub></p>
