(function () {
  'use strict';

  const ROOT_SELECTOR = '[data-jzopc-checkout]';
  const SECTION_SELECTOR = '[data-jzopc-section]';
  const ADDRESS_SECTION_SELECTOR = '[data-jzopc-section="addresses"]';
  const ADDRESS_INPUT_SELECTOR = [
    ADDRESS_SECTION_SELECTOR + ' input[name="id_address_delivery"]',
    ADDRESS_SECTION_SELECTOR + ' input[name="id_address_invoice"]',
    ADDRESS_SECTION_SELECTOR + ' input[name="use_same_address"]',
  ].join(',');
  const AGREEMENT_SELECTOR = '[data-jzopc-section="agreements"] input[name="agreements[]"]';
  const instances = new WeakMap();

  class JzOpcMutationClient {
    constructor(root) {
      this.root = root;
      this.abortController = null;
      this.listenerAbortController = typeof AbortController === 'function' ? new AbortController() : null;
      this.latestSequence = 0;
      this.onPaymentSelected = this.onPaymentSelected.bind(this);
      this.onCheckoutInputChanged = this.onCheckoutInputChanged.bind(this);
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
      }

      instances.delete(this.root);
    }

    readBootstrap() {
      const cartId = this.root.dataset.jzopcCartId || '';
      const stateVersion = this.root.dataset.jzopcStateVersion || '';
      const csrfToken = this.root.dataset.jzopcCsrfToken || '';
      const addressUrl = this.root.dataset.jzopcAddressUrl || '';
      const paymentUrl = this.root.dataset.jzopcPaymentUrl || '';
      const agreementsUrl = this.root.dataset.jzopcAgreementsUrl || '';

      if (
        !/^\d+$/.test(cartId)
        || Number(cartId) <= 0
        || !stateVersion
        || !csrfToken
        || !addressUrl
        || !paymentUrl
        || !agreementsUrl
      ) {
        return false;
      }

      this.cartId = cartId;
      this.stateVersion = stateVersion;
      this.csrfToken = csrfToken;
      this.addressUrl = addressUrl;
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

    onCheckoutInputChanged(event) {
      const target = event.target;
      if (!(target instanceof HTMLInputElement)) {
        return;
      }

      if (target.matches(ADDRESS_INPUT_SELECTOR)) {
        this.onAddressChanged();
        return;
      }

      if (target.matches(AGREEMENT_SELECTOR)) {
        this.onAgreementChanged();
      }
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

      if (!sameAddressInput.checked) {
        if (!(invoiceInput instanceof HTMLInputElement) || !invoiceInput.value) {
          this.dispatch('jzopc:checkout:validation-failed', {
            errors: [{
              code: 'invoice_address_required',
              message: 'Please select an invoice address.',
              field: 'invoiceAddressId',
            }],
            stateVersion: this.stateVersion,
            sequence: this.latestSequence,
          });
          return;
        }
        payload.invoiceAddressId = invoiceInput.value;
      }

      this.dispatch('jzopc:address:selected', {
        deliveryAddressId: payload.deliveryAddressId || null,
        invoiceAddressId: payload.invoiceAddressId || null,
        useSameAddress: sameAddressInput.checked,
      });
      this.mutate(this.addressUrl, payload);
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
