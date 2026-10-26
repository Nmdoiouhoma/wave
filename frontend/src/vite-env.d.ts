/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_BASE_URL?: string;
  readonly VITE_MOCKS?: string;
  readonly VITE_MAILPIT_URL?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
