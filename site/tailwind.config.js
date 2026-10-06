/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './resources/views/**/*.blade.php',
    './app/**/*.php',
  ],
  theme: {
    extend: {
      colors: {
        paper:   '#FAFAF7',
        surface: '#FFFFFF',
        ink:     { DEFAULT: '#1A1A18', soft: '#4A4A45' },
        muted:   '#8A8A82',
        hairline:{ DEFAULT: 'rgba(26,26,24,0.10)', strong: 'rgba(26,26,24,0.18)' },
        accent:  '#B3392C',
        status:  { success: '#2F6B4F', warning: '#A8791E', danger: '#B3392C' },
      },
      fontFamily: {
        display: ['"Playfair Display"', 'Georgia', '"Songti SC"', 'SimSun', 'serif'],
        sans:    ['"Inter"', 'system-ui', '"PingFang SC"', '"Microsoft YaHei"', 'sans-serif'],
      },
      fontSize: {
        'display-xl': 'clamp(44px, 6vw, 72px)',
        'display-lg': 'clamp(32px, 4vw, 48px)',
        'display-md': 'clamp(24px, 2.6vw, 34px)',
      },
      borderRadius: {
        '2px': '2px',
      },
      boxShadow: {
        float: '0 2px 16px rgba(26,26,24,0.08)',
      },
    },
  },
  plugins: [],
}
