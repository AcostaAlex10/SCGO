// Comprueba que FRONT/vercel.json siga declarando los headers de seguridad (A-07).
//
// Vercel los sirve a partir de ese archivo: no hay código que los produzca, así
// que ninguna prueba del frontend se entera si desaparecen. Esto es lo que avisa.
//
// Se corre desde FRONT/: `node ../.github/scripts/headers-vercel.mjs`.
import { readFileSync } from 'node:fs';

const EXIGIDOS = [
  'Content-Security-Policy',
  'Strict-Transport-Security',
  'X-Content-Type-Options',
  'Referrer-Policy',
  'Permissions-Policy',
];

// La ruta sale de la ubicación del script, no del directorio de trabajo: así da
// igual desde dónde se lo corra.
const config = JSON.parse(readFileSync(new URL('../../FRONT/vercel.json', import.meta.url), 'utf8'));
const reglas = config.headers ?? [];

// Solo cuentan las reglas que aplican a todas las rutas: un header declarado
// para una ruta suelta deja el resto del sitio sin cubrir.
const declarados = new Set(
  reglas
    .filter((r) => r.source === '/(.*)')
    .flatMap((r) => (r.headers ?? []).map((h) => String(h.key)))
);

const faltan = EXIGIDOS.filter((h) => !declarados.has(h));
if (faltan.length > 0) {
  console.error(`Faltan headers en vercel.json para todas las rutas: ${faltan.join(', ')}`);
  process.exit(1);
}

// frame-ancestors va en la CSP, no como X-Frame-Options: es lo que respetan los
// navegadores actuales y permite decir "ningún sitio puede embeberme".
const csp = reglas
  .filter((r) => r.source === '/(.*)')
  .flatMap((r) => r.headers ?? [])
  .find((h) => h.key === 'Content-Security-Policy');

if (!String(csp?.value ?? '').includes("frame-ancestors 'none'")) {
  console.error("La CSP no declara frame-ancestors 'none': el sitio se puede embeber en un iframe");
  process.exit(1);
}

// `connect-src` tiene que nombrar la API de cada entorno al que se despliegue el
// frontend. Si falta una, el navegador bloquea TODOS los pedidos y la aplicación
// queda muda: no hay error del servidor, solo un aviso en la consola. Ya pasó al
// cambiar VITE_API_URL sin tocar este archivo.
//
// Cuando exista staging (B-02, bloqueado por DEC-04), se agrega su origen acá y
// en vercel.json, en ese orden: esta lista es la que obliga.
const ORIGENES_API = ['https://ingenieria-en-software-proyecto.onrender.com'];

const connectSrc = String(csp?.value ?? '')
  .split(';')
  .map((d) => d.trim())
  .find((d) => d.startsWith('connect-src'));

const sinDeclarar = ORIGENES_API.filter((origen) => !String(connectSrc ?? '').includes(origen));
if (sinDeclarar.length > 0) {
  console.error(
    `La CSP no permite hablar con la API de estos entornos: ${sinDeclarar.join(', ')}.\n` +
      'Sin eso el navegador bloquea los pedidos y la aplicación queda muda.'
  );
  process.exit(1);
}

console.log(
  `vercel.json declara los ${EXIGIDOS.length} headers de seguridad ` +
    `y permite ${ORIGENES_API.length} origen(es) de API`
);
