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

async function readBinding(page, stage) {
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
  if (binding.reserved !== '0') {
    fail(`${stage}: fresh checkout unexpectedly reports an active finalization reservation.`);
  }

  return binding;
}

async function beginFinalization(page, binding) {
  return page.evaluate(async (trusted) => {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    const submissionAttempt = Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');

    const body = new URLSearchParams();
    body.set('token', trusted.csrfToken);
    body.set('cartId', trusted.cartId);
    body.set('stateVersion', trusted.stateVersion);
    body.set('submissionAttempt', submissionAttempt);
    body.set('finalizationAction', 'begin');

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
      // Asserted by the caller as a malformed structured response.
    }

    return {
      status: response.status,
      payload,
      submissionAttempt,
    };
  }, binding);
}

function assertCustomerRequired(result, stage) {
  if (result.status >= 500) {
    fail(`${stage}: server failed with HTTP ${result.status}.`);
  }
  if (!result.payload || typeof result.payload !== 'object') {
    fail(`${stage}: endpoint did not return structured JSON.`);
  }
  if (result.payload.success !== false) {
    fail(`${stage}: incomplete checkout was not rejected fail-closed.`);
  }

  const errors = Array.isArray(result.payload.errors) ? result.payload.errors : [];
  const codes = errors
    .map((error) => (error && typeof error.code === 'string' ? error.code : ''))
    .filter(Boolean);

  if (!codes.includes('customer_required')) {
    fail(`${stage}: expected customer_required, received [${codes.join(', ')}].`);
  }
  if (codes.includes('finalization_in_progress')) {
    fail(`${stage}: incomplete preflight acquired a competing reservation before validation completed.`);
  }
}

try {
  const cartUrl = new URL('/cart', baseUrl);
  cartUrl.searchParams.set('add', '1');
  cartUrl.searchParams.set('id_product', String(productId));
  cartUrl.searchParams.set('qty', '1');
  await navigate(pageA, cartUrl.toString(), 'core-cart-add');

  await Promise.all([
    navigate(pageA, `${baseUrl}/order`, 'tab-a-checkout'),
    navigate(pageB, `${baseUrl}/order`, 'tab-b-checkout'),
  ]);

  const [bindingA, bindingB] = await Promise.all([
    readBinding(pageA, 'tab-a-checkout'),
    readBinding(pageB, 'tab-b-checkout'),
  ]);

  if (bindingA.cartId !== bindingB.cartId) {
    fail(`concurrent-tabs: tabs do not share the same Core cart (${bindingA.cartId} !== ${bindingB.cartId}).`);
  }
  if (bindingA.stateVersion !== bindingB.stateVersion) {
    fail('concurrent-tabs: freshly loaded tabs disagree on authoritative checkout stateVersion.');
  }

  const [resultA, resultB] = await Promise.all([
    beginFinalization(pageA, bindingA),
    beginFinalization(pageB, bindingB),
  ]);

  if (resultA.submissionAttempt === resultB.submissionAttempt) {
    fail('concurrent-tabs: independent browser attempts unexpectedly reused the same submissionAttempt.');
  }

  assertCustomerRequired(resultA, 'tab-a-finalization');
  assertCustomerRequired(resultB, 'tab-b-finalization');

  await Promise.all([
    navigate(pageA, `${baseUrl}/order`, 'tab-a-post-rejection'),
    navigate(pageB, `${baseUrl}/order`, 'tab-b-post-rejection'),
  ]);

  const [afterA, afterB] = await Promise.all([
    readBinding(pageA, 'tab-a-post-rejection'),
    readBinding(pageB, 'tab-b-post-rejection'),
  ]);

  if (afterA.cartId !== bindingA.cartId || afterB.cartId !== bindingA.cartId) {
    fail('concurrent-tabs: rejected preflights changed the shared Core cart binding.');
  }
  if (afterA.reserved !== '0' || afterB.reserved !== '0') {
    fail('concurrent-tabs: rejected concurrent preflights leaked a finalization reservation.');
  }
  if (pageErrors.length > 0) {
    fail(`concurrent-tabs: browser JavaScript error: ${pageErrors.join(' | ')}`);
  }

  process.stdout.write(
    `Concurrent-tab finalization preflight contract OK: cart=${bindingA.cartId}, attempts=2, rejection=customer_required, reservation=0\n`,
  );
} finally {
  await context.close();
  await browser.close();
}
