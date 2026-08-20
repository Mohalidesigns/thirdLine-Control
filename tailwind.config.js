import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                mono: ['Roboto Mono', ...defaultTheme.fontFamily.mono],
            },
            colors: {
                // The brand navy scale — mirrors the --navy-* custom
                // properties in resources/css/app.css. Change one, change both.
                navy: {
                    50: '#F0F4F9',
                    100: '#D9E2EF',
                    200: '#B3C4DD',
                    300: '#7F9AC0',
                    400: '#4A6D9E',
                    500: '#2A4E7E',
                    600: '#1A365D',
                    700: '#152C4C',
                    800: '#10223A',
                    900: '#0B1727',
                    DEFAULT: '#1A365D',
                },
                primary: {
                    DEFAULT: '#1A365D',
                    light: '#2A4E7E',
                    dark: '#10223A',
                },
                accent: {
                    DEFAULT: '#C9A227',
                    light: '#DBBA4E',
                },
            },
        },
    },

    plugins: [forms],
};
