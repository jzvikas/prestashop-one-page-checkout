(function () {
  'use strict';

  const ROOT_SELECTOR = '[data-jzopc-checkout]';
  const SECTION_SELECTOR = '[data-jzopc-section]';
  const PAYMENT_OPTION_SELECTOR = '[data-jzopc-section="payment"] input[name="payment-option"]';
  const AGREEMENT_SELECTOR = '[data-jzopc-section="agreements"] input[name="agreements[]"]';
  const FINAL_SUBMIT_SELECTOR = '[data-jzopc-final-submit]';
  const FINAL_STATUS_SELECTOR = '[data-jzopc-final-status]';
  const ACTIVATION_SELECTOR = 'button, input[type="submit"], input[type="button"], a[href]';
  const instances = new WeakMap();

  class JzOpcBinaryPaymentController {
    constructor(root) {
      this.root = root;
      this.busy = false;
      this.replaying = false;
      this.abortController = null;
      this.lockedControls = [];
      this.managedDisabledControls = new Map();
      this.onCaptureClick = this.onCaptureClick.bind(this);
      this.onCaptureSubmit = this.onCaptureSubmit.bind(this);
      this.onStateChanged = this.onStateChanged.bind(this);
    }

    start() {
      if (!this.readBootstrap()) {
        return false;
      }

      this.root.addEventListener('click', this.onCaptureClick, true);
      this.root.addEventListener('submit', this.onCaptureSubmit, true);
      this.root.addEventListener('change', this.onStateChanged);
      this.root.addEventListener('jzopc:section:updated', this.onStateChanged);
      this.syncBinaryUi();

      return true;
    }

    destroy() {
      if (this.abortController) {
        this.abortController.abort();
      }

      this.root.removeEventListener('click', this.onCaptureClick, true);
      this.root.removeEventListener('submit', this.onCaptureSubmit, true);
      this.root.removeEventListener('change', this.onStateChanged);
      this.root.removeEventListener('jzopc:section:updated', this.onStateChanged);
      this.restoreControls();
      this.restoreManagedBinaryControls();
      instances.delete(this.root);
    }

    readBootstrap() {
      const cartId = this.root.dataset.jzopcCartId || '';
      const finalizationUrl = this.root.dataset.jzopcFinalizationUrl || '';
      if (!/^[1-9]\d*$/.test(cartId) || !finalizationUrl) {
        return false;
      }

      this.finalizationUrl = finalizationUrl;
      return true;
    }

    onStateChanged() {
      this.syncBinaryUi();
    }

    syncBinaryUi() {
      this.restoreStaleManagedControls();

      const selected = this.selectedBinaryOption();
      const finalSubmit = this.root.querySelector(FINAL_SUBMIT_SELECTOR);
      if (finalSubmit instanceof HTMLElement) {
        finalSubmit.hidden = selected instanceof HTMLInputElement;
      }

      if (!(selected instanceof HTMLInputElement)) {
        this.restoreManagedBinaryControls();
        return;
      }

      const container = this.binaryContainerFor(selected);
      if (!(container instanceof HTMLElement)) {
        this.showStatus(this.message('binary-required'));
        return;
      }

      const accepted = this.allAgreementsAccepted();
      for (const control of container.querySelectorAll('button, input')) {
        if (!(control instanceof HTMLButtonElement || control instanceof HTMLInputElement)) {
          continue;
        }

        if (!accepted) {
          if (!this.managedDisabledControls.has(control)) {
            this.managedDisabledControls.set(control, control.disabled);
          }
          control.disabled = true;
        } else if (this.managedDisabledControls.has(control)) {
          control.disabled = this.managedDisabledControls.get(control) === true;
          this.managedDisabledControls.delete(control);
        }
      }
    }

    restoreStaleManagedControls() {
      const selected = this.selectedBinaryOption();
      const currentContainer = selected instanceof HTMLInputElement ? this.binaryContainerFor(selected) : null;

      for (const [control, disabled] of this.managedDisabledControls.entries()) {
        if (!control.isConnected || !this.root.contains(control)) {
          this.managedDisabledControls.delete(control);
          continue;
        }

        if (!(currentContainer instanceof HTMLElement) || !currentContainer.contains(control)) {
          control.disabled = disabled;
          this.managedDisabledControls.delete(control);
        }
      }
    }

    restoreManagedBinaryControls() {
      for (const [control, disabled] of this.managedDisabledControls.entries()) {
        if (control && control.isConnected) {
          control.disabled = disabled;
        }
      }
      this.managedDisabledControls.clear();
    }

    onCaptureClick(event) {
      if (this.replaying) {
        return;
      }

      const target = event.target;
      if (!(target instanceof Element)) {
        return;
      }

      const selected = this.selectedBinaryOption();
      if (!(selected instanceof HTMLInputElement)) {
        return;
      }

      const container = this.binaryContainerFor(selected);
      if (!(container instanceof HTMLElement)) {
        return;
      }

      const activation = target.closest(ACTIVATION_SELECTOR);
      if (!activation || !container.contains(activation)) {
        return;
      }

      event.preventDefault();
      event.stopImmediatePropagation();
      if (this.busy || !this.ensureAgreementsAccepted()) {
        return;
      }

      this.begin({ type: 'click', target: activation }, selected, container);
    }

    onCaptureSubmit(event) {
      if (this.replaying) {
        return;
      }

      const form = event.target;
      if (!(form instanceof HTMLFormElement)) {
        return;
      }

      const selected = this.selectedBinaryOption();
      if (!(selected instanceof HTMLInputElement)) {
        return;
      }

      const container = this.binaryContainerFor(selected);
      if (!(container instanceof HTMLElement) || !container.contains(form)) {
        return;
      }

      event.preventDefault();
      event.stopImmediatePropagation();
      if (this.busy || !this.ensureAgreementsAccepted()) {
        return;
      }

      this.begin({ type: 'submit', target: form }, selected, container);
    }

    ensureAgreementsAccepted() {
      const missing = this.firstMissingAgreement();
      if (!(missing instanceof HTMLInputElement)) {
        return true;
      }

      missing.reportValidity();
      this.showStatus(this.message('agreements-required'));
      return false;
    }

    async begin(activation, selected, binaryContainer) {
      const attemptId = this.createAttemptId();
      if (!attemptId) {
        this.showStatus(this.message('secure-attempt-failed'));
        return;
      }

      this.busy = true;
      this.setBusyState(true, binaryContainer);
      this.showStatus('');
      this.dispatch('jzopc:checkout:binary-preflight-started', { paymentOptionId: selected.id });
      let handoffStarted = false;

      try {
        const result = await this.request(attemptId, false);
        if (!result) {
          await this.bestEffortRelease(attemptId);
          this.fail(this.message('submission-failed'));
          return;
        }

        const { payload } = result;
        if (typeof payload.stateVersion === 'string' && payload.stateVersion) {
          this.root.dataset.jzopcStateVersion = payload.stateVersion;
        }
        if (typeof payload.csrfToken === 'string' && payload.csrfToken) {
          this.root.dataset.jzopcCsrfToken = payload.csrfToken;
        }

        if (!payload.success) {
          if (!this.applySections(payload.sections)) {
            await this.bestEffortRelease(attemptId);
            this.fail(this.message('review-checkout'));
            return;
          }
          this.fail(this.firstErrorMessage(payload.errors) || this.message('review-checkout'));
          return;
        }

        // A successful finalization preflight is reservation-only and must not replace payment DOM.
        // Replacing third-party binary controls here would discard their bound runtime state/handlers.
        if (Object.keys(payload.sections).length !== 0) {
          await this.bestEffortRelease(attemptId);
          this.fail(this.message('review-checkout'));
          return;
        }

        const current = this.selectedBinaryOption();
        const currentContainer = current instanceof HTMLInputElement ? this.binaryContainerFor(current) : null;
        if (!(current instanceof HTMLInputElement)
          || current.id !== selected.id
          || !(currentContainer instanceof HTMLElement)
          || !this.activationStillValid(activation, currentContainer)) {
          await this.bestEffortRelease(attemptId);
          this.fail(this.message('payment-changed'));
          return;
        }

        this.dispatch('jzopc:checkout:binary-payment-handoff', { paymentOptionId: selected.id });
        this.replaying = true;
        try {
          if (activation.type === 'click' && activation.target instanceof HTMLElement) {
            handoffStarted = true;
            activation.target.click();
          } else if (activation.type === 'submit' && activation.target instanceof HTMLFormElement) {
            handoffStarted = true;
            this.submitForm(activation.target);
          } else {
            throw new Error('Binary payment activation is unavailable.');
          }
        } finally {
          this.replaying = false;
        }
      } catch (error) {
        if (error && error.name === 'AbortError') {
          return;
        }

        if (handoffStarted) {
          // Once the payment module's own click/submit path has started, a synchronous throw is
          // ambiguous: the handler may already have initiated remote/payment work. Preserve the
          // reservation and freeze checkout until Core cleanup or the bounded TTL recovery path.
          this.failClosedHandoff(this.message('handoff-failed'));
          return;
        }

        await this.bestEffortRelease(attemptId);
        this.fail(this.message('handoff-failed'));
      }
    }

    activationStillValid(activation, container) {
      return activation
        && activation.target instanceof Element
        && activation.target.isConnected
        && container.contains(activation.target);
    }

    submitForm(form) {
      if (typeof window.jQuery === 'function') {
        window.jQuery(form).trigger('submit');
        return;
      }
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
        return;
      }

      HTMLFormElement.prototype.submit.call(form);
    }

    selectedBinaryOption() {
      const selected = this.root.querySelector(PAYMENT_OPTION_SELECTOR + ':checked');
      return selected instanceof HTMLInputElement && selected.classList.contains('binary') ? selected : null;
    }

    binaryContainerFor(selected) {
      const moduleName = selected.dataset.moduleName || '';
      if (!/^[A-Za-z0-9_-]+$/.test(moduleName)) {
        return null;
      }

      const paymentSection = selected.closest('[data-jzopc-section="payment"]');
      if (!(paymentSection instanceof HTMLElement)) {
        return null;
      }

      const nativeContainer = paymentSection.querySelector('.js-payment-' + CSS.escape(moduleName));
      if (nativeContainer instanceof HTMLElement) {
        return nativeContainer;
      }

      const additional = this.root.ownerDocument.getElementById(selected.id + '-additional-information');
      if (additional instanceof HTMLElement
        && paymentSection.contains(additional)
        && additional.querySelector(ACTIVATION_SELECTOR + ', form')) {
        return additional;
      }

      return null;
    }

    allAgreementsAccepted() {
      return this.firstMissingAgreement() === null;
    }

    firstMissingAgreement() {
      return Array.from(this.root.querySelectorAll(AGREEMENT_SELECTOR))
        .find((input) => input instanceof HTMLInputElement && !input.checked) || null;
    }

    currentBinding() {
      const cartId = this.root.dataset.jzopcCartId || '';
      const stateVersion = this.root.dataset.jzopcStateVersion || '';
      const csrfToken = this.root.dataset.jzopcCsrfToken || '';
      if (!/^[1-9]\d*$/.test(cartId) || !stateVersion || !csrfToken) {
        return null;
      }

      return { cartId, stateVersion, csrfToken };
    }

    async request(attemptId, staleRetried) {
      const binding = this.currentBinding();
      if (!binding) {
        throw new Error('Checkout binding is unavailable.');
      }

      const body = new URLSearchParams();
      body.set('token', binding.csrfToken);
      body.set('cartId', binding.cartId);
      body.set('stateVersion', binding.stateVersion);
      body.set('submissionAttempt', attemptId);
      body.set('finalizationAction', 'begin');

      this.abortController = typeof AbortController === 'function' ? new AbortController() : null;
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
        throw new Error('Invalid binary-payment preflight response.');
      }

      const stale = !payload.success
        && response.status === 409
        && payload.retryable === true
        && payload.errors.some((error) => error && error.code === 'stale_state')
        && typeof payload.stateVersion === 'string'
        && payload.stateVersion;
      if (stale && !staleRetried) {
        this.root.dataset.jzopcStateVersion = payload.stateVersion;
        return this.request(attemptId, true);
      }

      return { response, payload };
    }

    async bestEffortRelease(attemptId) {
      const binding = this.currentBinding();
      if (!binding || !attemptId) {
        return;
      }

      const body = new URLSearchParams();
      body.set('token', binding.csrfToken);
      body.set('cartId', binding.cartId);
      body.set('stateVersion', binding.stateVersion);
      body.set('submissionAttempt', attemptId);
      body.set('finalizationAction', 'release');

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
        });
        const payload = await response.json();
        if (this.isValidResponse(payload) && typeof payload.stateVersion === 'string' && payload.stateVersion) {
          this.root.dataset.jzopcStateVersion = payload.stateVersion;
        }
      } catch (error) {
        // Reservation TTL is the last-resort recovery if the browser cannot release its own attempt.
      }
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

    setBusyState(busy, exemptContainer) {
      this.root.toggleAttribute('data-jzopc-finalizing', busy);
      this.root.setAttribute('aria-busy', busy ? 'true' : 'false');

      if (busy) {
        this.lockControls(exemptContainer);
      } else {
        this.restoreControls();
      }
    }

    lockControls(exemptContainer) {
      this.lockedControls = [];
      for (const control of this.root.querySelectorAll('button, input, select, textarea')) {
        if (!(control instanceof HTMLButtonElement
          || control instanceof HTMLInputElement
          || control instanceof HTMLSelectElement
          || control instanceof HTMLTextAreaElement)) {
          continue;
        }
        if (exemptContainer instanceof HTMLElement && exemptContainer.contains(control)) {
          continue;
        }

        this.lockedControls.push({ control, disabled: control.disabled });
        control.disabled = true;
      }
    }

    freezeAllControls() {
      for (const control of this.root.querySelectorAll('button, input, select, textarea')) {
        if (control instanceof HTMLButtonElement
          || control instanceof HTMLInputElement
          || control instanceof HTMLSelectElement
          || control instanceof HTMLTextAreaElement) {
          control.disabled = true;
        }
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

    failClosedHandoff(message) {
      this.busy = true;
      this.root.setAttribute('data-jzopc-handoff-uncertain', 'true');
      this.root.setAttribute('aria-busy', 'true');
      this.freezeAllControls();
      this.showStatus(message);
    }

    fail(message) {
      this.busy = false;
      this.setBusyState(false, null);
      this.showStatus(message);
      this.syncBinaryUi();
    }

    showStatus(message) {
      const status = this.root.querySelector(FINAL_STATUS_SELECTOR);
      if (status instanceof HTMLElement) {
        status.textContent = message;
      }
    }

    message(key) {
      const element = this.root.querySelector('[data-jzopc-final-message="' + key + '"]');
      return element && element.textContent ? element.textContent.trim() : '';
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
        if (!next || template.content.children.length !== 1 || !next.matches(SECTION_SELECTOR)
          || next.getAttribute('data-jzopc-section') !== name) {
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

    firstErrorMessage(errors) {
      for (const error of errors) {
        if (error && typeof error.message === 'string' && error.message) {
          return error.message;
        }
      }

      return '';
    }

    dispatch(name, detail) {
      this.root.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
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

      const controller = new JzOpcBinaryPaymentController(checkoutRoot);
      if (!controller.start()) {
        return null;
      }

      instances.set(checkoutRoot, controller);
      return controller;
    }
  }

  window.JzOpcBinaryPaymentController = JzOpcBinaryPaymentController;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      JzOpcBinaryPaymentController.mount(document);
    }, { once: true });
  } else {
    JzOpcBinaryPaymentController.mount(document);
  }
}());
