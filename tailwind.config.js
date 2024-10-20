import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
        './node_modules/flowbite/**/*.js'
    ],
    theme: {
        extend: {
            fontFamily: {
                'body': [
                    'Open Sans',
                    'Inter',
                    'ui-sans-serif',
                    'system-ui',
                    // other fallback fonts
                ],
                'sans': [
                    'Open Sans',
                    'Inter',
                    'ui-sans-serif',
                    'system-ui',
                    // other fallback fonts
                ]
            },
        },
    },
    plugins: [
        require('flowbite/plugin')({
            charts: true,
            forms: true,
            tooltips: true
        }),
    ],
};
