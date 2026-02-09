export default {
    content: [
        './resources/**/*.antlers.html',
        './resources/**/*.blade.php',
        './content/**/*.md'
    ],
    theme: {
        fontFamily: {
            display: ['"Dancing Script"', 'cursive'],
            serif: ['"EB Garamond"', 'Georgia', 'serif'],
            sans: ['Inter', 'sans-serif'],
            mono: ['Menlo', 'monospace']
        },
        extend: {
            colors: {
                'peach': '#ffbc97',
                'peach-light': '#ffd4b8',
                'peach-dark': '#e8a07a',
                'sage': '#9bb8a5',
                'sage-light': '#c5d8cc',
                'cream': '#fdf8f4',
                'warm-gray': '#666666',
            }
        }
    },
    plugins: [],
    important: true
}
