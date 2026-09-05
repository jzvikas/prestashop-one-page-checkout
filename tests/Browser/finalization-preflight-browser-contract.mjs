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

try {
  const cartUrl = new URL('/cart', baseUrl);
  cartUrl.searchParams.set('add', '1');
  cartUrl.searchParams.set('id_product', String(productId));
  cartUrl.searchParams.set('qty', '1');
  await navigate(cartUrl.toString(), 'core-cart-add');
  await navigate(`${baseUrl}/order`, 'incomplete-checkout');

  await page.locator('[data-jzopc-checkout]').waitFor({ state: 'attached', timeout: 10000 });

  const initial = await page.locator('[data-jzopc-checkout]').evaluate((root) => ({
    cartId: root.getAttribute('data-jzopc-cart-id') || '',
    stateVersion: root.getAttribute('data-jzopc-state-version') || '',
    csrfToken: root.getAttribute('data-jzopc-csrf-token') || '',
    finalizationUrl: root.getAttribute('data-jzopc-finalization-url') || '',
    reserved: root.getAttribute('data-jzopc-finalization-reserved') || '',
  }));

  if (!/^[1-9]\d*$/.test(initial.cartId) || !initial.stateVersion || !initial.csrfToken || !initial.finalizationUrl) {
    fail('incomplete-checkout: trusted finalization bootstrap is incomplete.');
  }
  if (initial.reserved !== '0') {
    fail('incomplete-checkout: a fresh cart unexpectedly starts with a finalization reservation.');
  }

  const result = await page.evaluate(async (binding) => {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    const submissionAttempt = Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');

    const body = new URLSearchParams();
    body.set('token', binding.csrfToken);
    body.set('cartId', binding.cartId);
    body.set('stateVersion', binding.stateVersion);
    body.set('submissionAttempt', submissionAttempt);
    body.set('finalizationAction', 'begin');

    const response = await fetch(binding.finalizationUrl, {
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
      // Asserted below as a contract failure rather than hiding a malformed server response.
    }

    return {
      status: response.status,
      payload,
    };
  }, initial);

  if (result.status >= 500) {
    fail(`premature-finalization: server failed with HTTP ${result.status}.`);
  }
  if (!result.payload || typeof result.payload !== 'object') {
    fail('premature-finalization: endpoint did not return the structured JSON contract.');
  }
  if (result.payload.success !== false) {
    fail('premature-finalization: incomplete checkout was not rejected fail-closed.');
  }

  const errors = Array.isArray(result.payload.errors) ? result.payload.errors : [];
  const codes = errors
    .map((error) => (error && typeof error.code === 'string' ? error.code : ''))
    .filter(Boolean);
  if (!codes.includes('customer_required')) {
    fail(`premature-finalization: expected customer_required, received [${codes.join(', ')}].`);
  }

  await navigate(`${baseUrl}/order`, 'post-rejection-reload');
  const after = await page.locator('[data-jzopc-checkout]').evaluate((root) => ({
    cartId: root.getAttribute('data-jzopc-cart-id') || '',
    reserved: root.getAttribute('data-jzopc-finalization-reserved') || '',
  }));

  if (after.cartId !== initial.cartId) {
    fail('post-rejection-reload: rejected finalization changed the active Core cart binding.');
  }
  if (after.reserved !== '0') {
    fail('post-rejection-reload: rejected preflight leaked a finalization reservation.');
  }
  if (pageErrors.length > 0) {
    fail(`premature-finalization: browser JavaScript error: ${pageErrors.join(' | ')}`);
  }

  process.stdout.write(
    `Active browser premature-finalization contract OK: cart=${initial.cartId}, rejection=customer_required, reservation=0\n`,
  );
} finally {
  await context.close();
  await browser.close();
}
