(function () {
  'use strict';

  const ROOT_SELECTOR = '[data-jzopc-checkout]';
  const STATUS_SELECTOR = '[data-jzopc-final-status]';
  const MESSAGE_SELECTOR = '[data-jzopc-final-message="handoff-ambiguous"]';
  const INTERACTIVE_SELECTOR = 'button, input, select, textarea';
  const NON_FORM_ACTIVATION_SELECTOR = 'a[href], [role="button"]';
  const FINALIZATION_IN_PROGRESS_CODE = 'finalization_in_progress';

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

    for (const activation of root.querySelectorAll(NON_FORM_ACTIVATION_SELECTOR)) {
      if (activation instanceof HTMLElement) {
        activation.setAttribute('aria-disabled', 'true');
        activation.setAttribute('tabindex', '-1');
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

  function rootForEvent(event) {
    const target = event.target;
    if (!(target instanceof Element)) {
      return null;
    }

    const root = target.closest(ROOT_SELECTOR);
    return root instanceof HTMLElement ? root : null;
  }

  function suppressLockedActivation(event) {
    const root = rootForEvent(event);
    if (!(root instanceof HTMLElement) || !root.hasAttribute('data-jzopc-payment-handoff-ambiguous')) {
      return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
  }

  function hasErrorCode(event, code) {
    const detail = event.detail || {};
    return Array.isArray(detail.errors)
      && detail.errors.some((error) => error && error.code === code);
  }

  function scheduleAmbiguousLock(root) {
    // Submit/mutation controllers finish synchronous failure cleanup after publishing lifecycle
    // events. Lock in the next microtask so that cleanup cannot re-enable controls afterwards.
    Promise.resolve().then(function () {
      lockAmbiguousCheckout(root);
    });
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

  // Capture before checkout/payment-module handlers. Disabled native controls cover normal form UI,
  // while these listeners also stop link-style binary activators and form submits inside a locked
  // reservation surface from reaching module handlers or browser default navigation.
  document.addEventListener('click', suppressLockedActivation, true);
  document.addEventListener('submit', suppressLockedActivation, true);

  document.addEventListener('jzopc:checkout:payment-handoff-ambiguous', function (event) {
    const root = rootForEvent(event);
    if (!(root instanceof HTMLElement)) {
      return;
    }

    scheduleAmbiguousLock(root);
  });

  document.addEventListener('jzopc:checkout:validation-failed', function (event) {
    if (!hasErrorCode(event, FINALIZATION_IN_PROGRESS_CODE)) {
      return;
    }

    const root = rootForEvent(event);
    if (!(root instanceof HTMLElement)) {
      return;
    }

    // The exact machine error came from the guarded server reservation boundary. Remember only
    // the boolean fact locally so remounts in this page cannot present an unlocked retry surface.
    root.dataset.jzopcFinalizationReserved = '1';
    scheduleAmbiguousLock(root);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      lockServerReservedCheckout(document);
    }, { once: true });
  } else {
    lockServerReservedCheckout(document);
  }
}());
