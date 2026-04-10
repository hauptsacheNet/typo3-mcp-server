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

  test('create token via central button shows name modal then token modal', async ({ page }) => {
    const createBtn = frame.locator('#create-token-btn');
    await expect(createBtn).toBeVisible();
    await createBtn.click();

    // Name input modal renders in the top frame (TYPO3 Modal API appends to top document).
    // Target by title to avoid strict-mode violations when TYPO3 stacks multiple modals.
    const nameModal = page.locator('.modal').filter({ hasText: 'Create Token' });
    await expect(nameModal).toBeVisible({ timeout: 15000 });

    const nameInput = nameModal.locator('#modal-token-name-input');
    await expect(nameInput).toBeVisible();
    await nameInput.fill('test-token');
    await nameModal.getByRole('button', { name: 'Create', exact: true }).click();

    // Token "show once" modal appears (may coexist briefly with the name modal)
    const tokenModal = page.locator('.modal').filter({ hasText: 'Token Created' });
    await expect(tokenModal).toBeVisible({ timeout: 15000 });
    await expect(tokenModal.locator('.alert-warning')).toContainText('only be shown once');

    const tokenInput = tokenModal.locator('#modal-token-value');
    await expect(tokenInput).toBeVisible();
    const tokenValue = await tokenInput.inputValue();
    expect(tokenValue).toMatch(/^[0-9a-f]{64}$/);

    await expect(tokenModal.locator('button', { hasText: 'Copy' })).toBeVisible();
    await tokenModal.locator('button', { hasText: 'I have copied the token' }).click();

    // Verify token appears in the table
    const tokensContainer = frame.locator('#tokens-container');
    await expect(tokensContainer.locator('table')).toBeVisible({ timeout: 10000 });
    await expect(tokensContainer.locator('td', { hasText: 'test-token' })).toBeVisible();
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
