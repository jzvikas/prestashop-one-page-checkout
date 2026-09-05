<div
  class="jzopc-checkout"
  data-jzopc-checkout
  data-jzopc-cart-id="{$jzopcBootstrap.cartId|intval}"
  data-jzopc-csrf-token="{$jzopcBootstrap.csrfToken|escape:'html':'UTF-8'}"
  data-jzopc-state-version="{$jzopcBootstrap.stateVersion|escape:'html':'UTF-8'}"
  data-jzopc-payment-url="{$jzopcBootstrap.paymentSelectionUrl|escape:'html':'UTF-8'}"
  data-jzopc-agreements-url="{$jzopcBootstrap.agreementsUrl|escape:'html':'UTF-8'}"
>
  <div class="jzopc-checkout__status" role="status" aria-live="polite" aria-atomic="true" data-jzopc-status></div>

  <div class="jzopc-checkout__layout">
    <main class="jzopc-checkout__main" aria-label="{l s='Checkout' d='Modules.Jzonepagecheckout.Shop'}">
      {$jzopcSections.addresses nofilter}
      {$jzopcSections.delivery nofilter}
      {$jzopcSections.payment nofilter}
      {$jzopcSections.agreements nofilter}
    </main>

    <aside class="jzopc-checkout__summary" aria-label="{l s='Order summary' d='Modules.Jzonepagecheckout.Shop'}">
      {$jzopcSections.summary nofilter}
    </aside>
  </div>
</div>
