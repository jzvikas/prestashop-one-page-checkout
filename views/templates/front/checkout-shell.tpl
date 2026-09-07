{foreach $jzopc_stylesheet_urls as $jzopc_stylesheet_url}
  <link
    rel="stylesheet"
    href="{$jzopc_stylesheet_url|escape:'htmlall':'UTF-8'}"
    data-jzopc-style-asset
  >
{/foreach}

<div
  class="jzopc-checkout"
  data-jzopc-checkout
  data-jzopc-cart-id="{$jzopc_bootstrap.cartId|escape:'htmlall':'UTF-8'}"
  data-jzopc-state-version="{$jzopc_bootstrap.stateVersion|escape:'htmlall':'UTF-8'}"
  data-jzopc-csrf-token="{$jzopc_bootstrap.csrfToken|escape:'htmlall':'UTF-8'}"
  data-jzopc-identity-url="{$jzopc_bootstrap.identityUrl|escape:'htmlall':'UTF-8'}"
  data-jzopc-address-url="{$jzopc_bootstrap.addressUrl|escape:'htmlall':'UTF-8'}"
  data-jzopc-address-save-url="{$jzopc_bootstrap.addressSaveUrl|escape:'htmlall':'UTF-8'}"
  data-jzopc-carrier-url="{$jzopc_bootstrap.carrierUrl|escape:'htmlall':'UTF-8'}"
  data-jzopc-payment-url="{$jzopc_bootstrap.paymentUrl|escape:'htmlall':'UTF-8'}"
  data-jzopc-agreements-url="{$jzopc_bootstrap.agreementsUrl|escape:'htmlall':'UTF-8'}"
  data-jzopc-finalization-url="{$jzopc_bootstrap.finalizationUrl|escape:'htmlall':'UTF-8'}"
  data-jzopc-finalization-reserved="{if $jzopc_finalization_reserved}1{else}0{/if}"
>
  <div class="jzopc-checkout__main">
    {foreach from=$jzopc_sections key=jzopc_section_key item=jzopc_section_html}
      {if $jzopc_section_key !== 'summary'}
        {$jzopc_section_html nofilter}
      {/if}
    {/foreach}
  </div>

  <aside class="jzopc-checkout__rail">
    {$jzopc_sections.summary nofilter}

    <div class="jzopc-finalization" data-jzopc-finalization>
      <div id="jzopc-final-status" class="jzopc-finalization__status" data-jzopc-final-status role="status" aria-live="polite" aria-atomic="true"></div>
      <button
        class="jzopc-finalization__submit"
        data-jzopc-final-submit
        type="button"
        aria-describedby="jzopc-final-status"
      >
        <span data-jzopc-final-label>{l s='Place order' d='Modules.Jzonepagecheckout.Shop'}</span>
        <span data-jzopc-final-loading hidden>{l s='Processing order…' d='Modules.Jzonepagecheckout.Shop'}</span>
      </button>

      <div data-jzopc-final-messages hidden>
        <span data-jzopc-final-message="payment-required">{l s='Please choose a payment method before placing the order.' d='Modules.Jzonepagecheckout.Shop'}</span>
        <span data-jzopc-final-message="agreements-required">{l s='Please accept all required checkout conditions before placing the order.' d='Modules.Jzonepagecheckout.Shop'}</span>
        <span data-jzopc-final-message="binary-required">{l s='This payment method requires its own confirmation control.' d='Modules.Jzonepagecheckout.Shop'}</span>
        <span data-jzopc-final-message="payment-unavailable">{l s='The selected payment method cannot be submitted. Please choose another payment method.' d='Modules.Jzonepagecheckout.Shop'}</span>
        <span data-jzopc-final-message="secure-attempt-failed">{l s='Your browser could not start a secure order submission. Please try again.' d='Modules.Jzonepagecheckout.Shop'}</span>
        <span data-jzopc-final-message="session-changed">{l s='Your checkout session changed. Please refresh the page and try again.' d='Modules.Jzonepagecheckout.Shop'}</span>
        <span data-jzopc-final-message="submission-failed">{l s='Order submission failed. Please try again.' d='Modules.Jzonepagecheckout.Shop'}</span>
        <span data-jzopc-final-message="review-checkout">{l s='The order could not be submitted. Please review your checkout and try again.' d='Modules.Jzonepagecheckout.Shop'}</span>
        <span data-jzopc-final-message="handoff-failed">{l s='The payment method could not be opened. Please try again.' d='Modules.Jzonepagecheckout.Shop'}</span>
        <span data-jzopc-final-message="handoff-ambiguous">{l s='Payment processing may already have started. Do not submit the order again. Check the payment result or refresh this page later.' d='Modules.Jzonepagecheckout.Shop'}</span>
        <span data-jzopc-final-message="payment-changed">{l s='The selected payment method is no longer available. Please refresh the checkout.' d='Modules.Jzonepagecheckout.Shop'}</span>
      </div>
    </div>
  </aside>
</div>

{* The custom shell is the authoritative asset boundary. These external same-origin scripts are
   emitted only when OPC takeover actually rendered, so native/Core fallback cannot receive the
   checkout runtime while the custom shell cannot exist without its safety controllers. *}
{foreach $jzopc_javascript_urls as $jzopc_javascript_url}
  <script
    src="{$jzopc_javascript_url|escape:'htmlall':'UTF-8'}"
    data-jzopc-runtime-asset
    defer
  ></script>
{/foreach}
