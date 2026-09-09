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
  // It arrives as a stream block AND as the dedicated tool_call event. The
  // block survives — it keeps the CLI's tool_call_id, which is what results are
  // keyed by, and its own arguments.
  await ready(page);
  await feed(page, [
    { event: 'block_start', data: { block_index: 0, block_type: 'tool_call', tool_name: 'mcp__bridge__company_directory', tool_call_id: 't1' } },
    { event: 'block_delta', data: { block_index: 0, content: '{"name":"Jasper"}' } },
    { event: 'block_stop', data: { block_index: 0 } },
    { event: 'tool_call', data: { tool_name: 'company_directory', parameters: { name: 'Jasper' }, tool_call_id: 'mcp-1' } },
    { event: 'tool_result', data: { tool_call_id: 't1', result: 'Jasper Bauer' } },
  ]);

  const b = await blocks(page);
  expect(b).toHaveLength(1);
  expect(b[0].tool_call_id).toBe('t1');
  // The result finds it, which is the point of keeping that id.
  expect(b[0].result).toBe('Jasper Bauer');

  // Shown without the MCP namespace — display formatting, not data.
  const text = await page.evaluate(() =>
    document.querySelector('ai-bridge-chat').shadowRoot.querySelector('.messages').textContent);
  expect(text).toContain('company_directory');
  expect(text).not.toContain('mcp__bridge__');
});

test('the operator\'s own MCP tool is not claimed by a bridge frame', async ({ page }) => {
  await ready(page);
  await feed(page, [
    { event: 'block_start', data: { block_index: 0, block_type: 'tool_call', tool_name: 'mcp__playwright__navigate', tool_call_id: 'local' } },
    { event: 'block_delta', data: { block_index: 0, content: '{"url":"LOCAL"}' } },
    { event: 'block_stop', data: { block_index: 0 } },
    { event: 'block_start', data: { block_index: 1, block_type: 'tool_call', tool_name: 'mcp__bridge__navigate', tool_call_id: 'srv' } },
    { event: 'block_delta', data: { block_index: 1, content: '{"url":"SERVER"}' } },
    { event: 'block_stop', data: { block_index: 1 } },
    { event: 'tool_call', data: { tool_name: 'navigate', parameters: { url: 'SERVER' }, tool_call_id: 'mcp-1' } },
  ]);

  const b = await blocks(page);
  expect(b).toHaveLength(2);
  expect(b[0].parameters).toEqual({ url: 'LOCAL' });
  expect(b[1].parameters).toEqual({ url: 'SERVER' });
});

test('a frame arriving before the block STARTS still draws one call', async ({ page }) => {
  // PROTOCOL.md does not pin the order down, which is why the recorder
  // reconciles at persist. The live path searched only blocks that already
  // existed, so this drew the call twice live and once after a reload — the
  // worst shape a bug can take.
  await ready(page);
  await feed(page, [
    { event: 'tool_call', data: { tool_name: 'roll_dice', parameters: { n: 1 }, tool_call_id: 'mcp-1' } },
    { event: 'block_start', data: { block_index: 0, block_type: 'tool_call', tool_name: 'mcp__bridge__roll_dice', tool_call_id: 't1' } },
    { event: 'block_delta', data: { block_index: 0, content: '{"n":1}' } },
    { event: 'block_stop', data: { block_index: 0 } },
  ]);

  const b = await blocks(page);
  expect(b).toHaveLength(1);
  expect(b[0].tool_call_id).toBe('t1');
});

test('an unclosed tool block keeps its arguments when the next block opens', async ({ page }) => {
  // The recorder finalises here; the component did not, so the same stream gave
  // two different answers and the wrong one was the one seen first.
  await ready(page);
  await feed(page, [
    { event: 'block_start', data: { block_index: 0, block_type: 'tool_call', tool_name: 'Bash', tool_call_id: 't1' } },
    { event: 'block_delta', data: { block_index: 0, content: '{"command":"echo hi"}' } },
    { event: 'block_start', data: { block_index: 1, block_type: 'text' } },
    { event: 'block_delta', data: { block_index: 1, content: 'done' } },
    { event: 'block_stop', data: { block_index: 1 } },
  ]);

  const b = await blocks(page);
  expect(b[0].parameters).toEqual({ command: 'echo hi' });
});

test('a frame arriving before the block closes still draws one call', async ({ page }) => {
  await ready(page);
  await feed(page, [
    { event: 'block_start', data: { block_index: 0, block_type: 'tool_call', tool_name: 'mcp__bridge__roll_dice', tool_call_id: 't1' } },
    { event: 'tool_call', data: { tool_name: 'roll_dice', parameters: { n: 1 }, tool_call_id: 'mcp-1' } },
    { event: 'block_delta', data: { block_index: 0, content: '{"n":1}' } },
    { event: 'block_stop', data: { block_index: 0 } },
  ]);

  const b = await blocks(page);
  expect(b).toHaveLength(1);
  expect(b[0].parameters).toEqual({ n: 1 });
});

test('arguments cut off mid-stream are kept rather than shown as none', async ({ page }) => {
  await ready(page);
  await feed(page, [
    { event: 'block_start', data: { block_index: 0, block_type: 'tool_call', tool_name: 'Read', tool_call_id: 't1' } },
    { event: 'block_delta', data: { block_index: 0, content: '{"file_pa' } },
    { event: 'block_stop', data: { block_index: 0 } },
  ]);

  const b = await blocks(page);
  // A sibling field, not a `_raw` key inside parameters — a tool may genuinely
  // take an argument called `_raw`.
  expect(b[0].parameters).toEqual({});
  expect(b[0].parameters_raw).toBe('{"file_pa');
});

test('arguments survive a turn that is cancelled before the block closes', async ({ page }) => {
  // A turn killed mid-arguments never sends block_stop — that is what
  // truncated means — so decoding only there leaves the block showing "called
  // with no arguments".
  await ready(page);
  await feed(page, [
    { event: 'block_start', data: { block_index: 0, block_type: 'tool_call', tool_name: 'Bash', tool_call_id: 't1' } },
    { event: 'block_delta', data: { block_index: 0, content: '{"command":"echo hi"}' } },
    { event: 'error', data: { code: 'provider_error', message: 'died' } },
  ]);

  const b = await blocks(page);
  expect(b[0].parameters).toEqual({ command: 'echo hi' });
  expect(b[0]).not.toHaveProperty('text');
});

test('a server-declared tool the bridge runs locally is still drawn', async ({ page }) => {
  // execute:"local" tools are namespaced under the bridge but never send a
  // tool_call frame, so skipping blocks by namespace deleted exactly these.
  await ready(page);
  await feed(page, [
    { event: 'block_start', data: { block_index: 0, block_type: 'tool_call', tool_name: 'mcp__bridge__fetch_mail', tool_call_id: 't1' } },
    { event: 'block_delta', data: { block_index: 0, content: '{"box":"inbox"}' } },
    { event: 'block_stop', data: { block_index: 0 } },
    { event: 'tool_result', data: { tool_call_id: 't1', result: '12 messages' } },
  ]);

  const b = await blocks(page);
  expect(b).toHaveLength(1);
  expect(b[0].tool_name).toBe('mcp__bridge__fetch_mail');
  expect(b[0].parameters).toEqual({ box: 'inbox' });
  expect(b[0].result).toBe('12 messages');
});

test('a failed call with no output is still shown', async ({ page }) => {
  // An empty successful result has nothing to show; an empty FAILURE must not
  // vanish while its successful neighbours are drawn.
  await ready(page);
  await feed(page, [
    { event: 'tool_result', data: { tool_call_id: 'orphan', result: '', is_error: true } },
  ]);

  const html = await page.evaluate(() =>
    document.querySelector('ai-bridge-chat').shadowRoot.querySelector('.messages').innerHTML);
  expect(html).toContain('res err');
});

test('a result whose call arrived only as a tool_call frame finds its owner', async ({ page }) => {
  // With no stream block to claim, the frame stands alone — and its block must
  // still carry an id, or its result orphans.
  await ready(page);
  await feed(page, [
    { event: 'tool_call', data: { tool_name: 'roll_dice', parameters: { n: 1 }, tool_call_id: 'mcp-1' } },
    { event: 'tool_result', data: { tool_call_id: 'mcp-1', result: '17' } },
  ]);

  const b = await blocks(page);
  expect(b).toHaveLength(1);
  expect(b[0].result).toBe('17');
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
