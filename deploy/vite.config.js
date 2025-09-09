import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  base: '/WebOfInfluenceResearch/',
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
  },
  define: {
    'process.env': {}
  }
})
