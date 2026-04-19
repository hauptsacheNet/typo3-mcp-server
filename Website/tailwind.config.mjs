/** @type {import('tailwindcss').Config} */
export default {
  content: ['./src/**/*.{astro,html,js,jsx,md,mdx,svelte,ts,tsx,vue}'],
  theme: {
    extend: {
      colors: {
        'hn-orange': '#FA8E05',
        'hn-blue': '#004660',
        'hn-turquoise': '#00af9c',
        'hn-purple': '#B35FFF',
        'hn-yellow': '#FFD700',
        'hn-bg': '#111111',
        'hn-fg': '#FFFFFF',
        'hn-gray': '#F5F5F5',
        'hn-gray-dim': '#767676',
      },
      fontFamily: {
        display: ['Aldrich', 'sans-serif'],
        body: ['Nunito Sans', 'Segoe UI', 'system-ui', 'sans-serif'],
        mono: ['JetBrains Mono', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'monospace'],
      },
      borderRadius: {
        glass: '10px',
        btn: '6px',
      },
      backdropBlur: {
        glass: '20px',
      },
      maxWidth: {
        container: '1280px',
      },
    },
  },
  plugins: [],
};
