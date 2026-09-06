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
const pageA = await context.newPage();
const pageB = await context.newPage();
const pageErrors = [];

for (const [name, page] of [['tab-a', pageA], ['tab-b', pageB]]) {
  page.on('pageerror', (error) => {
    pageErrors.push(`${name}: ${error instanceof Error ? error.message : String(error)}`);
  });
}

async function navigate(page, url, stage) {
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

async function readBinding(page, stage, expectedReserved = '0') {
  await page.locator('[data-jzopc-checkout]').waitFor({ state: 'attached', timeout: 10000 });

  const binding = await page.locator('[data-jzopc-checkout]').evaluate((root) => ({
    cartId: root.getAttribute('data-jzopc-cart-id') || '',
    stateVersion: root.getAttribute('data-jzopc-state-version') || '',
    csrfToken: root.getAttribute('data-jzopc-csrf-token') || '',
    finalizationUrl: root.getAttribute('data-jzopc-finalization-url') || '',
    reserved: root.getAttribute('data-jzopc-finalization-reserved') || '',
  }));

  if (!/^[1-9]\d*$/.test(binding.cartId)) {
    fail(`${stage}: invalid cart binding.`);
  }
  if (!binding.stateVersion || !binding.csrfToken || !binding.finalizationUrl) {
    fail(`${stage}: trusted finalization bootstrap is incomplete.`);
  }
  if (binding.reserved !== expectedReserved) {
    fail(`${stage}: expected finalization reservation=${expectedReserved}, received ${binding.reserved || '<empty>'}.`);
  }

  return binding;
}

async function mutationEndpoint(page, attribute, stage) {
  const value = await page.locator('[data-jzopc-checkout]').getAttribute(attribute);
  if (!value) {
    fail(`${stage}: missing ${attribute}.`);
  }

  return new URL(value, baseUrl);
}

async function runMutation(page, attribute, trigger, stage) {
  const endpoint = await mutationEndpoint(page, attribute, stage);
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

async function completeGuestIdentity(page) {
  const form = page.locator('[data-jzopc-identity-form="create"] form');
  await form.waitFor({ state: 'attached', timeout: 10000 });

  const email = `jzopc.runtime.${Date.now()}.${Math.random().toString(16).slice(2)}@example.com`;
  await fillIfPresent(form, 'input[name="firstname"]', 'Runtime');
  await fillIfPresent(form, 'input[name="lastname"]', 'Checkout');
  await fillIfPresent(form, 'input[name="email"]', email);

  for (const checkbox of await form.locator('input[type="checkbox"][required]').all()) {
    if (!(await checkbox.isChecked())) {
      await checkbox.check();
    }
  }

  await runMutation(
    page,
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

async function completeDeliveryAddress(page) {
  const opener = page.locator('[data-jzopc-address-editor-open][data-jzopc-address-role="delivery"]').last();

  await runMutation(
    page,
    'data-jzopc-address-save-url',
    async () => opener.click(),
    'address-present',
  );

  const editor = page.locator('[data-jzopc-address-editor][data-jzopc-address-role="delivery"]');
  await editor.waitFor({ state: 'attached', timeout: 10000 });
  const form = editor.locator('form');
  if (await form.count() !== 1) {
    fail('address-save: expected exactly one Core address form.');
  }

  await fillIfPresent(form, 'input[name="firstname"]', 'Runtime');
  await fillIfPresent(form, 'input[name="lastname"]', 'Checkout');
  await fillIfPresent(form, 'input[name="address1"]', '1 Runtime Street');
  await fillIfPresent(form, 'input[name="postcode"]', '10001');
  await fillIfPresent(form, 'input[name="city"]', 'New York');
  await fillIfPresent(form, 'input[name="alias"]', 'Runtime address');

  const country = form.locator('select[name="id_country"]');
  if (await country.count() === 1 && await country.inputValue() === '') {
    fail('address-save: Core address form did not provide a default country.');
  }

  const state = form.locator('select[name="id_state"]');
  if (await state.count() === 1 && await state.isVisible() && await state.inputValue() === '') {
    const stateValues = await state.locator('option').evaluateAll((options) => (
      options.map((option) => option.value).filter((value) => value !== '')
    ));
    if (stateValues.length === 0) {
      fail('address-save: visible state selector has no selectable state.');
    }
    await state.selectOption(stateValues[0]);
  }

  for (const checkbox of await form.locator('input[type="checkbox"][required]').all()) {
    if (!(await checkbox.isChecked())) {
      await checkbox.check();
    }
  }

  await runMutation(
    page,
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
    await runMutation(
      page,
      'data-jzopc-address-url',
      async () => sameAddress.check(),
      'invoice-same-address',
    );
  }

  if (await page.locator('[data-jzopc-section="addresses"] input[name="id_address_invoice"]:checked').count() !== 1) {
    fail('address-save: checkout did not retain a selected invoice address.');
  }
}

async function selectCarrier(page) {
  const options = page.locator('[data-jzopc-section="delivery"] input[name="delivery_option"]');
  if (await options.count() === 0) {
    fail('carrier-selection: orderable physical checkout has no Core delivery option.');
  }

  const option = options.first();
  await runMutation(
    page,
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

async function selectCheckPayment(page) {
  const option = page.locator(
    '[data-jzopc-section="payment"] input[name="payment-option"][data-module-name="ps_checkpayment"]',
  );
  if (await option.count() !== 1) {
    fail(`payment-selection: expected exactly one ps_checkpayment option, found ${await option.count()}.`);
  }

  await runMutation(
    page,
    'data-jzopc-payment-url',
    async () => option.evaluate((node) => {
      node.checked = true;
      node.dispatchEvent(new Event('change', { bubbles: true }));
    }),
    'payment-selection',
  );

  const selectedModule = await page.locator(
    '[data-jzopc-section="payment"] input[name="payment-option"]:checked',
  ).getAttribute('data-module-name');
  if (selectedModule !== 'ps_checkpayment') {
    fail(`payment-selection: server refresh selected ${selectedModule || '<none>'} instead of ps_checkpayment.`);
  }
}

async function approveAgreements(page) {
  const agreements = page.locator('[data-jzopc-section="agreements"] input[name="agreements[]"]');
  const count = await agreements.count();
  if (count === 0) {
    return;
  }

  await runMutation(
    page,
    'data-jzopc-agreements-url',
    async () => agreements.evaluateAll((nodes) => {
      for (const node of nodes) {
        node.checked = true;
      }
      nodes[nodes.length - 1].dispatchEvent(new Event('change', { bubbles: true }));
    }),
    'agreements-selection',
  );

  if (await page.locator(
    '[data-jzopc-section="agreements"] input[name="agreements[]"]:not(:checked)',
  ).count() !== 0) {
    fail('agreements-selection: server refresh did not retain every required agreement.');
  }
}

async function prepareOrderableCheckout(page) {
  await completeGuestIdentity(page);
  await completeDeliveryAddress(page);
  await selectCarrier(page);
  await selectCheckPayment(page);
  await approveAgreements(page);
}

async function finalizationRequest(page, binding, action, submissionAttempt) {
  return page.evaluate(async ({ trusted, finalizationAction, attempt }) => {
    const body = new URLSearchParams();
    body.set('token', trusted.csrfToken);
    body.set('cartId', trusted.cartId);
    body.set('stateVersion', trusted.stateVersion);
    body.set('submissionAttempt', attempt);
    body.set('finalizationAction', finalizationAction);

    const response = await fetch(trusted.finalizationUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body.toString(),
    });

    let payload = null;
    try {
      payload = await response.json();
    } catch {
      // The caller treats a non-JSON result as a contract failure.
    }

    return { status: response.status, payload };
  }, { trusted: binding, finalizationAction: action, attempt: submissionAttempt });
}

function attemptId() {
  return Array.from(crypto.getRandomValues(new Uint8Array(16)), (value) => (
    value.toString(16).padStart(2, '0')
  )).join('');
}

function errorCodes(result) {
  return Array.isArray(result.payload?.errors)
    ? result.payload.errors.map((error) => error?.code || '').filter(Boolean)
    : [];
}

function assertStructured(result, stage) {
  if (result.status >= 500) {
    fail(`${stage}: server failed with HTTP ${result.status}.`);
  }
  if (!result.payload || typeof result.payload !== 'object') {
    fail(`${stage}: endpoint did not return structured JSON.`);
  }
}

try {
  const cartUrl = new URL('/cart', baseUrl);
  cartUrl.searchParams.set('add', '1');
  cartUrl.searchParams.set('id_product', String(productId));
  cartUrl.searchParams.set('qty', '1');
  await navigate(pageA, cartUrl.toString(), 'core-cart-add');

  await navigate(pageA, `${baseUrl}/order`, 'tab-a-checkout');
  const initial = await readBinding(pageA, 'tab-a-initial', '0');
  await prepareOrderableCheckout(pageA);

  await Promise.all([
    navigate(pageA, `${baseUrl}/order`, 'tab-a-orderable'),
    navigate(pageB, `${baseUrl}/order`, 'tab-b-orderable'),
  ]);

  const [bindingA, bindingB] = await Promise.all([
    readBinding(pageA, 'tab-a-orderable', '0'),
    readBinding(pageB, 'tab-b-orderable', '0'),
  ]);

  if (bindingA.cartId !== initial.cartId || bindingB.cartId !== initial.cartId) {
    fail('orderable-concurrency: identity/address preparation changed the shared Core cart.');
  }
  if (bindingA.stateVersion !== bindingB.stateVersion) {
    fail('orderable-concurrency: prepared tabs disagree on authoritative stateVersion.');
  }

  const attemptA = attemptId();
  const attemptB = attemptId();
  if (attemptA === attemptB) {
    fail('orderable-concurrency: independent attempt IDs unexpectedly collided.');
  }

  const [resultA, resultB] = await Promise.all([
    finalizationRequest(pageA, bindingA, 'begin', attemptA),
    finalizationRequest(pageB, bindingB, 'begin', attemptB),
  ]);
  assertStructured(resultA, 'tab-a-begin');
  assertStructured(resultB, 'tab-b-begin');

  const contenders = [
    { page: pageA, binding: bindingA, attempt: attemptA, result: resultA, name: 'tab-a' },
    { page: pageB, binding: bindingB, attempt: attemptB, result: resultB, name: 'tab-b' },
  ];
  const winners = contenders.filter(({ result }) => result.payload.success === true);
  const losers = contenders.filter(({ result }) => result.payload.success === false);

  if (winners.length !== 1 || losers.length !== 1) {
    fail(`orderable-concurrency: expected one reservation winner and one loser, got winners=${winners.length}, losers=${losers.length}.`);
  }

  const winner = winners[0];
  const loser = losers[0];
  const loserCodes = errorCodes(loser.result);
  if (!loserCodes.includes('finalization_in_progress')) {
    fail(`${loser.name}-begin: expected finalization_in_progress, received [${loserCodes.join(', ')}].`);
  }

  const replay = await finalizationRequest(winner.page, winner.binding, 'begin', winner.attempt);
  assertStructured(replay, `${winner.name}-idempotent-replay`);
  if (replay.payload.success !== true) {
    fail(`${winner.name}-idempotent-replay: exact winning attempt was not idempotent [${errorCodes(replay).join(', ')}].`);
  }

  const foreignRelease = await finalizationRequest(loser.page, loser.binding, 'release', loser.attempt);
  assertStructured(foreignRelease, `${loser.name}-foreign-release`);
  if (foreignRelease.payload.success !== true) {
    fail(`${loser.name}-foreign-release: foreign exact-attempt release request was not safely handled.`);
  }

  await Promise.all([
    navigate(pageA, `${baseUrl}/order`, 'tab-a-reserved'),
    navigate(pageB, `${baseUrl}/order`, 'tab-b-reserved'),
  ]);
  const [reservedA, reservedB] = await Promise.all([
    readBinding(pageA, 'tab-a-reserved', '1'),
    readBinding(pageB, 'tab-b-reserved', '1'),
  ]);
  if (reservedA.cartId !== initial.cartId || reservedB.cartId !== initial.cartId) {
    fail('orderable-concurrency: active reservation changed the shared Core cart.');
  }

  const releaseBinding = winner.page === pageA ? reservedA : reservedB;
  const exactRelease = await finalizationRequest(winner.page, releaseBinding, 'release', winner.attempt);
  assertStructured(exactRelease, `${winner.name}-exact-release`);
  if (exactRelease.payload.success !== true) {
    fail(`${winner.name}-exact-release: winning attempt could not release its own reservation [${errorCodes(exactRelease).join(', ')}].`);
  }

  await Promise.all([
    navigate(pageA, `${baseUrl}/order`, 'tab-a-released'),
    navigate(pageB, `${baseUrl}/order`, 'tab-b-released'),
  ]);
  await Promise.all([
    readBinding(pageA, 'tab-a-released', '0'),
    readBinding(pageB, 'tab-b-released', '0'),
  ]);

  if (pageErrors.length > 0) {
    fail(`orderable-concurrency: browser JavaScript error: ${pageErrors.join(' | ')}`);
  }

  process.stdout.write(
    `Orderable concurrent-tab finalization reservation contract OK: cart=${initial.cartId}, winner=${winner.name}, loser=${loser.name}, payment=ps_checkpayment, exact-release=1\n`,
  );
} finally {
  await context.close();
  await browser.close();
}
