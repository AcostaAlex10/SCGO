import { defineConfig } from 'vite'
import path from 'path'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'


function figmaAssetResolver() {
  return {
    name: 'figma-asset-resolver',
    resolveId(id) {
      if (id.startsWith('figma:asset/')) {
        const filename = id.replace('figma:asset/', '')
        return path.resolve(import.meta.dirname, 'src/assets', filename)
      }
    },
  }
}

// Ruta base del sitio. En Vercel la app vive en la raiz del dominio, pero en
// GitHub Pages queda bajo /<repo>/. El workflow de Pages define BASE_PATH; si no
// existe, se compila para la raiz y el deploy de Vercel no se ve afectado.
const BASE_PATH = process.env.BASE_PATH ?? '/'

export default defineConfig({
  base: BASE_PATH,
  plugins: [
    figmaAssetResolver(),
    // The React and Tailwind plugins are both required for Make, even if
    // Tailwind is not being actively used – do not remove them
    react(),
    tailwindcss(),
  ],
  resolve: {
    alias: {
      // Alias @ to the src directory
      '@': path.resolve(import.meta.dirname, './src'),
    },
  },

  // File types to support raw imports. Never add .css, .tsx, or .ts files to this.
  assetsInclude: ['**/*.svg', '**/*.csv'],

  build: {
    rolldownOptions: {
      output: {
        // Con ARCHIVO_UNICO=1 se desactiva la division en chunks y todo el JS
        // sale en un bundle, para poder incrustarlo en un unico .html (ver
        // scripts/demo-un-archivo.mjs). El resto de los builds no se altera.
        ...(process.env.ARCHIVO_UNICO === '1' ? { codeSplitting: false } : {}),
      },
    },
  },
})
