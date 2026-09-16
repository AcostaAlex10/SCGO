import { chromium } from 'playwright';
const BASE = process.env.SGSO_URL || 'http://127.0.0.1:8123/SCGO/';
const ok = [], mal = [];
const chequear = (q, c, d = '') => { (c ? ok : mal).push(q + (d ? ' — ' + d : '')); console.log(`${c ? '  OK  ' : ' FALLA'} ${q}${d ? ' — ' + d : ''}`); };

const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport: { width: 1400, height: 1100 } })).newPage();
const errores = [];
page.on('pageerror', e => errores.push(e.message));

const estadoDe = (idObra) => page.evaluate((id) => {
  const k = Object.keys(localStorage).find(x => x.startsWith('sgso_mock_db_'));
  if (!k) return null;
  const p = JSON.parse(localStorage.getItem(k)).proyectos.find(o => String(o.id) === String(id));
  return p ? p.estado : null;
}, idObra);

const irA = async (ruta) => { await page.goto(BASE + '#' + ruta, { waitUntil: 'networkidle' }); await page.waitForTimeout(1400); };
const desplazar = async () => { await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight)); await page.waitForTimeout(400); };

const entrar = async () => {
  await page.waitForTimeout(700);
  if (await page.locator('input[type=email]').count()) {
    await page.fill('input[type=email]', 'admin@sgso.test');
    await page.fill('input[type=password]', 'admin123');
    await page.click('button[type=submit]');
    await page.waitForTimeout(2500);
  }
};

await page.goto(BASE, { waitUntil: 'networkidle' });
await entrar();
// Forzamos un guardado para que exista la copia en localStorage.
await page.evaluate(() => window.sgsoMockReset());
await page.waitForTimeout(2500);
await entrar();

// 1. Los datos base ya son coherentes: la obra 2 tiene un periodo abierto.
chequear('la obra 2, con un período abierto, arranca pausada', (await estadoDe(2)) === 'pausada', await estadoDe(2));

// 2. Registrar un periodo abierto sobre la obra 1 la pausa.
await irA('/proyectos/1');
chequear('la obra 1 arranca en ejecución', (await estadoDe(1)) === 'en_ejecucion', await estadoDe(1));
await desplazar();
// La fecha de hoy en la zona horaria LOCAL, igual que la calcula la app en
// ProyectosPage.tsx. Con toISOString() a secas se obtiene la fecha UTC, que
// entre las 21 y la medianoche en Argentina ya es la del dia siguiente: la
// prueba fallaba sola si se corria de noche, sin que la app tuviera nada malo.
const hoy = (() => {
  const d = new Date();
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
})();
await page.fill('#ii', hoy);
await page.fill('#im', 'Prueba: lluvias');
await page.click('button:has-text("Registrar período")');
await page.waitForTimeout(1800);
chequear('registrar un período abierto pausa la obra', (await estadoDe(1)) === 'pausada', await estadoDe(1));
await desplazar();
chequear('aparece la insignia «Obra pausada»', await page.locator('text=Obra pausada').first().isVisible().catch(() => false));

// 3. Cerrarlo la reactiva.
await page.locator('button:has-text("Continuar obra")').first().click();
await page.waitForTimeout(1800);
chequear('«Continuar obra» reactiva la obra', (await estadoDe(1)) === 'en_ejecucion', await estadoDe(1));
await desplazar();
chequear('la insignia desaparece', (await page.locator('text=Obra pausada').count()) === 0);

// 4. Un periodo historico (ya terminado) no pausa nada.
await page.fill('#ii', '2026-01-05');
await page.fill('#if', '2026-01-20');
await page.fill('#im', 'Prueba: parada vieja ya cerrada');
await page.click('button:has-text("Registrar período")');
await page.waitForTimeout(1800);
chequear('un período ya terminado no pausa la obra', (await estadoDe(1)) === 'en_ejecucion', await estadoDe(1));

// 5. Borrar el periodo vigente tambien reactiva.
await page.fill('#ii', hoy);
await page.fill('#im', 'Prueba: para borrar');
await page.click('button:has-text("Registrar período")');
await page.waitForTimeout(1800);
chequear('vuelve a pausarse al registrar otro vigente', (await estadoDe(1)) === 'pausada', await estadoDe(1));
await desplazar();
// El boton de la fila del periodo vigente, no el primero de la lista.
const fila = page.locator('div', { hasText: 'Prueba: para borrar' }).last();
await fila.locator('button[title="Eliminar"]').click();
await page.waitForTimeout(1800);
chequear('eliminar el período vigente reactiva la obra', (await estadoDe(1)) === 'en_ejecucion', await estadoDe(1));

// 6. Una obra finalizada no revive por registrar un periodo.
await irA('/proyectos/3');
chequear('la obra 3 está finalizada', (await estadoDe(3)) === 'finalizada', await estadoDe(3));
await desplazar();
await page.fill('#ii', hoy);
await page.fill('#im', 'Prueba: sobre obra finalizada');
await page.click('button:has-text("Registrar período")');
await page.waitForTimeout(1800);
chequear('una obra finalizada no pasa a pausada', (await estadoDe(3)) === 'finalizada', await estadoDe(3));

chequear('sin errores de JavaScript', errores.length === 0, errores.slice(0, 2).join(' | '));
await nav.close();
console.log(`\n${ok.length}/${ok.length + mal.length} OK`);
if (mal.length) process.exit(1);
