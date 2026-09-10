/**
 * One corpus, two readers — the browser half.
 *
 * tests/Conformance/tool-call-scenarios.json is replayed here through the chat
 * component and, in tests/Unit/ToolCallConformanceTest.php, through
 * ConversationRecorder. They are independent implementations of the same
 * protocol rules; every parity bug in this area came from asserting by hand
 * that they agree, which they repeatedly did not.
 *
 * MANUAL — run from the repository root:
 *   ~/.local/bin/pw test tests/Browser/conformance.spec.js
 *
 * Add a scenario to the JSON when a divergence is found; both suites pick it
 * up with no further wiring.
 */

const { test, expect } = require('@playwright/test');
const fs = require('fs');

const BOOT = 'file:///work/tests/Browser/harness.html';
const corpus = JSON.parse(fs.readFileSync('/work/tests/Conformance/tool-call-scenarios.json', 'utf8'));

for (const scenario of corpus.scenarios) {
  test(`conformance: ${scenario.name}`, async ({ page }) => {
    await page.goto(BOOT);
    await page.evaluate(() => {
      const el = document.querySelector('ai-bridge-chat');
      el.s.activeId = 1;
      el.s.conv = { id: 1, title: 'T', provider: 'claude', model: 'sonnet', mode: 'bridge' };
      el.assistant = { role: 'assistant', blocks: [] };
      el.s.messages = [el.assistant];
      el.s.streaming = true;
      el.renderAll({ stick: true });
    });

    const actual = await page.evaluate((events) => {
      const el = document.querySelector('ai-bridge-chat');
      const terminals = ['done', 'error', 'cancelled'];
      for (const e of events) el.handleEvent(e);
      // The terminal is part of the scenario. Calling finish() unconditionally
      // made it impossible to express a turn that ends in an error or a
      // cancellation, which is where several divergences lived.
      if (!events.some((e) => terminals.includes(e.event))) el.finish();

      // EVERY block, EVERY field — a comparison narrower than the thing it is
      // comparing is decoration, and this one used to check four fields of one
      // block type.
      const clean = (b) => {
        const out = {};
        for (const k of Object.keys(b).sort()) {
          if (k === '_open') continue;          // presentation-only
          if (k.startsWith('_')) continue;      // internal bookkeeping
          if (b[k] === undefined) continue;
          // An EMPTY object and an empty array are the same thing here, and
          // must be compared as such. PHP's `json_decode($json, true)` cannot
          // tell them apart, so the recorder holds `[]` where this side holds
          // `{}` — and each half of the corpus was quietly asserting against
          // its own representation. Thirteen scenarios carry `parameters: {}`,
          // and every one of them passed while comparing a different value on
          // each side: the corpus claims "every block, every field, both
          // readers", and that was the hole in it.
          out[k] = (b[k] && typeof b[k] === 'object' && !Array.isArray(b[k]) && Object.keys(b[k]).length === 0)
            ? []
            : b[k];
        }
        return out;
      };

      return el.assistant.blocks.map(clean);
    }, scenario.events);

    // The SAME normalisation on the expected side. Applying it to only one half
    // does not close the hole, it moves it: the corpus is written with `{}` for
    // a no-argument call, and the point is that both readers are compared
    // against one representation rather than each against its own.
    const emptyObject = (v) => v && typeof v === 'object' && !Array.isArray(v) && Object.keys(v).length === 0;
    const expected = scenario.expected.map((b) => {
      const out = {};
      for (const k of Object.keys(b).sort()) out[k] = emptyObject(b[k]) ? [] : b[k];
      return out;
    });

    expect(actual).toEqual(expected);
  });
}
