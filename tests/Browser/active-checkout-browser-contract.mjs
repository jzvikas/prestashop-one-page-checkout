import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

const baseUrl = String(process.env.JZOPC_BROWSER_BASE_URL || '').replace(/\/$/, '');
const productId = Number.parseInt(String(process.env.JZOPC_RUNTIME_PRODUCT_ID || ''), 10);
const configuredFixtureRoot = String(process.env.JZOPC_ACTIVE_FIXTURE_ROOT || '');

function fail(message) {
  throw new Error(message);
}

if (!/^https?:\/\//i.test(baseUrl)) {
  fail('JZOPC_BROWSER_BASE_URL must be an absolute HTTP(S) URL.');
}
if (!Number.isInteger(productId) || productId <= 0) {
  fail('JZOPC_RUNTIME_PRODUCT_ID must be a positive integer.');
}
if (!configuredFixtureRoot) {
  fail('JZOPC_ACTIVE_FIXTURE_ROOT is required.');
}

const baseOrigin = new URL(baseUrl).origin;
const fixtureRoot = fs.realpathSync(configuredFixtureRoot);
if (
  fixtureRoot !== '/tmp/jzopc-active-fixture'
  && !fixtureRoot.startsWith('/tmp/jzopc-active-fixture-')
) {
  fail('Browser contract refuses a fixture outside /tmp/jzopc-active-fixture*.');
}

const serviceFailureMarker = path.join(fixtureRoot, '.jzopc-runtime-failure-service');
if (fs.existsSync(serviceFailureMarker)) {
  fail('Service failure marker must not already exist before browser execution.');
}

const requiredAssets = new Set([
  'payment-controller.js',
  'checkout-mutation-client.js',
  'final-submit-controller.js',
  'ordinary-payment-submit-guard.js',
  'binary-payment-controller.js',
  'payment-handoff-ambiguity-guard.js',
]);

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();
const assetResponses = new Map();
const pageErrors = [];
let currentStage = 'bootstrap';

page.on('pageerror', (error) => {
  pageErrors.push(`${currentStage}: ${error instanceof Error ? error.message : String(error)}`);
});

page.on('response', (response) => {
  const responseUrl = new URL(response.url());
  for (const asset of requiredAssets) {
    if (responseUrl.pathname.endsWith(`/modules/jzonepagecheckout/views/js/${asset}`)) {
      assetResponses.set(asset, response.status());
    }
  }
});

await page.addInitScript(() => {
  window.__jzopcLifecycle = [];

  document.addEventListener('jzopc:checkout:initialized', (event) => {
    const detail = event && event.detail && typeof event.detail === 'object' ? event.detail : {};
    window.__jzopcLifecycle.push({
      type: 'initialized',
      stateVersion: typeof detail.stateVersion === 'string' ? detail.stateVersion : null,
    });
  });

  document.addEventListener('jzopc:checkout:validation-failed', (event) => {
    const detail = event && event.detail && typeof event.detail === 'object' ? event.detail : {};
    const errors = Array.isArray(detail.errors) ? detail.errors : [];
    window.__jzopcLifecycle.push({
      type: 'validation-failed',
      stateVersion: typeof detail.stateVersion === 'string' ? detail.stateVersion : null,
      errors: errors.map((error) => ({
        code: error && typeof error.code === 'string' ? error.code : null,
      })),
    });
  });
});

async function navigate(url, stage) {
  currentStage = stage;
  const errorsBefore = pageErrors.length;
  const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
  if (!response) {
    fail(`${stage}: navigation did not return a document response.`);
  }
  if (response.status() >= 400) {
    fail(`${stage}: navigation returned HTTP ${response.status()}.`);
  }
  await page.waitForTimeout(100);
  if (pageErrors.length !== errorsBefore) {
    fail(`${stage}: browser JavaScript error: ${pageErrors.slice(errorsBefore).join(' | ')}`);
  }

  return response;
}

async function bootstrapDiagnostics() {
  return page.locator('[data-jzopc-checkout]').evaluate((root) => ({
    readyState: document.readyState,
    mutationClientType: typeof window.JzOpcMutationClient,
    lifecycle: Array.isArray(window.__jzopcLifecycle) ? window.__jzopcLifecycle.slice() : null,
    bootstrap: {
      cartId: root.getAttribute('data-jzopc-cart-id') || '',
      stateVersion: root.getAttribute('data-jzopc-state-version') || '',
      csrfToken: root.getAttribute('data-jzopc-csrf-token') || '',
      identityUrl: root.getAttribute('data-jzopc-identity-url') || '',
      addressUrl: root.getAttribute('data-jzopc-address-url') || '',
      addressSaveUrl: root.getAttribute('data-jzopc-address-save-url') || '',
      carrierUrl: root.getAttribute('data-jzopc-carrier-url') || '',
      paymentUrl: root.getAttribute('data-jzopc-payment-url') || '',
      agreementsUrl: root.getAttribute('data-jzopc-agreements-url') || '',
    },
    scriptSources: Array.from(document.scripts)
      .map((script) => script.src || '')
      .filter((source) => source.includes('/modules/jzonepagecheckout/views/js/')),
  }));
}

async function assertHealthyCheckout(stage, requireAssetNetwork = false) {
  await page.locator('[data-jzopc-checkout]').waitFor({ state: 'attached', timeout: 10000 });

  const rootCount = await page.locator('[data-jzopc-checkout]').count();
  if (rootCount !== 1) {
    fail(`${stage}: expected exactly one OPC root, found ${rootCount}.`);
  }

  if (requireAssetNetwork) {
    await page.waitForTimeout(250);
    for (const asset of requiredAssets) {
      const status = assetResponses.get(asset);
      if (!Number.isInteger(status) || status < 200 || status >= 400) {
        const diagnostics = await bootstrapDiagnostics();
        fail(`${stage}: required browser asset ${asset} did not load successfully (status=${String(status)}); diagnostics=${JSON.stringify(diagnostics)}.`);
      }
    }
  }

  try {
    await page.waitForFunction(() => (
      Array.isArray(window.__jzopcLifecycle)
      && window.__jzopcLifecycle.some((event) => event && event.type === 'initialized')
    ), null, { timeout: 10000 });
  } catch (error) {
    const diagnostics = await bootstrapDiagnostics();
    fail(`${stage}: checkout initialized lifecycle was not observed; diagnostics=${JSON.stringify(diagnostics)}; waitError=${error instanceof Error ? error.message : String(error)}.`);
  }

  const state = await page.locator('[data-jzopc-checkout]').evaluate((root) => {
    const endpointAttributes = [
      'data-jzopc-identity-url',
      'data-jzopc-address-url',
      'data-jzopc-address-save-url',
      'data-jzopc-carrier-url',
      'data-jzopc-payment-url',
      'data-jzopc-agreements-url',
      'data-jzopc-finalization-url',
    ];

    return {
      cartId: root.getAttribute('data-jzopc-cart-id') || '',
      stateVersion: root.getAttribute('data-jzopc-state-version') || '',
      csrfToken: root.getAttribute('data-jzopc-csrf-token') || '',
      reserved: root.getAttribute('data-jzopc-finalization-reserved') || '',
      endpoints: Object.fromEntries(endpointAttributes.map((attribute) => [
        attribute,
        root.getAttribute(attribute) || '',
      ])),
      lifecycle: Array.isArray(window.__jzopcLifecycle) ? window.__jzopcLifecycle.slice() : [],
    };
  });

  if (!/^[1-9]\d*$/.test(state.cartId)) {
    fail(`${stage}: OPC bootstrap cart ID is invalid.`);
  }
  if (!state.stateVersion || !state.csrfToken) {
    fail(`${stage}: OPC trusted state/CSRF bootstrap is incomplete.`);
  }
  if (state.reserved !== '0') {
    fail(`${stage}: fresh browser checkout unexpectedly reports an active finalization reservation.`);
  }
  if (!state.lifecycle.some((event) => event.type === 'initialized' && event.stateVersion === state.stateVersion)) {
    fail(`${stage}: initialized lifecycle did not carry the rendered state version.`);
  }

  for (const [attribute, endpoint] of Object.entries(state.endpoints)) {
    if (!endpoint) {
      fail(`${stage}: ${attribute} is empty.`);
    }
    const resolved = new URL(endpoint, baseUrl);
    if (resolved.origin !== baseOrigin) {
      fail(`${stage}: ${attribute} escaped the runtime origin (${resolved.origin} !== ${baseOrigin}).`);
    }
    if (!resolved.pathname.includes('/module/jzonepagecheckout/')) {
      fail(`${stage}: ${attribute} does not target the OPC module route.`);
    }
  }

  return state;
}

async function assertIdentityValidationFailure(expectedCartId) {
  currentStage = 'identity-validation';
  const errorsBefore = pageErrors.length;
  const pathBefore = new URL(page.url()).pathname;
  const validationCountBefore = await page.evaluate(() => (
    Array.isArray(window.__jzopcLifecycle)
      ? window.__jzopcLifecycle.filter((event) => event && event.type === 'validation-failed').length
      : 0
  ));

  const createForm = page.locator('[data-jzopc-identity-form="create"] form');
  const loginForm = page.locator('[data-jzopc-identity-form="login"] form');
  if (await createForm.count() !== 1 || await loginForm.count() !== 1) {
    fail('identity-validation: expected exactly one Core create form and one Core login form.');
  }

  await createForm.evaluate((form) => {
    form.noValidate = true;
    form.requestSubmit();
  });

  await page.waitForFunction((previousCount) => (
    Array.isArray(window.__jzopcLifecycle)
    && window.__jzopcLifecycle.filter((event) => event && event.type === 'validation-failed').length > previousCount
  ), validationCountBefore, { timeout: 10000 });

  const result = await page.locator('[data-jzopc-checkout]').evaluate((root) => {
    const failures = Array.isArray(window.__jzopcLifecycle)
      ? window.__jzopcLifecycle.filter((event) => event && event.type === 'validation-failed')
      : [];

    return {
      cartId: root.getAttribute('data-jzopc-cart-id') || '',
      stateVersion: root.getAttribute('data-jzopc-state-version') || '',
      latestFailure: failures.length > 0 ? failures[failures.length - 1] : null,
    };
  });

  if (result.cartId !== expectedCartId) {
    fail('identity-validation: server validation changed the active Core cart binding.');
  }
  if (!result.stateVersion) {
    fail('identity-validation: validated response did not preserve a server state version.');
  }
  if (!result.latestFailure || !Array.isArray(result.latestFailure.errors) || result.latestFailure.errors.length === 0) {
    fail('identity-validation: empty identity submit did not return server validation errors.');
  }
  if (new URL(page.url()).pathname !== pathBefore || pathBefore !== '/order') {
    fail('identity-validation: validation failure unexpectedly navigated away from /order.');
  }
  if (await page.locator('[data-jzopc-identity-form="create"] form').count() !== 1) {
    fail('identity-validation: Core create form disappeared after recoverable server validation failure.');
  }
  if (pageErrors.length !== errorsBefore) {
    fail(`identity-validation: browser JavaScript error: ${pageErrors.slice(errorsBefore).join(' | ')}`);
  }
}

async function assertNativeFallback(stage) {
  const rootCount = await page.locator('[data-jzopc-checkout]').count();
  if (rootCount !== 0) {
    fail(`${stage}: native fallback unexpectedly rendered the OPC root.`);
  }

  await page.locator('#checkout-personal-information-step').waitFor({ state: 'attached', timeout: 10000 });
  await page.waitForTimeout(250);

  const initializedCount = await page.evaluate(() => (
    Array.isArray(window.__jzopcLifecycle)
      ? window.__jzopcLifecycle.filter((event) => event && event.type === 'initialized').length
      : -1
  ));
  if (initializedCount !== 0) {
    fail(`${stage}: OPC JavaScript initialized while Core native checkout was active.`);
  }
}

try {
  const cartUrl = new URL('/cart', baseUrl);
  cartUrl.searchParams.set('add', '1');
  cartUrl.searchParams.set('id_product', String(productId));
  cartUrl.searchParams.set('qty', '1');
  await navigate(cartUrl.toString(), 'core-cart-add');

  await navigate(`${baseUrl}/order`, 'healthy-opc');
  const initialState = await assertHealthyCheckout('healthy-opc', true);
  await assertIdentityValidationFailure(initialState.cartId);

  fs.writeFileSync(serviceFailureMarker, 'browser\n', { flag: 'wx' });
  try {
    await navigate(`${baseUrl}/order`, 'service-fallback');
    await assertNativeFallback('service-fallback');
  } finally {
    if (fs.existsSync(serviceFailureMarker)) {
      fs.unlinkSync(serviceFailureMarker);
    }
  }

  await navigate(`${baseUrl}/order`, 'recovered-opc');
  const recoveredState = await assertHealthyCheckout('recovered-opc', false);
  if (recoveredState.cartId !== initialState.cartId) {
    fail('Recovered OPC did not preserve the same Core browser cart.');
  }

  process.stdout.write(
    `Active browser identity/takeover/fallback contract OK: cart=${initialState.cartId}, assets=${requiredAssets.size}, origin=${baseOrigin}\n`,
  );
} finally {
  if (fs.existsSync(serviceFailureMarker)) {
    try {
      fs.unlinkSync(serviceFailureMarker);
    } catch {
      // The original browser-contract failure remains authoritative; the HTTP cleanup layer also
      // refuses stale markers before it starts and will report a persistent marker separately.
    }
  }
  await context.close();
  await browser.close();
}