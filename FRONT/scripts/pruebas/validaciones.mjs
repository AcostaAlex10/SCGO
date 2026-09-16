import { chromium } from 'playwright';
const BASE = process.env.SGSO_URL || 'http://127.0.0.1:8123/SCGO/';
const ok = [], mal = [];
const chequear = (q, c, d = '') => { (c ? ok : mal).push(q); console.log(`${c ? '  OK  ' : ' FALLA'} ${q}${d ? ' — ' + d : ''}`); };

const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport: { width: 1400, height: 1000 } })).newPage();

const entrar = async () => {
  await page.waitForTimeout(700);
  if (await page.locator('input[type=email]').count()) {
    await page.fill('input[type=email]', 'admin@sgso.test');
    await page.fill('input[type=password]', 'admin123');
    await page.click('button[type=submit]');
    await page.waitForTimeout(2500);
  }
};
const irA = async (r) => { await page.goto(BASE + '#' + r, { waitUntil: 'networkidle' }); await page.waitForTimeout(1800);
                           await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight)); await page.waitForTimeout(500); };
const contar = (col, idObra) => page.evaluate(([c, id]) => {
  const k = Object.keys(localStorage).find(x => x.startsWith('sgso_mock_db_'));
  if (!k) return -1;
  const db = JSON.parse(localStorage.getItem(k));
  return id === null ? db[c].length : db[c].filter(f => String(f.id_proyecto ?? '') === String(id)).length;
}, [col, idObra]);
const escribir = (id, v) => page.evaluate(([i, val]) => {
  const el = document.getElementById(i);
  const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
  setter.call(el, val); el.dispatchEvent(new Event('input', { bubbles: true }));
}, [id, v]);

await page.goto(BASE, { waitUntil: 'networkidle' });
await entrar();
await page.evaluate(() => window.sgsoMockReset());
await page.waitForTimeout(2500);
await entrar();

// ---- 1. El navegador frena la fecha invertida (primera barrera) ----
await irA('/proyectos/1');
// La fecha de hoy en la zona horaria LOCAL, igual que la calcula la app en
// ProyectosPage.tsx. Con toISOString() a secas se obtiene la fecha UTC, que
// entre las 21 y la medianoche en Argentina ya es la del dia siguiente: la
// prueba fallaba sola si se corria de noche, sin que la app tuviera nada malo.
const hoy = (() => {
  const d = new Date();
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
})();
chequear('el campo «Hasta» lleva min = la fecha de inicio',
  (await page.locator('#if').getAttribute('min')) === hoy, String(await page.locator('#if').getAttribute('min')));
await escribir('if', '2025-01-01');
chequear('con el min puesto, el navegador marca el campo inválido',
  await page.evaluate(() => !document.getElementById('if').checkValidity()));

// ---- 2. El simulador también, si alguien esquiva el navegador (segunda barrera) ----
const antes = await contar('inactividades', 1);
await page.evaluate(() => document.getElementById('if').removeAttribute('min'));
await escribir('ii', hoy);
await escribir('if', '2025-09-11');
await escribir('im', 'Prueba: fin antes del inicio');
await page.click('button:has-text("Registrar período")');
await page.waitForTimeout(1600);
chequear('el simulador rechaza el período con fin anterior al inicio',
  (await contar('inactividades', 1)) === antes, `antes ${antes}, ahora ${await contar('inactividades', 1)}`);
chequear('y el motivo se explica en pantalla',
  (await page.locator('text=/no puede ser anterior al inicio/i').count()) > 0);

// ---- 3. Lo mismo en etapas de planificación ----
await irA('/proyectos/30');
if (await page.locator('#eff').count()) {
  chequear('el campo «Fin» de etapa también lleva min',
    (await page.locator('#eff').getAttribute('min')) !== null);
  const etapasAntes = await contar('etapas', null);
  await page.evaluate(() => document.getElementById('eff').removeAttribute('min'));
  await escribir('efi', hoy);
  await escribir('eff', '2025-01-01');
  const nom = page.locator('input[placeholder*="Cimientos" i]').first();
  if (await nom.count()) await nom.fill('Etapa con fechas al revés');
  const peso = page.locator('input[placeholder*="30" i]').first();
  if (await peso.count()) await peso.fill('10');
  await page.click('button:has-text("Agregar etapa")');
  await page.waitForTimeout(1600);
  chequear('el simulador rechaza la etapa con fin anterior al inicio',
    (await contar('etapas', null)) === etapasAntes, `antes ${etapasAntes}, ahora ${await contar('etapas', null)}`);
} else {
  chequear('formulario de etapas presente', false, 'no se encontró #eff');
}

await nav.close();
console.log(`\n${ok.length}/${ok.length + mal.length} OK`);
if (mal.length) process.exit(1);
