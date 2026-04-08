const { chromium } = require('./node_modules/playwright');
(async() => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1400, height: 1600 } });
  await page.goto('http://localhost:8081/documents/2170/edit', { waitUntil: 'networkidle' });
  const editText = page.locator('text=Edit Text').first();
  if (await editText.count()) {
    await editText.click().catch(() => {});
  }
  await page.waitForTimeout(2500);
  const data = await page.evaluate(() => {
    const rectObj = (el) => {
      if (!el) return null;
      const r = el.getBoundingClientRect();
      return { x: r.x, y: r.y, left: r.left, top: r.top, right: r.right, bottom: r.bottom, width: r.width, height: r.height };
    };
    const labels = Array.from(document.querySelectorAll('.annotation'));
    const targetText = labels.find(el => (el.textContent || '').includes('Address Change'));
    const checkboxes = labels.filter(el => el.classList.contains('auto-detected-drawn-checkbox'));
    const hits = [];
    if (targetText) {
      const r = targetText.getBoundingClientRect();
      const points = [
        [r.left + 4, r.top + 4],
        [r.left + 12, r.top + 12],
        [r.left + 24, r.top + 12],
        [r.left + 36, r.top + 12],
      ];
      for (const [x, y] of points) {
        const hit = document.elementFromPoint(x, y);
        hits.push({ x, y, hitTag: hit?.tagName || null, hitClass: hit?.className || null, hitText: (hit?.textContent || '').slice(0, 80) });
      }
    }
    return {
      targetText: targetText ? {
        text: (targetText.textContent || '').slice(0, 200),
        rect: rectObj(targetText),
        zIndex: getComputedStyle(targetText).zIndex,
        pointerEvents: getComputedStyle(targetText).pointerEvents,
        cursor: getComputedStyle(targetText).cursor,
      } : null,
      checkboxes: checkboxes.map(el => ({
        text: (el.textContent || '').slice(0, 80),
        rect: rectObj(el),
        zIndex: getComputedStyle(el).zIndex,
        pointerEvents: getComputedStyle(el).pointerEvents,
        cursor: getComputedStyle(el).cursor,
        html: el.outerHTML.slice(0, 500),
      })),
      hits,
    };
  });
  console.log(JSON.stringify(data, null, 2));
  await browser.close();
})().catch(err => { console.error(err); process.exit(1); });
