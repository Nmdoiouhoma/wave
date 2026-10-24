// Read `import.meta.env.X` literally so Vite can inline the values.

/** CONVENTIONS §9: canonical name, no alias. */
export const API_BASE_URL: string = import.meta.env.VITE_API_BASE_URL || '/api/v1';

export const MOCKS_ENABLED: boolean =
  import.meta.env.VITE_MOCKS === '1' || import.meta.env.VITE_MOCKS === 'true';
