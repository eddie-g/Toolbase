const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1600, height: 2000 } });
  // Login
  await page.goto('http://localhost:8081/admin/login');
  await page.fill('#data\\.email', 'eddie.gray.biz@gmail.com');
  await page.fill('#data\\.password', 'codex-test-admin-2861');
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');
  // Upload f1040s1.pdf
  await page.goto('http://localhost:8081/pdf-editor');
  const fileInput = await page.locator('input[type=file]').first();
  await fileInput.setInputFiles('/home/wolf/Toolbase/tests/OverlayEditor/f1040s1.pdf');
  await page.waitForTimeout(3000);
  // Find the doc id from URL
  await page.waitForURL(/documents\/\d+\/edit/);
  const url = page.url();
  const docId = url.match(/documents\/(\d+)/)[1];
  console.log('docId=', docId);
  await page.goto(`http://localhost:8081/documents/${docId}/edit-new`);
  await page.waitForTimeout(4000);
  // Probe annotations
  const probe = await page.evaluate(() => {
    const st = window.__editorTestState;
    if (!st) return { error: 'no bridge' };
    st.setEditModeEnabled(true);
    const data = st.pageData[0];
    const targets = ['promoted_1_46_66', 'promoted_1_5_13', 'promoted_1_8_16', 'promoted_1_0_0'];
    const out = [];
    for (const uid of targets) {
      const ann = data.annotations.find(a => a._uid === uid);
      if (!ann) { out.push({uid, missing: true}); continue; }
      st.selectAnnotation(ann, 0);
      const ae = document.getElementById('ae-1');
      ae.dataset.editing = '1';
      ae.dataset.editingUid = uid;
      ae.focus();
      // Trigger sync
      const fn = window.__syncActiveEditor || null;
      // Just look at what's there
      out.push({
        uid,
        renderMode: ae.dataset.renderMode,
        canvasOwns: ae.dataset.canvasOwns,
        innerHTMLLen: ae.innerHTML.length,
        innerHTMLStart: ae.innerHTML.slice(0, 200),
        promotedFromExtraction: !!ann.promotedFromExtraction,
        userAuthored: !!ann._userAuthored,
        sourceSpansLen: (ann.sourceSpans || []).length,
        sourceLineBBoxesLen: (ann.sourceLineBBoxes || []).length,
      });
    }
    return out;
  });
  console.log(JSON.stringify(probe, null, 2));
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
