/** @type {import('tailwindcss').Config} */
export default {
    // storage/framework/views (Filament kompilyatsiyalari) qo'shilmaydi — u
    // Mini App CSS'ini kerak bo'lmagan klasslar bilan shishiradi. Filament o'z
    // CSS'ini ishlatadi.
    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{js,jsx,ts,tsx}',
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: [
                    'ui-sans-serif',
                    'system-ui',
                    '-apple-system',
                    'Segoe UI',
                    'Roboto',
                    'sans-serif',
                    'Apple Color Emoji',
                    'Segoe UI Emoji',
                    'Noto Color Emoji',
                ],
            },
        },
    },
    plugins: [],
};
