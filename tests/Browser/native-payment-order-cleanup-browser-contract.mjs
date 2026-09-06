import { chromium } from 'playwright';

const baseUrl = String(process.env.JZOPC_BROWSER_BASE_URL || '').replace(/\/$/, '');
const productId = Number.parseInt(String(process.env.JZOPC_RUNTIME_PRODUCT_ID || ''), 10);

function fail(message) {
  throw new Error(message);
}

if (!/^https?:\/\//i.test(baseUrl)) {
  fail('JZOPC_BROWSER_BASE_URL must be an absolute HTTP(S) URL.');
}
if (!Number.isInteger(productId) || productId <= 0) {
  fail('JZOPC_RUNTIME_PRODUCT_ID must be a positive integer.');
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();
const pageErrors = [];

page.on('pageerror', (error) => {
  pageErrors.push(error instanceof Error ? error.message : String(error));
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
  if (!/^[1-9]\d*$/.test(result.cartId) || !result.stateVersion || !result.csrfToken) {
    fail(`${stage}: trusted checkout binding is incomplete.`);
  }
  if (result.reserved !== '0') {
    fail(`${stage}: checkout unexpectedly started with an active finalization reservation.`);
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
  const errorsBefore = pageErrors.length;
  const responsePromise = page.waitForResponse((response) => {
    if (response.request().method() !== 'POST') {
      return false;
    }
    const actual = new URL(response.url());
    return actual.origin === endpoint.origin && actual.pathname === endpoint.pathname;
  }, { timeout: 15000 });

  await trigger();
  const response = await responsePromise;
  let payload = null;
  try {
    payload = await response.json();
  } catch {
    fail(`${stage}: mutation response was not JSON.`);
  }
  if (response.status() >= 500) {
    fail(`${stage}: mutation failed with HTTP ${response.status()}.`);
  }
  if (!payload || payload.success !== true) {
    const codes = Array.isArray(payload?.errors)
      ? payload.errors.map((error) => error?.code || '').filter(Boolean)
      : [];
    fail(`${stage}: mutation was rejected [${codes.join(', ')}].`);
  }
  await page.waitForTimeout(75);
  if (pageErrors.length !== errorsBefore) {
    fail(`${stage}: browser JavaScript error: ${pageErrors.slice(errorsBefore).join(' | ')}`);
  }

  return payload;
}

async function fillIfPresent(scope, selector, value) {
  const field = scope.locator(selector);
  if (await field.count() === 0) {
    return;
  }
  if (await field.first().inputValue() === '') {
    await field.first().fill(value);
  }
}

async function completeGuestIdentity() {
  const form = page.locator('[data-jzopc-identity-form="create"] form');
  await form.waitFor({ state: 'attached', timeout: 10000 });
  const email = `jzopc.native-order.${Date.now()}.${Math.random().toString(16).slice(2)}@example.com`;
  await fillIfPresent(form, 'input[name="firstname"]', 'Runtime');
  await fillIfPresent(form, 'input[name="lastname"]', 'Order');
  await fillIfPresent(form, 'input[name="email"]', email);
  for (const checkbox of await form.locator('input[type="checkbox"][required]').all()) {
    if (!(await checkbox.isChecked())) {
      await checkbox.check();
    }
  }
  await runMutation(
    'data-jzopc-identity-url',
    async () => form.evaluate((node) => {
      node.noValidate = true;
      node.requestSubmit();
    }),
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
  await fillIfPresent(form, 'input[name="lastname"]', 'Order');
  await fillIfPresent(form, 'input[name="address1"]', '1 Native Payment Street');
  await fillIfPresent(form, 'input[name="postcode"]', '10001');
  await fillIfPresent(form, 'input[name="city"]', 'New York');
  await fillIfPresent(form, 'input[name="alias"]', 'Native payment runtime');

  const country = form.locator('select[name="id_country"]');
  if (await country.count() === 1 && await country.inputValue() === '') {
    fail('address-save: Core address form did not provide a default country.');
  }
  const state = form.locator('select[name="id_state"]');
  if (await state.count() === 1 && await state.isVisible() && await state.inputValue() === '') {
    const values = await state.locator('option').evaluateAll((options) => (
      options.map((option) => option.value).filter((value) => value !== '')
    ));
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
    async () => form.evaluate((node) => {
      node.noValidate = true;
      node.requestSubmit();
    }),
    'address-save',
  );
  await page.locator('[data-jzopc-section="addresses"] input[name="id_address_delivery"]:checked')
    .waitFor({ state: 'attached', timeout: 10000 });

  const sameAddress = page.locator('[data-jzopc-section="addresses"] input[name="use_same_address"]');
  if (await sameAddress.count() === 1 && !(await sameAddress.isChecked())) {
    await runMutation('data-jzopc-address-url', async () => sameAddress.check(), 'invoice-same-address');
  }
  if (await page.locator('[data-jzopc-section="addresses"] input[name="id_address_invoice"]:checked').count() !== 1) {
    fail('address-save: checkout did not retain a selected invoice address.');
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
  if (await page.locator('[data-jzopc-section="delivery"] input[name="delivery_option"]:checked').count() !== 1) {
    fail('carrier-selection: server refresh did not retain a selected Core delivery option.');
  }
}

async function selectCheckPayment() {
  const option = page.locator(
    '[data-jzopc-section="payment"] input[name="payment-option"][data-module-name="ps_checkpayment"]',
  );
  if (await option.count() !== 1) {
    fail(`payment-selection: expected exactly one ps_checkpayment option, found ${await option.count()}.`);
  }
  await runMutation(
    'data-jzopc-payment-url',
    async () => option.evaluate((node) => {
      node.checked = true;
      node.dispatchEvent(new Event('change', { bubbles: true }));
    }),
    'payment-selection',
  );
  const selected = page.locator('[data-jzopc-section="payment"] input[name="payment-option"]:checked');
  if (await selected.getAttribute('data-module-name') !== 'ps_checkpayment') {
    fail('payment-selection: server refresh did not retain ps_checkpayment.');
  }
  const optionId = await selected.getAttribute('id');
  if (!optionId || await page.locator(`#pay-with-${optionId}-form form`).count() !== 1) {
    fail('payment-selection: Core-presented ps_checkpayment form is unavailable.');
  }
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
  if (await page.locator('[data-jzopc-section="agreements"] input[name="agreements[]"]:not(:checked)').count() !== 0) {
    fail('agreements-selection: server refresh did not retain every required agreement.');
  }
}

function isOrderConfirmation(urlString) {
  const url = new URL(urlString);
  return /(?:^|\/)order-confirmation\/?$/i.test(url.pathname)
    || url.searchParams.get('controller') === 'order-confirmation';
}

function safePath(urlString) {
  try {
    return new URL(urlString).pathname || '/';
  } catch {
    return '<invalid>';
  }
}

function isCheckPaymentValidation(urlString) {
  const url = new URL(urlString);
  return /\/module\/ps_checkpayment\/validation\/?$/i.test(url.pathname)
    || (
      url.searchParams.get('fc') === 'module'
      && url.searchParams.get('module') === 'ps_checkpayment'
      && url.searchParams.get('controller') === 'validation'
    );
}

async function paymentHandoffShape() {
  const selected = page.locator('[data-jzopc-section="payment"] input[name="payment-option"]:checked');
  const optionId = await selected.getAttribute('id');
  if (!optionId) {
    return { optionId: '', method: '', actionPath: '', sameOrigin: false, marker: '', connected: false };
  }

  const form = page.locator(`#pay-with-${optionId}-form form`);
  if (await form.count() !== 1) {
    return { optionId, method: '', actionPath: '', sameOrigin: false, marker: '', connected: false };
  }

  return form.evaluate((node) => {
    let action = null;
    try {
      action = new URL(node.action, window.location.href);
    } catch {
      action = null;
    }

    return {
      optionId,
      method: String(node.method || '').toUpperCase(),
      actionPath: action ? action.pathname : '<invalid>',
      sameOrigin: action ? action.origin === window.location.origin : false,
      marker: node.getAttribute('data-jzopc-payment-action-form') || '',
      connected: node.isConnected,
    };
  }, optionId);
}

async function installSafeHandoffTrace() {
  await page.locator('[data-jzopc-checkout]').evaluate((root) => {
    const trace = {
      handoff: 0,
      blocked: 0,
      ambiguous: 0,
      preflight: 0,
    };
    window.__jzopcNativePaymentTrace = trace;
    root.addEventListener('jzopc:checkout:payment-handoff', () => { trace.handoff += 1; });
    root.addEventListener('jzopc:checkout:payment-submit-blocked', () => { trace.blocked += 1; });
    root.addEventListener('jzopc:checkout:payment-handoff-ambiguous', () => { trace.ambiguous += 1; });
    root.addEventListener('jzopc:checkout:final-preflight-completed', () => { trace.preflight += 1; });
  });
}

async function safeHandoffTrace() {
  return page.evaluate(() => {
    const trace = window.__jzopcNativePaymentTrace;
    if (!trace || typeof trace !== 'object') {
      return { handoff: 0, blocked: 0, ambiguous: 0, preflight: 0 };
    }
    return {
      handoff: Number(trace.handoff) || 0,
      blocked: Number(trace.blocked) || 0,
      ambiguous: Number(trace.ambiguous) || 0,
      preflight: Number(trace.preflight) || 0,
    };
  });
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
  await selectCheckPayment();
  await approveAgreements();

  const finalButton = page.locator('[data-jzopc-final-submit]');
  await finalButton.waitFor({ state: 'visible', timeout: 10000 });
  if (await finalButton.isDisabled()) {
    fail('native-payment-submit: final submit button is disabled before handoff.');
  }

  const beforeHandoff = await paymentHandoffShape();
  if (beforeHandoff.method !== 'POST' || beforeHandoff.marker !== '1' || !beforeHandoff.connected || !beforeHandoff.actionPath) {
    fail(
      `native-payment-submit: invalid action-only form shape [method=${beforeHandoff.method || '<missing>'}`
      + ` marker=${beforeHandoff.marker || '<missing>'} connected=${beforeHandoff.connected ? '1' : '0'}`
      + ` same_origin=${beforeHandoff.sameOrigin ? '1' : '0'} action_path=${beforeHandoff.actionPath || '<missing>'}].`,
    );
  }
  await installSafeHandoffTrace();

  const finalizationRequest = page.waitForResponse((response) => {
    if (response.request().method() !== 'POST') {
      return false;
    }
    const requestUrl = new URL(response.url());
    return /\/module\/jzonepagecheckout\/finalize\/?$/i.test(requestUrl.pathname);
  }, { timeout: 15000 });
  const validationRequestPromise = page.waitForRequest(
    (request) => isCheckPaymentValidation(request.url()),
    { timeout: 15000 },
  ).catch(() => null);
  const validationResponsePromise = page.waitForResponse(
    (response) => isCheckPaymentValidation(response.url()),
    { timeout: 15000 },
  ).catch(() => null);

  await finalButton.click();
  const preflightResponse = await finalizationRequest;
  if (preflightResponse.status() >= 400) {
    fail(`native-payment-submit: final preflight failed with HTTP ${preflightResponse.status()}.`);
  }
  const preflightPayload = await preflightResponse.json();
  if (!preflightPayload || preflightPayload.success !== true) {
    const codes = Array.isArray(preflightPayload?.errors)
      ? preflightPayload.errors.map((error) => error?.code || '').filter(Boolean)
      : [];
    fail(`native-payment-submit: final preflight was rejected [${codes.join(', ')}].`);
  }

  const validationRequest = await validationRequestPromise;
  if (!validationRequest) {
    const trace = await safeHandoffTrace();
    const afterHandoff = await paymentHandoffShape();
    fail(
      `native-payment-submit: ps_checkpayment validation request was not observed after successful preflight`
      + ` [handoff=${trace.handoff} blocked=${trace.blocked} ambiguous=${trace.ambiguous} preflight=${trace.preflight}`
      + ` method=${afterHandoff.method || '<missing>'} marker=${afterHandoff.marker || '<missing>'}`
      + ` connected=${afterHandoff.connected ? '1' : '0'} same_origin=${afterHandoff.sameOrigin ? '1' : '0'}`
      + ` action_path=${afterHandoff.actionPath || '<missing>'} final_path=${safePath(page.url())}].`,
    );
  }
  if (validationRequest.method() !== 'POST') {
    fail(`native-payment-submit: ps_checkpayment validation used unexpected method ${validationRequest.method()}.`);
  }

  const validationResponse = await validationResponsePromise;
  const validationStatus = validationResponse ? validationResponse.status() : 0;
  try {
    await page.waitForURL((url) => isOrderConfirmation(url.toString()), { timeout: 30000 });
  } catch {
    const trace = await safeHandoffTrace();
    fail(
      `native-payment-submit: validation request did not reach Core order confirmation`
      + ` [validation_status=${validationStatus || '<missing>'} handoff=${trace.handoff}`
      + ` blocked=${trace.blocked} ambiguous=${trace.ambiguous} preflight=${trace.preflight}`
      + ` final_path=${safePath(page.url())}].`,
    );
  }

  const confirmedUrl = new URL(page.url());
  const cartId = confirmedUrl.searchParams.get('id_cart') || '';
  const orderId = confirmedUrl.searchParams.get('id_order') || '';
  const moduleId = confirmedUrl.searchParams.get('id_module') || '';
  if (cartId !== initial.cartId) {
    fail(`native-payment-submit: order confirmation cart ${cartId || '<missing>'} does not match OPC cart ${initial.cartId}.`);
  }
  if (!/^[1-9]\d*$/.test(orderId) || !/^[1-9]\d*$/.test(moduleId)) {
    fail('native-payment-submit: Core order confirmation did not expose a positive order/module identity.');
  }
  if (pageErrors.length > 0) {
    fail(`native-payment-submit: browser JavaScript error: ${pageErrors.join(' | ')}`);
  }

  process.stdout.write(`JZOPC_NATIVE_ORDER_CART_ID=${cartId}\n`);
  process.stdout.write(`JZOPC_NATIVE_ORDER_ID=${orderId}\n`);
  process.stdout.write(`Native ps_checkpayment order completion contract OK: cart=${cartId}, order=${orderId}\n`);
} finally {
  await context.close();
  await browser.close();
}
