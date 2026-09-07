import { chromium } from 'playwright';

const baseUrl = String(process.env.JZOPC_BROWSER_BASE_URL || '').replace(/\/$/, '');
const storageStatePath = String(process.env.JZOPC_AMBIGUOUS_STORAGE_STATE_PATH || '');
const expectedCartId = String(process.env.JZOPC_AMBIGUOUS_CART_ID || '');

function fail(message) { throw new Error(message); }
if (!/^https?:\/\//i.test(baseUrl)) fail('JZOPC_BROWSER_BASE_URL must be an absolute HTTP(S) URL.');
if (storageStatePath !== '/tmp/jzopc-ambiguous-browser-state.json') fail('Recovery requires the fixed ephemeral browser-state path.');
if (!/^[1-9]\d*$/.test(expectedCartId)) fail('JZOPC_AMBIGUOUS_CART_ID must be a positive integer.');

function isValidation(urlString) {
  const url = new URL(urlString);
  return /\/module\/ps_checkpayment\/validation\/?$/i.test(url.pathname)
    || (url.searchParams.get('fc') === 'module' && url.searchParams.get('module') === 'ps_checkpayment' && url.searchParams.get('controller') === 'validation');
}
function isOrderConfirmation(urlString) {
  const url = new URL(urlString);
  return /(?:^|\/)order-confirmation\/?$/i.test(url.pathname) || url.searchParams.get('controller') === 'order-confirmation';
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ storageState: storageStatePath });
const page = await context.newPage();
const pageErrors = [];
const trace = { preflight: 0, handoff: 0, ambiguous: 0, blocked: 0 };
page.on('pageerror', (error) => pageErrors.push(error instanceof Error ? error.message : String(error)));
await page.exposeFunction('jzopcRecoveryTrace', (eventName) => {
  if (Object.prototype.hasOwnProperty.call(trace, eventName)) trace[eventName] += 1;
});

try {
  const response = await page.goto(`${baseUrl}/order`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  if (!response || response.status() >= 400) fail('ttl-recovery: checkout navigation failed.');

  const root = page.locator('[data-jzopc-checkout]');
  await root.waitFor({ state: 'attached', timeout: 10000 });
  const binding = await root.evaluate((node) => ({
    cartId: node.getAttribute('data-jzopc-cart-id') || '',
    reserved: node.getAttribute('data-jzopc-finalization-reserved') || '',
    uncertain: node.getAttribute('data-jzopc-payment-handoff-ambiguous') || '',
    busy: node.getAttribute('aria-busy') || '',
  }));
  if (binding.cartId !== expectedCartId) fail(`ttl-recovery: expected cart ${expectedCartId}, got ${binding.cartId || '<missing>'}.`);
  if (binding.reserved !== '0') fail('ttl-recovery: expired reservation still renders as active.');
  if (binding.uncertain === 'true' || binding.busy === 'true') fail('ttl-recovery: fresh checkout document retained client ambiguity lock.');

  const selected = page.locator('[data-jzopc-section="payment"] input[name="payment-option"][data-module-name="ps_checkpayment"]:checked');
  if (await selected.count() !== 1) fail('ttl-recovery: canonical ps_checkpayment selection was not restored.');
  const agreements = page.locator('[data-jzopc-section="agreements"] input[name="agreements[]"]');
  if (await agreements.count() > 0 && await agreements.locator(':not(:checked)').count() !== 0) {
    fail('ttl-recovery: canonical legal approvals were not restored.');
  }

  await root.evaluate((node) => {
    node.addEventListener('jzopc:checkout:final-preflight-completed', () => void window.jzopcRecoveryTrace('preflight'));
    node.addEventListener('jzopc:checkout:payment-handoff', () => void window.jzopcRecoveryTrace('handoff'));
    node.addEventListener('jzopc:checkout:payment-handoff-ambiguous', () => void window.jzopcRecoveryTrace('ambiguous'));
    node.addEventListener('jzopc:checkout:payment-submit-blocked', () => void window.jzopcRecoveryTrace('blocked'));
  });

  const finalizeResponsePromise = page.waitForResponse((candidate) => {
    if (candidate.request().method() !== 'POST') return false;
    return /\/module\/jzonepagecheckout\/finalize\/?$/i.test(new URL(candidate.url()).pathname);
  }, { timeout: 15000 });
  const validationRequestPromise = page.waitForRequest((request) => isValidation(request.url()), { timeout: 15000 });

  await page.locator('[data-jzopc-final-submit]').click();
  const finalizeResponse = await finalizeResponsePromise;
  if (finalizeResponse.status() >= 400) fail(`ttl-recovery: finalization returned HTTP ${finalizeResponse.status()}.`);
  const validationRequest = await validationRequestPromise;
  if (validationRequest.method() !== 'POST') fail('ttl-recovery: native payment validation did not use POST.');

  await page.waitForURL((url) => isOrderConfirmation(url.toString()), { timeout: 30000 });
  if (trace.preflight < 1 || trace.handoff < 1 || trace.ambiguous !== 0 || trace.blocked !== 0) {
    fail(`ttl-recovery: invalid lifecycle [preflight=${trace.preflight} handoff=${trace.handoff} ambiguous=${trace.ambiguous} blocked=${trace.blocked}].`);
  }

  const confirmed = new URL(page.url());
  const cartId = confirmed.searchParams.get('id_cart') || '';
  const orderId = confirmed.searchParams.get('id_order') || '';
  if (cartId !== expectedCartId || !/^[1-9]\d*$/.test(orderId)) fail('ttl-recovery: Core confirmation does not match recovered cart/order.');
  if (pageErrors.length !== 0) fail(`ttl-recovery: browser JavaScript error: ${pageErrors.join(' | ')}`);

  process.stdout.write(`JZOPC_RECOVERED_ORDER_CART_ID=${cartId}\n`);
  process.stdout.write(`JZOPC_RECOVERED_ORDER_ID=${orderId}\n`);
  process.stdout.write(`Ambiguous reservation TTL recovery contract OK: cart=${cartId}, order=${orderId}\n`);
} finally {
  await context.close();
  await browser.close();
}
