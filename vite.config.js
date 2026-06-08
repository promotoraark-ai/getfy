import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/member-builder.js',
                'resources/js/plugins/getfyPluginVueBridge.js',
            ],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
                compilerOptions: {
                    isCustomElement: (tag) => tag.startsWith('vds-') || tag.startsWith('media-'),
                },
            },
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
            '@getfy/plugin-sdk': '/resources/js/plugin-sdk',
            'vue-runtime-internal': 'vue',
        },
    },
    optimizeDeps: {
        include: ['pdfjs-dist', '@svg-maps/world'],
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    build: {
        rollupOptions: {
            // Vue só é externalizado no entry do bridge (plugin-ui importa "vue" via import map com scope).
            // O painel principal deve empacotar Vue nos chunks — import map global quebrava o build-only.
            output: {
                entryFileNames: (chunk) =>
                    chunk.name === 'getfyPluginVueBridge' ? 'getfy-plugin-vue.mjs' : 'assets/[name]-[hash].js',
            },
        },
    },
});
