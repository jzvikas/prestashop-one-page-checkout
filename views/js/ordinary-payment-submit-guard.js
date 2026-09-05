(function () {
  'use strict';

  const ROOT_SELECTOR = '[data-jzopc-checkout]';
  const PAYMENT_OPTION_SELECTOR = '[data-jzopc-section="payment"] input[name="payment-option"]';
  const PAYMENT_FORM_PREFIX = 'pay-with-';
  const PAYMENT_FORM_SUFFIX = '-form';
  const instances = new WeakMap();

  class JzOpcOrdinaryPaymentSubmitGuard {
    constructor(root) {
      this.root = root;
      this.authorizedForm = null;
      this.authorizedPaymentOptionId = '';
      this.onSubmit = this.onSubmit.bind(this);
      this.onPaymentHandoff = this.onPaymentHandoff.bind(this);
      this.onCheckoutStateChanged = this.onCheckoutStateChanged.bind(this);
    }

    start() {
      this.root.addEventListener('submit', this.onSubmit, true);
      this.root.addEventListener('jzopc:checkout:payment-handoff', this.onPaymentHandoff);
      this.root.addEventListener('change', this.onCheckoutStateChanged);
      this.root.addEventListener('jzopc:section:updated', this.onCheckoutStateChanged);
      return true;
    }

    destroy() {
      this.root.removeEventListener('submit', this.onSubmit, true);
      this.root.removeEventListener('jzopc:checkout:payment-handoff', this.onPaymentHandoff);
      this.root.removeEventListener('change', this.onCheckoutStateChanged);
      this.root.removeEventListener('jzopc:section:updated', this.onCheckoutStateChanged);
      this.clearAuthorization();
      instances.delete(this.root);
    }

    onSubmit(event) {
      const form = event.target;
      if (!(form instanceof HTMLFormElement) || !this.root.contains(form)) {
        return;
      }

      const selected = this.selectedOrdinaryOption();
      if (!(selected instanceof HTMLInputElement)) {
        return;
      }

      const selectedForm = this.paymentFormFor(selected.id);
      if (!(selectedForm instanceof HTMLFormElement) || form !== selectedForm) {
        return;
      }

      if (this.isAuthorized(form, selected.id)) {
        // Authorization is deliberately one observable submit only. A payment handler that keeps
        // the page alive cannot turn the same reservation into an unlimited direct-submit window.
        this.clearAuthorization();
        return;
      }

      // A module-provided ordinary payment form must not be directly submitted before OPC has
      // completed final preflight and reserved this cart/attempt. Keep the form fields interactive
      // for embedded/tokenization integrations, but stop the observable native submit lifecycle.
      event.preventDefault();
      event.stopImmediatePropagation();

      this.root.dispatchEvent(new CustomEvent('jzopc:checkout:payment-submit-blocked', {
        bubbles: true,
        detail: { paymentOptionId: selected.id },
      }));

      const finalButton = this.root.querySelector('[data-jzopc-final-submit]');
      if (finalButton instanceof HTMLButtonElement && !finalButton.disabled) {
        finalButton.focus({ preventScroll: true });
      }
    }

    onPaymentHandoff(event) {
      const detail = event && event.detail && typeof event.detail === 'object' ? event.detail : null;
      const paymentOptionId = detail && typeof detail.paymentOptionId === 'string'
        ? detail.paymentOptionId
        : '';
      const selected = this.selectedOrdinaryOption();

      if (!(selected instanceof HTMLInputElement) || selected.id !== paymentOptionId) {
        this.clearAuthorization();
        return;
      }

      const form = this.paymentFormFor(paymentOptionId);
      if (!(form instanceof HTMLFormElement)) {
        this.clearAuthorization();
        return;
      }

      // The final-submit controller dispatches this synchronously only after the server reservation
      // succeeds and immediately before it invokes the module-owned submit lifecycle.
      this.authorizedForm = form;
      this.authorizedPaymentOptionId = paymentOptionId;

      // jQuery may complete its synthetic submit path without producing a native submit event that
      // reaches this capture listener. Revoke the authorization after the current synchronous handoff
      // stack either way; requestSubmit/native submit events run before this microtask.
      Promise.resolve().then(() => {
        if (this.authorizedForm === form && this.authorizedPaymentOptionId === paymentOptionId) {
          this.clearAuthorization();
        }
      });
    }

    onCheckoutStateChanged(event) {
      if (event && event.type === 'change') {
        const target = event.target;
        if (!(target instanceof HTMLInputElement) || !target.matches(PAYMENT_OPTION_SELECTOR)) {
          return;
        }
      }

      this.clearAuthorization();
    }

    selectedOrdinaryOption() {
      const selected = this.root.querySelector(PAYMENT_OPTION_SELECTOR + ':checked');
      if (!(selected instanceof HTMLInputElement) || selected.classList.contains('binary')) {
        return null;
      }

      return selected;
    }

    paymentFormFor(optionId) {
      if (!optionId) {
        return null;
      }

      const container = this.root.ownerDocument.getElementById(
        PAYMENT_FORM_PREFIX + optionId + PAYMENT_FORM_SUFFIX,
      );
      if (!(container instanceof HTMLElement) || !this.root.contains(container)) {
        return null;
      }

      return container.querySelector('form');
    }

    isAuthorized(form, paymentOptionId) {
      return this.authorizedForm === form
        && this.authorizedPaymentOptionId === paymentOptionId
        && form.isConnected;
    }

    clearAuthorization() {
      this.authorizedForm = null;
      this.authorizedPaymentOptionId = '';
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

      const guard = new JzOpcOrdinaryPaymentSubmitGuard(checkoutRoot);
      instances.set(checkoutRoot, guard);
      guard.start();
      return guard;
    }
  }

  window.JzOpcOrdinaryPaymentSubmitGuard = JzOpcOrdinaryPaymentSubmitGuard;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      JzOpcOrdinaryPaymentSubmitGuard.mount(document);
    }, { once: true });
  } else {
    JzOpcOrdinaryPaymentSubmitGuard.mount(document);
  }
}());
