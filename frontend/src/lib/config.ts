// Read `import.meta.env.X` literally so Vite can inline the values (and drop mock code in builds).

/** CONVENTIONS §9 names it VITE_API_BASE_URL; VITE_API_BASE is accepted as an alias. */
export const API_BASE_URL: string =
  import.meta.env.VITE_API_BASE_URL || import.meta.env.VITE_API_BASE || '/api/v1';

export const MOCKS_ENABLED: boolean =
  import.meta.env.VITE_MOCKS === '1' || import.meta.env.VITE_MOCKS === 'true';
