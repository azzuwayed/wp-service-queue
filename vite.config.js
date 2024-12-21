import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

// List of Node.js modules to externalize
const nodeModules = [
  'fs', 'path', 'url', 'util', 'crypto', 'module', 'os', 'stream', 'buffer',
  'process', 'assert', 'events', 'http', 'https', 'net', 'tls', 'zlib',
  'querystring', 'child_process', 'dns', 'readline', 'perf_hooks', 'worker_threads',
  'tty', 'fsevents', 'v8', 'http2', 'fs/promises'
]

// Add node: prefix versions
const nodeBuiltins = [...nodeModules, ...nodeModules.map(mod => `node:${mod}`)]

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
            format: 'es', // Specify ES module format
            entryFileNames: 'app.js',
            chunkFileNames: '[name].js',
            assetFileNames: '[name].[ext]',
            manualChunks: {
                vue: ['vue']
                }
            },
            external: [
                'jquery',
                ...nodeBuiltins
            ]
        },
        target: 'es2015',
        minify: 'terser',
        terserOptions: {
            compress: {
                drop_console: true,  // Remove console.* statements
                drop_debugger: true, // Remove debugger statements
                pure_funcs: ['console.log'], // Remove specific functions
                passes: 2,          // Multiple compression passes
                unsafe: true,       // Enable unsafe transformations
                dead_code: true     // Remove unreachable code
            },
            mangle: {
                properties: false,  // Don't mangle property names
                toplevel: true,    // Mangle top-level names
                safari10: true     // Safari 10 compatibility
            },
            format: {
                comments: false,   // Remove comments
                ascii_only: true,  // Use ASCII-only characters
                wrap_iife: true    // Wrap IIFEs
            },
            ecma: 2015,           // Specify ECMAScript version
            keep_classnames: false,
            keep_fnames: false,
            safari10: true
        },
        sourcemap: false,
        chunkSizeWarningLimit: 500
    },
    define: {
        'process.env.NODE_ENV': '"production"'
    },
    optimizeDeps: {
        exclude: nodeBuiltins
    },
    resolve: {
        alias: {
            '@': resolve(__dirname, 'assets/src')
        }
    },
    server: {
        fs: {
            strict: true
        }
    }
})
