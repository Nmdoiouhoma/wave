/// <reference types="vitest/config" />
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    port: 5180,
    strictPort: true,
    host: true,
    // Served behind nginx (http://localhost:8090) in the compose stack.
    allowedHosts: true,
  },
  preview: { port: 5180, strictPort: true },
  test: {
    // happy-dom: MSW can intercept multipart XHR uploads (jsdom's FormData/Blob cannot be read).
    environment: 'happy-dom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    css: false,
    restoreMocks: true,
  },
});
