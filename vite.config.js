import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  base: '/world/',
  plugins: [vue()],
  server: {
    proxy: {
      '/api': {
        target: 'https://api.ewrm.io',
        changeOrigin: true,
        secure: true,
      },
    },
  },
})
