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

// ---- 3. El alta de obra sigue el CU1 (D-15, informe de testing) ----
// DEF-02: con el formulario vacio, cada uno de los seis campos dice
// "Obligatorio" y queda marcado; el navegador no muestra su propio aviso.
await irA('/proyectos');
await page.click('button:has-text("Nuevo Proyecto")');
await page.waitForTimeout(500);
const obrasAntes = await contar('proyectos', null);
await page.click('button:has-text("Registrar Proyecto")');
await page.waitForTimeout(500);
const dialogo = page.getByRole('dialog');
chequear('con el formulario vacio, los seis campos dicen "Obligatorio"',
  (await dialogo.getByText('Obligatorio', { exact: true }).count()) === 6, `hay ${await dialogo.getByText('Obligatorio', { exact: true }).count()}`);
chequear('y los seis quedan marcados como invalidos',
  (await dialogo.locator('[aria-invalid="true"]').count()) === 6, `hay ${await dialogo.locator('[aria-invalid="true"]').count()}`);
chequear('el navegador no suma su propio aviso',
  (await page.evaluate(() => document.activeElement?.validationMessage ?? '')) === '');
chequear('no se registra nada', (await contar('proyectos', null)) === obrasAntes);

// DEF-03: Cancelar con datos cargados pide confirmar con el texto del CU1.
await page.fill('#nombre', 'Obra que se cancela');
chequear('al escribir, el campo deja de marcarse',
  (await dialogo.locator('#nombre[aria-invalid="true"]').count()) === 0);
let pregunta = '';
page.once('dialog', async (d) => { pregunta = d.message(); await d.dismiss(); });
await page.click('button:has-text("Cancelar")');
await page.waitForTimeout(400);
chequear('Cancelar pide "Confirmar cancelación de registro de obra"',
  pregunta === 'Confirmar cancelación de registro de obra', pregunta);
chequear('si no se confirma, el formulario sigue abierto con lo cargado',
  (await dialogo.count()) === 1 && (await page.inputValue('#nombre')) === 'Obra que se cancela');
page.once('dialog', (d) => d.accept());
await page.click('button:has-text("Cancelar")');
await page.waitForTimeout(400);
chequear('si se confirma, se cierra', (await page.getByRole('dialog').count()) === 0);

// DEF-01: el alta dice "Obra registrada con éxito".
await page.click('button:has-text("Nuevo Proyecto")');
await page.waitForTimeout(500);
await page.fill('#nombre', `Obra de prueba ${Date.now()}`);
await page.getByRole('dialog').getByRole('combobox').first().click();
await page.getByRole('option', { name: 'Mantenimiento' }).click();
await page.fill('#encargado', 'Responsable de prueba');
await page.fill('#ubicacion', 'Posadas, Misiones');
await page.fill('#fecha', hoy);
await page.fill('#presupuesto', '100000');
await page.click('button:has-text("Registrar Proyecto")');
await page.waitForTimeout(1200);
chequear('el alta dice "Obra registrada con éxito"',
  (await page.locator('[data-sonner-toast]').filter({ hasText: 'Obra registrada con éxito' }).count()) === 1,
  await page.locator('[data-sonner-toast]').allInnerTexts().then((t) => t.join(' | ')));
chequear('y la obra queda registrada una vez', (await contar('proyectos', null)) === obrasAntes + 1);

// ---- 4. Materiales permite descartar lo cargado sin guardar (DEF-06) ----
await irA('/materiales');
await page.getByRole('combobox').first().click();
await page.getByRole('option', { name: 'Obra Vial Ruta 14' }).click();
await page.waitForTimeout(1200);
const consumosAntes = await contar('consumos', null);
const campoConsumo = page.locator('input[placeholder^="Consumo"]').first();
await campoConsumo.fill('10');
await page.click('button:has-text("Cancelar")');
await page.waitForTimeout(400);
chequear('Cancelar en Materiales vacia lo cargado', (await campoConsumo.inputValue()) === '');
chequear('y no registra ningun consumo', (await contar('consumos', null)) === consumosAntes);

// ---- 5. «Nuevo usuario» no se autocompleta con los datos de quien lo crea (D-23) ----
// El navegador rellenaba nombre y email con los del administrador y la
// contraseña con la suya: se crearian cuentas con datos ajenos sin querer.
await irA('/usuarios');
chequear('el nombre de «Nuevo usuario» lleva autocomplete="off"',
  (await page.locator('#nombre').getAttribute('autocomplete')) === 'off', String(await page.locator('#nombre').getAttribute('autocomplete')));
chequear('el email lleva autocomplete="off"',
  (await page.locator('#email').getAttribute('autocomplete')) === 'off', String(await page.locator('#email').getAttribute('autocomplete')));
chequear('la contraseña lleva autocomplete="new-password"',
  (await page.locator('#contrasena').getAttribute('autocomplete')) === 'new-password', String(await page.locator('#contrasena').getAttribute('autocomplete')));

await nav.close();
console.log(`\n${ok.length}/${ok.length + mal.length} OK`);
if (mal.length) process.exit(1);
