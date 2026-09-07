import { chromium } from 'playwright';

const baseUrl = String(process.env.JZOPC_BROWSER_BASE_URL || '').replace(/\/$/, '');
const productId = Number.parseInt(String(process.env.JZOPC_RUNTIME_PRODUCT_ID || ''), 10);

function fail(message) { throw new Error(message); }
if (!/^https?:\/\//i.test(baseUrl)) fail('JZOPC_BROWSER_BASE_URL must be an absolute HTTP(S) URL.');
if (!Number.isInteger(productId) || productId <= 0) fail('JZOPC_RUNTIME_PRODUCT_ID must be a positive integer.');

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();
const pageErrors = [];
const trace = { handoff: 0, ambiguous: 0, preflight: 0, locked: 0 };
page.on('pageerror', (error) => pageErrors.push(error instanceof Error ? error.message : String(error)));
await page.exposeFunction('jzopcAmbiguousTrace', (eventName) => {
  if (Object.prototype.hasOwnProperty.call(trace, eventName)) trace[eventName] += 1;
});

async function navigate(url, stage) {
  const before = pageErrors.length;
  const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
  if (!response || response.status() >= 400) fail(`${stage}: navigation failed.`);
  await page.waitForTimeout(75);
  if (pageErrors.length !== before) fail(`${stage}: browser error: ${pageErrors.slice(before).join(' | ')}`);
}

async function endpoint(attribute, stage) {
  const value = await page.locator('[data-jzopc-checkout]').getAttribute(attribute);
  if (!value) fail(`${stage}: missing ${attribute}.`);
  return new URL(value, baseUrl);
}

async function mutate(attribute, trigger, stage) {
  const target = await endpoint(attribute, stage);
  const responsePromise = page.waitForResponse((response) => {
    if (response.request().method() !== 'POST') return false;
    const actual = new URL(response.url());
    return actual.origin === target.origin && actual.pathname === target.pathname;
  }, { timeout: 15000 });
  await trigger();
  const response = await responsePromise;
  let payload;
  try { payload = await response.json(); } catch { fail(`${stage}: non-JSON response.`); }
  if (response.status() >= 500 || !payload || payload.success !== true) {
    const codes = Array.isArray(payload?.errors) ? payload.errors.map((error) => error?.code || '').filter(Boolean) : [];
    fail(`${stage}: mutation rejected [${codes.join(', ')}].`);
  }
  return payload;
}

async function fillIfEmpty(scope, selector, value) {
  const field = scope.locator(selector).first();
  if (await field.count() === 1 && await field.inputValue() === '') await field.fill(value);
}

async function completeIdentity() {
  const form = page.locator('[data-jzopc-identity-form="create"] form');
  await form.waitFor({ state: 'attached', timeout: 10000 });
  await fillIfEmpty(form, 'input[name="firstname"]', 'Runtime');
  await fillIfEmpty(form, 'input[name="lastname"]', 'Ambiguous');
  await fillIfEmpty(form, 'input[name="email"]', `jzopc.ambiguous.${Date.now()}.${Math.random().toString(16).slice(2)}@example.com`);
  for (const checkbox of await form.locator('input[type="checkbox"][required]').all()) if (!(await checkbox.isChecked())) await checkbox.check();
  await mutate('data-jzopc-identity-url', () => form.evaluate((node) => { node.noValidate = true; node.requestSubmit(); }), 'identity');
}

async function completeAddress() {
  const opener = page.locator('[data-jzopc-address-editor-open][data-jzopc-address-role="delivery"]').last();
  await mutate('data-jzopc-address-save-url', () => opener.click(), 'address-present');
  const editor = page.locator('[data-jzopc-address-editor][data-jzopc-address-role="delivery"]');
  await editor.waitFor({ state: 'attached', timeout: 10000 });
  const form = editor.locator('form');
  await fillIfEmpty(form, 'input[name="firstname"]', 'Runtime');
  await fillIfEmpty(form, 'input[name="lastname"]', 'Ambiguous');
  await fillIfEmpty(form, 'input[name="address1"]', '2 Ambiguous Payment Street');
  await fillIfEmpty(form, 'input[name="postcode"]', '10001');
  await fillIfEmpty(form, 'input[name="city"]', 'New York');
  await fillIfEmpty(form, 'input[name="alias"]', 'Ambiguous payment runtime');
  const state = form.locator('select[name="id_state"]');
  if (await state.count() === 1 && await state.isVisible() && await state.inputValue() === '') {
    const values = await state.locator('option').evaluateAll((options) => options.map((option) => option.value).filter(Boolean));
    if (values.length === 0) fail('address: state selector has no value.');
    await state.selectOption(values[0]);
  }
  for (const checkbox of await form.locator('input[type="checkbox"][required]').all()) if (!(await checkbox.isChecked())) await checkbox.check();
  await mutate('data-jzopc-address-save-url', () => form.evaluate((node) => { node.noValidate = true; node.requestSubmit(); }), 'address-save');
  const same = page.locator('[data-jzopc-section="addresses"] input[name="use_same_address"]');
  if (await same.count() === 1 && !(await same.isChecked())) await mutate('data-jzopc-address-url', () => same.check(), 'invoice-same');
}

async function selectCarrier() {
  const option = page.locator('[data-jzopc-section="delivery"] input[name="delivery_option"]').first();
  if (await option.count() !== 1) fail('carrier: no Core delivery option.');
  await mutate('data-jzopc-carrier-url', () => option.evaluate((node) => {
    node.checked = true;
    node.dispatchEvent(new Event('change', { bubbles: true }));
  }), 'carrier');
}

async function selectPayment() {
  const option = page.locator('[data-jzopc-section="payment"] input[name="payment-option"][data-module-name="ps_checkpayment"]');
  if (await option.count() !== 1) fail('payment: ps_checkpayment option missing.');
  await mutate('data-jzopc-payment-url', () => option.evaluate((node) => {
    node.checked = true;
    node.dispatchEvent(new Event('change', { bubbles: true }));
  }), 'payment');
  const selected = page.locator('[data-jzopc-section="payment"] input[name="payment-option"]:checked');
  const optionId = await selected.getAttribute('id');
  if (!optionId) fail('payment: selected option id missing.');
  const form = page.locator(`#pay-with-${optionId}-form form`);
  if (await form.count() !== 1) fail('payment: Core-presented form missing.');
  return { optionId, form };
}

async function approveAgreements() {
  const agreements = page.locator('[data-jzopc-section="agreements"] input[name="agreements[]"]');
  if (await agreements.count() === 0) return;
  await mutate('data-jzopc-agreements-url', () => agreements.evaluateAll((nodes) => {
    for (const node of nodes) node.checked = true;
    nodes[nodes.length - 1].dispatchEvent(new Event('change', { bubbles: true }));
  }), 'agreements');
}

function isValidation(urlString) {
  const url = new URL(urlString);
  return /\/module\/ps_checkpayment\/validation\/?$/i.test(url.pathname)
    || (url.searchParams.get('fc') === 'module' && url.searchParams.get('module') === 'ps_checkpayment' && url.searchParams.get('controller') === 'validation');
}

async function waitTrace(timeoutMs = 2500) {
  const deadline = Date.now() + timeoutMs;
  do {
    if (trace.preflight >= 1 && trace.handoff >= 1 && trace.ambiguous >= 1 && trace.locked >= 1) return { ...trace };
    await new Promise((resolve) => setTimeout(resolve, 20));
  } while (Date.now() < deadline);
  return { ...trace };
}

try {
  const cartUrl = new URL('/cart', baseUrl);
  cartUrl.searchParams.set('add', '1');
  cartUrl.searchParams.set('id_product', String(productId));
  cartUrl.searchParams.set('qty', '1');
  await navigate(cartUrl.toString(), 'cart-add');
  await navigate(`${baseUrl}/order`, 'checkout');

  const root = page.locator('[data-jzopc-checkout]');
  await root.waitFor({ state: 'attached', timeout: 10000 });
  const cartId = await root.getAttribute('data-jzopc-cart-id');
  if (!cartId || !/^[1-9]\d*$/.test(cartId)) fail('checkout: invalid cart binding.');

  await completeIdentity();
  await completeAddress();
  await selectCarrier();
  const { optionId, form } = await selectPayment();
  await approveAgreements();

  await root.evaluate((node) => {
    node.addEventListener('jzopc:checkout:final-preflight-completed', () => void window.jzopcAmbiguousTrace('preflight'));
    node.addEventListener('jzopc:checkout:payment-handoff', () => void window.jzopcAmbiguousTrace('handoff'));
    node.addEventListener('jzopc:checkout:payment-handoff-ambiguous', () => void window.jzopcAmbiguousTrace('ambiguous'));
    node.addEventListener('jzopc:checkout:payment-handoff-locked', () => void window.jzopcAmbiguousTrace('locked'));
  });

  await form.evaluate((node) => {
    if (typeof node.requestSubmit !== 'function') throw new Error('requestSubmit is required for the ambiguity runtime fixture.');
    window.__jzopcAmbiguousOriginalRequestSubmit = node.requestSubmit;
    node.requestSubmit = function () { throw new Error('JZOPC injected synchronous module handoff failure'); };
  });

  const finalizeResponsePromise = page.waitForResponse((response) => {
    if (response.request().method() !== 'POST') return false;
    return /\/module\/jzonepagecheckout\/finalize\/?$/i.test(new URL(response.url()).pathname);
  }, { timeout: 15000 });
  const escapedValidation = page.waitForRequest((request) => isValidation(request.url()), { timeout: 1500 }).catch(() => null);

  await page.locator('[data-jzopc-final-submit]').click();
  const finalizeResponse = await finalizeResponsePromise;
  if (finalizeResponse.status() >= 400) fail(`ambiguous-handoff: finalization returned HTTP ${finalizeResponse.status()}.`);
  if (await escapedValidation !== null) fail('ambiguous-handoff: validation request escaped after injected synchronous handoff failure.');

  const observed = await waitTrace();
  if (observed.preflight < 1 || observed.handoff < 1 || observed.ambiguous < 1 || observed.locked < 1) {
    fail(`ambiguous-handoff: incomplete lifecycle [preflight=${observed.preflight} handoff=${observed.handoff} ambiguous=${observed.ambiguous} locked=${observed.locked}].`);
  }

  const lockState = await root.evaluate((node) => ({
    ambiguous: node.getAttribute('data-jzopc-payment-handoff-ambiguous') || '',
    busy: node.getAttribute('aria-busy') || '',
    enabledControls: Array.from(node.querySelectorAll('button, input, select, textarea')).filter((control) => !control.disabled).length,
  }));
  if (lockState.ambiguous !== 'true' || lockState.busy !== 'true' || lockState.enabledControls !== 0) {
    fail(`ambiguous-handoff: checkout not fail-closed [ambiguous=${lockState.ambiguous} busy=${lockState.busy} enabled=${lockState.enabledControls}].`);
  }

  await form.evaluate((node) => {
    const original = window.__jzopcAmbiguousOriginalRequestSubmit;
    if (typeof original !== 'function') throw new Error('Original requestSubmit was not retained.');
    node.requestSubmit = original;
  });

  const retryValidation = page.waitForRequest((request) => isValidation(request.url()), { timeout: 600 }).catch(() => null);
  await form.evaluate((node) => node.requestSubmit());
  if (await retryValidation !== null) fail('ambiguous-handoff: locked checkout allowed a native payment retry.');

  const retryFinalize = page.waitForRequest((request) => /\/module\/jzonepagecheckout\/finalize\/?$/i.test(new URL(request.url()).pathname), { timeout: 600 }).catch(() => null);
  await page.locator('[data-jzopc-final-submit]').evaluate((node) => node.click());
  if (await retryFinalize !== null) fail('ambiguous-handoff: locked checkout allowed a second finalization request.');

  if (pageErrors.length !== 0) fail(`ambiguous-handoff: browser JavaScript error: ${pageErrors.join(' | ')}`);
  process.stdout.write(`JZOPC_AMBIGUOUS_CART_ID=${cartId}\n`);
  process.stdout.write(`Ambiguous native payment handoff contract OK: cart=${cartId}, option=${optionId}, reservation retained for server verification\n`);
} finally {
  await context.close();
  await browser.close();
}
