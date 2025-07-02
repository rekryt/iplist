import { createVuetify } from 'vuetify';
import * as components from 'vuetify/components';
import * as directives from 'vuetify/directives';

export default defineNuxtPlugin((nuxtApp) => {
    const cookieTheme = useCookieTheme();

    const vuetify = createVuetify({
        components,
        directives,
        theme: {
            defaultTheme: cookieTheme.value && cookieTheme.value !== 'system' ? cookieTheme.value : 'light',
            themes: {
                light: {
                    dark: false,
                    colors: {
                        primary: '#4caf50',
                        secondary: '#4caf50',
                        background: '#FFFFFF',
                        surface: '#FFFFFF',
                        'primary-darken-1': '#3700B3',
                        'secondary-darken-1': '#018786',
                        error: '#f55a4e',
                        info: '#00d3ee',
                        success: '#5cb860',
                        warning: '#ffa21a',
                    },
                },
                // dark: {
                //     dark: true,
                //     colors: {
                //         background: '#121212',
                //         error: '#CF6679',
                //         info: '#2196F3',
                //         'on-background': '#fff',
                //         'on-error': '#fff',
                //         'on-info': '#fff',
                //         'on-primary': '#fff',
                //         'on-primary-darken-1': '#fff',
                //         'on-secondary': '#fff',
                //         'on-secondary-darken-1': '#fff',
                //         'on-success': '#fff',
                //         'on-surface': '#fff',
                //         'on-surface-bright': '#000',
                //         'on-surface-light': '#fff',
                //         'on-surface-variant': '#000000',
                //         'on-warning': '#fff',
                //         primary: '#2196F3',
                //         'primary-darken-1': '#277CC1',
                //         secondary: '#54B6B2',
                //         'secondary-darken-1': '#48A9A6',
                //         success: '#4CAF50',
                //         surface: '#212121',
                //         'surface-bright': '#ccbfd6',
                //         'surface-light': '#424242',
                //         'surface-variant': '#c8c8c8',
                //         warning: '#FB8C00',
                //     },
                // },
            },
        },
    });

    nuxtApp.vueApp.use(vuetify);
});
