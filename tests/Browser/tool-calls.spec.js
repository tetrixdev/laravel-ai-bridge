/**
 * How a live turn's tool calls render in <ai-bridge-chat>.
 *
 * MANUAL — see tests/Browser/README.md. Run from the repository root:
 *   ~/.local/bin/pw test tests/Browser/tool-calls.spec.js
 *
 * A tool that ran on the operator's own machine has no WebSocket tool_call
 * frame; its stream block is the only description of it that will ever exist.
 * The component used to skip those blocks outright, so a run of shell commands
 * appeared as nothing at all, and it had no handler for tool_result, so a
 * result could not have been drawn even if one arrived.
 */

const { test, expect } = require('@playwright/test');

const BOOT = 'file:///work/tests/Browser/harness.html';

/** Open a conversation so the transcript renders. */
async function ready(page) {
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
}

/** Feed the component stream events, as the SSE buffer would. */
async function feed(page, events) {
  await page.evaluate((evts) => {
    const el = document.querySelector('ai-bridge-chat');
    for (const e of evts) el.handleEvent(e);
    el.renderAll();
  }, events);
}

const blocks = (page) => page.evaluate(() =>
  document.querySelector('ai-bridge-chat').assistant.blocks);

test('a locally-run tool is drawn with its name and arguments', async ({ page }) => {
  await ready(page);
  await feed(page, [
    { event: 'block_start', data: { block_index: 0, block_type: 'tool_call', tool_name: 'Bash', tool_call_id: 't1' } },
    { event: 'block_delta', data: { block_index: 0, content: '{"command":"echo one"}' } },
    { event: 'block_stop', data: { block_index: 0 } },
  ]);

  const text = await page.evaluate(() =>
    document.querySelector('ai-bridge-chat').shadowRoot.querySelector('.messages').textContent);

  expect(text).toContain('Bash');
  expect(text).toContain('echo one');
  expect(await blocks(page)).toMatchObject([{ type: 'tool_call', tool_name: 'Bash', parameters: { command: 'echo one' } }]);
});

test('a result is attached to the call that produced it', async ({ page }) => {
  await ready(page);
  await feed(page, [
    { event: 'block_start', data: { block_index: 0, block_type: 'tool_call', tool_name: 'Bash', tool_call_id: 't1' } },
    { event: 'block_delta', data: { block_index: 0, content: '{"command":"echo one"}' } },
    { event: 'block_stop', data: { block_index: 0 } },
    { event: 'tool_result', data: { tool_call_id: 't1', result: 'one', is_error: false } },
  ]);

  const b = await blocks(page);
  // Attached to the call, not floating loose as a separate block.
  expect(b).toHaveLength(1);
  expect(b[0].result).toBe('one');

  const text = await page.evaluate(() =>
    document.querySelector('ai-bridge-chat').shadowRoot.querySelector('.messages').textContent);
  expect(text).toContain('one');
});

test('a failed result is marked as failed, not just prefixed', async ({ page }) => {
  await ready(page);
  await feed(page, [
    { event: 'block_start', data: { block_index: 0, block_type: 'tool_call', tool_name: 'Bash', tool_call_id: 't1' } },
    { event: 'block_delta', data: { block_index: 0, content: '{}' } },
    { event: 'block_stop', data: { block_index: 0 } },
    { event: 'tool_result', data: { tool_call_id: 't1', result: 'no such file', is_error: true } },
  ]);

  const errs = await page.evaluate(() =>
    document.querySelector('ai-bridge-chat').shadowRoot.querySelectorAll('.tool .res.err').length);
  expect(errs).toBe(1);
});

test('a run of tools can be counted and grouped by kind', async ({ page }) => {
  // The handover's acceptance case: "3 commands, 1 file read", not "4 tool calls".
  await ready(page);
  const evts = [];
  [['Bash', '{"command":"echo one"}'], ['Bash', '{"command":"echo two"}'],
   ['Bash', '{"command":"echo three"}'], ['Read', '{"file_path":"/note.txt"}']]
    .forEach(([name, args], i) => {
      evts.push({ event: 'block_start', data: { block_index: i, block_type: 'tool_call', tool_name: name, tool_call_id: `t${i}` } });
      evts.push({ event: 'block_delta', data: { block_index: i, content: args } });
      evts.push({ event: 'block_stop', data: { block_index: i } });
    });
  await feed(page, evts);

  const names = (await blocks(page)).map((b) => b.tool_name);
  expect(names).toEqual(['Bash', 'Bash', 'Bash', 'Read']);
});

test('a tool the server resolves is drawn once, not twice', async ({ page }) => {
  // It arrives as a stream block AND as the dedicated tool_call event.
  await ready(page);
  await feed(page, [
    { event: 'block_start', data: { block_index: 0, block_type: 'tool_call', tool_name: 'mcp__bridge__company_directory', tool_call_id: 't1' } },
    { event: 'block_delta', data: { block_index: 0, content: '{"name":"Jasper"}' } },
    { event: 'block_stop', data: { block_index: 0 } },
    { event: 'tool_call', data: { tool_name: 'company_directory', parameters: { name: 'Jasper' } } },
  ]);

  const b = await blocks(page);
  expect(b).toHaveLength(1);
  expect(b[0].tool_name).toBe('company_directory');
});

test('arguments cut off mid-stream are kept rather than shown as none', async ({ page }) => {
  await ready(page);
  await feed(page, [
    { event: 'block_start', data: { block_index: 0, block_type: 'tool_call', tool_name: 'Read', tool_call_id: 't1' } },
    { event: 'block_delta', data: { block_index: 0, content: '{"file_pa' } },
    { event: 'block_stop', data: { block_index: 0 } },
  ]);

  const b = await blocks(page);
  expect(b[0].parameters).toEqual({ _raw: '{"file_pa' });
});

test('a rate limit notice is recorded but not drawn into the answer', async ({ page }) => {
  await ready(page);
  await feed(page, [
    { event: 'rate_limit', data: { provider: 'claude', info: { status: 'allowed', utilization: 0.35 } } },
    { event: 'block_start', data: { block_index: 0, block_type: 'text' } },
    { event: 'block_delta', data: { block_index: 0, content: 'the answer' } },
    { event: 'block_stop', data: { block_index: 0 } },
  ]);

  const state = await page.evaluate(() => document.querySelector('ai-bridge-chat').s.rateLimit);
  expect(state).toMatchObject({ provider: 'claude' });

  const text = await page.evaluate(() =>
    document.querySelector('ai-bridge-chat').shadowRoot.querySelector('.messages').textContent);
  expect(text).toContain('the answer');
  expect(text).not.toContain('utilization');
});
