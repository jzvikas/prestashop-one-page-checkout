<section class="jzopc-agreements" data-jzopc-section="agreements" aria-labelledby="jzopc-agreements-title">
  <h2 id="jzopc-agreements-title">{l s='Terms and conditions' mod='jzonepagecheckout'}</h2>

  {if $conditions|count}
    <fieldset class="jzopc-agreements__list">
      <legend class="sr-only">{l s='Required agreements' mod='jzonepagecheckout'}</legend>
      {foreach from=$conditions key=conditionKey item=conditionHtml}
        <div class="jzopc-agreements__item">
          <input
            id="jzopc-agreement-{$conditionKey|escape:'htmlall':'UTF-8'}"
            class="jzopc-agreements__checkbox"
            type="checkbox"
            name="agreements[]"
            value="{$conditionKey|escape:'htmlall':'UTF-8'}"
            required
          >
          <label for="jzopc-agreement-{$conditionKey|escape:'htmlall':'UTF-8'}" class="jzopc-agreements__label">
            {* Core ConditionsToApproveFinder returns formatted shop/module terms HTML. *}
            {$conditionHtml nofilter}
          </label>
        </div>
      {/foreach}
    </fieldset>
  {/if}
</section>
