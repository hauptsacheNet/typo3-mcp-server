import { test, expect } from '../fixtures/setup-fixtures';
import type { FrameLocator } from '@playwright/test';

test.describe('MCP Server Backend Module', () => {
  let frame: FrameLocator;

  test.beforeEach(async ({ page, backend }) => {
    await backend.gotoMcpModule();
    frame = page.frameLocator('#typo3-contentIframe');
    await expect(frame.locator('.module-body')).toBeVisible();
  });

  test('module page loads with expected sections', async () => {
    await expect(frame.locator('#mcpSetupTabs')).toBeVisible();
    await expect(frame.locator('#tokens-container')).toBeVisible();
  });

  test('tab navigation works', async () => {
    await frame.locator('#local-mcp-remote-tab').click();
    await expect(frame.locator('#local-mcp-remote')).toBeVisible();

    await frame.locator('#local-cli-tab').click();
    await expect(frame.locator('#local-cli')).toBeVisible();

    await frame.locator('#remote-setup-tab').click();
    await expect(frame.locator('#remote-setup')).toBeVisible();
  });

  test('create n8n token shows modal with plain token', async ({ page }) => {
    await frame.locator('#n8n-pill').click();
    await expect(frame.locator('#n8n')).toBeVisible();

    const createBtn = frame.locator('#create-n8n-token-btn');
    // Skip if token already exists (from a previous test run)
    test.skip(!(await createBtn.isVisible({ timeout: 3000 }).catch(() => false)),
      'n8n token already exists — run with clean DB');

    await createBtn.click();

    // Modal renders in the top frame (TYPO3 Modal API appends to top document)
    const modal = page.locator('.modal');
    await expect(modal).toBeVisible({ timeout: 15000 });
    await expect(modal.locator('.modal-title')).toContainText('Token Created');
    await expect(modal.locator('.alert-warning')).toContainText('only be shown once');

    const tokenInput = modal.locator('#modal-token-value');
    await expect(tokenInput).toBeVisible();
    const tokenValue = await tokenInput.inputValue();
    expect(tokenValue).toMatch(/^[0-9a-f]{64}$/);

    await expect(modal.locator('button', { hasText: 'Copy' })).toBeVisible();
    await modal.locator('button', { hasText: 'I have copied the token' }).click();
  });

  test('create mcp-remote token shows modal', async ({ page }) => {
    await frame.locator('#local-mcp-remote-tab').click();
    await expect(frame.locator('#local-mcp-remote')).toBeVisible();

    const createBtn = frame.locator('#create-mcp-remote-token-btn');
    test.skip(!(await createBtn.isVisible({ timeout: 3000 }).catch(() => false)),
      'mcp-remote token already exists — run with clean DB');

    await createBtn.click();

    const modal = page.locator('.modal');
    await expect(modal).toBeVisible({ timeout: 15000 });
    await expect(modal.locator('.modal-title')).toContainText('Token Created');

    const tokenInput = modal.locator('#modal-token-value');
    await expect(tokenInput).toBeVisible();
    const tokenValue = await tokenInput.inputValue();
    expect(tokenValue).toMatch(/^[0-9a-f]{64}$/);

    await modal.locator('button', { hasText: 'I have copied the token' }).click();
  });

  test('create manus token and verify in token list', async ({ page }) => {
    await frame.locator('#manus-pill').click();
    await expect(frame.locator('#manus')).toBeVisible();

    const createBtn = frame.locator('#create-manus-token-btn');
    test.skip(!(await createBtn.isVisible({ timeout: 3000 }).catch(() => false)),
      'manus token already exists — run with clean DB');

    await createBtn.click();

    const modal = page.locator('.modal');
    await expect(modal).toBeVisible({ timeout: 15000 });

    await modal.locator('button', { hasText: 'I have copied the token' }).click();
    await page.waitForLoadState('networkidle');

    // Re-acquire frame after reload
    frame = page.frameLocator('#typo3-contentIframe');
    const tokensContainer = frame.locator('#tokens-container');
    await expect(tokensContainer.locator('table')).toBeVisible({ timeout: 10000 });
    await expect(tokensContainer.locator('td', { hasText: 'manus token' })).toBeVisible();
  });

  test('revoke token shows confirmation modal', async ({ page }) => {
    // Need existing tokens — check if any revoke buttons exist
    const revokeBtn = frame.locator('.revoke-token-btn').first();
    test.skip(!(await revokeBtn.isVisible({ timeout: 3000 }).catch(() => false)),
      'No tokens exist to revoke — create tokens first');

    await revokeBtn.click();

    const modal = page.locator('.modal');
    await expect(modal).toBeVisible({ timeout: 5000 });

    await modal.locator('button', { hasText: 'Cancel' }).click();
    await expect(modal).not.toBeVisible({ timeout: 5000 });
  });

  test('refresh tokens button works', async () => {
    const refreshBtn = frame.locator('#refresh-tokens-btn');
    await expect(refreshBtn).toBeVisible();
    await refreshBtn.click();
    await expect(frame.locator('#tokens-container')).toBeVisible();
  });

  test('endpoint status indicators exist', async () => {
    await expect(frame.locator('.endpoint-status').first()).toBeVisible({ timeout: 10000 });
  });

  test('copy buttons exist', async () => {
    await expect(frame.locator('.copy-button').first()).toBeVisible({ timeout: 10000 });
  });
});
