import { test, expect, type Page, type FrameLocator } from '@playwright/test';
import { readFileSync } from 'fs';
import { join } from 'path';

/**
 * The change review view (MCP Apps) inside a minimal host: the host answers the
 * view's JSON-RPC requests over postMessage like an MCP Apps host does, and proxies
 * tools/call to canned ReviewChange results. Needs no TYPO3, the server side is
 * covered by the functional tests.
 */

const viewHtml = readFileSync(join(__dirname, '../../../../Resources/Private/McpApps/ChangeReview.html'), 'utf8');

const pendingChange = {
  action: 'show',
  pending: true,
  canPublish: true,
  canDiscard: true,
  preview_url: 'https://example.com/news/live?ADMCMD_prev=abc',
  record: {
    table: 'tx_news_domain_model_news', uid: 100, tableLabel: 'News', title: 'Live news', state: 'unchanged', fields: [],
    relations: [{
      field: 'content_elements', label: 'Content elements', records: [{
        table: 'tt_content', uid: 300, tableLabel: 'Page Content', title: 'Changed header', state: 'changed',
        fields: [{ field: 'header', label: 'Header', before: 'Live header', after: 'Changed header' }],
        relations: [{
          field: 'assets', label: 'Media', records: [{
            table: 'sys_file_reference', uid: 400, tableLabel: 'File Reference', title: 'test.jpg', state: 'new',
            fields: [{ field: 'alternative', label: 'Alternative', before: '', after: 'Team photo' }], relations: [],
          }],
        }],
      }],
    }],
  },
};

const publishedChange = { ...pendingChange, action: 'publish', pending: false, canPublish: false, canDiscard: false, preview_url: undefined, frontend_url: 'https://example.com/news/live' };

async function mountView(page: Page, responses: Record<string, unknown>): Promise<FrameLocator> {
  await page.setContent('<!DOCTYPE html><html><body><iframe id="view" style="width:600px;height:600px;border:0"></iframe></body></html>');
  await page.evaluate(({ html, responses }) => {
    const w = window as any;
    w.hostCalls = [];
    const frame = document.getElementById('view') as HTMLIFrameElement;
    const reply = (id: number, result: unknown) => frame.contentWindow!.postMessage({ jsonrpc: '2.0', id, result }, '*');
    window.addEventListener('message', (event) => {
      if (event.source !== frame.contentWindow) return;
      const msg = event.data;
      w.hostCalls.push(msg);
      if (msg.method === 'ui/initialize') {
        reply(msg.id, {
          protocolVersion: '2026-01-26',
          hostInfo: { name: 'test-host', version: '1.0' },
          hostCapabilities: { openLinks: {}, serverTools: {}, updateModelContext: { text: {} } },
          hostContext: { theme: 'light', locale: 'en-US' },
        });
      } else if (msg.method === 'ui/notifications/initialized') {
        const result = { content: [{ type: 'text', text: JSON.stringify({ action: 'update', table: 'tt_content', uid: 300 }) }] };
        frame.contentWindow!.postMessage({ jsonrpc: '2.0', method: 'ui/notifications/tool-input', params: { arguments: { action: 'update', table: 'tt_content', uid: 300 } } }, '*');
        frame.contentWindow!.postMessage({ jsonrpc: '2.0', method: 'ui/notifications/tool-result', params: result }, '*');
      } else if (msg.method === 'tools/call') {
        const data = responses[msg.params.arguments.action];
        reply(msg.id, { content: [{ type: 'text', text: JSON.stringify(data) }], structuredContent: data });
      } else if (msg.id !== undefined) {
        reply(msg.id, {});
      }
    });
    frame.srcdoc = html;
  }, { html: viewHtml, responses });

  return page.frameLocator('#view');
}

const hostCalls = (page: Page) => page.evaluate(() => (window as any).hostCalls as any[]);

test('shows the change of the news with its changed content element and file reference', async ({ page }) => {
  const view = await mountView(page, { show: pendingChange });

  await expect(view.locator('h1')).toHaveText('Live news');
  await expect(view.getByText('Content elements')).toBeVisible();
  await expect(view.locator('del', { hasText: 'Live' })).toBeVisible();
  await expect(view.locator('ins', { hasText: 'Changed' })).toBeVisible();
  await expect(view.locator('ins', { hasText: 'Team photo' })).toBeVisible();
  await expect(view.locator('.badge.new')).toHaveText('New');

  const call = (await hostCalls(page)).find((msg) => msg.method === 'tools/call');
  expect(call.params).toEqual({ name: 'ReviewChange', arguments: { action: 'show', table: 'tt_content', uid: 300 } });
  expect((await hostCalls(page)).some((msg) => msg.method === 'ui/notifications/size-changed')).toBe(true);
});

test('opens the preview through the host', async ({ page }) => {
  const view = await mountView(page, { show: pendingChange });

  await view.getByRole('button', { name: /Preview/ }).click();

  await expect.poll(async () => (await hostCalls(page)).find((msg) => msg.method === 'ui/open-link')?.params)
    .toEqual({ url: pendingChange.preview_url });
});

test('publishes only after confirmation and tells the model', async ({ page }) => {
  const view = await mountView(page, { show: pendingChange, publish: publishedChange });

  const publish = view.locator('button[data-action="publish"]');
  await publish.click();
  await expect(publish).toHaveText('Publish live now?');
  expect((await hostCalls(page)).filter((msg) => msg.params?.arguments?.action === 'publish')).toHaveLength(0);

  await publish.click();

  await expect(view.getByRole('status')).toHaveText('Published. The change is live now.');
  await expect(view.locator('button[data-action="publish"]')).toHaveCount(0);
  await expect(view.getByRole('button', { name: /Open page/ })).toBeVisible();
  const modelContext = (await hostCalls(page)).find((msg) => msg.method === 'ui/update-model-context');
  expect(modelContext.params.content[0].text).toContain('published');
  expect(modelContext.params.content[0].text).toContain('tx_news_domain_model_news:100');
});

test('hides publishing when the user may not publish', async ({ page }) => {
  const view = await mountView(page, { show: { ...pendingChange, canPublish: false } });

  await expect(view.locator('h1')).toHaveText('Live news');
  await expect(view.locator('button[data-action="publish"]')).toHaveCount(0);
  await expect(view.locator('button[data-action="discard"]')).toBeVisible();
});
