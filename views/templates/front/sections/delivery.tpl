<section class="jzopc-delivery" data-jzopc-section="delivery" aria-labelledby="jzopc-delivery-title">
  <h2 id="jzopc-delivery-title" class="jzopc-delivery__title">{l s='Shipping method' d='Modules.Jzonepagecheckout.Shop'}</h2>

  {if $hookDisplayBeforeCarrier}
    <div class="jzopc-delivery__hook jzopc-delivery__hook--before">{$hookDisplayBeforeCarrier nofilter}</div>
  {/if}

  {if empty($deliveryOptions)}
    <p class="jzopc-delivery__empty" role="status">{l s='No delivery method is currently available for this address.' d='Modules.Jzonepagecheckout.Shop'}</p>
  {else}
    <fieldset class="jzopc-delivery__options">
      <legend class="jzopc-delivery__legend">{l s='Choose a delivery method' d='Modules.Jzonepagecheckout.Shop'}</legend>
      {foreach from=$deliveryOptions key=deliveryOption item=carrier name=deliveryOptions}
        <label class="jzopc-delivery__option" for="jzopc-delivery-option-{$smarty.foreach.deliveryOptions.iteration|intval}">
          <input
            id="jzopc-delivery-option-{$smarty.foreach.deliveryOptions.iteration|intval}"
            type="radio"
            name="delivery_option"
            value="{$deliveryOption|escape:'html':'UTF-8'}"
            {if $selectedDeliveryOption !== null && $selectedDeliveryOption === $deliveryOption}checked{/if}
          >
          <span class="jzopc-delivery__details">
            <span class="jzopc-delivery__name">{$carrier.name|default:''|escape:'html':'UTF-8'}</span>
            {if !empty($carrier.delay)}<span class="jzopc-delivery__delay">{$carrier.delay|escape:'html':'UTF-8'}</span>{/if}
            {if !empty($carrier.price)}<span class="jzopc-delivery__price">{$carrier.price|escape:'html':'UTF-8'}</span>{/if}
          </span>
        </label>
        {if !empty($carrier.extraContent)}
          <div class="jzopc-delivery__extra" data-jzopc-carrier-extra="{$deliveryOption|escape:'html':'UTF-8'}">{$carrier.extraContent nofilter}</div>
        {/if}
      {/foreach}
    </fieldset>
  {/if}

  {if $hookDisplayAfterCarrier}
    <div class="jzopc-delivery__hook jzopc-delivery__hook--after">{$hookDisplayAfterCarrier nofilter}</div>
  {/if}
</section>
