// @ts-check
import { defineConfig } from 'astro/config';

import tailwindcss from '@tailwindcss/vite';

// https://astro.build/config
export default defineConfig({
  site: 'https://sanctuaryshine.co.uk',
  trailingSlash: 'never',
  build: {
    format: 'directory'
  },
  vite: {
    plugins: [tailwindcss()]
  }
});