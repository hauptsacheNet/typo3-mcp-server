import { test as setup, expect } from '@playwright/test';
import config from '../config';

const authFile = 'tests/playwright/.auth/login.json';

setup('authenticate as admin', async ({ page }) => {
  await page.goto(config.baseUrl + '/typo3/');
  await page.waitForLoadState('networkidle');

  // TYPO3 v13 login form
  await page.getByLabel('Username').fill(config.admin.username);
  await page.getByLabel('Password').fill(config.admin.password);
  await page.getByRole('button', { name: /log ?in/i }).click();

  // Wait for backend to load
  await page.waitForURL(/\/typo3\//);
  await page.waitForLoadState('networkidle');

  // Verify login succeeded — TYPO3 backend has a module menu
  await expect(page.locator('[data-modulemenu-identifier]').first()).toBeVisible({ timeout: 15000 });

  await page.context().storageState({ path: authFile });
});
