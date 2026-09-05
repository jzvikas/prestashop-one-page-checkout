<section class="jzopc-identity" data-jzopc-section="identity" aria-labelledby="jzopc-identity-title">
  <h2 id="jzopc-identity-title" class="jzopc-identity__title">{l s='Personal information' d='Modules.Jzonepagecheckout.Shop'}</h2>

  {if $bound}
    <div class="jzopc-identity__current" role="status">
      <p class="jzopc-identity__name">
        {$firstName|escape:'html':'UTF-8'} {$lastName|escape:'html':'UTF-8'}
      </p>
      <p class="jzopc-identity__email">{$email|escape:'html':'UTF-8'}</p>
      <p class="jzopc-identity__status">
        {if $isGuest}
          {l s='Guest checkout information is saved.' d='Modules.Jzonepagecheckout.Shop'}
        {else}
          {l s='You are signed in.' d='Modules.Jzonepagecheckout.Shop'}
        {/if}
      </p>
    </div>
  {else}
    <div class="jzopc-identity__forms" aria-live="polite">
      <div class="jzopc-identity__register" data-jzopc-identity-form="create">
        <h3>{l s='Customer information' d='Modules.Jzonepagecheckout.Shop'}</h3>
        {if $guestAllowed}
          <p>{l s='Enter your details to continue as a guest, or enter a password to create an account.' d='Modules.Jzonepagecheckout.Shop'}</p>
        {else}
          <p>{l s='Create an account to continue checkout.' d='Modules.Jzonepagecheckout.Shop'}</p>
        {/if}
        {$registerFormHtml nofilter}
      </div>

      <div class="jzopc-identity__login" data-jzopc-identity-form="login">
        <h3>{l s='Already have an account?' d='Modules.Jzonepagecheckout.Shop'}</h3>
        {$loginFormHtml nofilter}
      </div>
    </div>
  {/if}
</section>
