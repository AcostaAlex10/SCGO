import { chromium } from 'playwright';
const BASE = process.env.SGSO_URL || 'http://127.0.0.1:8123/SCGO/';
const ok = [], mal = [];
const chequear = (q, c, d = '') => (c ? ok : mal).push(q + (d ? ' — ' + d : ''));

const nav = await chromium.launch();
const ctx = await nav.newContext();
const page = await ctx.newPage();

const entrar = async () => {
  await page.goto(BASE, { waitUntil: 'networkidle' });
  await page.waitForTimeout(600);
  if (await page.locator('input[type=email]').count()) {
    await page.fill('input[type=email]', 'admin@sgso.test');
    await page.fill('input[type=password]', 'admin123');
    await page.click('button[type=submit]');
    await page.waitForTimeout(2500);
  }
};
const claves = () => page.evaluate(() => Object.keys(localStorage).filter(k => k.startsWith('sgso_mock_db_')));
const db = () => page.evaluate(() => {
  const k = Object.keys(localStorage).find(x => x.startsWith('sgso_mock_db_'));
  return k ? JSON.parse(localStorage.getItem(k)) : null;
});

await entrar();
// sgsoMockReset() fuerza un guardado: es la via mas corta para que la clave exista.
await page.evaluate(() => window.sgsoMockReset());
await page.waitForTimeout(2500);
await entrar();

// 1. La clave lleva huella de datos.json, no el "v1" fijo de antes
const k1 = await claves();
chequear('la clave lleva la huella de datos.json (no "v1")',
  k1.length === 1 && /^sgso_mock_db_[a-z0-9]+$/.test(k1[0]) && k1[0] !== 'sgso_mock_db_v1', k1.join(','));
const obrasBase = (await db()).proyectos.length;

// 2. Un cambio propio sobrevive a la recarga (no debe romperse)
await page.evaluate(() => {
  const k = Object.keys(localStorage).find(x => x.startsWith('sgso_mock_db_'));
  const d = JSON.parse(localStorage.getItem(k));
  d.proyectos.push({ ...d.proyectos[0], id_proyecto: 9999, nombre: 'OBRA DE PRUEBA PERSISTENCIA' });
  localStorage.setItem(k, JSON.stringify(d));
});
await page.goto(BASE + '#/proyectos', { waitUntil: 'networkidle' });
await page.reload({ waitUntil: 'networkidle' });
await page.waitForTimeout(2000);
chequear('un cambio guardado sobrevive a la recarga',
  (await page.locator('body').innerText()).includes('OBRA DE PRUEBA PERSISTENCIA'),
  `${obrasBase} obras base`);

// 3. Las copias de huellas anteriores se descartan al cargar
await page.evaluate(() => {
  localStorage.setItem('sgso_mock_db_v1', JSON.stringify({ proyectos: [{ nombre: 'COPIA VIEJA' }] }));
  localStorage.setItem('sgso_mock_db_zzzzzz', JSON.stringify({ proyectos: [{ nombre: 'OTRA HUELLA' }] }));
});
await page.reload({ waitUntil: 'networkidle' });
await page.waitForTimeout(2000);
const k2 = await claves();
chequear('se purgan las copias de huellas anteriores',
  k2.length === 1 && !k2.includes('sgso_mock_db_v1') && !k2.includes('sgso_mock_db_zzzzzz'), k2.join(','));
const t2 = await page.locator('body').innerText();
chequear('no se cuela el contenido de una copia vieja',
  !t2.includes('COPIA VIEJA') && !t2.includes('OTRA HUELLA'));
chequear('la copia propia sigue intacta tras la purga',
  t2.includes('OBRA DE PRUEBA PERSISTENCIA'));

// 4. sgsoMockReset() sigue volviendo al punto de partida
await page.evaluate(() => window.sgsoMockReset());
await page.waitForTimeout(2500);
await entrar();
await page.goto(BASE + '#/proyectos', { waitUntil: 'networkidle' });
await page.waitForTimeout(1800);
chequear('sgsoMockReset() vuelve al punto de partida',
  !(await page.locator('body').innerText()).includes('OBRA DE PRUEBA PERSISTENCIA')
  && (await db()).proyectos.length === obrasBase);

await nav.close();
for (const o of ok) console.log('  OK   ' + o);
for (const m of mal) console.log(' FALLA ' + m);
console.log(`\n${ok.length}/${ok.length + mal.length} OK`);
if (mal.length) process.exit(1);
