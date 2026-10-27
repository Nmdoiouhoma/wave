/// <reference types="vitest/config" />
import { fileURLToPath, URL } from 'node:url';
import { rm } from 'node:fs/promises';
import { resolve } from 'node:path';
import { defineConfig, loadEnv, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

/** Dev/mock-only assets living in public/ (MSW worker, synthetic tones). */
const MOCK_ASSETS = ['mockServiceWorker.js', 'mock-audio'];

/** qa F5: never ship mock assets in a build unless it is explicitly a mock build (VITE_MOCKS=1). */
function stripMockAssets(mocks: boolean): Plugin {
  let outDir = 'dist';
  return {
    name: 'wave-strip-mock-assets',
    apply: 'build',
    configResolved(config) {
      outDir = resolve(config.root, config.build.outDir);
    },
    async closeBundle() {
      if (mocks) return;
      await Promise.all(
        MOCK_ASSETS.map((p) => rm(resolve(outDir, p), { recursive: true, force: true })),
      );
    },
  };
}

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_');
  const mocks = env.VITE_MOCKS === '1' || env.VITE_MOCKS === 'true';
  return {
    plugins: [react(), tailwindcss(), stripMockAssets(mocks)],
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
  };
});
