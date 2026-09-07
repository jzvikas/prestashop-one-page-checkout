import { chromium } from 'playwright';

const baseUrl = String(process.env.JZOPC_BROWSER_BASE_URL || '').replace(/\/$/, '');
const productId = Number.parseInt(String(process.env.JZOPC_RUNTIME_FREE_PRODUCT_ID || ''), 10);

function fail(message) {
  throw new Error(message);
}

if (!/^https?:\/\//i.test(baseUrl)) {
  fail('JZOPC_BROWSER_BASE_URL must be an absolute HTTP(S) URL.');
}
if (!Number.isInteger(productId) || productId <= 0) {
  fail('JZOPC_RUNTIME_FREE_PRODUCT_ID must be a positive integer.');
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();
const pageErrors = [];
const trace = { preflight: 0, handoff: 0, blocked: 0, ambiguous: 0 };

page.on('pageerror', (error) => {
  pageErrors.push(error instanceof Error ? error.message : String(error));
});

await page.exposeFunction('jzopcFreeOrderTraceEvent', (name) => {
  if (Object.prototype.hasOwnProperty.call(trace, name)) {
    trace[name] += 1;
  }
});

async function navigate(url, stage) {
  const errorsBefore = pageErrors.length;
  const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
  if (!response || response.status() >= 400) {
    fail(`${stage}: navigation failed with HTTP ${response ? response.status() : 'no-response'}.`);
  }
  await page.waitForTimeout(100);
  if (pageErrors.length !== errorsBefore) {
    fail(`${stage}: browser JavaScript error: ${pageErrors.slice(errorsBefore).join(' | ')}`);
  }
}

async function binding(stage) {
  const root = page.locator('[data-jzopc-checkout]');
  await root.waitFor({ state: 'attached', timeout: 10000 });
  const result = await root.evaluate((node) => ({
    cartId: node.getAttribute('data-jzopc-cart-id') || '',
    stateVersion: node.getAttribute('data-jzopc-state-version') || '',
    csrfToken: node.getAttribute('data-jzopc-csrf-token') || '',
    reserved: node.getAttribute('data-jzopc-finalization-reserved') || '',
  }));
  if (!/^[1-9]\d*$/.test(result.cartId) || !result.stateVersion || !result.csrfToken || result.reserved !== '0') {
    fail(`${stage}: trusted checkout binding is incomplete or unexpectedly reserved.`);
  }
  return result;
}

async function mutationEndpoint(attribute, stage) {
  const value = await page.locator('[data-jzopc-checkout]').getAttribute(attribute);
  if (!value) {
    fail(`${stage}: missing ${attribute}.`);
  }
  return new URL(value, baseUrl);
}

async function runMutation(attribute, trigger, stage) {
  const endpoint = await mutationEndpoint(attribute, stage);
  const responsePromise = page.waitForResponse((response) => {
    if (response.request().method() !== 'POST') {
      return false;
    }
    const actual = new URL(response.url());
    return actual.origin === endpoint.origin && actual.pathname === endpoint.pathname;
  }, { timeout: 15000 });
  await trigger();
  const response = await responsePromise;
  let payload;
  try {
    payload = await response.json();
  } catch {
    fail(`${stage}: mutation response was not JSON.`);
  }
  if (response.status() >= 500 || !payload || payload.success !== true) {
    const codes = Array.isArray(payload?.errors)
      ? payload.errors.map((error) => error?.code || '').filter(Boolean)
      : [];
    fail(`${stage}: mutation was rejected [${codes.join(', ')}].`);
  }
  return payload;
}

async function fillIfPresent(scope, selector, value) {
  const field = scope.locator(selector);
  if (await field.count() > 0 && await field.first().inputValue() === '') {
    await field.first().fill(value);
  }
}

async function completeGuestIdentity() {
  const form = page.locator('[data-jzopc-identity-form="create"] form');
  await form.waitFor({ state: 'attached', timeout: 10000 });
  await fillIfPresent(form, 'input[name="firstname"]', 'Runtime');
  await fillIfPresent(form, 'input[name="lastname"]', 'FreeOrder');
  await fillIfPresent(form, 'input[name="email"]', `jzopc.free.${Date.now()}.${Math.random().toString(16).slice(2)}@example.com`);
  for (const checkbox of await form.locator('input[type="checkbox"][required]').all()) {
    if (!(await checkbox.isChecked())) {
      await checkbox.check();
    }
  }
  await runMutation(
    'data-jzopc-identity-url',
    async () => form.evaluate((node) => { node.noValidate = true; node.requestSubmit(); }),
    'guest-identity',
  );
  await page.waitForFunction(() => (
    document.querySelector('[data-jzopc-section="identity"] [data-jzopc-identity-form]') === null
      && document.querySelector('[data-jzopc-section="identity"] .jzopc-identity__current') !== null
  ), null, { timeout: 10000 });
}

async function completeDeliveryAddress() {
  const opener = page.locator('[data-jzopc-address-editor-open][data-jzopc-address-role="delivery"]').last();
  await runMutation('data-jzopc-address-save-url', async () => opener.click(), 'address-present');
  const editor = page.locator('[data-jzopc-address-editor][data-jzopc-address-role="delivery"]');
  await editor.waitFor({ state: 'attached', timeout: 10000 });
  const form = editor.locator('form');
  if (await form.count() !== 1) {
    fail('address-save: expected exactly one Core address form.');
  }
  await fillIfPresent(form, 'input[name="firstname"]', 'Runtime');
  await fillIfPresent(form, 'input[name="lastname"]', 'FreeOrder');
  await fillIfPresent(form, 'input[name="address1"]', '1 Free Order Street');
  await fillIfPresent(form, 'input[name="postcode"]', '10001');
  await fillIfPresent(form, 'input[name="city"]', 'New York');
  await fillIfPresent(form, 'input[name="alias"]', 'Free order runtime');
  const state = form.locator('select[name="id_state"]');
  if (await state.count() === 1 && await state.isVisible() && await state.inputValue() === '') {
    const values = await state.locator('option').evaluateAll((options) => options.map((option) => option.value).filter(Boolean));
    if (values.length === 0) {
      fail('address-save: visible state selector has no selectable state.');
    }
    await state.selectOption(values[0]);
  }
  for (const checkbox of await form.locator('input[type="checkbox"][required]').all()) {
    if (!(await checkbox.isChecked())) {
      await checkbox.check();
    }
  }
  await runMutation(
    'data-jzopc-address-save-url',
    async () => form.evaluate((node) => { node.noValidate = true; node.requestSubmit(); }),
    'address-save',
  );
  await page.locator('[data-jzopc-section="addresses"] input[name="id_address_delivery"]:checked')
    .waitFor({ state: 'attached', timeout: 10000 });
  const sameAddress = page.locator('[data-jzopc-section="addresses"] input[name="use_same_address"]');
  if (await sameAddress.count() === 1 && !(await sameAddress.isChecked())) {
    await runMutation('data-jzopc-address-url', async () => sameAddress.check(), 'invoice-same-address');
  }
}

async function selectCarrier() {
  const options = page.locator('[data-jzopc-section="delivery"] input[name="delivery_option"]');
  if (await options.count() === 0) {
    fail('carrier-selection: orderable physical checkout has no Core delivery option.');
  }
  const option = options.first();
  await runMutation(
    'data-jzopc-carrier-url',
    async () => option.evaluate((node) => {
      node.checked = true;
      node.dispatchEvent(new Event('change', { bubbles: true }));
    }),
    'carrier-selection',
  );
}

async function approveAgreements() {
  const agreements = page.locator('[data-jzopc-section="agreements"] input[name="agreements[]"]');
  const count = await agreements.count();
  if (count === 0) {
    return;
  }
  await runMutation(
    'data-jzopc-agreements-url',
    async () => agreements.evaluateAll((nodes) => {
      for (const node of nodes) {
        node.checked = true;
      }
      nodes[nodes.length - 1].dispatchEvent(new Event('change', { bubbles: true }));
    }),
    'agreements-selection',
  );
}

function isOrderConfirmation(urlString) {
  const url = new URL(urlString);
  return /(?:^|\/)order-confirmation\/?$/i.test(url.pathname)
    || url.searchParams.get('controller') === 'order-confirmation';
}

try {
  const cartUrl = new URL('/cart', baseUrl);
  cartUrl.searchParams.set('add', '1');
  cartUrl.searchParams.set('id_product', String(productId));
  cartUrl.searchParams.set('qty', '1');
  await navigate(cartUrl.toString(), 'core-cart-add');
  await navigate(`${baseUrl}/order`, 'active-checkout');

  const initial = await binding('active-checkout');
  await completeGuestIdentity();
  await completeDeliveryAddress();
  await selectCarrier();
  await approveAgreements();

  const freeStatus = page.locator('[data-jzopc-section="payment"] .jzopc-payment__free');
  if (await freeStatus.count() !== 1) {
    fail('free-order: server did not render the zero-total Core payment state.');
  }
  const selected = page.locator('[data-jzopc-section="payment"] input[name="payment-option"]:checked');
  if (await selected.count() !== 1 || await selected.getAttribute('data-module-name') !== 'free_order') {
    fail('free-order: exact Core free_order option was not server-preselected.');
  }
  const optionId = await selected.getAttribute('id');
  if (!optionId) {
    fail('free-order: selected Core option has no identifier.');
  }
  const form = page.locator(`#pay-with-${optionId}-form form`);
  if (await form.count() !== 1) {
    fail('free-order: Core-presented free_order action form is unavailable.');
  }
  const formShape = await form.evaluate((node) => {
    const action = new URL(node.action, window.location.href);
    return {
      sameOrigin: action.origin === window.location.origin,
      path: action.pathname,
      controller: action.searchParams.get('controller') || '',
      freeOrder: action.searchParams.get('free_order') || '',
      cartId: action.searchParams.get('id_cart') || '',
      method: String(node.method || '').toUpperCase(),
    };
  });
  const actionIsOrderConfirmation = /(?:^|\/)order-confirmation\/?$/i.test(formShape.path)
    || formShape.controller === 'order-confirmation';
  const cartQueryIsCompatible = formShape.cartId === '' || formShape.cartId === initial.cartId;
  if (!formShape.sameOrigin || !actionIsOrderConfirmation || formShape.freeOrder !== '1'
    || !cartQueryIsCompatible || formShape.method !== 'POST') {
    const cartQuery = formShape.cartId === '' ? 'absent' : (formShape.cartId === initial.cartId ? 'trusted' : 'mismatch');
    fail(`free-order: invalid Core action form [method=${formShape.method} path=${formShape.path} free_order=${formShape.freeOrder} cart_query=${cartQuery}].`);
  }

  await page.locator('[data-jzopc-checkout]').evaluate((root) => {
    root.addEventListener('jzopc:checkout:final-preflight-completed', () => { void window.jzopcFreeOrderTraceEvent('preflight'); });
    root.addEventListener('jzopc:checkout:payment-handoff', () => { void window.jzopcFreeOrderTraceEvent('handoff'); });
    root.addEventListener('jzopc:checkout:payment-submit-blocked', () => { void window.jzopcFreeOrderTraceEvent('blocked'); });
    root.addEventListener('jzopc:checkout:payment-handoff-ambiguous', () => { void window.jzopcFreeOrderTraceEvent('ambiguous'); });
  });

  const finalizationResponsePromise = page.waitForResponse((response) => (
    response.request().method() === 'POST'
      && /\/module\/jzonepagecheckout\/finalize\/?$/i.test(new URL(response.url()).pathname)
  ), { timeout: 15000 });

  const finalButton = page.locator('[data-jzopc-final-submit]');
  await finalButton.waitFor({ state: 'visible', timeout: 10000 });
  if (await finalButton.isDisabled()) {
    fail('free-order: final submit button is disabled before Core handoff.');
  }
  await finalButton.click();
  const finalizationResponse = await finalizationResponsePromise;
  if (finalizationResponse.status() >= 400) {
    fail(`free-order: finalization preflight failed with HTTP ${finalizationResponse.status()}.`);
  }

  await page.waitForURL((url) => isOrderConfirmation(url.toString()), { timeout: 30000 });
  if (trace.preflight < 1 || trace.handoff < 1 || trace.blocked !== 0 || trace.ambiguous !== 0) {
    fail(`free-order: invalid reserved Core handoff trace [preflight=${trace.preflight} handoff=${trace.handoff} blocked=${trace.blocked} ambiguous=${trace.ambiguous}].`);
  }

  const confirmed = new URL(page.url());
  const cartId = confirmed.searchParams.get('id_cart') || '';
  const orderId = confirmed.searchParams.get('id_order') || '';
  const moduleId = confirmed.searchParams.get('id_module') || '';
  if (cartId !== initial.cartId || !/^[1-9]\d*$/.test(orderId) || moduleId !== '-1') {
    fail(`free-order: invalid Core confirmation identity [cart=${cartId || '<missing>'} order=${orderId || '<missing>'} module=${moduleId || '<missing>'}].`);
  }

  await page.reload({ waitUntil: 'domcontentloaded', timeout: 30000 });
  const refreshed = new URL(page.url());
  if (refreshed.searchParams.get('id_cart') !== cartId || refreshed.searchParams.get('id_order') !== orderId) {
    fail('free-order: confirmation refresh changed the Core cart/order identity.');
  }
  if (pageErrors.length > 0) {
    fail(`free-order: browser JavaScript error: ${pageErrors.join(' | ')}`);
  }

  process.stdout.write(`JZOPC_FREE_ORDER_CART_ID=${cartId}\n`);
  process.stdout.write(`JZOPC_FREE_ORDER_ID=${orderId}\n`);
  process.stdout.write(`Core free-order completion contract OK: cart=${cartId}, order=${orderId}\n`);
} finally {
  await context.close();
  await browser.close();
}