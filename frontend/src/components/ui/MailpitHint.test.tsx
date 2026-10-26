import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

describe('MailpitHint (W-64)', () => {
  afterEach(() => {
    vi.unstubAllEnvs();
    vi.resetModules();
  });

  it('links to Mailpit when VITE_MAILPIT_URL is set in dev', async () => {
    vi.stubEnv('VITE_MAILPIT_URL', 'http://localhost:8027');
    vi.resetModules();
    const { MailpitHint } = await import('./MailpitHint');
    render(<MailpitHint />);
    expect(screen.getByRole('link', { name: 'Ouvrir Mailpit (dev)' })).toHaveAttribute(
      'href',
      'http://localhost:8027',
    );
  });

  it('renders nothing when unset', async () => {
    vi.stubEnv('VITE_MAILPIT_URL', '');
    vi.resetModules();
    const { MailpitHint } = await import('./MailpitHint');
    const { container } = render(<MailpitHint />);
    expect(container).toBeEmptyDOMElement();
  });
});
