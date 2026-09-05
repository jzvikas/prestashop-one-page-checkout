(function () {
  'use strict';

  const ROOT_SELECTOR = '[data-jzopc-checkout]';
  const STATUS_SELECTOR = '[data-jzopc-final-status]';
  const MESSAGE_SELECTOR = '[data-jzopc-final-message="handoff-ambiguous"]';
  const INTERACTIVE_SELECTOR = 'button, input, select, textarea';

  function lockAmbiguousCheckout(root) {
    if (!(root instanceof HTMLElement) || root.hasAttribute('data-jzopc-payment-handoff-ambiguous')) {
      return;
    }

    root.setAttribute('data-jzopc-payment-handoff-ambiguous', 'true');
    root.setAttribute('aria-busy', 'true');

    for (const control of root.querySelectorAll(INTERACTIVE_SELECTOR)) {
      if (control instanceof HTMLButtonElement
        || control instanceof HTMLInputElement
        || control instanceof HTMLSelectElement
        || control instanceof HTMLTextAreaElement) {
        control.disabled = true;
      }
    }

    const status = root.querySelector(STATUS_SELECTOR);
    const message = root.querySelector(MESSAGE_SELECTOR);
    if (status instanceof HTMLElement && message instanceof HTMLElement) {
      status.textContent = message.textContent ? message.textContent.trim() : '';
      status.setAttribute('role', 'alert');
      status.setAttribute('aria-live', 'assertive');
    }

    root.dispatchEvent(new CustomEvent('jzopc:checkout:payment-handoff-locked', {
      bubbles: true,
      detail: {},
    }));
  }

  function lockServerReservedCheckout(scope) {
    const root = scope instanceof HTMLElement && scope.matches(ROOT_SELECTOR)
      ? scope
      : scope.querySelector(ROOT_SELECTOR);
    if (!(root instanceof HTMLElement) || root.dataset.jzopcFinalizationReserved !== '1') {
      return;
    }

    lockAmbiguousCheckout(root);
  }

  document.addEventListener('jzopc:checkout:payment-handoff-ambiguous', function (event) {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }

    const root = target.closest(ROOT_SELECTOR);
    if (!(root instanceof HTMLElement)) {
      return;
    }

    // Existing submit controllers finish their synchronous error cleanup immediately after emitting
    // the ambiguity event. Lock in the next microtask so their normal failure cleanup cannot
    // accidentally re-enable checkout controls while the server reservation is still authoritative.
    Promise.resolve().then(function () {
      lockAmbiguousCheckout(root);
    });
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      lockServerReservedCheckout(document);
    }, { once: true });
  } else {
    lockServerReservedCheckout(document);
  }
}());
