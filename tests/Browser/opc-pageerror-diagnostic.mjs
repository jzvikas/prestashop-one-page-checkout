import { chromium } from 'playwright';

const baseUrl = String(process.env.JZOPC_BROWSER_BASE_URL || '').replace(/\/$/, '');
const productId = Number.parseInt(String(process.env.JZOPC_RUNTIME_PRODUCT_ID || ''), 10);
if (!/^https?:\/\//i.test(baseUrl) || !Number.isInteger(productId) || productId <= 0) {
  throw new Error('OPC pageerror diagnostic requires the runtime base URL and product ID.');
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();
const errors = [];

page.on('pageerror', (error) => {
  errors.push(error instanceof Error && error.stack ? error.stack : String(error));
});

try {
  const cartUrl = new URL('/cart', baseUrl);
  cartUrl.searchParams.set('add', '1');
  cartUrl.searchParams.set('ajax', '1');
  cartUrl.searchParams.set('id_product', String(productId));
  cartUrl.searchParams.set('qty', '1');
  const cartResponse = await page.goto(cartUrl.toString(), { waitUntil: 'domcontentloaded', timeout: 30000 });
  if (!cartResponse || cartResponse.status() >= 400) {
    throw new Error(`Core Ajax cart setup failed with HTTP ${cartResponse ? cartResponse.status() : 'none'}.`);
  }

  errors.length = 0;
  const orderResponse = await page.goto(`${baseUrl}/order`, { waitUntil: 'networkidle', timeout: 30000 });
  if (!orderResponse || orderResponse.status() >= 400) {
    throw new Error(`OPC /order navigation failed with HTTP ${orderResponse ? orderResponse.status() : 'none'}.`);
  }

  const diagnostics = await page.evaluate(() => ({
    hasJQuery: typeof window.jQuery !== 'undefined',
    hasDollar: typeof window.$ !== 'undefined',
    scripts: Array.from(document.scripts).map((script, index) => ({
      index,
      src: script.src || '',
      type: script.type || '',
      defer: script.defer,
      async: script.async,
      inlinePreview: script.src ? '' : (script.textContent || '').trim().slice(0, 180),
    })),
  }));

  process.stdout.write(`OPC browser diagnostics: ${JSON.stringify(diagnostics)}\n`);
  if (errors.length > 0) {
    process.stderr.write(`OPC page errors:\n${errors.join('\n---\n')}\n`);
    process.exitCode = 1;
  } else {
    process.stdout.write('OPC pageerror diagnostic completed without browser exceptions.\n');
  }
} finally {
  await context.close();
  await browser.close();
}
