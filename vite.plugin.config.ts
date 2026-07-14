import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Single-file ESM lib build. React / ReactDOM come from the host at runtime (never
// bundled — they are the ONLY externals). The vendored @vctrs/plugin-ui client kit and
// its axios dependency are bundled inline so the extracted plugin ships one self-
// contained dist/entry.js with zero bare runtime imports.
export default defineConfig({
  plugins: [react()],
  build: {
    lib: {
      entry: 'ui/entry.tsx',
      formats: ['es'],
      fileName: () => 'entry.js',
    },
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      external: ['react', 'react-dom', 'react-dom/client'],
    },
  },
});
