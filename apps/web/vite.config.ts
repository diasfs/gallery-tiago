/// <reference types="vitest/config" />
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  // Inside docker-compose the frontend container reaches the API via the
  // `api` service name; outside docker (`bun run dev`), it's the host's
  // published port. Override with VITE_API_PROXY_TARGET if needed.
  const apiProxyTarget = env.VITE_API_PROXY_TARGET || 'http://localhost:8081'

  return {
    plugins: [vue(), tailwindcss()],
    resolve: {
      alias: {
        '@': path.resolve(__dirname, './src'),
      },
    },
    server: {
      host: true,
      port: 5173,
      strictPort: true,
      allowedHosts: ['vite.dias.poa.br'],
      proxy: {
        // Same-origin proxy so the admin session cookie (set by
        // `POST /api/admin/login`) is sent on subsequent requests without
        // needing CORS at all — see apps/web/src/api/client.ts.
        '/api': {
          target: apiProxyTarget,
          changeOrigin: true,
        },
        // Converted AVIF/thumb media paths are returned by the API relative
        // (e.g. `converted/ab/<uuid>/master.avif`) and resolved by
        // `mediaUrl()` against the same origin, so proxy those too.
        '/converted': {
          target: apiProxyTarget,
          changeOrigin: true,
        },
        // Face crops written by worker-faces (faces/<xx>/<faceId>.jpg).
        '/faces': {
          target: apiProxyTarget,
          changeOrigin: true,
        },
        '/originals': {
          target: apiProxyTarget,
          changeOrigin: true,
        },
      },
    },
    test: {
      environment: 'jsdom',
      globals: true,
    },
  }
})
