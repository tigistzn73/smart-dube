/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        ethiopia: {
          green: '#009A44',
          yellow: '#FED100',
          red: '#D92121',
          gold: '#E5A93C',
          dark: '#0B0F19',
          surface: '#151C2C',
          card: '#1E293B',
          accent: '#10B981',
          telebirr: '#00A3E0',
          cbe: '#8B1E41'
        }
      },
      fontFamily: {
        sans: ['Inter', 'Outfit', 'sans-serif']
      }
    },
  },
  plugins: [],
}
