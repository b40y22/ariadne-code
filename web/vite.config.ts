import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [vue()],
  server: {
    proxy: {
      '/api': process.env.API_TARGET ?? 'http://localhost:8090',
    },
  },
})
