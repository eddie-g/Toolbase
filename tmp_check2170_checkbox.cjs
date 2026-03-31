const { chromium } = require('./node_modules/playwright');
(async() => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1400, height: 1600 } });
  await page.goto('http://localhost:8081/documents/2170/edit', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');
  const editText = page.locator('text=Edit Text').first();
  if (await editText.count()) {
    await editText.click().catch(() => {});
  }
  await page.waitForSelector('.annotation', { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(3000);
  const data = await page.evaluate(() => {
    const rectObj = (el) => {
      if (el == null) return null;
      const r = el.getBoundingClientRect();
      return { left: r.left, top: r.top, right: r.right, bottom: r.bottom, width: r.width, height: r.height };
    };
    const labels = Array.from(document.querySelectorAll('.annotation'));
    const targetText = labels.find((el) => (el.textContent || '').includes('Address Change'));
    const checkboxes = labels.filter((el) => el.classList.contains('auto-detected-drawn-checkbox'));
    const hits = [];
    if (targetText) {
      const r = targetText.getBoundingClientRect();
      for (let dx = 2; dx <= Math.min(50, Math.max(2, r.width - 2)); dx += 8) {
        const x = r.left + dx;
        const y = r.top + Math.min(12, Math.max(2, r.height / 2));
        const hit = document.elementFromPoint(x, y);
        hits.push({ x, y, hitTag: hit ? hit.tagName : null, hitClass: hit ? hit.className : null, hitText: hit ? (hit.textContent || '').slice(0, 80) : null });
      }
    }
    return {
      annotationCount: labels.length,
      targetText: targetText ? {
        text: (targetText.textContent || '').slice(0, 200),
        rect: rectObj(targetText),
        zIndex: getComputedStyle(targetText).zIndex,
        pointerEvents: getComputedStyle(targetText).pointerEvents,
        cursor: getComputedStyle(targetText).cursor,
        classes: targetText.className,
      } : null,
      checkboxes: checkboxes.map((el) => ({
        text: (el.textContent || '').slice(0, 80),
        rect: rectObj(el),
        zIndex: getComputedStyle(el).zIndex,
        pointerEvents: getComputedStyle(el).pointerEvents,
        cursor: getComputedStyle(el).cursor,
        classes: el.className,
      })),
      hits,
    };
  });
  console.log(JSON.stringify(data, null, 2));
  await browser.close();
})().catch((err) => { console.error(err); process.exit(1); });
