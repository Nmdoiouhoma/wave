// Read `import.meta.env.X` literally so Vite can inline the values.

/** CONVENTIONS §9: canonical name, no alias. */
export const API_BASE_URL: string = import.meta.env.VITE_API_BASE_URL || '/api/v1';

export const MOCKS_ENABLED: boolean =
  import.meta.env.VITE_MOCKS === '1' || import.meta.env.VITE_MOCKS === 'true';

/**
 * FB-02: dev-only link to Mailpit. `import.meta.env.DEV` is a build-time `false` in production,
 * so the minifier drops the value (W-64: no Mailpit reference in the prod bundle).
 */
export const MAILPIT_URL: string | null = import.meta.env.DEV
  ? import.meta.env.VITE_MAILPIT_URL || null
  : null;
