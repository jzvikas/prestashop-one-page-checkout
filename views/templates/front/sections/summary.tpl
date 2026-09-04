<section class="jzopc-summary" data-checkout-section="summary" aria-live="polite" aria-atomic="true">
  <h2 class="jzopc-summary__title">{l s='Order summary' d='Modules.Jzonepagecheckout.Shop'}</h2>

  {if isset($cart.products_count)}
    <p class="jzopc-summary__count">
      {l s='%count% item(s) in your cart' sprintf=['%count%' => $cart.products_count] d='Modules.Jzonepagecheckout.Shop'}
    </p>
  {/if}

  {if isset($cart.subtotals)}
    <dl class="jzopc-summary__lines">
      {foreach from=$cart.subtotals item=subtotal}
        {if isset($subtotal.label) && isset($subtotal.value) && $subtotal.value !== null}
          <div class="jzopc-summary__line">
            <dt>{$subtotal.label|escape:'htmlall':'UTF-8'}</dt>
            <dd>{$subtotal.value|escape:'htmlall':'UTF-8'}</dd>
          </div>
        {/if}
      {/foreach}
    </dl>
  {/if}

  {if isset($cart.totals.total)}
    <div class="jzopc-summary__total">
      <span>{$cart.totals.total.label|escape:'htmlall':'UTF-8'}</span>
      <strong>{$cart.totals.total.value|escape:'htmlall':'UTF-8'}</strong>
    </div>
  {/if}
</section>
