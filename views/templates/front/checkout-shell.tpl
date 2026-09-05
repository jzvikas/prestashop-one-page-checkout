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
>
  {foreach $jzopc_sections as $jzopc_section_html}
    {$jzopc_section_html nofilter}
  {/foreach}
</div>
