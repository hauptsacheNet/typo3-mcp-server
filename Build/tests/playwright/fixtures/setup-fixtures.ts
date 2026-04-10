import { test as base, type Locator, type Page, expect } from '@playwright/test';
import config from '../config';

export class BackendPage {
  readonly page: Page;

  constructor(page: Page) {
    this.page = page;
  }

  async gotoModule(path: string): Promise<void> {
    await this.page.goto(config.baseUrl + path);
    await this.page.waitForLoadState('networkidle');
  }

  async gotoMcpModule(): Promise<void> {
    await this.gotoModule('/typo3/module/user/mcp-server');
  }
}

export class McpModulePage {
  readonly page: Page;

  constructor(page: Page) {
    this.page = page;
  }

  async clickTab(tabId: string): Promise<void> {
    await this.page.locator(`#${tabId}`).click();
  }

  async createToken(buttonId: string): Promise<void> {
    await this.page.locator(`#${buttonId}`).click();
  }

  getTokenModal(): Locator {
    return this.page.locator('.modal');
  }

  getTokensContainer(): Locator {
    return this.page.locator('#tokens-container');
  }
}

type Fixtures = {
  backend: BackendPage;
  mcpModule: McpModulePage;
};

export const test = base.extend<Fixtures>({
  backend: async ({ page }, use) => {
    await use(new BackendPage(page));
  },
  mcpModule: async ({ page }, use) => {
    await use(new McpModulePage(page));
  },
});

export { expect };
