import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [vue()],
  server: {
    // The demo code is a PHP fixture one level up, shared with the analyzer's tests.
    fs: { allow: ['..'] },
    proxy: {
      '/api': process.env.API_TARGET ?? 'http://localhost:8090',
    },
  },
})
