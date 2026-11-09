{if $suawave_paid}
  <p>{l s='Your crypto payment has been confirmed. Thank you.' d='Modules.Suawave.Shop'}</p>
{elseif $suawave_closed}
  <section class="suawave-payment">
    <h3>{l s='Payment window closed' d='Modules.Suawave.Shop'}</h3>
    <p>{l s='The payment window for order' d='Modules.Suawave.Shop'} <strong>{$suawave_order_reference|escape:'htmlall':'UTF-8'}</strong> (#{$suawave_order_id|escape:'htmlall':'UTF-8'}) {l s='closed on' d='Modules.Suawave.Shop'} {$suawave_deadline|escape:'htmlall':'UTF-8'}. {l s='Do not send funds to the old address. Please contact the shop.' d='Modules.Suawave.Shop'}</p>
    {if $suawave_retry_url}
      <form method="post" action="{$suawave_retry_url|escape:'htmlall':'UTF-8'}">
        <input type="hidden" name="id_order" value="{$suawave_order_id|escape:'htmlall':'UTF-8'}">
        <input type="hidden" name="key" value="{$suawave_retry_key|escape:'htmlall':'UTF-8'}">
        <button type="submit" class="btn btn-primary">{l s='Request a new payment address' d='Modules.Suawave.Shop'}</button>
      </form>
    {/if}
  </section>
{else}
  <section class="suawave-payment">
    <h3>{l s='Complete your crypto payment' d='Modules.Suawave.Shop'}</h3>
    <p>
      {l s='Send exactly' d='Modules.Suawave.Shop'}
      <strong>{$suawave_amount|escape:'htmlall':'UTF-8'}</strong>
      {l s='of' d='Modules.Suawave.Shop'}
      <strong>{$suawave_ticker|escape:'htmlall':'UTF-8'}</strong>
      {l s='to this address:' d='Modules.Suawave.Shop'}
    </p>
    <p><code>{$suawave_address|escape:'htmlall':'UTF-8'}</code></p>
    {if $suawave_qr}
      <p><img src="{$suawave_qr|escape:'htmlall':'UTF-8'}" alt="{l s='Payment QR code' d='Modules.Suawave.Shop'}" width="256" height="256"></p>
    {/if}
    {if $suawave_detected}
      <p>{l s='Your transfer was detected and is waiting for confirmations. This page does not update by itself, reload it or check your order history later.' d='Modules.Suawave.Shop'}</p>
    {else}
      <p>{l s='The order is confirmed automatically once the transfer is confirmed on the network.' d='Modules.Suawave.Shop'}</p>
    {/if}
    <p>{l s='Received so far:' d='Modules.Suawave.Shop'} {$suawave_received|escape:'htmlall':'UTF-8'}</p>
    {if $suawave_deadline}
      <p>{l s='Pay before:' d='Modules.Suawave.Shop'} <strong>{$suawave_deadline|escape:'htmlall':'UTF-8'}</strong></p>
    {/if}
    <p>{l s='Minimum accepted per transfer:' d='Modules.Suawave.Shop'} {$suawave_minimum|escape:'htmlall':'UTF-8'}</p>
  </section>
{/if}
