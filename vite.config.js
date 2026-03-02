import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  base: '/world/', // 🔥 critical for subdirectory deploy

  plugins: [vue()],

  server: {
    proxy: {
      '/api': {
        target: 'http://localhost:8888/web',
        changeOrigin: true,
      },
    },
  },
})
