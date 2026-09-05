# ADR-0011: Shared checkout services in the legacy front container

## Status

Accepted.

## Context

The version-specific checkout process adapters are entered from front-office checkout hooks and module front controllers. Those entry points resolve application services through `Module::get()`.

Installed-runtime CI with PrestaShop 9.1.5 exposed a production compatibility gap after the first real Core process contract was added: `CheckoutProcessBuilder` was available to the Symfony module container but absent from the legacy front-office container. PrestaShop's legacy `ContainerBuilder` loads module services for the front scope from `config/front/services.yml` via `LoadServicesFromModulesPass('front')`; a root-only `config/services.yml` is therefore not sufficient for legacy checkout requests.

The failure occurred before any custom checkout activation was possible and was correctly caught while `INTEGRATION_SHELL_READY` remained `false`.

## Decision

The module owns one canonical service graph in `config/common/services.yml`.

- `config/services.yml` imports the common graph for the Symfony/module container.
- `config/front/services.yml` imports the same common graph for the legacy front-office container.
- The graph is not duplicated, so aliases, renderer registration, DBAL dependencies and security/concurrency services cannot silently drift between the two containers.
- Only services intentionally retrieved through `Module::get()` are public: the checkout process builder, legacy render adapter, front asset registrar, concrete mutation application services and response mapper. Their dependencies remain private by default.
- Doctrine-backed mutex and selection-store services continue to use PrestaShop's `doctrine.dbal.default_connection`; the legacy front container initializes Doctrine before module compiler passes run.

No checkout activation rule changes as part of this decision. `INTEGRATION_SHELL_READY` remains `false`.

## Verification

The repository smoke suite checks that both module service entry files import the same common graph and that required public entry services remain declared.

The installed-runtime matrix then installs the module into real PrestaShop 9.1.5 and 9.2.0-beta.1 shops and executes the Core process adapter contract. Both runtimes must resolve `CheckoutProcessBuilder` through the actual module/front container, build a real Core `CheckoutProcess`, preserve the supplied `CheckoutSession`, and exercise the appropriate legacy/provider path.

This runtime verification is required because a root-container-only smoke test cannot prove legacy front-office service visibility.

## Consequences

- Front-office checkout hooks and module front controllers use the same application graph as Symfony-aware module code.
- A missing or stale legacy front service definition becomes a CI failure instead of a production checkout fatal error.
- PrestaShop 9.1 compatibility no longer depends on the Symfony kernel having loaded the module's root service file first.
- The module still has no production takeover until the readiness gate is explicitly opened after the remaining runtime/browser requirements are proven.

## Next milestone

With process/session resolution now proven through the real front container, the next runtime boundary is real Smarty rendering of the module checkout step/shell and trusted bootstrap on both supported runtime families, followed by HTTP/browser lifecycle tests for assets, stale-safe mutations and representative carrier/payment integrations.
