# Suawave Payment user guide

Version 1.0.0. Compatible with Magento Open Source and Adobe Commerce 2.4.4 or newer on PHP 8.3 or newer.

## What it does

Suawave Payment adds a stablecoin payment method to checkout. The customer places the order and sees the amount, a deposit address and a QR code on the success page. The customer then sends the payment from any wallet.

The order is invoiced and moves to `processing` only after the Suawave API sends a signed, confirmed callback that the module has verified.

The module uses the public Suawave API. No account, API key or shared secret is needed.

## Before you start

1. A store reachable from the public internet over HTTPS. The gateway refuses callbacks to private or loopback addresses.
2. PHP extensions `bcmath`, `curl`, `json` and `openssl`.
3. A payout wallet address on the network of the token you want to accept (for example `trc20/usdt` on TRON or `bep20/usdt` on BNB Smart Chain).
4. The store currency must be a fiat code the gateway can convert from, such as USD or EUR.

## Install with Composer

```
composer require suawave/module-payment
bin/magento module:enable Suawave_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

## Install from the zip

1. Unzip the package into `app/code/Suawave/Payment`, so that `app/code/Suawave/Payment/registration.php` exists.
2. Run the same `module:enable`, `setup:upgrade`, `setup:di:compile`, `setup:static-content:deploy` and `cache:flush` commands as above.

`setup:upgrade` creates the table `suawave_callback_event`, which remembers processed callback events.

## Configure

Open Stores, Configuration, Sales, Payment Methods, Suawave payments.

| Field | What to enter |
|---|---|
| Enabled | Yes turns the method on. |
| Title | The name customers see at checkout. |
| Gateway base URL | `https://api.suawave.com`, without a trailing slash. |
| Ticker | Token ticker with the slash, lowercase: `trc20/usdt` or `bep20/usdt`. |
| Payout address | Your wallet on the ticker's network. Settlements are paid here. |
| Public store origin | The HTTPS origin the gateway reaches your store on, for example `https://shop.example`, with no path. |
| Payment from applicable countries, Sort order | Optional, the usual Magento payment restrictions. |

Save the configuration and flush the cache.

## What your customer sees

1. At checkout the customer picks Suawave payments and places the order.
2. The success page shows the amount in the token, the deposit address, the network and a QR code.
3. After the customer pays, the order stays `pending` until the gateway confirms the deposit. A confirmed deposit invoices the order and sets it to `processing`.
4. A smaller payment adds an order comment and keeps the order open. A later deposit is added to the total.

## What you see as the merchant

Every confirmed deposit is written as an order comment with the transaction hash and the running total. Nothing the customer's browser sends changes an order.

## Check that it works

Open `https://<your store>/suawave/callback/index` in a browser. The answer `401 missing signature` means the route is reachable and refusing unsigned requests, as it should.

## Troubleshooting

| Symptom | Cause |
|---|---|
| The method is missing at checkout | Not enabled, an empty payout address or gateway URL, an unsupported store currency or no fresh price from the gateway. |
| Order stays pending after payment | The gateway could not reach the store: check the Public store origin, HTTPS and any firewall or CDN in front of the store. |
| Callback answers 503 | The gateway public key could not be fetched or is not an RSA key of at least 2048 bits. Check the Gateway base URL and flush the cache. |

## Support

Documentation: https://suawave.com/docs. Support: https://suawave.com.
