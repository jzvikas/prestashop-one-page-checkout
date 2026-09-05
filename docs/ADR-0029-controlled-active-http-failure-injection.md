# ADR-0029 — Controlled active HTTP failure injection

## Status

Accepted for pre-readiness verification. Runtime execution is still pending.

## Context

ADR-0027 requires checkout integration failures to fall back to PrestaShop Core before a partial One Page Checkout process becomes authoritative. ADR-0028 added installed-object failure isolation, but that does not prove the complete Front Office request path:

- `actionFrontControllerSetMedia` failure before checkout bootstrap;
- 9.0/9.1 reference-bearing `actionCheckoutRender` fallback;
- 9.2+ provider preparation returning no provider;
- real Smarty/template and shell-service failures;
- recovery on a later request after a request-local circuit breaker was tripped.

Production checkout takeover cannot be enabled merely to test those paths. The repository must keep `INTEGRATION_SHELL_READY=false` until the required gates succeed.

## Decision

1. The normal installed module is tested first with the production readiness constant still closed. The existing fail-closed HTTP contract must prove that Core `/order` contains no OPC root/assets and that direct module finalization remains unavailable.
2. Only after that production-boundary check, the runtime workflow creates a disposable copy under `/tmp/jzopc-active-fixture*`.
3. The fixture builder requires `JZOPC_RUNTIME_ACTIVE_FIXTURE=1`, refuses non-`/tmp` targets and changes the readiness constant only inside that disposable copy. It verifies the repository source remains closed.
4. Test-only failure instrumentation is installed only into that disposable copy. Production `CheckoutShellRenderer`, `PrestaShopCheckoutTemplateRenderer` and `CheckoutFrontendAssetRegistrar` must contain no runtime-failure marker strings.
5. The instrumenter is fail-closed: it rejects symlink patch targets, requires one exact source anchor per patch and aborts if source structure drifts.
6. The active HTTP contract creates an orderable product through Core `Product`/category/stock APIs and creates the browser cart through the real Core CartController using one cookie session. It does not insert cart/order rows directly and does not invoke payment finalization or `PaymentModule::validateOrder()`.
7. The same cart/cookie session must prove this sequence:
   - healthy active OPC;
   - persistence failure by removing the module-owned selection table;
   - Core native checkout fallback;
   - persistence restoration and healthy OPC recovery;
   - shell-service failure through a temporary marker;
   - Core native fallback and healthy recovery;
   - real Smarty template lookup failure through a temporary missing-template target;
   - Core native fallback and healthy recovery;
   - frontend asset-registration failure through a temporary marker;
   - Core native fallback and healthy recovery.
8. Each marker is removed in a local `finally` boundary, and the whole test has a second cleanup boundary that removes every marker, restores the selection schema, disables the temporary checkout configuration and removes the runtime product.
9. Native fallback requires more than a non-500 response: the OPC root must be absent and the Core personal-information checkout step must be present.
10. Evidence from the disposable active fixture is a pre-readiness verification aid only. It does not itself authorize flipping the production readiness constant.

## Security rationale

The active fixture is intentionally not a hidden production switch. There is no environment-variable branch in production activation policy, no debug controller and no committed runtime marker in production integration classes. The only open readiness constant exists in a copied test tree whose path and opt-in environment are both constrained.

The failure modes operate before payment/order ownership is transferred. They do not submit a payment, release a finalization reservation, call Core order creation APIs or trust browser monetary state.

Using the same cart/cookie for failure and recovery also proves an important containment property: a failure latch is request-local. A service, template or asset exception must not leave later requests permanently disabled or partially active.

## Compatibility coverage

The configured runtime workflow executes the same controlled HTTP contract for:

- PrestaShop 9.0.3 — legacy `actionCheckoutRender` family;
- PrestaShop 9.1.5 — legacy `actionCheckoutRender` family;
- PrestaShop 9.2.0-beta.1 — provider family after the native OPC conflict fixture is explicitly disabled for this isolated active test.

The production closed-readiness contract always runs before the active fixture is built.

## Verification state

The source/runtime contracts and workflow wiring are committed but have not executed in GitHub Actions because the repository is currently receiving no workflow checks/statuses. They must not be described as passing runtime evidence.

The test-only instrumenter itself was syntax-checked with PHP locally and executed against a synthetic `/tmp/jzopc-active-fixture-static` source layout. It inserted all three marker checks at the expected anchors and the resulting instrumented PHP snippets remained syntax-valid. That verifies the patch mechanism only; it is not a PrestaShop runtime/browser result.

Before `INTEGRATION_SHELL_READY` can be reconsidered, the configured runtime matrix must actually execute successfully and the remaining real browser payment/carrier/identity/concurrency/accessibility gates must also pass.
