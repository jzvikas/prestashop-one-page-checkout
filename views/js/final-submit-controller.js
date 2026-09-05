(function () {
  'use strict';

  const ROOT_SELECTOR = '[data-jzopc-checkout]';
  const FINAL_SUBMIT_SELECTOR = '[data-jzopc-final-submit]';
  const FINAL_STATUS_SELECTOR = '[data-jzopc-final-status]';
  const FINAL_LABEL_SELECTOR = '[data-jzopc-final-label]';
  const FINAL_LOADING_SELECTOR = '[data-jzopc-final-loading]';
  const FINAL_MESSAGE_SELECTOR = '[data-jzopc-final-message]';
  const SECTION_SELECTOR = '[data-jzopc-section]';
  const PAYMENT_OPTION_SELECTOR = '[data-jzopc-section="payment"] input[name="payment-option"]';
  const AGREEMENT_SELECTOR = '[data-jzopc-section="agreements"] input[name="agreements[]"]';
  const instances = new WeakMap();

  class JzOpcFinalSubmitController {
    constructor(root) {
      this.root = root;
      this.busy = false;
      this.abortController = null;
      this.lockedControls = [];
      this.paymentOptionId = null;
      this.paymentForm = null;
      this.messages = new Map();
      this.onClick = this.onClick.bind(this);
    }

    start() {
      if (!this.readBootstrap() || !this.readMessages()) {
        return false;
      }

      this.root.addEventListener('click', this.onClick);
      return true;
    }

    destroy() {
      if (this.abortController) {
        this.abortController.abort();
      }
      this.root.removeEventListener('click', this.onClick);
      this.restoreControls();
      instances.delete(this.root);
    }

    readBootstrap() {
      const cartId = this.root.dataset.jzopcCartId || '';
      const finalizationUrl = this.root.dataset.jzopcFinalizationUrl || '';
      if (!/^\d+$/.test(cartId) || Number(cartId) <= 0 || !finalizationUrl) {
        return false;
      }

      this.cartId = cartId;
      this.finalizationUrl = finalizationUrl;
      return true;
    }

    readMessages() {
      this.messages.clear();
      for (const element of this.root.querySelectorAll(FINAL_MESSAGE_SELECTOR)) {
        const key = element.getAttribute('data-jzopc-final-message') || '';
        const value = element.textContent ? element.textContent.trim() : '';
        if (key && value) {
          this.messages.set(key, value);
        }
      }

      return [
        'payment-required',
        'agreements-required',
        'binary-required',
        'payment-unavailable',
        'secure-attempt-failed',
        'session-changed',
        'submission-failed',
        'review-checkout',
        'handoff-failed',
        'payment-changed',
      ].every((key) => this.messages.has(key));
    }

    message(key) {
      return this.messages.get(key) || '';
    }

    onClick(event) {
      const target = event.target;
      if (!(target instanceof Element)) {
        return;
      }

      const button = target.closest(FINAL_SUBMIT_SELECTOR);
      if (!button || !this.root.contains(button)) {
        return;
      }

      event.preventDefault();
      if (this.busy) {
        return;
      }

      this.beginFinalization();
    }

    async beginFinalization() {
      const selected = this.root.querySelector(PAYMENT_OPTION_SELECTOR + ':checked');
      if (!(selected instanceof HTMLInputElement)) {
        const firstPayment = this.root.querySelector(PAYMENT_OPTION_SELECTOR);
        if (firstPayment instanceof HTMLInputElement) {
          firstPayment.reportValidity();
        }
        this.showStatus(this.message('payment-required'));
        return;
      }

      const missingAgreement = Array.from(this.root.querySelectorAll(AGREEMENT_SELECTOR))
        .find((input) => input instanceof HTMLInputElement && !input.checked);
      if (missingAgreement instanceof HTMLInputElement) {
        missingAgreement.reportValidity();
        this.showStatus(this.message('agreements-required'));
        return;
      }

      // Binary/self-submitting payment modules need a dedicated interception contract so their
      // own confirmation control cannot bypass server preflight. Until that contract exists,
      // fail closed rather than pretending the generic native-form handoff is compatible.
      if (selected.classList.contains('binary')) {
        this.showStatus(this.message('binary-required'));
        this.dispatch('jzopc:checkout:binary-payment-required', {
          paymentOptionId: selected.id,
        });
        return;
      }

      const paymentForm = this.findPaymentForm(selected.id);
      if (!(paymentForm instanceof HTMLFormElement)) {
        this.showStatus(this.message('payment-unavailable'));
        return;
      }

      const attemptId = this.createAttemptId();
      if (!attemptId) {
        this.showStatus(this.message('secure-attempt-failed'));
        return;
      }

      this.busy = true;
      this.paymentOptionId = selected.id;
      this.paymentForm = paymentForm;
      this.setBusyState(true, paymentForm);
      this.showStatus('');
      this.dispatch('jzopc:checkout:final-submit-started', {
        stateVersion: this.root.dataset.jzopcStateVersion || '',
      });

      await this.send(attemptId, false);
    }

    async send(attemptId, staleRetried) {
      const binding = this.currentBinding();
      if (!binding) {
        this.fail(this.message('session-changed'));
        return;
      }

      const body = new URLSearchParams();
      body.set('token', binding.csrfToken);
      body.set('cartId', binding.cartId);
      body.set('stateVersion', binding.stateVersion);
      body.set('submissionAttempt', attemptId);

      this.abortController = typeof AbortController === 'function' ? new AbortController() : null;

      try {
        const response = await fetch(this.finalizationUrl, {
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

        if (!this.isValidResponse(payload)) {
          throw new Error('Invalid checkout finalization response.');
        }

        const stale = !payload.success
          && response.status === 409
          && payload.retryable === true
          && payload.errors.some((error) => error && error.code === 'stale_state')
          && typeof payload.stateVersion === 'string'
          && payload.stateVersion;

        if (stale && !staleRetried) {
          this.setStateVersion(payload.stateVersion);
          await this.send(attemptId, true);
          return;
        }

        if (!this.applySections(payload.sections)) {
          throw new Error('Checkout finalization sections could not be applied safely.');
        }

        if (typeof payload.stateVersion === 'string' && payload.stateVersion) {
          this.setStateVersion(payload.stateVersion);
        }
        if (typeof payload.csrfToken === 'string' && payload.csrfToken) {
          this.root.dataset.jzopcCsrfToken = payload.csrfToken;
        }

        if (!payload.success) {
          const message = this.firstErrorMessage(payload.errors) || this.message('review-checkout');
          this.dispatch('jzopc:checkout:validation-failed', {
            errors: payload.errors,
            stateVersion: this.root.dataset.jzopcStateVersion || '',
          });
          this.fail(message);
          return;
        }

        if (payload.redirect) {
          window.location.assign(payload.redirect);
          return;
        }

        this.dispatch('jzopc:checkout:final-preflight-completed', {
          stateVersion: this.root.dataset.jzopcStateVersion || '',
          paymentOptionId: this.paymentOptionId,
        });
        this.handoffToNativePayment();
      } catch (error) {
        if (error && error.name === 'AbortError') {
          return;
        }

        this.dispatch('jzopc:checkout:error', {
          message: this.message('submission-failed'),
        });
        this.fail(this.message('submission-failed'));
      }
    }

    currentBinding() {
      const cartId = this.root.dataset.jzopcCartId || '';
      const stateVersion = this.root.dataset.jzopcStateVersion || '';
      const csrfToken = this.root.dataset.jzopcCsrfToken || '';
      if (!/^\d+$/.test(cartId) || Number(cartId) <= 0 || !stateVersion || !csrfToken) {
        return null;
      }

      return { cartId, stateVersion, csrfToken };
    }

    handoffToNativePayment() {
      let form = this.paymentForm;
      if (!(form instanceof HTMLFormElement) || !form.isConnected) {
        form = this.findPaymentForm(this.paymentOptionId || '');
      }

      if (!(form instanceof HTMLFormElement)) {
        this.fail(this.message('payment-changed'));
        return;
      }

      this.dispatch('jzopc:checkout:payment-handoff', {
        paymentOptionId: this.paymentOptionId,
      });

      try {
        // Match PrestaShop Core's checkout handoff: submit the payment module's own form/action.
        // Do not requestSubmit(), synthesize payment inputs or call PaymentModule::validateOrder().
        HTMLFormElement.prototype.submit.call(form);
      } catch (error) {
        this.dispatch('jzopc:checkout:error', {
          message: this.message('handoff-failed'),
        });
        this.fail(this.message('handoff-failed'));
      }
    }

    findPaymentForm(optionId) {
      if (!optionId) {
        return null;
      }

      const container = this.root.ownerDocument.getElementById('pay-with-' + optionId + '-form');
      if (!container || !this.root.contains(container)) {
        return null;
      }

      return container.querySelector('form');
    }

    createAttemptId() {
      const cryptoObject = window.crypto;
      if (!cryptoObject || typeof cryptoObject.getRandomValues !== 'function') {
        return null;
      }

      const bytes = new Uint8Array(16);
      cryptoObject.getRandomValues(bytes);
      return Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
    }

    setBusyState(busy, paymentForm) {
      const button = this.root.querySelector(FINAL_SUBMIT_SELECTOR);
      if (button instanceof HTMLButtonElement) {
        button.disabled = busy;
        button.setAttribute('aria-busy', busy ? 'true' : 'false');
      }

      const label = this.root.querySelector(FINAL_LABEL_SELECTOR);
      const loading = this.root.querySelector(FINAL_LOADING_SELECTOR);
      if (label instanceof HTMLElement) {
        label.hidden = busy;
      }
      if (loading instanceof HTMLElement) {
        loading.hidden = !busy;
      }

      this.root.toggleAttribute('data-jzopc-finalizing', busy);
      this.root.setAttribute('aria-busy', busy ? 'true' : 'false');

      if (busy) {
        this.lockControls(paymentForm);
      } else {
        this.restoreControls();
      }
    }

    lockControls(paymentForm) {
      this.lockedControls = [];
      for (const control of this.root.querySelectorAll('button, input, select, textarea')) {
        if (!(control instanceof HTMLButtonElement
          || control instanceof HTMLInputElement
          || control instanceof HTMLSelectElement
          || control instanceof HTMLTextAreaElement)) {
          continue;
        }
        if (paymentForm instanceof HTMLFormElement && paymentForm.contains(control)) {
          continue;
        }

        this.lockedControls.push({ control, disabled: control.disabled });
        control.disabled = true;
      }
    }

    restoreControls() {
      for (const entry of this.lockedControls) {
        if (entry.control && entry.control.isConnected) {
          entry.control.disabled = entry.disabled;
        }
      }
      this.lockedControls = [];
    }

    fail(message) {
      this.busy = false;
      this.setBusyState(false, null);
      this.paymentOptionId = null;
      this.paymentForm = null;
      this.showStatus(message);
    }

    showStatus(message) {
      const status = this.root.querySelector(FINAL_STATUS_SELECTOR);
      if (status instanceof HTMLElement) {
        status.textContent = message;
      }
    }

    isValidResponse(payload) {
      if (!payload || typeof payload !== 'object' || typeof payload.success !== 'boolean') {
        return false;
      }
      if (payload.success && (typeof payload.stateVersion !== 'string' || !payload.stateVersion)) {
        return false;
      }
      if (payload.stateVersion !== null && typeof payload.stateVersion !== 'string') {
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

    applySections(sections) {
      const replacements = [];
      for (const [name, html] of Object.entries(sections)) {
        if (!/^[a-z][a-z0-9_-]*$/.test(name)) {
          return false;
        }

        const current = this.root.querySelector('[data-jzopc-section="' + CSS.escape(name) + '"]');
        if (!current) {
          return false;
        }

        const template = document.createElement('template');
        template.innerHTML = html.trim();
        const next = template.content.firstElementChild;
        if (!next || template.content.children.length !== 1 || !next.matches(SECTION_SELECTOR)) {
          return false;
        }
        if (next.getAttribute('data-jzopc-section') !== name) {
          return false;
        }

        replacements.push({ name, current, next });
      }

      for (const replacement of replacements) {
        replacement.current.replaceWith(replacement.next);
      }
      for (const replacement of replacements) {
        this.dispatch('jzopc:section:updated', {
          section: replacement.name,
          root: replacement.next,
          stateVersion: this.root.dataset.jzopcStateVersion || '',
        });
      }

      return true;
    }

    setStateVersion(stateVersion) {
      this.root.dataset.jzopcStateVersion = stateVersion;
    }

    firstErrorMessage(errors) {
      for (const error of errors) {
        if (error && typeof error.message === 'string' && error.message) {
          return error.message;
        }
      }

      return '';
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

      const controller = new JzOpcFinalSubmitController(checkoutRoot);
      if (!controller.start()) {
        return null;
      }

      instances.set(checkoutRoot, controller);
      return controller;
    }
  }

  window.JzOpcFinalSubmitController = JzOpcFinalSubmitController;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      JzOpcFinalSubmitController.mount(document);
    }, { once: true });
  } else {
    JzOpcFinalSubmitController.mount(document);
  }
}());
