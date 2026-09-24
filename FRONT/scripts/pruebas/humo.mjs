// Recorrido de humo sobre la demo estatica: los cuatro roles y sus pantallas.
import { chromium } from 'playwright';

const BASE = process.env.SGSO_URL || 'http://127.0.0.1:8123/SCGO/';

const CUENTAS = [
  { rol: 'AdministradorSistema',   email: 'admin@sgso.test',          pass: 'admin123',   usuarios: true,  costos: true },
  { rol: 'PersonalAdministrativo', email: 'administrativo@sgso.test', pass: 'admin123',   usuarios: false, costos: true },
  { rol: 'PersonalTecnico',        email: 'tecnico@sgso.test',        pass: 'tecnico123', usuarios: false, costos: false },
  { rol: 'Gerente',                email: 'gerente@sgso.test',        pass: 'gerente123', usuarios: false, costos: true },
];

const resultados = [];
function chequear(rol, que, ok, detalle = '') {
  resultados.push({ rol, que, ok, detalle });
  console.log(`${ok ? '  OK  ' : ' FALLA'} [${rol}] ${que}${detalle ? ' — ' + detalle : ''}`);
}

const navegador = await chromium.launch();

for (const cuenta of CUENTAS) {
  const ctx = await navegador.newContext({ viewport: { width: 1400, height: 900 } });
  const page = await ctx.newPage();
  const errores = [];
  page.on('console', (m) => { if (m.type() === 'error') errores.push(m.text()); });
  page.on('pageerror', (e) => errores.push('pageerror: ' + e.message));

  // 1. Login
  await page.goto(BASE, { waitUntil: 'networkidle' });
  const textoLogin = await page.locator('body').innerText();
  await page.fill('input[type=email]', cuenta.email);
  await page.fill('input[type=password]', cuenta.pass);
  await page.click('button[type=submit]');
  await page.waitForURL((u) => !u.hash.includes('/login'), { timeout: 15000 }).catch(() => {});
  const entro = !page.url().includes('/login');
  chequear(cuenta.rol, 'inicia sesion y sale del login', entro, page.url().split('#')[1] ?? '');
  if (!entro) {
    chequear(cuenta.rol, 'login (motivo)', false, (await page.textContent('body')).slice(0, 200));
    await ctx.close();
    continue;
  }

  // 1b. El producto se llama SCGO (DEC-01). La marca vieja quedo en la pantalla
  // de login y en la barra de arriba despues del renombre. Distingue mayusculas a
  // proposito: los emails de prueba (@sgso.test) no son la marca.
  await page.waitForTimeout(800);
  const textoApp = await page.locator('body').innerText();
  const marcaVieja = [textoLogin, textoApp].some((t) => /SGSO/.test(t));
  const marcaNueva = [textoLogin, textoApp].every((t) => /SCGO/.test(t));
  chequear(cuenta.rol, 'la interfaz dice SCGO y no SGSO', marcaNueva && !marcaVieja,
    marcaVieja ? 'todavia dice SGSO' : (marcaNueva ? '' : 'no dice SCGO'));

  // 2. Sidebar: Usuarios solo para el AdministradorSistema
  const nav = page.locator('nav');
  await nav.first().waitFor({ timeout: 10000 });
  const items = (await nav.first().innerText()).split('\n').map((s) => s.trim()).filter(Boolean);
  const tieneUsuarios = items.some((t) => /^Usuarios$/i.test(t));
  chequear(cuenta.rol, `menu Usuarios ${cuenta.usuarios ? 'visible' : 'oculto'}`,
    tieneUsuarios === cuenta.usuarios, 'menu: ' + items.join(', '));

  // 3. RF20: el Personal Tecnico no ve el presupuesto de las obras
  await page.goto(BASE + '#/proyectos', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  const textoProyectos = await page.locator('body').innerText();
  const hayPresupuesto = /Presupuesto[\s\S]{0,40}\$\s?[\d.]/.test(textoProyectos) || /\$\s?[\d]{1,3}([.,]\d{3})+/.test(textoProyectos);
  chequear(cuenta.rol, `presupuesto en Proyectos ${cuenta.costos ? 'visible (RF20)' : 'oculto (RF20)'}`,
    hayPresupuesto === cuenta.costos, hayPresupuesto ? 'se ven montos' : 'no se ven montos');

  // 4. La ruta sobrevive a la recarga (router por hash)
  await page.goto(BASE + '#/alertas', { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  const sigueEnAlertas = page.url().includes('#/alertas');
  const cuerpoAlertas = await page.locator('body').innerText();
  chequear(cuenta.rol, 'recargar en #/alertas mantiene la ruta',
    sigueEnAlertas && !/404|no encontrada/i.test(cuerpoAlertas), page.url().split('/').pop());

  // 5. Cada pantalla del menu carga sin romperse
  const rutas = ['/', '/proyectos', '/seguimiento', '/materiales', '/documentacion', '/reportes', '/alertas', '/maquinaria'];
  const rotas = [];
  for (const r of rutas) {
    await page.goto(BASE + '#' + r, { waitUntil: 'networkidle' });
    await page.waitForTimeout(700);
    const t = await page.locator('body').innerText();
    if (/404|Página no encontrada|Something went wrong/i.test(t) || t.trim().length < 40) rotas.push(r);
  }
  chequear(cuenta.rol, 'las 8 pantallas del menu cargan', rotas.length === 0,
    rotas.length ? 'rotas: ' + rotas.join(', ') : '');

  const ruido = errores.filter((e) => !/favicon|Download the React DevTools/i.test(e));
  chequear(cuenta.rol, 'sin errores en consola', ruido.length === 0, ruido.slice(0, 2).join(' | '));

  await ctx.close();
}

await navegador.close();

const fallas = resultados.filter((r) => !r.ok);
console.log(`\n=== ${resultados.length - fallas.length}/${resultados.length} comprobaciones OK ===`);
if (fallas.length) {
  console.log('FALLAS:');
  for (const f of fallas) console.log(` - [${f.rol}] ${f.que} — ${f.detalle}`);
  process.exit(1);
}
