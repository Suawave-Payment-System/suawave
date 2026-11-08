<?php

$_['heading_title'] = 'Suawave';
$_['text_extension'] = 'Extensions';
$_['text_home'] = 'Home';
$_['text_success'] = 'Success: the settings were saved.';
$_['text_edit'] = 'Edit Suawave';
$_['text_enabled'] = 'Enabled';
$_['text_disabled'] = 'Disabled';

$_['entry_status'] = 'Status';
$_['entry_base_url'] = 'Gateway API base URL';
$_['entry_ticker'] = 'Asset and chain (ticker)';
$_['entry_payout_address'] = 'Payout address';
$_['entry_public_key'] = 'Callback public key';
$_['entry_external_origin'] = 'Shop public origin';
$_['entry_order_currency'] = 'Order currency';
$_['entry_pending_status'] = 'Order status while waiting for payment';
$_['entry_paid_status'] = 'Order status after a confirmed payment';
$_['entry_sort_order'] = 'Sort order';
$_['entry_callback_url'] = 'Callback endpoint of this shop';

$_['help_base_url'] = 'Base URL of the gateway public API, for example https://api.suawave.com. No trailing path.';
$_['help_ticker'] = 'Lowercase ticker including the slash, for example trc20/usdt or bep20/usdt.';
$_['help_payout_address'] = 'The address that receives the forwarded funds.';
$_['help_public_key'] = 'PEM public key of the gateway (GET /pubkey/). Leave empty to fetch it from the gateway when saving.';
$_['help_external_origin'] = 'The https origin exactly as the gateway reaches this shop, for example https://shop.example. Callback signatures are verified against it.';
$_['help_order_currency'] = 'Only orders in this currency can be paid. The gateway amount is the order total in this currency, one to one with the token.';

$_['error_permission'] = 'Warning: you do not have permission to modify this extension.';
$_['error_base_url'] = 'The gateway API base URL must start with http:// or https://.';
$_['error_ticker'] = 'The ticker is required.';
$_['error_payout_address'] = 'The payout address is required.';
$_['error_external_origin'] = 'The shop public origin must be an https origin without a path.';
$_['error_public_key_fetch'] = 'The public key could not be fetched from the gateway.';
$_['error_public_key'] = 'The callback public key is not usable.';
