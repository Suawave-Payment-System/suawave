{if $suawave_paid}
  <p>{l s='Your crypto payment has been confirmed. Thank you.' d='Modules.Suawave.Shop'}</p>
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
    <p>{l s='Minimum accepted per transfer:' d='Modules.Suawave.Shop'} {$suawave_minimum|escape:'htmlall':'UTF-8'}</p>
  </section>
{/if}
