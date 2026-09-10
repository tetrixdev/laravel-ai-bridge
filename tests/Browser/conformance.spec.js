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
      for (const e of events) el.handleEvent(e);
      el.finish();

      // Only the fields both implementations model.
      return el.assistant.blocks
        .filter((b) => b.type === 'tool_call')
        .map((b) => {
          const shape = {
            tool_name: b.tool_name ?? null,
            tool_call_id: b.tool_call_id ?? null,
            parameters: b.parameters ?? {},
          };
          if (b.parameters_raw !== undefined) shape.parameters_raw = b.parameters_raw;
          return shape;
        });
    }, scenario.events);

    const expected = scenario.expected.map((e) => {
      const shape = {
        tool_name: e.tool_name ?? null,
        tool_call_id: e.tool_call_id ?? null,
        parameters: e.parameters ?? {},
      };
      if (e.parameters_raw !== undefined) shape.parameters_raw = e.parameters_raw;
      return shape;
    });

    expect(actual).toEqual(expected);
  });
}
