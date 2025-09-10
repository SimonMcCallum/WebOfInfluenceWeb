import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  // Set base path for deployment to /webofinfluence/
  base: '/webofinfluence/',
  build: {
    outDir: 'dist',
    assetsDir: 'assets'
  }
})
