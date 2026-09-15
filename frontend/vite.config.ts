import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'node:path'
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: { alias: { '@': path.resolve(__dirname, 'src') } },
  // react-dom reads process.env.NODE_ENV; polyfill it since the IIFE runs without Node globals
  define: { 'process.env.NODE_ENV': JSON.stringify('production') },
  build: {
    outDir: '../assets/app', emptyOutDir: true,
    lib: { entry: 'src/main.tsx', name: 'InventoryWorkspace', formats: ['iife'], fileName: () => 'inventory-app.js', cssFileName: 'inventory-app' },
  },
})
