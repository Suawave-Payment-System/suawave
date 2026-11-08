=== Suawave Payments ===
Contributors: suawave
Tags: crypto, stablecoin, payments, woocommerce, usdt
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept stablecoin payments such as USDT in WooCommerce. An order is paid only after a signed callback verifies.

== Description ==

Suawave Payments adds a stablecoin payment method to WooCommerce and a small invoice API for plain WordPress. At checkout the plugin asks the Suawave API for a deposit address and shows the exact amount, the address and a QR code.

The order is marked paid only when the API sends a callback whose RSA signature verifies. A pending callback never pays an order.

The payout address is your own wallet. Every deposit is forwarded there.

Features:

* Shows the deposit address, the amount, the network, the minimum deposit and a QR code on the thank-you page and in the order email.
* Verifies every callback with the API public key (RSA-SHA256) before reading it.
* Ignores repeated deliveries of the same callback.
* Keeps the order on hold after a partial payment and adds a later deposit to the running total.
* Calculates amounts as exact decimal strings, never as floating point numbers.
* Works with WooCommerce 8 or newer and declares compatibility with HPOS (custom order tables).
* Has no bundled third-party code and no runtime dependencies.

= External service =

This plugin talks to the Suawave payment gateway API that you configure in the settings (default `https://api.suawave.com`). It is required for the plugin to work.

* When an order is placed the plugin sends the payout address you configured, the asset and chain and a callback URL of your site (with the order number and a random nonce) to the gateway to receive a deposit address and a minimum deposit.
* To draw the QR code the plugin sends the deposit address and the amount to the gateway.
* When the "convert" price mode is selected, the order total and store currency are sent to the gateway to get the asset amount.
* No customer name, e-mail address or postal address is ever sent to the gateway.
* The gateway then calls your site back with the deposit status.

Service provider: Suawave, https://suawave.com. Documentation: https://suawave.com/docs.

== Installation ==

1. Upload the plugin through Plugins, Add New, Upload Plugin, or copy the `suawave-payment` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Open WooCommerce, Settings, Payments, Suawave payment.
4. Enter the gateway API base URL, the asset and chain (for example `trc20/usdt`), your payout address and the gateway public key.
5. Make sure your site is reachable by the gateway over HTTPS.

== Frequently Asked Questions ==

= Do I need an account or an API key? =

No. The plugin uses the public Suawave API, which needs no registration and no key.

= Where do I get the callback public key? =

Run `curl https://api.suawave.com/pubkey/` once and paste the `pubkey` value in the settings, turning `\n` into real line breaks.

= Can a customer pay less than the order total? =

A smaller confirmed deposit leaves the order on hold with a note. A later deposit is added to the running total, and the order is paid when the total reaches the order amount.

= Does it work without WooCommerce? =

Yes. The functions `suawave_create_invoice()` and `suawave_invoice_is_paid()` create an invoice and read its state from any plugin or theme.

= Why does the callback have no nonce? =

The callback is a signed server-to-server request from the API, not a form of a logged-in user. The RSA signature in the `x-ca-signature` header authenticates it instead.

== Changelog ==

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.0 =
First release.
