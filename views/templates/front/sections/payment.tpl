<section class="jzopc-payment" data-jzopc-section="payment" aria-labelledby="jzopc-payment-title">
  <h2 id="jzopc-payment-title" class="jzopc-payment__title">{l s='Payment' d='Modules.Jzonepagecheckout.Shop'}</h2>

  {if $hookDisplayPaymentTop}
    <div class="jzopc-payment__hook jzopc-payment__hook--top">{$hookDisplayPaymentTop nofilter}</div>
  {/if}

  {if $isFree}
    <p class="jzopc-payment__free" role="status">{l s='No payment is needed for this order.' d='Modules.Jzonepagecheckout.Shop'}</p>
  {/if}

  <div class="jzopc-payment__options payment-options" data-jzopc-payment-options>
    {foreach from=$paymentOptions item=moduleOptions}
      {foreach from=$moduleOptions item=option}
        <div class="jzopc-payment__option-wrap">
          <div id="{$option.id|escape:'html':'UTF-8'}-container" class="jzopc-payment__option payment-option">
            <input
              class="ps-shown-by-js{if !empty($option.binary)} binary{/if}"
              id="{$option.id|escape:'html':'UTF-8'}"
              data-module-name="{$option.module_name|default:''|escape:'html':'UTF-8'}"
              name="payment-option"
              type="radio"
              required
              {if $isFree}checked{/if}
            >
            <label for="{$option.id|escape:'html':'UTF-8'}">
              <span>{$option.call_to_action_text|default:''|escape:'html':'UTF-8'}</span>
              {if !empty($option.logo)}
                <img src="{$option.logo|escape:'html':'UTF-8'}" alt="" loading="lazy">
              {/if}
            </label>
          </div>

          {if !empty($option.additionalInformation)}
            <div id="{$option.id|escape:'html':'UTF-8'}-additional-information" class="jzopc-payment__additional js-additional-information additional-information ps-hidden">
              {$option.additionalInformation nofilter}
            </div>
          {/if}

          <div id="pay-with-{$option.id|escape:'html':'UTF-8'}-form" class="jzopc-payment__form js-payment-option-form ps-hidden">
            {if !empty($option.form)}
              {$option.form nofilter}
            {else}
              <form id="payment-{$option.id|escape:'html':'UTF-8'}-form" method="POST" action="{$option.action|default:''|escape:'html':'UTF-8'}">
                {if !empty($option.inputs)}
                  {foreach from=$option.inputs item=input}
                    <input
                      type="{$input.type|default:'hidden'|escape:'html':'UTF-8'}"
                      name="{$input.name|default:''|escape:'html':'UTF-8'}"
                      value="{$input.value|default:''|escape:'html':'UTF-8'}"
                    >
                  {/foreach}
                {/if}
                <button class="jzopc-payment__native-submit" hidden id="pay-with-{$option.id|escape:'html':'UTF-8'}" type="submit"></button>
              </form>
            {/if}
          </div>
        </div>
      {/foreach}
    {foreachelse}
      <p class="jzopc-payment__empty" role="status">{l s='Unfortunately, no payment method is currently available.' d='Modules.Jzonepagecheckout.Shop'}</p>
    {/foreach}
  </div>
</section>
