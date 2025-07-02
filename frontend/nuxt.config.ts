import eslintPlugin from 'vite-plugin-eslint';
const path = require('path');
// https://v3.nuxtjs.org/api/configuration/nuxt.config
export default defineNuxtConfig({
    typescript: {
        strict: true,
    },

    app: {
        head: {
            title: 'IP Address Collection and Management Service',
            meta: [
                {
                    name: 'viewport',
                    content: 'width=device-width, initial-scale=1',
                },
                {
                    charset: 'utf-8',
                },
            ],
            link: [
                {
                    rel: 'stylesheet',
                    href: 'https://fonts.googleapis.com/css?family=Roboto:300,400,500,700|Material+Icons',
                },
            ],
        },
    },

    css: ['vuetify/lib/styles/main.sass', '@mdi/font/css/materialdesignicons.min.css', 'assets/scss/default.scss'],

    modules: [
        [
            '@pinia/nuxt',
            {
                autoImports: [
                    // automatically imports `defineStore`
                    'defineStore', // import { defineStore } from 'pinia'
                    // automatically imports `defineStore` as `definePiniaStore`
                    ['defineStore', 'definePiniaStore'], // import { defineStore as definePiniaStore } from 'pinia'
                ],
            },
        ],
        '@kevinmarrec/nuxt-pwa',
        '@nuxtjs/i18n',
    ],

    pwa: {
        meta: {
            name: 'IPList',
            title: 'IPList',
            author: 'Rekryt',
            description: 'IP Address Collection and Management Service with multiple formats',
            theme_color: '#000000',
        },
    },

    i18n: {
        strategy: 'prefix_except_default',
        locales: [
            { code: 'en', language: 'en-US' },
            { code: 'ru', language: 'ru-RU' },
            { code: 'cn', language: 'zh-CN' },
        ],
        defaultLocale: 'en',
    },

    build: {
        transpile: ['vuetify'],
    },

    vite: {
        plugins: [eslintPlugin()],
        define: {
            'process.env.DEBUG': false,
        },
        server: {
            /**
           * If develop from docker
          watch: {
              usePolling: true,
          },
          */
        },
    },

    nitro: {
        devProxy: {
            '/api': {
                target: process.env.API_BASE_URL ?? 'https://iplist.opencck.org/',
                hostRewrite: process.env.API_BASE_URL ?? 'https://iplist.opencck.org/',
                changeOrigin: true,
            },
        },
        output: {
            publicDir: path.join(__dirname, '../public/'),
        },
    },

    compatibilityDate: '2025-06-28',
});
