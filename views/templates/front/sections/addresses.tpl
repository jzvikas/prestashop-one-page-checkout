<section class="jzopc-addresses" data-jzopc-section="addresses" aria-labelledby="jzopc-addresses-title">
  <h2 id="jzopc-addresses-title" class="jzopc-addresses__title">{l s='Addresses' d='Modules.Jzonepagecheckout.Shop'}</h2>

  {if empty($addresses)}
    <p class="jzopc-addresses__empty" role="status">{l s='No saved address is available yet.' d='Modules.Jzonepagecheckout.Shop'}</p>
  {else}
    <fieldset class="jzopc-addresses__group" data-jzopc-address-group="delivery">
      <legend>{l s='Delivery address' d='Modules.Jzonepagecheckout.Shop'}</legend>
      {foreach from=$addresses item=address}
        <label class="jzopc-addresses__option" for="jzopc-delivery-address-{$address.id|intval}">
          <input
            id="jzopc-delivery-address-{$address.id|intval}"
            type="radio"
            name="id_address_delivery"
            value="{$address.id|intval}"
            {if $deliveryAddressId === $address.id}checked{/if}
          >
          <span class="jzopc-addresses__alias">{$address.alias|escape:'html':'UTF-8'}</span>
          <address class="jzopc-addresses__address">
            {foreach from=$address.lines item=line}
              <span class="jzopc-addresses__line">{$line|escape:'html':'UTF-8'}</span>{if !$line@last}<br>{/if}
            {/foreach}
          </address>
        </label>
      {/foreach}
    </fieldset>

    <div class="jzopc-addresses__invoice-mode">
      <label for="jzopc-use-same-address">
        <input
          id="jzopc-use-same-address"
          type="checkbox"
          name="use_same_address"
          value="1"
          {if $useSameAddress}checked{/if}
        >
        {l s='Use the delivery address as the invoice address' d='Modules.Jzonepagecheckout.Shop'}
      </label>
    </div>

    <fieldset
      class="jzopc-addresses__group"
      data-jzopc-address-group="invoice"
      {if $useSameAddress}hidden{/if}
    >
      <legend>{l s='Invoice address' d='Modules.Jzonepagecheckout.Shop'}</legend>
      {foreach from=$addresses item=address}
        <label class="jzopc-addresses__option" for="jzopc-invoice-address-{$address.id|intval}">
          <input
            id="jzopc-invoice-address-{$address.id|intval}"
            type="radio"
            name="id_address_invoice"
            value="{$address.id|intval}"
            {if $invoiceAddressId === $address.id}checked{/if}
          >
          <span class="jzopc-addresses__alias">{$address.alias|escape:'html':'UTF-8'}</span>
          <address class="jzopc-addresses__address">
            {foreach from=$address.lines item=line}
              <span class="jzopc-addresses__line">{$line|escape:'html':'UTF-8'}</span>{if !$line@last}<br>{/if}
            {/foreach}
          </address>
        </label>
      {/foreach}
    </fieldset>
  {/if}
</section>
