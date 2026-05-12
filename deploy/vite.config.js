import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  // Served at the root of woi.simonmccallum.org.nz
  base: '/',
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
    rollupOptions: {
      input: {
        main: 'index.html',
        analytics: 'analytics/analytics.html',
        apitest: 'api/APITest.html',
        ainamefinder: 'src/ai-data-prep.html'
      }
    }
  },
  server: {
    fs: {
      allow: ['..']
    },
    // Proxy API calls during `npm run dev` to the live PHP API
    proxy: {
      '/php-api': {
        target: 'https://woi.simonmccallum.org.nz',
        changeOrigin: true,
        secure: true
      }
    }
  }
})
