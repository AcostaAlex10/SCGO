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

// 6. Usuarios dados de baja: la tabla lista solo activos y los dados de baja van
// en una seccion colapsable aparte. Contexto limpio de administrador (el
// simulador guarda en localStorage, asi no queda estado de otras corridas).
{
  const ROL = 'AdministradorSistema';
  const VICTIMA = 'Claudia Rossi'; // Gerente: no es administrador ni "vos"
  const ctx = await navegador.newContext({ viewport: { width: 1400, height: 900 } });
  const page = await ctx.newPage();
  const errores = [];
  page.on('console', (m) => { if (m.type() === 'error') errores.push(m.text()); });
  page.on('pageerror', (e) => errores.push('pageerror: ' + e.message));
  const texto = async (loc) => ((await loc.first().textContent({ timeout: 3000 }).catch(() => null)) ?? '');
  const conteo = async (re) => { const m = (await texto(page.getByText(re))).match(re); return m ? Number(m[1]) : null; };
  const seccion = () => page.getByRole('button', { name: /Usuarios dados de baja \((\d+)\)/ });
  const cuenta = async () => { const m = (await texto(seccion())).match(/\((\d+)\)/); return m ? Number(m[1]) : null; };

  await page.goto(BASE, { waitUntil: 'networkidle' });
  await page.fill('input[type=email]', 'admin@sgso.test');
  await page.fill('input[type=password]', 'admin123');
  await page.click('button[type=submit]');
  await page.waitForURL((u) => !u.hash.includes('/login'), { timeout: 15000 }).catch(() => {});
  await page.goto(BASE + '#/usuarios', { waitUntil: 'networkidle' });
  await page.getByText(/Usuarios del sistema \(\d+\)/).first().waitFor({ timeout: 10000 }).catch(() => {});
  // El titulo arranca en (0) hasta que llega la lista: esperar a que haya filas.
  await page.locator('tbody tr').first().waitFor({ timeout: 10000 }).catch(() => {});
  await page.waitForTimeout(500);

  // Estado inicial del simulador: 5 activos y 1 dado de baja (Marcos Silva).
  const n0 = await conteo(/Usuarios del sistema \((\d+)\)/);
  const m0 = await cuenta();
  chequear(ROL, 'Usuarios: el titulo cuenta solo los activos (N)', n0 === 5, `N=${n0}`);
  const marcosEnTabla = await page.locator('tr:visible', { hasText: 'Marcos Silva' }).count();
  chequear(ROL, 'Usuarios: un dado de baja no aparece en la tabla principal', marcosEnTabla === 0, `filas con Marcos Silva: ${marcosEnTabla}`);

  // Seccion colapsable: empieza colapsada y muestra M.
  chequear(ROL, 'Usuarios: seccion "dados de baja (M)" visible con M=1 aun colapsada', m0 === 1, `M=${m0}`);
  const boton = seccion().first();
  chequear(ROL, 'Usuarios: la seccion empieza colapsada (aria-expanded=false)',
    (await boton.getAttribute('aria-expanded', { timeout: 3000 }).catch(() => null)) === 'false');
  await boton.click({ timeout: 3000 }).catch(() => {});
  chequear(ROL, 'Usuarios: el boton expande la seccion (aria-expanded=true)',
    (await boton.getAttribute('aria-expanded', { timeout: 3000 }).catch(() => null)) === 'true');
  const filaMarcos = page.locator('tr', { hasText: 'Marcos Silva' });
  const textoFila = await texto(filaMarcos);
  chequear(ROL, 'Usuarios: la fila de baja muestra nombre, email y rol',
    /Marcos Silva/.test(textoFila) && /tecnico2@sgso\.test/.test(textoFila) && /T[eé]cnico/i.test(textoFila), textoFila.slice(0, 80));
  chequear(ROL, 'Usuarios: la fila de baja no tiene selector de rol',
    (await filaMarcos.locator('[role=combobox]').count()) === 0 && (await filaMarcos.count()) > 0);
  chequear(ROL, 'Usuarios: la fila de baja tiene "Reactivar a Marcos Silva"',
    (await page.getByRole('button', { name: 'Reactivar a Marcos Silva' }).count()) === 1);
  await boton.click({ timeout: 3000 }).catch(() => {});
  chequear(ROL, 'Usuarios: el boton colapsa de nuevo (aria-expanded=false)',
    (await boton.getAttribute('aria-expanded', { timeout: 3000 }).catch(() => null)) === 'false');

  // El propio usuario no puede darse de baja.
  const filaYo = page.locator('tr', { hasText: '(vos)' });
  const botonYo = filaYo.getByRole('button', { name: /^Dar de baja a / });
  chequear(ROL, 'Usuarios: el boton de baja del propio usuario esta deshabilitado',
    (await botonYo.count()) === 1 && await botonYo.isDisabled());

  // Baja: confirmar en un dialogo y la fila pasa a la seccion sin recargar.
  const botonBaja = page.getByRole('button', { name: `Dar de baja a ${VICTIMA}` });
  await botonBaja.click({ timeout: 3000 }).catch(() => {});
  const dialogo = page.getByRole('dialog');
  chequear(ROL, 'Usuarios: dar de baja abre un dialogo de confirmacion', (await dialogo.count()) === 1);
  // Cancelar no cambia nada.
  await dialogo.getByRole('button', { name: /cancelar/i }).click({ timeout: 3000 }).catch(() => {});
  await page.waitForTimeout(400);
  chequear(ROL, 'Usuarios: cancelar no cambia nada',
    (await conteo(/Usuarios del sistema \((\d+)\)/)) === 5 && (await cuenta()) === 1
      && (await page.getByRole('dialog').count()) === 0);
  // Cancelar con Escape tambien cierra el dialogo sin cambiar nada.
  await botonBaja.click({ timeout: 3000 }).catch(() => {});
  const abrioEsc = (await page.getByRole('dialog').count()) === 1;
  await page.keyboard.press('Escape');
  await page.waitForTimeout(400);
  chequear(ROL, 'Usuarios: Escape cierra el dialogo y no cambia N ni M',
    abrioEsc && (await page.getByRole('dialog').count()) === 0
      && (await conteo(/Usuarios del sistema \((\d+)\)/)) === 5 && (await cuenta()) === 1);
  // Confirmar.
  await botonBaja.click({ timeout: 3000 }).catch(() => {});
  await page.getByRole('dialog').getByRole('button', { name: /confirmar|dar de baja|aceptar|s[ií]/i }).last().click({ timeout: 3000 }).catch(() => {});
  await page.waitForTimeout(800);
  chequear(ROL, 'Usuarios: confirmar muestra el aviso "Claudia Rossi dado de baja"',
    (await page.getByText(`${VICTIMA} dado de baja`).count()) > 0);
  chequear(ROL, 'Usuarios: tras la baja N-1 y M+1 sin recargar',
    (await conteo(/Usuarios del sistema \((\d+)\)/)) === 4 && (await cuenta()) === 2);

  // Reactivar sin confirmacion: vuelve a la tabla.
  const abierta = (await seccion().first().getAttribute('aria-expanded', { timeout: 3000 }).catch(() => null)) === 'true';
  if (!abierta) await seccion().first().click({ timeout: 3000 }).catch(() => {});
  await page.getByRole('button', { name: `Reactivar a ${VICTIMA}` }).click({ timeout: 3000 }).catch(() => {});
  await page.waitForTimeout(800);
  chequear(ROL, 'Usuarios: reactivar muestra el aviso "Claudia Rossi reactivado"',
    (await page.getByText(`${VICTIMA} reactivado`).count()) > 0);
  chequear(ROL, 'Usuarios: tras reactivar N+1 y M-1 sin dialogo',
    (await conteo(/Usuarios del sistema \((\d+)\)/)) === 5 && (await cuenta()) === 1
      && (await page.getByRole('dialog').count()) === 0);

  // Si M queda en 0 la seccion desaparece (reactivar a Marcos Silva).
  if ((await seccion().first().getAttribute('aria-expanded', { timeout: 3000 }).catch(() => null)) !== 'true')
    await seccion().first().click({ timeout: 3000 }).catch(() => {});
  const reactivo = await page.getByRole('button', { name: 'Reactivar a Marcos Silva' }).click({ timeout: 3000 }).then(() => true, () => false);
  await page.waitForTimeout(800);
  chequear(ROL, 'Usuarios: con M=0 la seccion de bajas desaparece', reactivo && (await seccion().count()) === 0
    && (await conteo(/Usuarios del sistema \((\d+)\)/)) === 6);

  const ruido = errores.filter((e) => !/favicon|Download the React DevTools/i.test(e));
  chequear(ROL, 'Usuarios: sin errores de consola en usuarios dados de baja', ruido.length === 0, ruido.slice(0, 2).join(' | '));

  await ctx.close(); // contexto descartable: el estado del simulador no sobrevive
}

await navegador.close();

const fallas = resultados.filter((r) => !r.ok);
console.log(`\n=== ${resultados.length - fallas.length}/${resultados.length} comprobaciones OK ===`);
if (fallas.length) {
  console.log('FALLAS:');
  for (const f of fallas) console.log(` - [${f.rol}] ${f.que} — ${f.detalle}`);
  process.exit(1);
}
