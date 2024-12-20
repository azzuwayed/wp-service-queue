import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

export default defineConfig({
    plugins: [vue()],
    build: {
        outDir: 'assets/dist',
        assetsDir: '',
        rollupOptions: {
            input: {
                main: resolve(__dirname, 'assets/src/main.js'),
                style: resolve(__dirname, 'assets/src/style.css')
            },
            output: {
                entryFileNames: 'app.js',
                chunkFileNames: '[name].js',
                assetFileNames: '[name].[ext]'
            }
        }
    }
})
