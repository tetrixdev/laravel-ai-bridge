import { test, expect } from '@playwright/test';

/**
 * The stall watchdog must not abandon a healthy turn.
 *
 * A tool running on the operator's own machine emits no stream events while it
 * works — `npm install`, a test run, a large `grep`. Any of those over the
 * watchdog's window is indistinguishable from a dead turn from the browser's
 * side, and the component used to declare the turn stalled: a false error, the
 * tool call drawn as unfinished for ever, and every later event dropped
 * including the answer the assistant went on to write.
 */

const HARNESS = 'file://' + process.cwd() + '/tests/Browser/harness.html';

/** Boot the component with a short watchdog and a scripted /status endpoint. */
async function boot(page, statusReplies) {
  await page.goto(HARNESS);
  await page.waitForFunction(() => !!document.querySelector('ai-bridge-chat')?.s);

  // AFTER the page has loaded, not in an init script: harness.html installs its
  // own `window.fetch` at parse time, which would replace anything set earlier —
  // and the component would then answer /status from the generic stub, look
  // finished, and the test would pass for entirely the wrong reason.
  return page.evaluate((replies) => {
    window.__statusCalls = 0;
    window.fetch = async (url) => {
      const u = String(url);
      if (u.includes('/status')) {
        const reply = replies[Math.min(window.__statusCalls, replies.length - 1)];
        window.__statusCalls++;
        if (reply === 'throw') throw new Error('network down');

        return {
          ok: reply !== 'error', status: reply === 'gone' ? 404 : (reply === 'error' ? 500 : 200),
          headers: { get: () => 'application/json' },
          json: async () => ({ status: reply }),
        };
      }

      return {
        ok: true, status: 200,
        headers: { get: () => 'application/json' },
        json: async () => (u.includes('/connections') ? { connections: [] } : { data: [] }),
      };
    };

    const el = document.querySelector('ai-bridge-chat');
    el.WATCHDOG_STEADY_MS = 60;
    el.s.streaming = true;
    el._activeRequestId = 'req_1';
    el.armWatchdog();
  }, statusReplies);
}

test('a long-running tool does not trip the stall watchdog', async ({ page }) => {
  await boot(page, ['streaming']);
  await page.waitForTimeout(500);

  const state = await page.evaluate(() => {
    const el = document.querySelector('ai-bridge-chat');

    return { error: el.s.error, streaming: el.s.streaming, checks: window.__statusCalls };
  });

  // It kept asking, and kept the turn.
  expect(state.checks).toBeGreaterThan(1);
  expect(state.error).toBeFalsy();
  expect(state.streaming).toBe(true);
});

test('a turn the server says is finished still ends the wait', async ({ page }) => {
  // The watchdog has to keep working — a re-arming loop that never gives up
  // would leave the UI on "Thinking" for ever, which is what it exists to stop.
  await boot(page, ['completed']);
  await page.waitForTimeout(400);

  const state = await page.evaluate(() => {
    const el = document.querySelector('ai-bridge-chat');

    return { error: el.s.error, streaming: el.s.streaming };
  });

  expect(state.error).toContain('stalled');
  expect(state.streaming).toBe(false);
});

test('a stream the server has never heard of ends the wait', async ({ page }) => {
  await boot(page, ['gone']);
  await page.waitForTimeout(400);

  expect(await page.evaluate(() => document.querySelector('ai-bridge-chat').s.error)).toContain('stalled');
});

test('an unanswerable status check gives up eventually, not immediately', async ({ page }) => {
  // A network blip is not evidence the turn died; a network that never answers
  // cannot re-arm for ever either.
  await boot(page, ['throw']);

  await page.waitForTimeout(150);
  expect(await page.evaluate(() => document.querySelector('ai-bridge-chat').s.error)).toBeFalsy();

  await page.waitForTimeout(600);
  expect(await page.evaluate(() => document.querySelector('ai-bridge-chat').s.error)).toContain('stalled');
});
