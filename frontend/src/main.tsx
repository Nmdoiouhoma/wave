import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import './index.css';

async function boot() {
  // Inline env check (not the shared constant) so the mock chunk is dropped from real builds.
  if (import.meta.env.VITE_MOCKS === '1' || import.meta.env.VITE_MOCKS === 'true') {
    const { startMockWorker } = await import('./mocks/browser');
    await startMockWorker();
  }
  createRoot(document.getElementById('root')!).render(
    <StrictMode>
      <App />
    </StrictMode>,
  );
}

void boot();
