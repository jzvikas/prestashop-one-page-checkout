(function () {
  'use strict';

  const instances = new WeakMap();
  const PAYMENT_SELECTOR = '[data-jzopc-section="payment"]';
  const OPTION_SELECTOR = 'input[name="payment-option"]';

  class JzOpcPaymentController {
    constructor(section) {
      this.section = section;
      this.abortController = typeof AbortController === 'function' ? new AbortController() : null;
      this.onChange = this.onChange.bind(this);
    }

    start() {
      if (this.abortController) {
        this.section.addEventListener('change', this.onChange, { signal: this.abortController.signal });
      } else {
        this.section.addEventListener('change', this.onChange);
      }

      this.syncSelection(false);
    }

    destroy() {
      if (this.abortController) {
        this.abortController.abort();
      } else {
        this.section.removeEventListener('change', this.onChange);
      }

      instances.delete(this.section);
    }

    onChange(event) {
      const target = event.target;
      if (!(target instanceof HTMLInputElement) || !target.matches(OPTION_SELECTOR)) {
        return;
      }

      this.syncSelection(true);
    }

    syncSelection(emitSelectionEvent) {
      const options = Array.from(this.section.querySelectorAll(OPTION_SELECTOR));
      const selected = options.find((option) => option.checked) || null;

      for (const option of options) {
        const active = selected !== null && option === selected;
        this.toggleRelatedElement(option.id + '-additional-information', active);
        this.toggleRelatedElement('pay-with-' + option.id + '-form', active);
        option.setAttribute('aria-expanded', active ? 'true' : 'false');
      }

      if (emitSelectionEvent && selected) {
        const detail = {
          optionId: selected.id,
          moduleName: selected.dataset.moduleName || '',
          binary: selected.classList.contains('binary'),
        };

        this.section.dispatchEvent(new CustomEvent('jzopc:payment:selected', {
          bubbles: true,
          detail,
        }));
      }
    }

    toggleRelatedElement(id, visible) {
      const element = this.section.ownerDocument.getElementById(id);
      if (!element || !this.section.contains(element)) {
        return;
      }

      element.classList.toggle('ps-hidden', !visible);
      element.hidden = !visible;
      element.setAttribute('aria-hidden', visible ? 'false' : 'true');
    }

    getSelectedOption() {
      return this.section.querySelector(OPTION_SELECTOR + ':checked');
    }

    getSelectedPaymentForm() {
      const selected = this.getSelectedOption();
      if (!selected) {
        return null;
      }

      const container = this.section.ownerDocument.getElementById('pay-with-' + selected.id + '-form');
      if (!container || !this.section.contains(container)) {
        return null;
      }

      return container.querySelector('form');
    }

    static mount(root) {
      const scope = root || document;
      const section = scope.matches && scope.matches(PAYMENT_SELECTOR)
        ? scope
        : scope.querySelector(PAYMENT_SELECTOR);

      if (!section) {
        return null;
      }

      const existing = instances.get(section);
      if (existing) {
        existing.destroy();
      }

      const controller = new JzOpcPaymentController(section);
      instances.set(section, controller);
      controller.start();

      section.dispatchEvent(new CustomEvent('jzopc:payment:initialized', {
        bubbles: true,
        detail: { controller },
      }));

      return controller;
    }
  }

  window.JzOpcPaymentController = JzOpcPaymentController;

  document.addEventListener('jzopc:section:updated', function (event) {
    if (!event.detail || event.detail.section !== 'payment') {
      return;
    }

    JzOpcPaymentController.mount(event.detail.root || document);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      JzOpcPaymentController.mount(document);
    }, { once: true });
  } else {
    JzOpcPaymentController.mount(document);
  }
}());
