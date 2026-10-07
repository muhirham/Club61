import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Plus Jakarta Sans', ...defaultTheme.fontFamily.sans],
                serif: ['Cinzel', ...defaultTheme.fontFamily.serif],
                script: ['Alex Brush', 'cursive'],
                // Brand guideline Club 61: Cheltenham Classic (utama) & Acumin Variable Concept (pendamping) — keduanya
                // font berlisensi; selama file webfont-nya belum dipasang, tampil dengan padanan Google Fonts terdekat.
                display: ['"Cheltenham Classic"', '"Source Serif 4"', 'Georgia', 'serif'],
                brand: ['"Acumin Variable Concept"', 'Archivo', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Brand guideline Club 61 (Pantone): Terakota 643U, Cokelat 7450U, Mint 387U, Cream 7544U.
                club: {
                    terra: '#662721',
                    'terra-dark': '#511D18',
                    brown: '#4F2F2A',
                    mint: '#C5EAE5',
                    cream: '#F7F0DB',
                    paper: '#FCF8EE',
                    line: '#E6DAC0',
                    muted: '#7A5A52',
                },
                forest: {
                    950: '#04160F',
                    900: '#07241A',
                    850: '#0A2E22',
                    800: '#0F3C2C',
                    700: '#15523D',
                    600: '#1C694E',
                    500: '#268564',
                },
                tennis: {
                    DEFAULT: '#CCFF00',
                    lime: '#CCFF00',
                    volt: '#D6FF2E',
                    soft: '#E4FFA1',
                },
            },
        },
    },

    plugins: [forms],
};