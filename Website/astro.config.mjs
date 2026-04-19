import { defineConfig } from 'astro/config';
import tailwind from '@astrojs/tailwind';

export default defineConfig({
  site: 'https://hauptsachenet.github.io',
  base: '/typo3-mcp-server',
  integrations: [tailwind()],
});
