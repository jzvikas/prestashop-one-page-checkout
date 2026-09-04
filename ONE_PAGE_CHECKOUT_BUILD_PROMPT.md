# Senior implementation prompt — PrestaShop 9 One Page Checkout

## Role

Act as a **principal/senior PrestaShop 9 module engineer**. You own the implementation end to end: architecture, code quality, checkout correctness, compatibility, security, performance, UX, automated tests, documentation, CI, and Git history.

Do not produce a toy demo, proof of concept, pseudo-code-only solution, or a theme-specific hack. Build a module that can become a **production-grade commercial One Page Checkout module**.

Repository:

- `https://github.com/jzvikas/prestashop-one-page-checkout`
- All source code, tests, configuration, documentation, CI and fixes must be committed to this Git repository.
- Do not keep important changes only in chat output, `/tmp`, local patches, or untracked files.
- Before changing code, inspect the current repository state and existing commits.
- After every logical milestone, run the relevant quality gates and commit the working state.
- Never commit a knowingly broken build.

---

# 1. Product goal

Create a **fast, reliable, modern, responsive One Page Checkout module for PrestaShop 9**.

The checkout must combine the normal checkout flow into one coherent page while preserving PrestaShop business rules and compatibility with third-party modules.

The page must make the following areas easy to understand and complete:

1. customer / account information;
2. delivery address;
3. invoice address when different;
4. carrier / delivery method;
5. payment method;
6. cart and totals summary;
7. required legal agreements / terms;
8. final order submission;
9. clear validation and error feedback.

The UX must feel like a modern e-commerce checkout, not like several old PrestaShop steps visually pasted onto one page.

Primary priorities, in this order:

1. **checkout correctness and order safety**;
2. **payment and carrier compatibility**;
3. **excellent user experience**;
4. **performance**;
5. **maintainable senior-level architecture**;
6. **theme compatibility**;
7. **automated testability**.

---

# 2. Target platform and runtime

## Required

- PrestaShop: **9.x**.
- PHP: **8.4 minimum**.
- Use PHP 8.4 language features where they improve correctness/readability, but do not introduce unnecessary cleverness.
- Follow the actual PHP support window of the targeted PrestaShop 9 release in Composer constraints.
- MySQL/MariaDB compatibility must follow PrestaShop 9 supported environments.
- Module must support multistore correctly unless a feature is explicitly global by design.
- Module must support multilingual shops.
- Module must not assume a specific currency, country, tax mode, carrier, payment module, language, theme, or shop ID.

## Important PrestaShop 9.2 compatibility rule

PrestaShop 9.2 introduced the native `ps_onepagecheckout` module and the `actionCheckoutBuildProcess` / `CheckoutProcessProviderInterface` extension mechanism.

Therefore:

- first detect and document the exact checkout architecture available in each supported PrestaShop 9.x version;
- on PrestaShop 9.2+, prefer the official checkout provider extension point where appropriate;
- never register an incompatible provider blindly;
- do not allow two active One Page Checkout providers to fight for the same checkout;
- detect the native `ps_onepagecheckout` module and provide a clear compatibility/conflict strategy;
- if this module becomes the active checkout provider, disabling/uninstalling it must restore the native PrestaShop checkout cleanly;
- PrestaShop 9.0/9.1 compatibility must use the safest supported approach available for those versions and must not rely on classes/hooks that only exist in 9.2;
- any version-specific class reference must be guarded so the module can still load on supported versions where that class does not exist.

Do **not** solve version differences with fragile blanket overrides if hooks/services/providers can solve them cleanly.

---

# 3. Discovery phase — mandatory before implementation

Before writing the actual checkout implementation:

1. inspect the repository;
2. inspect the current PrestaShop 9 checkout implementation and official developer documentation;
3. identify all supported checkout extension points needed for:
   - customer identity;
   - addresses;
   - delivery options;
   - payment options;
   - conditions/terms;
   - cart summary;
   - order submission;
   - AJAX state refresh;
4. identify version differences between relevant PrestaShop 9.x releases;
5. inspect how payment modules provide payment options and additional information;
6. inspect how carrier modules modify or augment checkout;
7. inspect checkout-related hooks that third-party modules expect;
8. inspect how the Classic and Hummingbird themes structure checkout markup and events;
9. write a short architecture decision record before large implementation work begins.

Do not guess core behavior when it can be verified in PrestaShop source.

If official documentation and Core behavior differ, Core behavior for the targeted release is authoritative; document the discrepancy.

---

# 4. Architecture requirements

Build the module as a maintainable application, not one giant module class.

The main module file must remain a thin integration/bootstrap layer.

Use clear boundaries such as:

```text
prestashop-one-page-checkout/
├── composer.json
├── config/
│   ├── services.yml
│   └── routes.yml                 # only if actually needed
├── src/
│   ├── Checkout/
│   ├── Controller/
│   ├── Domain/
│   ├── Form/
│   ├── Infrastructure/
│   ├── Integration/
│   ├── Provider/
│   ├── Security/
│   └── Service/
├── views/
│   ├── css/
│   ├── js/
│   └── templates/
├── tests/
│   ├── Unit/
│   ├── Integration/
│   └── Functional/
├── docs/
├── .github/workflows/
└── <module-main-file>.php
```

The exact structure may evolve if a better design is justified, but preserve separation of concerns.

## Code principles

- `declare(strict_types=1);` for modern PHP source files where compatible with PrestaShop module conventions.
- PSR-4 autoloading via Composer.
- Constructor dependency injection for services.
- Prefer small, focused services and immutable value objects where useful.
- No service locator abuse.
- No giant static helper classes.
- No hidden global state beyond unavoidable PrestaShop legacy integration boundaries.
- Wrap legacy PrestaShop dependencies behind adapters/services when that materially improves testability.
- Avoid direct SQL unless Core/domain APIs are inadequate and SQL is justified by performance/correctness.
- Parameterize every SQL query.
- No copied Core checkout code unless absolutely unavoidable. If copied, document why, track its upstream source/version, and minimize the copied surface.
- Never modify PrestaShop Core files.
- Avoid overrides. An override is allowed only as an explicitly documented last resort when no stable extension point exists for a supported version.

## Naming and design

Use names that describe business meaning, not implementation accidents.

Prefer concepts such as:

- `CheckoutState`;
- `CheckoutContext`;
- `CheckoutRefreshResult`;
- `CheckoutValidationResult`;
- `AddressSectionBuilder`;
- `DeliveryOptionsProvider`;
- `PaymentOptionsProvider`;
- `OrderSubmissionGuard`;
- `CheckoutStateSynchronizer`;

These are examples, not mandatory class names.

Do not create abstractions only to increase class count.

---

# 5. Checkout state model

Treat checkout as a **stateful transactional workflow**, not only a rendered page.

Define one authoritative server-side checkout state based on the current cart, customer/session, addresses, carrier, currency, country, taxes, selected payment method and legal requirements.

Every AJAX mutation must follow a predictable sequence:

1. authenticate/identify the current cart/session safely;
2. validate CSRF/security requirements;
3. validate and normalize request data;
4. apply the requested state change;
5. let PrestaShop recalculate dependent business state;
6. rebuild every checkout section affected by that mutation;
7. return structured success/error data;
8. include updated totals and state/version metadata where useful;
9. never leave the browser showing a stale state as if it were current.

Typical dependency chain:

- customer changes can affect addresses;
- address changes can affect taxes, available carriers and payment methods;
- carrier changes can affect totals and payment methods;
- cart changes can affect totals, carrier eligibility and payment eligibility;
- country changes can affect required fields, tax and carrier availability;
- payment method changes can affect additional forms or validation.

Do not refresh only the visually changed block if downstream state has also changed.

---

# 6. Front-office UX requirements

The implementation must work well on desktop, tablet and mobile.

## Layout

Default layout should provide:

- main checkout form/content area;
- order/cart summary area;
- sticky summary on suitable desktop widths when it does not harm accessibility;
- mobile layout that naturally collapses into one column;
- clear section headings;
- clear selected-state styling;
- visible totals and final payable amount;
- prominent but non-aggressive final order button.

Do not make critical functionality depend on hover.

## User flows

Support at minimum:

### Guest

- guest checkout when the shop allows it;
- required identity fields;
- optional account creation only where PrestaShop allows/configures it;
- email validation;
- clear duplicate-account/login guidance without exposing sensitive account information.

### Logged-in customer

- existing addresses;
- adding a new address;
- editing an address where safe;
- selecting a delivery address;
- selecting a separate invoice address;
- customer data must not be overwritten unexpectedly.

### Address UX

- country/state dependencies;
- PrestaShop-required address fields;
- postcode/phone/company/VAT-related fields based on PrestaShop rules;
- address aliases if required by Core;
- explicit validation errors next to relevant fields;
- no silent failures.

### Delivery

- render all available valid carrier choices;
- carrier logos/descriptions/delay information where available;
- carrier price correctly formatted;
- selected carrier preserved after unrelated refreshes where still valid;
- if selection becomes invalid, choose only a business-rule-safe fallback and visibly explain the state if user action is required;
- preserve third-party carrier module content/hooks.

### Payment

- render payment options provided by PrestaShop payment modules;
- support payment option additional information/forms;
- support form-based payment flows;
- support redirect/external payment flows;
- support self-submitting/binary-style options where applicable;
- reinitialize payment module JavaScript/hooks after payment-section AJAX refreshes;
- never duplicate payment submission;
- do not invent a payment integration outside PrestaShop's payment option model.

### Legal conditions

- terms and conditions checkbox/checkboxes;
- privacy or other required agreements exposed by PrestaShop/modules;
- prevent final submission until required agreements are satisfied;
- validation must also happen server-side.

### Final submission

- one clear final action;
- disable/deduplicate repeated submits immediately;
- visible in-progress state;
- idempotency/double-order protection;
- never create two orders from double click, repeated AJAX call, browser retry or slow payment initialization;
- recover gracefully from validation errors;
- do not clear user-entered fields unnecessarily.

---

# 7. AJAX/API design

Use focused endpoints/actions instead of one giant “do everything” controller.

Potential operations include:

- update customer identity;
- select/add/edit delivery address;
- select/add/edit invoice address;
- toggle invoice-address mode;
- select carrier;
- refresh payment methods;
- refresh summary;
- validate checkout;
- begin final submission.

The exact API should minimize requests while preserving clear responsibilities.

## Response contract

Use a consistent structured contract, for example:

```json
{
  "success": true,
  "stateVersion": "...",
  "sections": {
    "addresses": "<html>",
    "delivery": "<html>",
    "payment": "<html>",
    "summary": "<html>"
  },
  "errors": [],
  "redirect": null
}
```

This is illustrative. Choose the most suitable response contract for PrestaShop, but keep it stable and documented.

Requirements:

- correct HTTP status codes where practical;
- machine-readable error codes plus translated user messages;
- no stack traces or sensitive data returned to customers;
- server logs contain enough context for diagnosis;
- reject stale or invalid state when continuing would risk incorrect totals/order data;
- AJAX handlers must be safe against cross-cart/customer access.

---

# 8. JavaScript architecture

Do not build a fragile pile of jQuery callbacks.

Prefer small ES modules/classes with clear responsibilities unless the target PrestaShop asset pipeline requires a different approach.

Suggested concepts:

- checkout event bus / event adapter;
- request client;
- section renderer;
- form serializer;
- loading-state manager;
- validation renderer;
- payment initializer;
- submit guard;
- request cancellation/stale-response guard.

## Race-condition protection

Rapid user changes can create out-of-order AJAX responses.

Implement protection using one or more of:

- `AbortController`;
- monotonically increasing request/state version;
- latest-request-wins logic;
- server state token/version.

A slower old response must never overwrite a newer checkout state.

## Events

Publish documented custom events for meaningful lifecycle points, for example:

- checkout initialized;
- section updating;
- section updated;
- carrier changed;
- payment refreshed;
- validation failed;
- final submit started;
- checkout error.

On PrestaShop 9.2+, respect/document native One Page Checkout JavaScript events where integration requires it, including payment refresh/final-submit behavior.

Do not break existing PrestaShop global checkout/cart events expected by third-party modules when they are relevant.

---

# 9. Theme compatibility

The module should not depend on only one theme.

Test at least against the standard PrestaShop 9 theme(s) relevant to the target release, including Classic and/or Hummingbird where supported.

Rules:

- namespace CSS under a module-specific checkout root;
- avoid broad selectors such as `.row`, `.form-control`, `button`, `input` without module scoping;
- avoid `!important` except for a documented unavoidable case;
- do not assume Bootstrap version-specific behavior unless the dependency is guaranteed;
- do not hardcode DOM from one theme when the module can own its own markup;
- keep template override requirements to the absolute minimum;
- document any required theme integration contract;
- preserve required hook output using the correct escaping strategy.

---

# 10. Accessibility

Target practical WCAG 2.1 AA behavior.

At minimum:

- semantic labels for inputs;
- keyboard-accessible controls;
- logical tab order;
- visible focus states;
- errors programmatically associated with fields;
- `aria-live` or equivalent for important AJAX validation/status changes;
- do not rely only on color to convey an error/selection;
- buttons must have proper disabled/loading semantics;
- modal/dialog behavior must manage focus correctly if modals are used;
- no inaccessible custom radio/checkbox implementation.

---

# 11. Security requirements

Checkout is security-sensitive. Treat every client value as untrusted.

Required:

- CSRF protection for state-changing requests using the appropriate PrestaShop mechanism;
- strict customer/cart ownership checks;
- prevent IDOR for address/customer/cart identifiers;
- server-side authorization for every mutable resource;
- escape output according to context;
- no raw untrusted HTML injection;
- validate and normalize all request inputs;
- prepared/parameterized SQL only;
- never trust prices, totals, taxes, discounts, carrier price or currency values sent by browser;
- server is authoritative for all monetary calculations;
- prevent replay/double-submit resulting in duplicate orders;
- do not log passwords, payment secrets, full payment credentials, auth tokens, session identifiers or unnecessary PII;
- configuration endpoints must be protected by normal Back Office authorization/token mechanisms;
- no debug endpoints in production;
- no hidden default credentials/secrets.

Perform a focused threat review for:

- IDOR;
- CSRF;
- XSS;
- injection;
- cart takeover;
- stale checkout state;
- duplicate order submission;
- payment tampering;
- address ownership bypass;
- forged carrier/payment identifiers.

Record the outcome in `docs/SECURITY.md`.

---

# 12. Performance requirements

Checkout must stay responsive on stores with large catalogs and busy carts.

Do not optimize by bypassing PrestaShop business rules.

Requirements:

- avoid full-page reloads for normal checkout edits;
- avoid rebuilding sections that provably cannot be affected;
- but correctness takes priority over micro-optimization;
- avoid N+1 queries;
- avoid repeated module scans/hook executions when the same result can safely be reused within one request;
- do not introduce large front-end frameworks just for checkout UI;
- keep JS/CSS bundles small;
- lazy-initialize expensive UI only where safe;
- do not persist redundant checkout data that PrestaShop already owns;
- cache only data that is safe to cache and define invalidation explicitly;
- never cache customer-specific rendered checkout fragments across customers;
- add lightweight timing/profiling hooks or debug logging behind an explicit development setting if useful.

For key AJAX operations, document expected query/request behavior and identify expensive paths.

---

# 13. Back Office configuration

Create a clean PrestaShop-compatible module configuration page.

Keep settings useful and limited. Avoid turning checkout into an unmaintainable page builder.

Potential settings:

- enable/disable module checkout flow;
- layout choice if more than one production-ready layout exists;
- optional sticky summary;
- optional section ordering only if technically safe;
- guest/login presentation preferences that do not violate shop configuration;
- development/debug diagnostics disabled by default.

Multistore configuration must respect shop context.

Every configuration value must have:

- validation;
- sensible defaults;
- translation;
- correct shop scope;
- uninstall cleanup policy.

Do not store settings in custom DB tables when standard Configuration storage is sufficient.

---

# 14. Install / uninstall / upgrade lifecycle

Installation must be atomic enough that failure does not leave the shop in a broken partial state.

Required:

- register only needed hooks;
- create DB schema only if truly required;
- use proper indexes and engine/charset conventions if tables are needed;
- make install/uninstall idempotent where practical;
- safely clean configuration on uninstall;
- do not delete merchant/customer/order business data that should be retained;
- restore native checkout behavior after disable/uninstall;
- provide upgrade scripts only when a real schema/config migration is introduced;
- do not bump module version just for arbitrary code edits unless release/version policy calls for it.

---

# 15. Error handling and observability

Errors must be useful to customers and useful to developers without leaking internals.

Customer-facing behavior:

- translated concise message;
- field-level errors where possible;
- preserve entered data;
- actionable retry path;
- no PHP exception dump.

Developer/admin behavior:

- structured contextual logs;
- include operation name and non-sensitive identifiers such as cart ID/shop ID when appropriate;
- include exception chain in server log where safe;
- distinguish validation failures from system failures;
- avoid log spam for expected validation errors.

If a critical checkout integration fails, prefer a safe fallback to native checkout where possible rather than leaving the customer on a dead page.

---

# 16. Compatibility with payment and carrier modules

This is a release blocker.

Create a compatibility test matrix and validate at least representative module categories:

## Payments

- standard embedded/form payment option;
- redirect payment;
- payment option with `additionalInformation`;
- payment option with additional form/action data;
- option requiring JavaScript initialization after DOM refresh;
- multiple payment options from one module if supported;
- unavailable payment after address/carrier change;
- payment error/retry path.

## Carriers

- standard carrier;
- free carrier;
- carrier with price;
- carrier module injecting additional checkout content;
- carrier unavailable after address change;
- multiple delivery choices;
- no carrier available.

Do not hardcode individual third-party modules into core architecture. Add adapters only when a specific incompatibility genuinely requires one, and isolate them.

---

# 17. Testing strategy — mandatory

A feature is not finished when it “works in browser once”.

Build automated tests from the beginning.

## Unit tests

Test pure/domain behavior such as:

- state transitions;
- validation mapping;
- normalization;
- refresh dependency decisions;
- submit guard/idempotency logic;
- compatibility/version capability decisions;
- configuration validation.

## Integration tests

Cover integration with PrestaShop services/models where feasible:

- cart/customer/address ownership;
- address changes affecting carrier/totals;
- carrier selection persistence;
- payment option refresh;
- configuration per shop;
- install/uninstall lifecycle.

## Functional/checkout tests

At minimum create repeatable tests for:

1. guest checkout happy path;
2. logged-in customer happy path;
3. existing address selection;
4. new address creation;
5. separate invoice address;
6. address validation errors;
7. country/state change;
8. carrier change and totals refresh;
9. payment refresh after delivery changes;
10. required terms not accepted;
11. payment unavailable state;
12. no carrier available state;
13. double-click/final-submit duplication attempt;
14. stale AJAX response scenario;
15. mobile viewport smoke flow;
16. multistore configuration isolation;
17. module disable restores native checkout.

If browser E2E infrastructure is added, keep it deterministic and documented.

## Regression tests

Every discovered bug must receive a regression test when reasonably testable.

Never “fix” a failing test by deleting it, weakening the assertion, marking it skipped, or excluding the failing code unless the test itself is demonstrably invalid and the reason is documented.

---

# 18. Quality gates

Add local tooling and GitHub Actions where practical.

At minimum aim for:

- Composer validation;
- PHP syntax check;
- PrestaShop/PHP coding standards check;
- PHP-CS-Fixer or equivalent project formatter/checker;
- PHPStan at the highest stable level that is practical for the PrestaShop integration, with a small documented baseline rather than blanket ignores;
- PHPUnit;
- JavaScript linting if a JS build/toolchain exists;
- template/static checks where practical;
- test that production Composer install/autoload works;
- archive/package sanity check for module distribution.

CI must fail on real quality failures.

Do not create a “green” pipeline by hiding warnings/errors.

---

# 19. Documentation deliverables

Maintain these documents as implementation progresses:

- `README.md`
  - module purpose;
  - compatibility;
  - installation;
  - configuration;
  - development setup;
  - testing commands;
  - build/package commands;
  - known limitations;
- `docs/ARCHITECTURE.md`
  - main components;
  - data/state flow;
  - checkout provider/version strategy;
  - AJAX refresh model;
  - important tradeoffs;
- `docs/SECURITY.md`
  - trust boundaries;
  - threat review;
  - CSRF/IDOR/duplicate-order protections;
- `docs/COMPATIBILITY.md`
  - supported PrestaShop/PHP versions;
  - theme matrix;
  - payment/carrier matrix;
  - PrestaShop 9.2 native OPC interaction;
- `CHANGELOG.md`
  - meaningful release changes.

Documentation must reflect the code that actually exists.

---

# 20. Git workflow — mandatory

All work goes to:

`https://github.com/jzvikas/prestashop-one-page-checkout`

Rules:

1. inspect `git status`, current branch and recent history before work;
2. use small logical commits;
3. use descriptive commit messages;
4. run relevant tests before each milestone commit;
5. never commit generated junk, secrets, IDE state, local caches or vendor dependencies unless distribution architecture explicitly requires otherwise;
6. maintain a correct `.gitignore`;
7. do not force-push over unrelated user work;
8. never discard existing user changes;
9. if existing work is broken, diagnose it before replacing it;
10. after changes, confirm there are no unintended untracked/modified files;
11. push/commit all intended changes to the repository as requested;
12. report the exact commit(s) created and test status after each major task.

If working in an agent environment that can create pull requests, prefer a feature branch + PR for large implementation phases unless the repository workflow explicitly requires direct commits to `main`.

---

# 21. Implementation phases

Work incrementally. Each phase must leave the repository coherent.

## Phase 0 — repository bootstrap and architecture discovery

Deliver:

- repository inspection;
- official PrestaShop 9 checkout investigation;
- supported-version strategy;
- module naming/namespace decision;
- Composer/autoload skeleton;
- `README.md` initial setup;
- `docs/ARCHITECTURE.md` initial ADR;
- CI skeleton;
- first tests/tooling.

Acceptance:

- module skeleton is installable or very close to installable;
- autoload works;
- quality commands are documented;
- CI can execute basic gates.

## Phase 1 — safe checkout provider/integration shell

Deliver:

- enable/disable behavior;
- PrestaShop 9.x capability detection;
- 9.2 provider integration strategy where applicable;
- safe native checkout fallback;
- base checkout page/template;
- frontend asset registration.

Acceptance:

- enabling the module activates only the intended flow;
- disabling it restores native checkout;
- no fatal class/reference errors on supported versions.

## Phase 2 — customer and addresses

Deliver:

- guest/customer identity;
- login integration where appropriate;
- delivery address;
- invoice address;
- validation;
- AJAX state synchronization;
- tests.

Acceptance:

- address ownership is secure;
- dependent checkout data refreshes correctly;
- validation is clear and accessible.

## Phase 3 — carriers and totals

Deliver:

- carrier choices;
- carrier hooks/module content;
- selection persistence;
- totals refresh;
- no-carrier states;
- tests.

Acceptance:

- totals always match server-side PrestaShop calculations;
- carrier changes never leave stale payment/totals UI.

## Phase 4 — payments

Deliver:

- payment options;
- additional payment forms/info;
- JS reinitialization lifecycle;
- redirect/form flows;
- payment refresh after dependency changes;
- tests.

Acceptance:

- representative payment module types work;
- no duplicated payment actions;
- payment option is never trusted from stale client state.

## Phase 5 — final submit and hardening

Deliver:

- legal conditions;
- full server-side validation;
- submit locking/idempotency;
- final order/payment handoff;
- robust failure recovery;
- security review;
- race-condition tests.

Acceptance:

- repeated submit cannot create duplicate orders;
- invalid state cannot create an incorrect order;
- no sensitive errors leak to customer.

## Phase 6 — UX/performance polish

Deliver:

- responsive layout;
- accessibility pass;
- loading states;
- sticky summary where configured;
- network/request reduction;
- profiling of expensive paths;
- theme compatibility fixes.

Acceptance:

- mobile and desktop flows are comfortable;
- no layout shift/stale-section chaos during refresh;
- acceptable checkout responsiveness.

## Phase 7 — release readiness

Deliver:

- full test matrix;
- packaging sanity check;
- installation/uninstallation test;
- upgrade path review;
- final docs;
- changelog;
- clean CI;
- release candidate commit/tag only if appropriate.

Acceptance:

- all required gates green;
- no known blocker severity checkout bugs;
- documented limitations are explicit;
- repository is clean.

---

# 22. Definition of done

Do not call this module production-ready until all of the following are true:

- module installs cleanly on supported PrestaShop 9 environments;
- module disables/uninstalls without breaking native checkout;
- PHP 8.4+ target is enforced/tested according to PrestaShop support constraints;
- no Core modifications;
- no unnecessary overrides;
- guest checkout works;
- logged-in checkout works;
- delivery/invoice addresses work;
- carriers work and recalculate correctly;
- payment options work and refresh correctly;
- legal terms work;
- final order handoff works;
- duplicate-order protection works;
- AJAX races/stale responses are controlled;
- error handling preserves user progress where possible;
- mobile/responsive UX is complete;
- accessibility basics are verified;
- multistore configuration is correct;
- translations are used for customer/admin text;
- security review completed;
- automated test suite exists and passes;
- static analysis/coding standards pass;
- CI is green;
- docs match implementation;
- all intended changes are committed to Git.

---

# 23. Engineering behavior while executing this prompt

Do not stop at identifying a problem when you can fix it safely.

For every implementation iteration:

1. inspect;
2. understand root cause/business rule;
3. implement the smallest correct architectural change;
4. add/update tests;
5. run targeted tests;
6. run broader quality gates before milestone completion;
7. inspect diff for accidental changes;
8. commit;
9. continue with the next highest-priority blocker.

When a test fails, investigate immediately.

When implementation reveals that an earlier architecture assumption was wrong, update architecture/docs and refactor instead of layering hacks on top.

Prefer correctness and maintainability over shipping a large amount of code quickly.

Do not leave TODOs for core checkout correctness, security, validation, payment handling or duplicate-order prevention.

---

# 24. First task to execute now

Start with **Phase 0**.

Specifically:

1. inspect the empty/current repository and Git history;
2. verify the current PrestaShop 9 checkout architecture from official docs and source;
3. decide and document the supported PrestaShop 9.x matrix, including the PrestaShop 9.2 native OPC compatibility strategy;
4. select the module technical name and PHP namespace;
5. create the production-oriented module skeleton;
6. add Composer autoload/tooling;
7. add initial automated tests and GitHub Actions;
8. add `README.md` and `docs/ARCHITECTURE.md`;
9. run all available checks;
10. fix every failure found;
11. commit all intended work to the repository;
12. report:
    - files created/changed;
    - architecture decisions;
    - commands/tests executed;
    - exact results;
    - commit SHA;
    - next implementation phase.

Do not implement the entire checkout as one uncontrolled first commit. Build and verify it phase by phase.

---

# 25. Official references to verify during implementation

Use the current official PrestaShop Developer Documentation and current PrestaShop source as primary references, especially:

- PrestaShop 9 module development documentation;
- PrestaShop 9 coding standards;
- PrestaShop 9 hook reference;
- One Page Checkout documentation for module developers;
- One Page Checkout documentation for theme developers;
- current `PrestaShop/PrestaShop` checkout source for each supported 9.x branch;
- current official `ps_onepagecheckout` source for PrestaShop 9.2+ behavior.

Do not rely on obsolete PrestaShop 1.6/1.7 tutorials when PrestaShop 9 provides a newer supported mechanism.

---

## Final quality bar

The result should look like code written and reviewed by a senior engineer who expects the module to process real customer orders and real money.

A checkout that is visually impressive but can create stale totals, duplicate orders, invalid carrier/payment combinations, security issues, or third-party payment regressions is **not acceptable**.

A checkout that is technically correct but confusing, slow, inaccessible, or difficult to maintain is also **not finished**.
