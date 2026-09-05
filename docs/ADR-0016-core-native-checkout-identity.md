# ADR-0016: Core-native checkout identity and authentication

## Status

Accepted for implementation behind the existing checkout readiness gate. Executable verification is deferred while the repository GitHub Actions quota is exhausted.

## Context

The One Page Checkout shell previously rendered addresses, delivery, payment, agreements and summary but intentionally omitted identity. Anonymous carts therefore could not progress into the existing Core-backed address flows because address mutations require a cart-bound customer.

Identity is not only a form-rendering concern. PrestaShop customer creation and authentication own password policy, duplicate-email handling, guest-account rules, opt-in fields, module-provided customer fields, authentication hooks, customer-session/cookie state, cart/customer binding, cart-rule recalculation and optional cart restoration. Reimplementing any of those rules in the module would create correctness and security drift.

Core source for PrestaShop 9.0 and 9.1 confirms the stable legacy front-office stack used by checkout:

- `CustomerForm` + `CustomerFormatter` for guest/account capture and validation;
- `CustomerPersister` for guest/account creation, guest-to-customer conversion and customer/session/cart side effects;
- `CustomerLoginForm` + `CustomerLoginFormatter` for authentication;
- `actionSubmitAccountBefore` before customer creation;
- `Context::updateCustomer()` after successful authentication/persistence;
- `PS_GUEST_CHECKOUT_ENABLED` as the shop guest-checkout rule.

`CustomerPersister` in both checked 9.0 and 9.1 releases depends on `PrestaShop\PrestaShop\Core\Crypto\Hashing`. The legacy/common front container exposes the Core `hashing` alias, matching the constructor pattern used by `FrontController` itself.

Two transactional consequences need special handling in an AJAX one-page flow:

1. successful customer creation/login can change the front-office CSRF token because the Core customer/session identity changed;
2. `Context::updateCustomer()` may restore another non-ordered customer cart when `PS_CART_FOLLOWING` applies. The identity request holds the mutex for the cart that started the request, not for a replacement cart restored inside Core.

## Decision

### Core owns identity business rules

`CheckoutIdentityService` is a thin adapter over Core forms/persisters. It does not implement email validation, password hashing/policy, duplicate-account checks, guest persistence, login authentication or account hooks itself.

For an anonymous checkout it builds the same Core form stack as the front controller, using the active `Context`, translator, language, Smarty/theme and template URLs. The customer form follows `PS_GUEST_CHECKOUT_ENABLED`; the login form delegates authentication to Core. The form action is intentionally empty because submission is intercepted by the module-owned guarded AJAX endpoint rather than sent to a separate legacy page.

Before create/guest submission the service preserves Core's `actionSubmitAccountBefore` lifecycle. Both create and login delegate their actual validation and persistence/authentication to `CustomerForm::submit()` or `CustomerLoginForm::submit()`.

After success the service requires the active Context customer and active cart customer IDs to be the same positive ID. Logged-in or already guest-bound checkout state is rendered as an escaped identity summary and is not silently editable through the anonymous creation form.

### Trusted rendering boundary

The identity section renders the active theme's native Core customer/login forms. Those form fragments, including module-added customer fields, are treated as the same explicit trusted Core/theme/module HTML boundary already used for native address forms. Browser strings are never concatenated directly into raw identity HTML by module code. Bound customer name/email values in module-owned markup are escaped normally.

On Core validation failure the already-submitted Core form instances are rendered and reused. The module does not instantiate a second customer-form stack just to display errors, avoiding duplicate execution of form-related module hooks.

### Guarded mutation

The `identity` module front controller inherits the same POST-only activation boundary as every other checkout mutation. `CheckoutIdentityMutation` runs through `CheckoutMutationOrchestrator`, so identity submission occurs only after CSRF/cart/state validation and while the initiating cart mutex is held.

A normal successful identity transition clears persisted payment/agreement authority and refreshes identity, addresses, delivery when physical, payment, agreements and summary. Customer group, addresses, cart rules, totals and eligibility may all have changed.

Invalid Core forms are returned with the native rendered field errors. Password/customer payloads are never logged by module code or exposed through lifecycle-event details.

### CSRF rotation after authentication transition

The initial anonymous `Tools::getToken(false)` is not assumed to remain valid after Core changes customer/session identity. Only after the request has reached `CheckoutMutationExecutionStatus::Completed` does the identity controller generate a fresh Core front-office token. `CheckoutMutationResponseMapper` can attach that token to a completed response, and the browser replaces both its in-memory token and the trusted checkout-root data attribute before another mutation is sent.

Rejected requests, including invalid-CSRF requests, never receive replacement CSRF material. Other mutation controllers do not opt into token rotation.

### Core cart restoration is a reload boundary

If Core customer authentication changes `Context::cart->id` from the cart ID protected by the current mutex, `CheckoutIdentityMutation` deliberately returns a non-successful internal outcome with no rendered sections and does not persist module selection state for the replacement cart. This prevents module writes/rendering from pretending the replacement cart is protected by the old cart lock.

The identity front controller recognizes that completed guarded Core transition and returns a successful redirect-only transport response to the Core order page, together with the fresh state version and CSRF token. The browser performs a full reload. The next page load establishes the replacement cart as the new server-authoritative bootstrap and obtains its own mutation lock on subsequent writes.

The module does not disable or override `PS_CART_FOLLOWING` and does not copy or merge carts itself.

## Consequences

- guest checkout/account creation/login use Core validation, hashing, hooks and session/cart side effects rather than module-owned copies;
- Classic/Hummingbird/third-party theme customer form markup remains available through Core rendering;
- module-added customer fields continue to participate through the Core form stack;
- identity can now unlock the existing customer-owned address flows for anonymous checkout;
- payment/agreement authority cannot survive an identity transition without fresh validation;
- auth-driven CSRF rotation no longer strands the browser with an anonymous token;
- Core cart restoration is handled with a full authoritative reload instead of unsafe cross-cart AJAX continuation;
- the production takeover gate remains closed until the deferred installed-runtime/browser matrix and final-submit/idempotency work are complete.

## Verification contract

Tests are added/updated to require:

- Core `CustomerForm`, `CustomerFormatter`, `CustomerPersister`, `CustomerLoginForm` and `CustomerLoginFormatter` usage;
- `PS_GUEST_CHECKOUT_ENABLED` and `actionSubmitAccountBefore` preservation;
- no direct customer creation/password hashing in module identity code;
- guarded public identity mutation wiring and full downstream refresh dependencies;
- identity endpoint presence in trusted shell/bootstrap;
- delegated native form serialization with reserved cart/CSRF/state bindings;
- completed-response CSRF rotation without token disclosure on rejected requests;
- installed anonymous Smarty shell rendering of both Core identity forms;
- the cart-restoration reload boundary.

These checks are committed but are **not executed in this milestone** because the repository GitHub Actions free quota is exhausted. They must be executed after quota reset; no deferred check is considered passing.
