import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';

/**
 * Map `import { __ } from '@wordpress/i18n'` to the `wp.i18n` global that
 * WordPress core exposes when the `wp-i18n` script is enqueued. Keeps the
 * bundle free of duplicated copies of the i18n runtime and lets
 * `wp_set_script_translations()` deliver locale data through the normal
 * WordPress pipeline — `wp.i18n.setLocaleData()` runs before our bundle.
 */
function wpI18nExternal(): Plugin {
  const MODULE_ID = '@wordpress/i18n';
  const REEXPORT =
    "const i = (typeof window !== 'undefined' && window.wp && window.wp.i18n) || {};" +
    'export const __ = i.__ || ((s) => s);' +
    'export const _x = i._x || ((s) => s);' +
    'export const _n = i._n || ((s, p, n) => (n === 1 ? s : p));' +
    'export const _nx = i._nx || ((s, p, n) => (n === 1 ? s : p));' +
    'export const sprintf = i.sprintf || ((s) => s);' +
    'export const isRTL = i.isRTL || (() => false);' +
    'export const setLocaleData = i.setLocaleData || (() => undefined);' +
    'export const getLocaleData = i.getLocaleData || (() => ({}));' +
    'export const hasTranslation = i.hasTranslation || (() => false);' +
    'export const subscribe = i.subscribe || (() => () => undefined);' +
    'export const resetLocaleData = i.resetLocaleData || (() => undefined);' +
    'export default i;';

  return {
    name: 'pfa-wp-i18n-external',
    enforce: 'pre',
    resolveId(id) {
      return id === MODULE_ID ? MODULE_ID : null;
    },
    load(id) {
      return id === MODULE_ID ? REEXPORT : null;
    }
  };
}

export default defineConfig({
  base: './',
  plugins: [react(), wpI18nExternal()],
  server: {
    port: 5196,
    strictPort: true
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: 'assets/src/main.tsx',
      output: {
        entryFileNames: 'assets/[name]-[hash].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash][extname]'
      }
    }
  }
});
