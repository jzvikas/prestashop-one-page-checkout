(function () {
  'use strict';

  const ROOT_SELECTOR = '[data-jzopc-checkout]';
  const SECTION_SELECTOR = '[data-jzopc-section]';
  const IDENTITY_FORM_SELECTOR = '[data-jzopc-section="identity"] [data-jzopc-identity-form]';
  const ADDRESS_SECTION_SELECTOR = '[data-jzopc-section="addresses"]';
  const ADDRESS_EDITOR_SELECTOR = '[data-jzopc-address-editor]';
  const ADDRESS_EDITOR_OPEN_SELECTOR = '[data-jzopc-address-editor-open]';
  const ADDRESS_EDITOR_CANCEL_SELECTOR = '[data-jzopc-address-editor-cancel]';
  const ADDRESS_EDITOR_COUNTRY_SELECTOR = ADDRESS_EDITOR_SELECTOR + ' select[name="id_country"]';
  const DELIVERY_OPTION_SELECTOR = '[data-jzopc-section="delivery"] input[name="delivery_option"]';
  const ADDRESS_INPUT_SELECTOR = [
    ADDRESS_SECTION_SELECTOR + ' input[name="id_address_delivery"]',
    ADDRESS_SECTION_SELECTOR + ' input[name="id_address_invoice"]',
    ADDRESS_SECTION_SELECTOR + ' input[name="use_same_address"]',
  ].join(',');
  const AGREEMENT_SELECTOR = '[data-jzopc-section="agreements"] input[name="agreements[]"]';
  const TRUSTED_BINDING_NAMES = new Set(['token', 'cartId', 'stateVersion']);
  const instances = new WeakMap();

  class JzOpcMutationClient {
    constructor(root) {
      this.root = root;
      this.abortController = null;
      this.listenerAbortController = typeof AbortController === 'function' ? new AbortController() : null;
      this.latestSequence = 0;
      this.onPaymentSelected = this.onPaymentSelected.bind(this);
      this.onCheckoutInputChanged = this.onCheckoutInputChanged.bind(this);
      this.onCheckoutClick = this.onCheckoutClick.bind(this);
      this.onCheckoutSubmit = this.onCheckoutSubmit.bind(this);
    }

    start() {
      if (!this.readBootstrap()) {
        return false;
      }

      const listenerOptions = this.listenerAbortController
        ? { signal: this.listenerAbortController.signal }
        : undefined;

      this.root.addEventListener('jzopc:payment:selected', this.onPaymentSelected, listenerOptions);
      this.root.addEventListener('change', this.onCheckoutInputChanged, listenerOptions);
      this.root.addEventListener('click', this.onCheckoutClick, listenerOptions);
      this.root.addEventListener('submit', this.onCheckoutSubmit, listenerOptions);
      this.dispatch('jzopc:checkout:initialized', { stateVersion: this.stateVersion });

      return true;
    }

    destroy() {
      if (this.abortController) {
        this.abortController.abort();
        this.abortController = null;
      }

      if (this.listenerAbortController) {
        this.listenerAbortController.abort();
      } else {
        this.root.removeEventListener('jzopc:payment:selected', this.onPaymentSelected);
        this.root.removeEventListener('change', this.onCheckoutInputChanged);
        this.root.removeEventListener('click', this.onCheckoutClick);
        this.root.removeEventListener('submit', this.onCheckoutSubmit);
      }

      instances.delete(this.root);
    }

    readBootstrap() {
      const cartId = this.root.dataset.jzopcCartId || '';
      const stateVersion = this.root.dataset.jzopcStateVersion || '';
      const csrfToken = this.root.dataset.jzopcCsrfToken || '';
      const identityUrl = this.root.dataset.jzopcIdentityUrl || '';
      const addressUrl = this.root.dataset.jzopcAddressUrl || '';
      const addressSaveUrl = this.root.dataset.jzopcAddressSaveUrl || '';
      const carrierUrl = this.root.dataset.jzopcCarrierUrl || '';
      const paymentUrl = this.root.dataset.jzopcPaymentUrl || '';
      const agreementsUrl = this.root.dataset.jzopcAgreementsUrl || '';

      if (
        !/^\d+$/.test(cartId)
        || Number(cartId) <= 0
        || !stateVersion
        || !csrfToken
        || !identityUrl
        || !addressUrl
        || !addressSaveUrl
        || !carrierUrl
        || !paymentUrl
        || !agreementsUrl
      ) {
        return false;
      }

      this.cartId = cartId;
      this.stateVersion = stateVersion;
      this.csrfToken = csrfToken;
      this.identityUrl = identityUrl;
      this.addressUrl = addressUrl;
      this.addressSaveUrl = addressSaveUrl;
      this.carrierUrl = carrierUrl;
      this.paymentUrl = paymentUrl;
      this.agreementsUrl = agreementsUrl;

      return true;
    }

    onPaymentSelected(event) {
      const detail = event.detail || {};
      if (typeof detail.optionId !== 'string' || typeof detail.moduleName !== 'string' || !detail.optionId || !detail.moduleName) {
        return;
      }

      this.mutate(this.paymentUrl, {
        paymentOptionId: detail.optionId,
        paymentModule: detail.moduleName,
      });
    }

    onCheckoutClick(event) {
      const target = event.target;
      if (!(target instanceof Element)) {
        return;
      }

      const cancel = target.closest(ADDRESS_EDITOR_CANCEL_SELECTOR);
      if (cancel && this.root.contains(cancel)) {
        const editor = cancel.closest(ADDRESS_EDITOR_SELECTOR);
        if (editor) {
          editor.remove();
        }
        return;
      }

      const opener = target.closest(ADDRESS_EDITOR_OPEN_SELECTOR);
      if (!opener || !this.root.contains(opener)) {
        return;
      }

      const role = opener.getAttribute('data-jzopc-address-role') || '';
      if (role !== 'delivery' && role !== 'invoice') {
        return;
      }

      const payload = {
        addressAction: 'present',
        addressRole: role,
        useSameAddress: role === 'delivery' && this.currentUseSameAddress() ? '1' : '0',
      };
      const addressId = opener.getAttribute('data-jzopc-address-id') || '';
      if (/^[1-9]\d*$/.test(addressId)) {
        payload.id_address = addressId;
      }

      this.mutate(this.addressSaveUrl, payload);
    }

    onCheckoutSubmit(event) {
      const form = event.target;
      if (!(form instanceof HTMLFormElement)) {
        return;
      }

      const identityContainer = form.closest(IDENTITY_FORM_SELECTOR);
      if (identityContainer && this.root.contains(identityContainer)) {
        event.preventDefault();
        const action = identityContainer.getAttribute('data-jzopc-identity-form') || '';
        if (action !== 'create' && action !== 'login') {
          return;
        }

        const payload = this.serializeForm(form);
        payload.identityAction = action;
        this.dispatch('jzopc:identity:submitting', { action });
        this.mutate(this.identityUrl, payload);
        return;
      }

      const editor = form.closest(ADDRESS_EDITOR_SELECTOR);
      if (!editor || !this.root.contains(editor)) {
        return;
      }

      event.preventDefault();
      const role = editor.getAttribute('data-jzopc-address-role') || '';
      if (role !== 'delivery' && role !== 'invoice') {
        return;
      }

      const payload = this.serializeForm(form);
      payload.addressAction = 'save';
      payload.addressRole = role;
      payload.useSameAddress = editor.getAttribute('data-jzopc-use-same-address') === '1' ? '1' : '0';
      this.mutate(this.addressSaveUrl, payload);
    }

    onCheckoutInputChanged(event) {
      const target = event.target;
      if (!(target instanceof Element)) {
        return;
      }

      if (target.matches(ADDRESS_EDITOR_COUNTRY_SELECTOR) && target instanceof HTMLSelectElement) {
        this.onAddressEditorCountryChanged(target);
        return;
      }

      if (!(target instanceof HTMLInputElement)) {
        return;
      }

      if (target.matches(ADDRESS_INPUT_SELECTOR)) {
        this.onAddressChanged();
        return;
      }

      if (target.matches(DELIVERY_OPTION_SELECTOR)) {
        this.onCarrierChanged(target);
        return;
      }

      if (target.matches(AGREEMENT_SELECTOR)) {
        this.onAgreementChanged();
      }
    }

    onAddressEditorCountryChanged(target) {
      const editor = target.closest(ADDRESS_EDITOR_SELECTOR);
      const form = target.closest('form');
      if (!editor || !(form instanceof HTMLFormElement)) {
        return;
      }

      const role = editor.getAttribute('data-jzopc-address-role') || '';
      if (role !== 'delivery' && role !== 'invoice') {
        return;
      }

      const payload = this.serializeForm(form);
      payload.addressAction = 'present';
      payload.addressRole = role;
      payload.useSameAddress = editor.getAttribute('data-jzopc-use-same-address') === '1' ? '1' : '0';
      this.mutate(this.addressSaveUrl, payload);
    }

    serializeForm(form) {
      const payload = {};
      for (const [rawName, value] of new FormData(form).entries()) {
        const name = rawName.endsWith('[]') ? rawName.slice(0, -2) : rawName;
        if (TRUSTED_BINDING_NAMES.has(name)) {
          continue;
        }
        if (Object.prototype.hasOwnProperty.call(payload, name)) {
          payload[name] = Array.isArray(payload[name]) ? payload[name] : [payload[name]];
          payload[name].push(String(value));
        } else {
          payload[name] = String(value);
        }
      }

      return payload;
    }

    currentUseSameAddress() {
      const input = this.root.querySelector(ADDRESS_SECTION_SELECTOR + ' input[name="use_same_address"]');
      return input instanceof HTMLInputElement && input.checked;
    }

    onAddressChanged() {
      const addressSection = this.root.querySelector(ADDRESS_SECTION_SELECTOR);
      if (!addressSection) {
        return;
      }

      const sameAddressInput = addressSection.querySelector('input[name="use_same_address"]');
      if (!(sameAddressInput instanceof HTMLInputElement)) {
        return;
      }

      const deliveryInput = addressSection.querySelector('input[name="id_address_delivery"]:checked');
      const invoiceInput = addressSection.querySelector('input[name="id_address_invoice"]:checked');
      const payload = {
        useSameAddress: sameAddressInput.checked ? '1' : '0',
      };

      if (deliveryInput instanceof HTMLInputElement && deliveryInput.value) {
        payload.deliveryAddressId = deliveryInput.value;
      }

      if (!sameAddressInput.checked && invoiceInput instanceof HTMLInputElement && invoiceInput.value) {
        payload.invoiceAddressId = invoiceInput.value;
      }

      this.dispatch('jzopc:address:selected', {
        deliveryAddressId: payload.deliveryAddressId || null,
        invoiceAddressId: payload.invoiceAddressId || null,
        useSameAddress: sameAddressInput.checked,
      });
      this.mutate(this.addressUrl, payload);
    }

    onCarrierChanged(target) {
      if (!target.checked || !target.value) {
        return;
      }

      this.dispatch('jzopc:carrier:selected', { deliveryOption: target.value });
      this.mutate(this.carrierUrl, { deliveryOption: target.value });
    }

    onAgreementChanged() {
      const agreements = Array.from(this.root.querySelectorAll(AGREEMENT_SELECTOR))
        .filter((input) => input.checked)
        .map((input) => input.value);

      this.mutate(this.agreementsUrl, { agreements });
    }

    mutate(url, operationPayload) {
      const sequence = ++this.latestSequence;
      if (this.abortController) {
        this.abortController.abort();
      }

      this.abortController = typeof AbortController === 'function' ? new AbortController() : null;
      this.dispatch('jzopc:checkout:updating', { sequence });

      this.send(url, operationPayload, sequence, false);
    }

    async send(url, operationPayload, sequence, staleRetried) {
      const body = new URLSearchParams();
      body.set('token', this.csrfToken);
      body.set('cartId', this.cartId);
      body.set('stateVersion', this.stateVersion);

      for (const [name, value] of Object.entries(operationPayload)) {
        if (TRUSTED_BINDING_NAMES.has(name)) {
          continue;
        }
        if (Array.isArray(value)) {
          for (const item of value) {
            body.append(name + '[]', item);
          }
        } else {
          body.set(name, value);
        }
      }

      try {
        const response = await fetch(url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: body.toString(),
          signal: this.abortController ? this.abortController.signal : undefined,
        });
        const payload = await response.json();

        if (sequence !== this.latestSequence) {
          return;
        }

        if (!this.isValidResponse(payload)) {
          throw new Error('Invalid checkout mutation response.');
        }

        const stale = !payload.success
          && response.status === 409
          && payload.retryable === true
          && payload.errors.some((error) => error && error.code === 'stale_state')
          && payload.stateVersion;

        if (stale && !staleRetried) {
          this.setStateVersion(payload.stateVersion);
          await this.send(url, operationPayload, sequence, true);
          return;
        }

        this.applyResponse(payload, sequence);
      } catch (error) {
        if (sequence !== this.latestSequence || (error && error.name === 'AbortError')) {
          return;
        }

        this.dispatch('jzopc:checkout:error', {
          sequence,
          message: 'Checkout update failed.',
        });
      }
    }

    isValidResponse(payload) {
      if (!payload || typeof payload !== 'object' || typeof payload.success !== 'boolean') {
        return false;
      }
      if (typeof payload.stateVersion !== 'string' || !payload.stateVersion) {
        return false;
      }
      if (!payload.sections || typeof payload.sections !== 'object' || Array.isArray(payload.sections)) {
        return false;
      }
      if (!Array.isArray(payload.errors)) {
        return false;
      }
      if (payload.redirect !== null && typeof payload.redirect !== 'string') {
        return false;
      }
      if (
        Object.prototype.hasOwnProperty.call(payload, 'csrfToken')
        && (typeof payload.csrfToken !== 'string' || !payload.csrfToken)
      ) {
        return false;
      }

      return Object.values(payload.sections).every((html) => typeof html === 'string');
    }

    applyResponse(payload, sequence) {
      if (sequence !== this.latestSequence) {
        return;
      }

      const replacements = this.prepareSectionReplacements(payload.sections);
      if (replacements === null) {
        this.dispatch('jzopc:checkout:error', {
          sequence,
          message: 'Checkout update could not be applied safely.',
        });
        return;
      }

      this.setStateVersion(payload.stateVersion);
      if (payload.csrfToken) {
        this.setCsrfToken(payload.csrfToken);
      }

      for (const replacement of replacements) {
        replacement.current.replaceWith(replacement.next);
      }

      for (const replacement of replacements) {
        this.dispatch('jzopc:section:updated', {
          section: replacement.name,
          root: replacement.next,
          stateVersion: this.stateVersion,
          sequence,
        });
      }

      if (!payload.success) {
        this.dispatch('jzopc:checkout:validation-failed', {
          errors: payload.errors,
          stateVersion: this.stateVersion,
          sequence,
        });
        return;
      }

      this.dispatch('jzopc:checkout:updated', {
        sections: replacements.map((replacement) => replacement.name),
        stateVersion: this.stateVersion,
        sequence,
      });

      if (payload.redirect) {
        window.location.assign(payload.redirect);
      }
    }

    prepareSectionReplacements(sections) {
      const replacements = [];

      for (const [name, html] of Object.entries(sections)) {
        if (!/^[a-z][a-z0-9_-]*$/.test(name)) {
          return null;
        }

        const current = this.root.querySelector('[data-jzopc-section="' + CSS.escape(name) + '"]');
        if (!current) {
          return null;
        }

        const template = document.createElement('template');
        template.innerHTML = html.trim();
        const next = template.content.firstElementChild;
        if (!next || template.content.children.length !== 1 || !next.matches(SECTION_SELECTOR)) {
          return null;
        }
        if (next.getAttribute('data-jzopc-section') !== name) {
          return null;
        }

        replacements.push({ name, current, next });
      }

      return replacements;
    }

    setStateVersion(stateVersion) {
      this.stateVersion = stateVersion;
      this.root.dataset.jzopcStateVersion = stateVersion;
    }

    setCsrfToken(csrfToken) {
      this.csrfToken = csrfToken;
      this.root.dataset.jzopcCsrfToken = csrfToken;
    }

    dispatch(name, detail) {
      this.root.dispatchEvent(new CustomEvent(name, {
        bubbles: true,
        detail,
      }));
    }

    static mount(root) {
      const scope = root || document;
      const checkoutRoot = scope.matches && scope.matches(ROOT_SELECTOR)
        ? scope
        : scope.querySelector(ROOT_SELECTOR);

      if (!checkoutRoot) {
        return null;
      }

      const existing = instances.get(checkoutRoot);
      if (existing) {
        existing.destroy();
      }

      const client = new JzOpcMutationClient(checkoutRoot);
      if (!client.start()) {
        return null;
      }

      instances.set(checkoutRoot, client);
      return client;
    }
  }

  window.JzOpcMutationClient = JzOpcMutationClient;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      JzOpcMutationClient.mount(document);
    }, { once: true });
  } else {
    JzOpcMutationClient.mount(document);
  }
}());