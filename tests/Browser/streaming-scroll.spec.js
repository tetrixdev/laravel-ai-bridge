/**
 * Streaming render behaviour of <ai-bridge-chat>, in a real browser.
 *
 * MANUAL — not part of `composer test`. The component is a hand-maintained
 * bundle with no build step, so these exist because there was nothing else that
 * could catch a scroll regression before a user did.
 *
 *   ~/.local/bin/pw test tests/Browser/streaming-scroll.spec.js   # from the repo root
 *
 * Why it is worth the trouble: the bridge now streams ~35 deltas per answer
 * instead of ~3, and each one re-renders the whole transcript. Every assertion
 * here was checked by reintroducing the bug it guards against.
 */
const { test, expect } = require('@playwright/test');

const BOOT = 'file:///work/tests/Browser/harness.html';

/** Give the component a long transcript so .messages actually scrolls. */
async function seed(page, count = 40) {
  await page.evaluate((n) => {
    const el = document.querySelector('ai-bridge-chat');
    el.s.activeId = 1;
    el.s.conv = { id: 1, title: 'Test', provider: 'claude', model: 'sonnet', mode: 'bridge' };
    el.s.messages = Array.from({ length: n }, (_, i) => ({
      role: i % 2 ? 'assistant' : 'user',
      blocks: [{ type: 'text', text: `message ${i} ` + 'padding '.repeat(30) }],
    }));
    el.renderAll({ stick: true });
  }, count);
}

const metrics = (page) => page.evaluate(() => {
  const m = document.querySelector('ai-bridge-chat').shadowRoot.querySelector('.messages');
  return { top: m.scrollTop, height: m.scrollHeight, client: m.clientHeight };
});

test('a reader who scrolled up stays put while the answer streams', async ({ page }) => {
  await page.goto(BOOT);
  await seed(page);

  // Scroll up into the middle of the transcript.
  await page.evaluate(() => {
    const el = document.querySelector('ai-bridge-chat');
    el.shadowRoot.querySelector('.messages').scrollTop = 300;
    // Open an assistant block, as a live turn would.
    el.assistant = { role: 'assistant', blocks: [] };
    el.s.messages.push(el.assistant);
    el.s.streaming = true;
  });

  const before = await metrics(page);
  expect(before.top).toBe(300);

  // 30 deltas, as a long partial-streamed answer produces.
  await page.evaluate(async () => {
    const el = document.querySelector('ai-bridge-chat');
    el.handleEvent({ event: 'block_start', data: { block_index: 0, block_type: 'text' } });
    for (let i = 0; i < 30; i++) {
      el.handleEvent({ event: 'block_delta', data: { block_index: 0, content: `chunk ${i} ` } });
      await new Promise((r) => requestAnimationFrame(r));
    }
  });

  const after = await metrics(page);
  // The transcript grew below the viewport, so the reader's view must not move.
  expect(after.height).toBeGreaterThan(before.height);
  expect(after.top).toBe(300);
});

test('a reader at the bottom keeps following the stream', async ({ page }) => {
  await page.goto(BOOT);
  await seed(page);

  await page.evaluate(async () => {
    const el = document.querySelector('ai-bridge-chat');
    el.assistant = { role: 'assistant', blocks: [] };
    el.s.messages.push(el.assistant);
    el.s.streaming = true;
    el.handleEvent({ event: 'block_start', data: { block_index: 0, block_type: 'text' } });
    for (let i = 0; i < 20; i++) {
      el.handleEvent({ event: 'block_delta', data: { block_index: 0, content: `chunk ${i} ` } });
      await new Promise((r) => requestAnimationFrame(r));
    }
  });

  const m = await metrics(page);
  expect(m.height - m.top - m.client).toBeLessThan(40);
});

test('renders are coalesced, not one per delta', async ({ page }) => {
  await page.goto(BOOT);
  await seed(page);

  const renders = await page.evaluate(async () => {
    const el = document.querySelector('ai-bridge-chat');
    let n = 0;
    const real = el.renderAll.bind(el);
    el.renderAll = (o) => { n++; return real(o); };
    el.assistant = { role: 'assistant', blocks: [] };
    el.s.messages.push(el.assistant);
    el.handleEvent({ event: 'block_start', data: { block_index: 0, block_type: 'text' } });
    for (let i = 0; i < 30; i++) el.handleEvent({ event: 'block_delta', data: { block_index: 0, content: 'x' } });
    await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
    return n;
  });

  // 31 events synchronously -> a single coalesced render.
  expect(renders).toBe(1);
});

test('the final state renders even if the tab never gets an animation frame', async ({ page }) => {
  await page.goto(BOOT);
  await seed(page);

  const text = await page.evaluate(async () => {
    const el = document.querySelector('ai-bridge-chat');
    el.assistant = { role: 'assistant', blocks: [] };
    el.s.messages.push(el.assistant);
    el.s.streaming = true;
    // Suppress frames entirely, mimicking a backgrounded tab.
    const rAF = window.requestAnimationFrame;
    window.requestAnimationFrame = () => 1;
    el.handleEvent({ event: 'block_start', data: { block_index: 0, block_type: 'text' } });
    el.handleEvent({ event: 'block_delta', data: { block_index: 0, content: 'FINAL-ANSWER-7781' } });
    el.handleEvent({ event: 'done', data: {} });
    window.requestAnimationFrame = rAF;
    return el.shadowRoot.querySelector('.messages').textContent;
  });

  expect(text).toContain('FINAL-ANSWER-7781');
});

test('sending scrolls to your own message even after scrolling up', async ({ page }) => {
  await page.goto(BOOT);
  await seed(page);

  const m = await page.evaluate(() => {
    const el = document.querySelector('ai-bridge-chat');
    el.shadowRoot.querySelector('.messages').scrollTop = 200;
    // The render send() performs once the message is appended.
    el.s.messages.push({ role: 'user', blocks: [{ type: 'text', text: 'MY-NEW-MESSAGE' }] });
    el.renderAll({ stick: true });
    const s = el.shadowRoot.querySelector('.messages');
    return { top: s.scrollTop, height: s.scrollHeight, client: s.clientHeight };
  });

  expect(m.height - m.top - m.client).toBeLessThan(40);
});
