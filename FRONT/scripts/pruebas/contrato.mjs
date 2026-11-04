// Reglas del contrato que no se pueden ejercitar desde la interfaz.
//
// Las otras cuatro suites manejan la pantalla, que es como lo usa una persona.
// Pero varias reglas viven solo en la capa de API: no hay boton para borrar un
// reporte ya enviado, ni para mandar un estado vacio. Sin estas pruebas, los
// defectos que cubren se arreglaron y nadie se enteraria si vuelven.
//
// Por eso el simulador expone `window.sgsoMockFetch` (ver servidor.ts): se le
// habla directo, con el mismo token que usa la app.
//
// Se corre igual que las demas, con el build estatico servido en :8123.
import { chromium } from 'playwright';

// SGSO_URL, como las otras cuatro suites. BASE queda por compatibilidad: era la
// unica que la leia, y correrla contra otra URL con SGSO_URL iba en silencio a
// localhost.
const BASE = process.env.SGSO_URL || process.env.BASE || 'http://127.0.0.1:8123/SGSO/';
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

// Llama al simulador directo. El token `mock.1` es el admin, que tiene todos
// los roles: lo que se prueba aca son las reglas de negocio, no los permisos
// (de eso se ocupa humo.mjs). Otro token solo hace falta para ver lo que
// recibe cada rol, como los importes que no le llegan al Tecnico (RF20).
const api = (metodo, ruta, cuerpo = null, token = 'mock.1') => page.evaluate(async ([m, r, c, t]) => {
  const res = await window.sgsoMockFetch(r, {
    method: m,
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${t}` },
    body: c === null ? undefined : JSON.stringify(c),
  });
  let datos = null;
  try { datos = await res.json(); } catch { /* respuesta sin cuerpo */ }
  return { estado: res.status, datos, total: res.headers.get('X-Total-Count') };
}, [metodo, ruta, cuerpo, token]);

const estadoObra = async (id) => (await api('GET', `/proyectos/${id}`)).datos?.estado;

// Fechas relativas a hoy, en la fecha local del producto (Buenos Aires, sin horario
// de verano): la alerta de consumo cuenta una ventana de 30 dias hacia atras desde
// hoy (D-13), asi que un registro con fecha fija deja de alertar con el tiempo.
const hace = (dias) => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Argentina/Buenos_Aires' })
  .format(new Date(Date.now() - dias * 86400000));
const dmy = (iso) => iso.split('-').reverse().join('/');

await page.goto(BASE, { waitUntil: 'networkidle' });
await entrar();
await page.evaluate(() => window.sgsoMockReset());
await page.waitForTimeout(2500);
await entrar();

chequear('el simulador expone la costura de prueba',
  await page.evaluate(() => typeof window.sgsoMockFetch === 'function'));

// ---- 1. Rechazar exige motivo (mock y PHP tienen que coincidir) ----
// El reporte 11 viene en revision en los datos base.
let r = await api('POST', '/reportes/11/rechazar', {});
chequear('rechazar sin motivo devuelve 422', r.estado === 422, `dio ${r.estado}`);
chequear('y el reporte sigue en revision',
  (await api('GET', '/reportes')).datos.find(x => x.id_reporte === 11)?.estado === 'en_revision');

r = await api('POST', '/reportes/11/rechazar', { observacion: '   ' });
chequear('un motivo de solo espacios tampoco alcanza', r.estado === 422, `dio ${r.estado}`);

// ---- 2. No se borra un reporte ya enviado ----
// Era el camino que dejaba la obra trabada: enviar el final y borrarlo.
chequear('la obra 1 arranca en ejecucion', await estadoObra('1') === 'en_ejecucion');

const creado = await api('POST', '/reportes', {
  id_proyecto: 1, titulo: 'Cierre de obra', contenido: 'Certificacion final', es_final: true,
});
const idFinal = creado.datos?.id_reporte;
chequear('se crea el reporte final en borrador',
  creado.estado === 201 && creado.datos?.es_final === true && creado.datos?.estado === 'borrador');

const enviado = await api('POST', `/reportes/${idFinal}/enviar`);
chequear('enviarlo lleva la obra a revision',
  enviado.datos?.estado_proyecto === 'en_revision' && await estadoObra('1') === 'en_revision',
  String(enviado.datos?.estado_proyecto));

const borrado = await api('DELETE', `/reportes/${idFinal}`);
chequear('borrar el reporte final en revision devuelve 409', borrado.estado === 409, `dio ${borrado.estado}`);
chequear('el reporte sigue existiendo',
  (await api('GET', '/reportes')).datos.some(x => x.id_reporte === idFinal));
// Una obra en revision es correcta solo si existe el reporte final que la puso
// ahi. Comprobar el estado solo no alcanza: es identico en el caso sano y en el
// trabado, que es justamente el defecto.
const enRevision = (await api('GET', '/reportes')).datos
  .some(x => x.id_proyecto === 1 && x.es_final && x.estado === 'en_revision');
chequear('la obra sigue en revision Y con su reporte final vivo',
  await estadoObra('1') === 'en_revision' && enRevision);

// La salida sigue siendo la prevista: resolverlo.
const rechazado = await api('POST', `/reportes/${idFinal}/rechazar`, { observacion: 'Faltan planos' });
chequear('rechazarlo con motivo devuelve la obra a ejecucion',
  rechazado.datos?.estado_proyecto === 'en_ejecucion' && await estadoObra('1') === 'en_ejecucion',
  String(rechazado.datos?.estado_proyecto));
chequear('y ahora si se puede borrar, porque quedo rechazado',
  (await api('DELETE', `/reportes/${idFinal}`)).estado === 200);

// ---- 3. Un estado vacio no pisa el de la obra ----
// Saltaba la validacion por venir vacio y se escribia igual.
const antesVacio = await estadoObra('1');
const conVacio = await api('PUT', '/proyectos/1', { nombre: 'Obra 1', estado: '' });
chequear('un PUT con estado vacio no falla', conVacio.estado === 200, `dio ${conVacio.estado}`);
chequear('y la obra conserva su estado',
  await estadoObra('1') === antesVacio, `${antesVacio} -> ${await estadoObra('1')}`);

// ---- 4. Una obra cancelada no recibe mas avance ----
const cancelada = await api('PUT', '/proyectos/1', { estado: 'cancelada' });
chequear('la obra 1 se puede cancelar desde ejecucion',
  cancelada.estado === 200 && await estadoObra('1') === 'cancelada');

// La planificacion 4 es la de la obra 1.
const avance = await api('POST', '/planificacion/4/avances', {
  cantidad_ejecutada: 10, porcentaje_avance: 55, fecha: '2026-09-10',
});
chequear('cargar avance en una obra cancelada devuelve 409', avance.estado === 409, `dio ${avance.estado}`);
chequear('la obra sigue cancelada', await estadoObra('1') === 'cancelada');

// ---- 5. Un documento no puede ser un enlace que ejecuta codigo (A-01) ----
// La interfaz muestra la URL como un enlace: con el esquema javascript:, abrirlo
// ejecuta codigo en la sesion de quien hace clic. PHP lo rechaza con 422 y el
// simulador tiene que hacer lo mismo. La obra 2 no se toca en los casos de arriba.
const malicioso = await api('POST', '/proyectos/2/documentos', {
  nombre: 'Plano de planta', tipo: 'pdf', url: 'javascript://x%0Aalert(document.cookie)',
});
chequear('un enlace javascript: devuelve 422', malicioso.estado === 422, `dio ${malicioso.estado}`);
const guardados = (await api('GET', '/proyectos/2/documentos')).datos ?? [];
chequear('y no queda guardado',
  !guardados.some((d) => String(d.url).toLowerCase().startsWith('javascript')));

const valido = await api('POST', '/proyectos/2/documentos', {
  nombre: 'Plano de planta', tipo: 'pdf', url: 'https://drive.google.com/file/d/abc123/view',
});
chequear('un enlace https se guarda', valido.estado === 201, `dio ${valido.estado}`);

// ---- 6. Los fallos de login se limitan por cuenta (A-03) ----
for (let i = 0; i < 5; i++) {
  r = await api('POST', '/auth/login', { email: 'tecnico@sgso.test', contrasena: 'equivocada' });
  chequear(`el fallo ${i + 1} de tecnico devuelve 401`,
    r.estado === 401 && r.datos?.error === 'Credenciales invalidas', `dio ${r.estado}`);
}
const bloqueado = await api('POST', '/auth/login', {
  email: 'tecnico@sgso.test', contrasena: 'tecnico123',
});
chequear('el sexto intento de tecnico bloquea incluso la clave correcta',
  bloqueado.estado === 429 && !bloqueado.datos?.token && bloqueado.datos?.reintentar_en_segundos > 0,
  `dio ${bloqueado.estado}`);

const otraCuenta = await api('POST', '/auth/login', {
  email: 'administrativo@sgso.test', contrasena: 'admin123',
});
chequear('el bloqueo de tecnico no afecta a administrativo', otraCuenta.estado === 200, `dio ${otraCuenta.estado}`);

r = await api('POST', '/auth/login', { email: 'tecnico2@sgso.test', contrasena: 'equivocada' });
chequear('la cuenta inactiva con clave incorrecta responde credenciales invalidas',
  r.estado === 401 && r.datos?.error === 'Credenciales invalidas', `dio ${r.estado}`);
r = await api('POST', '/auth/login', { email: 'tecnico2@sgso.test', contrasena: 'tecnico123' });
chequear('la cuenta inactiva con clave correcta responde 403', r.estado === 403, `dio ${r.estado}`);

// ---- 7. Una contraseña corta no se acepta al crear un usuario (A-05) ----
// El mínimo pasó de 6 a 10 caracteres. Vale la pena probarlo acá porque el
// simulador tiene su propia copia de la regla: si se desincroniza del PHP, la
// demo acepta contraseñas que en produccion fallan.
r = await api('POST', '/auth/register', {
  nombre: 'Prueba corta', email: `corta-${Date.now()}@sgso.test`, contrasena: 'corta123', rol: 'PersonalTecnico',
});
chequear('una contrasena de menos de 10 caracteres devuelve 422', r.estado === 422, `dio ${r.estado}`);

r = await api('POST', '/auth/register', {
  nombre: 'Prueba comun', email: `comun-${Date.now()}@sgso.test`, contrasena: 'password123', rol: 'PersonalTecnico',
});
chequear('una de las contrasenas mas usadas devuelve 422, aunque sea larga', r.estado === 422, `dio ${r.estado}`);

r = await api('POST', '/auth/register', {
  nombre: 'Prueba valida', email: `valida-${Date.now()}@sgso.test`, contrasena: 'obras-triwe-2026', rol: 'PersonalTecnico',
});
chequear('una contrasena larga y no comun se acepta', r.estado === 201, `dio ${r.estado}`);

// ---- 8. Corregir una sola fecha de una etapa se compara con la otra (C-01) ----
// La etapa 1 de los datos base va del 2026-06-29 al 2026-07-09. La API comparaba
// inicio y fin solo si llegaban los dos, y el simulador no validaba nada al editar.
r = await api('PUT', '/planificacion/etapa/1', { fecha_fin: '2026-06-01' });
chequear('corregir solo la fecha de fin a antes del inicio devuelve 422', r.estado === 422, `dio ${r.estado}`);

r = await api('PUT', '/planificacion/etapa/1', { fecha_inicio: '2026-08-01' });
chequear('corregir solo la fecha de inicio a despues del fin devuelve 422', r.estado === 422, `dio ${r.estado}`);

r = await api('PUT', '/planificacion/etapa/1', { peso_porcentual: 101 });
chequear('un peso mayor a 100 al editar devuelve 422', r.estado === 422, `dio ${r.estado}`);

r = await api('PUT', '/planificacion/etapa/1', { fecha_fin: '2026-07-20' });
chequear('corregir solo la fecha de fin dentro del rango se acepta', r.estado === 200, `dio ${r.estado}`);

// ---- 9. El uso de una maquina no acepta valores negativos ni obras inexistentes (C-01) ----
// Del uso cargado sale el promedio de la alerta de consumo (RF24). La API los
// aceptaba (y la obra inexistente le daba 500); el simulador no validaba nada.
r = await api('POST', '/maquinaria/1/registros', { fecha: '2026-03-01', horas_uso: 4, combustible_consumido: -10 });
chequear('un registro de maquinaria con combustible negativo devuelve 422', r.estado === 422, `dio ${r.estado}`);

r = await api('POST', '/maquinaria/1/registros', { fecha: '2026-03-01', horas_uso: 4, id_proyecto: 99999 });
chequear('un registro de maquinaria con una obra inexistente devuelve 422', r.estado === 422, `dio ${r.estado}`);

r = await api('POST', '/maquinaria/1/fallas', { descripcion: 'Perdida de aceite' });
chequear('una falla sin fecha devuelve 422', r.estado === 422, `dio ${r.estado}`);

r = await api('POST', '/maquinaria/1/registros', { fecha: '2026-03-01', horas_uso: 4, combustible_consumido: 20 });
chequear('un registro de maquinaria valido se acepta', r.estado === 201, `dio ${r.estado}`);

// ---- 10. Los listados que crecen se paginan a pedido (C-03) ----
// Sin ?limite= van enteros, como siempre. Con limite y desde, la pagina y el
// total en X-Total-Count, en el mismo orden que la API: lo mas reciente primero.
const todos = await api('GET', '/reportes');
chequear('sin pedir pagina, los reportes van enteros y sin total',
  todos.estado === 200 && todos.datos.length >= 3 && todos.total === null, `dio ${todos.datos?.length} y ${todos.total}`);
const fechas = todos.datos.map((x) => x.fecha_creacion);
chequear('los reportes van del mas reciente al mas viejo',
  fechas.every((f, i) => i === 0 || fechas[i - 1] >= f), fechas.join(', '));

const ids = (lista) => lista.map((x) => x.id_reporte).join(',');
r = await api('GET', '/reportes?limite=2');
chequear('la primera pagina trae las dos mas recientes y el total',
  ids(r.datos) === ids(todos.datos.slice(0, 2)) && r.total === String(todos.datos.length), `dio ${ids(r.datos)} y ${r.total}`);
r = await api('GET', '/reportes?limite=2&desde=2');
chequear('la segunda pagina sigue donde termino la primera',
  ids(r.datos) === ids(todos.datos.slice(2, 4)), `dio ${ids(r.datos)}`);

const reportesEnRevision = todos.datos.filter((x) => x.estado === 'en_revision').length;
r = await api('GET', '/reportes?estado=en_revision&limite=1');
chequear('el total respeta el filtro por estado',
  r.datos.length === 1 && r.total === String(reportesEnRevision), `dio ${r.total}, esperaba ${reportesEnRevision}`);

r = await api('GET', '/proyectos/2/asistencias?limite=abc');
chequear('un limite que no es un numero devuelve 422', r.estado === 422 && Boolean(r.datos?.errors?.limite), `dio ${r.estado}`);
r = await api('GET', '/proyectos/2/asistencias?limite=5&desde=-1');
chequear('un desde negativo devuelve 422', r.estado === 422 && Boolean(r.datos?.errors?.desde), `dio ${r.estado}`);

// En pantalla: la obra 2 con mas asistencias que una pagina muestra la primera
// y ofrece el resto, con el total real.
for (let i = 0; i < 52; i++) {
  await api('POST', '/proyectos/2/asistencias', { fecha: '2026-03-01', trabajador: `Operario ${i}`, estado: 'presente' });
}
const asistenciasObra2 = (await api('GET', '/proyectos/2/asistencias')).datos.length;
await page.goto(BASE + '#/proyectos/2', { waitUntil: 'networkidle' });
await page.waitForTimeout(1500);
const verMas = page.getByRole('button', { name: /Ver más/ });
chequear('la obra muestra el boton para ver mas asistencias con el total',
  (await verMas.count()) === 1 && (await verMas.innerText()).includes(`50 de ${asistenciasObra2}`),
  (await verMas.count()) ? await verMas.innerText() : 'sin boton');
if (await verMas.count()) {
  await verMas.click();
  await page.waitForTimeout(800);
}
chequear('ver mas trae el resto y el boton desaparece',
  (await page.getByText(/^Operario \d+$/).count()) === 52 && (await verMas.count()) === 0,
  `se ven ${await page.getByText(/^Operario \d+$/).count()}`);

// ---- 11. El consumo anomalo de maquinaria llega a Alertas (D-03) ----
// La API lo marcaba solo en el listado de la maquina. El feed tiene que traer
// una alerta por maquina, con la misma regla: mas de 1,5 veces su promedio.
// Las fechas son relativas a hoy porque solo alertan los ultimos 30 dias (D-13).
const maquina = await api('POST', '/maquinaria', { nombre: 'Excavadora de contrato', tipo: 'Movimiento de suelos' });
const idMaq = maquina.datos?.id_maquinaria;
for (const [fecha, litros] of [[hace(3), 10], [hace(2), 10], [hace(1), 40]]) {
  await api('POST', `/maquinaria/${idMaq}/registros`, { fecha, horas_uso: 1, combustible_consumido: litros, id_proyecto: 1 });
}
const obra1 = (await api('GET', '/proyectos/1')).datos?.nombre;
let feed = (await api('GET', '/analisis')).datos.alertas;
const alertaMaq = feed.find((a) => a.tipo === 'maquinaria' && a.maquina === 'Excavadora de contrato');
chequear('el consumo anomalo aparece en el feed de alertas, con la obra y la fecha',
  alertaMaq?.proyecto === obra1 && alertaMaq?.gravedad === 'media' && String(alertaMaq?.mensaje).includes(dmy(hace(1))) &&
    String(alertaMaq?.mensaje).includes('combustible por hora promedio de la máquina'),
  JSON.stringify(alertaMaq ?? null));

// El feed y el listado de cada maquina dicen lo mismo.
let discrepan = [];
for (const m of (await api('GET', '/maquinaria')).datos) {
  const marcados = (await api('GET', `/maquinaria/${m.id_maquinaria}/registros`)).datos.filter((r) => r.alerta_consumo).length;
  const alerta = feed.find((a) => a.tipo === 'maquinaria' && a.maquina === m.nombre);
  const cuenta = !alerta ? 0 : /^Un registro/.test(alerta.mensaje) ? 1 : Number(alerta.mensaje.match(/^(\d+) registros/)?.[1]);
  if (cuenta !== marcados) discrepan.push(`${m.nombre}: listado ${marcados}, feed ${cuenta}`);
}
chequear('el feed cuenta los mismos registros anomalos que el listado de cada maquina', discrepan.length === 0, discrepan.join('; '));

// Las alertas de avance usan la gravedad de la API: alta desde 15 puntos de desvio.
const analisis = (await api('GET', '/analisis')).datos;
discrepan = [];
for (const p of analisis.proyectos.filter((x) => x.alerta_avance)) {
  const alerta = analisis.alertas.find((a) => a.tipo === 'avance' && a.proyecto === p.nombre);
  const esperada = p.avance_esperado - p.avance_real >= 15 ? 'alta' : 'media';
  if (alerta?.gravedad !== esperada) discrepan.push(`${p.nombre}: ${alerta?.gravedad} en vez de ${esperada}`);
}
chequear('la gravedad de las alertas de avance es la de la API', discrepan.length === 0, discrepan.join('; '));

await page.goto(BASE + '#/alertas', { waitUntil: 'networkidle' });
await page.waitForTimeout(1500);
const filaMaquina = page.getByText('Excavadora de contrato');
chequear('la pantalla de alertas muestra la maquina y su obra',
  (await filaMaquina.count()) === 1 && (await filaMaquina.innerText()).includes(obra1),
  (await filaMaquina.count()) ? await filaMaquina.innerText() : 'no aparece');

// ---- 12. Una incidencia avisa por correo segun su gravedad (D-02) ----
// Protocolo de la API: alta a Gerentes y Personal Administrativo activos,
// media solo al Personal Administrativo, baja a nadie. El simulador no manda
// correos: responde a cuantos habria avisado.
const cuentas = (await api('GET', '/usuarios')).datos.filter((u) => u.activo !== false);
const cuantos = (...roles) => cuentas.filter((u) => roles.includes(u.rol)).length;
const esperados = { alta: cuantos('Gerente', 'PersonalAdministrativo'), media: cuantos('PersonalAdministrativo'), baja: 0 };
for (const gravedad of ['alta', 'media', 'baja']) {
  r = await api('POST', '/proyectos/1/incidencias', { fecha: '2026-03-02', tipo: 'clima', gravedad, descripcion: `Prueba ${gravedad}` });
  chequear(`una incidencia ${gravedad} avisa a ${esperados[gravedad]}`,
    r.estado === 201 && r.datos?.avisados === esperados[gravedad], `dio ${r.estado} y ${r.datos?.avisados}`);
}

// En pantalla, quien la carga ve a cuantos se aviso. La gravedad por defecto es media.
await page.goto(BASE + '#/proyectos/1', { waitUntil: 'networkidle' });
await page.waitForTimeout(1500);
await page.fill('#desc', 'Se corta la luz en la obra');
await page.locator('form').filter({ has: page.locator('#desc') }).getByRole('button', { name: /Registrar/ }).click();
await page.waitForTimeout(800);
chequear('al registrarla, la pantalla dice a cuantos se aviso por correo',
  (await page.getByText(`Se avisó por correo a ${esperados.media} persona`).count()) === 1);

// ---- 13. Una obra nueva arranca creada y pasa a planificacion con su planificacion (D-04) ----
const nueva = await api('POST', '/proyectos', {
  nombre: 'Obra de contrato D-04', tipo: 'Vivienda', ubicacion: 'Posadas', encargado: 'Ing. Prueba',
  fechaInicio: '2099-01-01', presupuesto: 1000000,
});
const idNueva = nueva.datos?.id;
chequear('una obra nueva arranca creada', nueva.estado === 201 && nueva.datos?.estado === 'creada', `dio ${nueva.estado} y ${nueva.datos?.estado}`);

await page.goto(BASE + '#/proyectos', { waitUntil: 'networkidle' });
await page.waitForTimeout(1200);
await page.getByRole('button', { name: 'Creadas', exact: true }).click();
await page.waitForTimeout(500);
chequear('el filtro de obras creadas la muestra',
  (await page.getByText('Obra de contrato D-04', { exact: true }).count()) === 1);

r = await api('POST', `/proyectos/${idNueva}/planificacion`, { fecha_carga: '2026-03-01' });
chequear('cargar su planificacion la pasa a planificacion',
  r.estado === 201 && await estadoObra(idNueva) === 'planificacion', `dio ${r.estado} y ${await estadoObra(idNueva)}`);

// ---- 14. La certificacion la calcula la API, al centavo (D-09) ----
// Antes la calculaba la pantalla, redondeada al peso. Es un importe: al
// Personal Tecnico (mock.3) no le llega (RF20).
const obraUno = (await api('GET', '/proyectos/1')).datos;
const esperado = Math.round(obraUno.presupuesto * obraUno.avance) / 100;
chequear('el detalle de la obra trae el monto certificado al centavo',
  obraUno.certificado === esperado, `dio ${obraUno.certificado}, esperaba ${esperado}`);
const comoTecnico = (await api('GET', '/proyectos/1', null, 'mock.3')).datos;
chequear('al Personal Tecnico no le llega la certificacion',
  comoTecnico && !('certificado' in comoTecnico) && !('presupuesto' in comoTecnico), JSON.stringify(Object.keys(comoTecnico ?? {})));

await page.goto(BASE + '#/proyectos/1', { waitUntil: 'networkidle' });
await page.waitForTimeout(1500);
const textoCertificado = esperado.toLocaleString('es-AR', { maximumFractionDigits: 2 });
chequear('la pantalla muestra el monto que dio la API',
  (await page.getByText(`Certificación a la fecha: $${textoCertificado}`).count()) === 1, textoCertificado);

// ---- 15. Un consumo no puede superar lo que queda asignado (DEF-05) ----
// El informe de testing encontro que se podia consumir mas de lo asignado. La
// API lo rechaza con 422 y dice cuanto queda; el simulador hace lo mismo.
const cemento = (await api('GET', '/proyectos/1/materiales')).datos.find((m) => m.nombre === 'Cemento');
const queda = cemento.restante;
r = await api('POST', `/proyectos/material/${cemento.id_asignacion}/consumos`, { fecha: '2026-03-02', cantidad_consumida: queda + 1 }, 'mock.3');
chequear('un consumo mayor a lo que queda se rechaza diciendo cuanto queda',
  r.estado === 422 && /Stock insuficiente: quedan .* bolsa/.test(r.datos?.errors?.cantidad_consumida ?? ''), `dio ${r.estado} ${JSON.stringify(r.datos)}`);
chequear('el consumo rechazado no se guarda',
  (await api('GET', '/proyectos/1/materiales')).datos.find((m) => m.nombre === 'Cemento').consumido === cemento.consumido);
r = await api('POST', `/proyectos/material/${cemento.id_asignacion}/consumos`, { fecha: '2026-03-02', cantidad_consumida: queda }, 'mock.3');
chequear('se puede consumir justo lo que queda', r.estado === 201, `dio ${r.estado}`);

// ---- 16. Una falla se marca resuelta sin borrarla (D-11) ----
// Antes, la unica forma de bajar el contador de fallas abiertas era borrar la
// falla, y con ella el historial que pide RF27.
const fallasAbiertas = async (id) => (await api('GET', '/maquinaria')).datos.find((m) => m.id_maquinaria === id)?.fallas_abiertas;
const conFalla = await api('POST', '/maquinaria', { nombre: 'Grua de contrato', tipo: 'Elevacion' });
const idGrua = conFalla.datos?.id_maquinaria;
const falla = await api('POST', `/maquinaria/${idGrua}/fallas`, { fecha: '2026-03-01', descripcion: 'Perdida de aceite' });
const idFalla = falla.datos?.id_falla;
chequear('la falla nueva cuenta como abierta', await fallasAbiertas(idGrua) === 1);

r = await api('PUT', `/maquinaria/falla/${idFalla}`, {});
chequear('marcar una falla sin decir resuelto devuelve 422', r.estado === 422, `dio ${r.estado}`);
r = await api('PUT', '/maquinaria/falla/99999', { resuelto: true });
chequear('marcar una falla inexistente devuelve 404', r.estado === 404, `dio ${r.estado}`);
r = await api('PUT', `/maquinaria/falla/${idFalla}`, { resuelto: true }, 'mock.5');
chequear('el Gerente no puede marcar una falla', r.estado === 403, `dio ${r.estado}`);

await page.goto(BASE + '#/maquinaria', { waitUntil: 'networkidle' });
await page.waitForTimeout(1000);
await page.locator('[data-slot=card]').filter({ hasText: 'Grua de contrato' })
  .getByRole('button', { name: 'Ver uso y fallas' }).click();
await page.getByRole('button', { name: 'Marcar resuelta' }).click();
await page.waitForTimeout(800);
chequear('desde la pantalla, la falla queda resuelta y el contador baja', await fallasAbiertas(idGrua) === 0);
chequear('y sigue en el historial',
  (await api('GET', `/maquinaria/${idGrua}/fallas`)).datos.some((f) => f.id_falla === idFalla && f.resuelto === true));
chequear('la pantalla la muestra resuelta', (await page.getByText('Resuelta', { exact: true }).count()) === 1);

r = await api('PUT', `/maquinaria/falla/${idFalla}`, { resuelto: false });
chequear('una falla resuelta se puede reabrir', r.estado === 200 && await fallasAbiertas(idGrua) === 1, `dio ${r.estado}`);

// ---- 17. La lista de consumos se guarda y se envia junta (D-16) ----
// DEF-04 del informe de testing. Entra entera o no entra. Usa la obra 2,
// que tiene dos materiales con stock (la 1 se agoto en la seccion 15).
const deObra2 = (await api('GET', '/proyectos/2/materiales')).datos;
const [m1, m2] = deObra2.filter((m) => m.restante >= 1);
const consumidoDe = async (id) => (await api('GET', '/proyectos/2/materiales')).datos.find((m) => m.id_asignacion === id).consumido;
r = await api('POST', '/proyectos/2/consumos', { fecha: '2026-03-02', consumos: [
  { id_asignacion: m1.id_asignacion, cantidad_consumida: 1 },
  { id_asignacion: m2.id_asignacion, cantidad_consumida: m2.restante + 1 },
] }, 'mock.3');
chequear('una lista con un material sin stock se rechaza nombrando el material',
  r.estado === 422 && Object.values(r.datos?.errors ?? {}).join(' ').includes(`${m2.nombre}: Stock insuficiente`), `dio ${r.estado} ${JSON.stringify(r.datos)}`);
chequear('y no se guarda ningun consumo de la lista',
  (await consumidoDe(m1.id_asignacion)) === m1.consumido && (await consumidoDe(m2.id_asignacion)) === m2.consumido);
r = await api('POST', '/proyectos/2/consumos', { fecha: '2026-03-02', consumos: [
  { id_asignacion: m1.id_asignacion, cantidad_consumida: 1 },
  { id_asignacion: m2.id_asignacion, cantidad_consumida: 1 },
] }, 'mock.3');
chequear('una lista valida se guarda entera', r.estado === 201 && r.datos?.registrados === 2, `dio ${r.estado} ${JSON.stringify(r.datos)}`);

// La pantalla: cargar dos materiales y "Guardar y enviar".
await page.goto(BASE + '#/materiales', { waitUntil: 'networkidle' });
await page.waitForTimeout(1200);
await page.getByRole('combobox').first().click();
await page.getByRole('option', { name: 'Edificio Residencial Los Pinos' }).click();
await page.waitForTimeout(1200);
const antes1 = await consumidoDe(m1.id_asignacion), antes2 = await consumidoDe(m2.id_asignacion);
const campos = page.locator('input[placeholder^="Consumo"]');
await campos.nth(0).fill('1');
await campos.nth(1).fill('1');
await page.click('button:has-text("Guardar y enviar")');
await page.waitForTimeout(1200);
const avisos = (await page.locator('[data-sonner-toast]').allInnerTexts()).join(' | ');
chequear('"Guardar y enviar" confirma el envio a planificacion', /enviada a planificación/.test(avisos), avisos);
chequear('y suma los dos consumos', (await consumidoDe(m1.id_asignacion)) + (await consumidoDe(m2.id_asignacion)) === antes1 + antes2 + 2);
chequear('y vacia lo cargado', (await campos.nth(0).inputValue()) === '' && (await campos.nth(1).inputValue()) === '');

// ---- 18. Ajustar la cantidad asignada de un material (D-23) ----
// Parte D: la API del simulador, que repite a MaterialObraController. Se crean
// materiales propios en la obra 2 para no depender del estado que dejaron las
// secciones anteriores.
const nuevoMaterial = async (nombre, unidad, cantidad) => {
  const mat = await api('POST', '/materiales', { nombre, unidad });
  const asig = await api('POST', '/proyectos/2/materiales', { id_material: mat.datos?.id_material, cantidad_asignada: cantidad });
  return asig.datos?.id_asignacion;
};
const filaDe = async (idAsig) => (await api('GET', '/proyectos/2/materiales')).datos.find((m) => m.id_asignacion === idAsig);
const consumirD23 = (idAsig, cantidad) => api('POST', `/proyectos/material/${idAsig}/consumos`, { cantidad_consumida: cantidad, fecha: '2026-03-02' }, 'mock.3');
const errorDe = (res) => res.datos?.errors?.cantidad_asignada;

const idYeso = await nuevoMaterial('Yeso D23 API', 'kg', 100);
await consumirD23(idYeso, 30);
await consumirD23(idYeso, 12.5);

r = await api('PUT', `/proyectos/material/${idYeso}`, { cantidad_asignada: 250.5 });
let fila = await filaDe(idYeso);
chequear('ajustar fija el valor absoluto y responde 200',
  r.estado === 200 && fila?.cantidad_asignada === 250.5 && fila?.consumido === 42.5 && fila?.restante === 208, `dio ${r.estado} ${JSON.stringify(fila)}`);
chequear('y los consumos no se tocan',
  (await api('GET', `/proyectos/material/${idYeso}/consumos`)).datos.length === 2);

r = await api('PUT', `/proyectos/material/${idYeso}`, { cantidad_asignada: 200 }, 'mock.4');
chequear('el PersonalAdministrativo puede ajustar', r.estado === 200, `dio ${r.estado}`);
await api('PUT', `/proyectos/material/${idYeso}`, { cantidad_asignada: 100 }); // base conocida
r = await api('PUT', `/proyectos/material/${idYeso}`, { cantidad_asignada: 120 }, 'mock.3');
chequear('el PersonalTecnico recibe 403', r.estado === 403, `dio ${r.estado}`);
r = await api('PUT', `/proyectos/material/${idYeso}`, { cantidad_asignada: 120 }, 'mock.5');
chequear('el Gerente recibe 403', r.estado === 403, `dio ${r.estado}`);
chequear('y el 403 no cambia la cantidad', (await filaDe(idYeso)).cantidad_asignada === 100);

for (const [valor, etiqueta] of [[undefined, 'faltante'], ['mucho', 'no numérica'], [0, 'cero'], [-5, 'negativa'], [10000000000, 'mayor al máximo de la columna']]) {
  r = await api('PUT', `/proyectos/material/${idYeso}`, valor === undefined ? {} : { cantidad_asignada: valor });
  chequear(`una cantidad ${etiqueta} da 422 «Cantidad inválida»`, r.estado === 422 && errorDe(r) === 'Cantidad inválida', `dio ${r.estado} ${JSON.stringify(r.datos)}`);
}
r = await api('PUT', `/proyectos/material/${idYeso}`, { cantidad_asignada: 9999999999.99 });
chequear('el máximo que entra en la columna se acepta', r.estado === 200, `dio ${r.estado} ${JSON.stringify(r.datos)}`);
await api('PUT', `/proyectos/material/${idYeso}`, { cantidad_asignada: 100 });

r = await api('PUT', `/proyectos/material/${idYeso}`, { cantidad_asignada: 42.49 });
chequear('menos de lo consumido da 422 y dice cuánto se consumió',
  r.estado === 422 && errorDe(r) === 'No podés asignar menos de lo ya consumido: se consumieron 42,5 kg', `dio ${r.estado} ${JSON.stringify(r.datos)}`);
chequear('y la cantidad no cambia', (await filaDe(idYeso)).cantidad_asignada === 100);
r = await api('PUT', `/proyectos/material/${idYeso}`, { cantidad_asignada: 42.5 });
fila = await filaDe(idYeso);
chequear('igual a lo consumido se acepta: restante 0 y sin exceso',
  r.estado === 200 && fila.restante === 0 && fila.excedido === false, `dio ${r.estado} ${JSON.stringify(fila)}`);

r = await api('PUT', '/proyectos/material/99999', { cantidad_asignada: 10 });
chequear('una asignación inexistente da 404 «Asignación no encontrada»',
  r.estado === 404 && r.datos?.error === 'Asignación no encontrada', `dio ${r.estado} ${JSON.stringify(r.datos)}`);

// En centésimos: 3,3 + 3,3 + 3,4 supera 10 en coma flotante.
const idCal = await nuevoMaterial('Cal D23 API', 'bolsa', 20);
for (const c of [3.3, 3.3, 3.4]) await consumirD23(idCal, c);
r = await api('PUT', `/proyectos/material/${idCal}`, { cantidad_asignada: 10 });
chequear('3,3 + 3,3 + 3,4 consumidos admiten ajustar a 10', r.estado === 200 && (await filaDe(idCal)).excedido === false, `dio ${r.estado} ${JSON.stringify(r.datos)}`);

// Formatos raros: PHP los lee igual que el simulador tiene que leerlos.
const idFormatos = await nuevoMaterial('Cemento D23 formatos', 'bolsa', 100);
for (const valor of [0.004, '0x10', '0b11']) {
  r = await api('PUT', `/proyectos/material/${idFormatos}`, { cantidad_asignada: valor });
  chequear(`ajustar a ${JSON.stringify(valor)} da 422 «Cantidad inválida»`,
    r.estado === 422 && errorDe(r) === 'Cantidad inválida', `dio ${r.estado} ${JSON.stringify(r.datos)}`);
}
r = await api('PUT', `/proyectos/material/${idFormatos}`, { cantidad_asignada: ' 5 ' });
chequear('ajustar a " 5 " se acepta como 5', r.estado === 200 && (await filaDe(idFormatos))?.cantidad_asignada === 5, `dio ${r.estado} ${JSON.stringify(r.datos)}`);
// Redondeo a centésimos como PHP: 1,005 con 1,01 consumido es 1,01, no 1,00.
const idRedondeo = await nuevoMaterial('Cal D23 redondeo', 'bolsa', 100);
await consumirD23(idRedondeo, 1.01);
r = await api('PUT', `/proyectos/material/${idRedondeo}`, { cantidad_asignada: 1.005 });
chequear('ajustar a 1,005 con 1,01 consumido se acepta y queda en 1,01',
  r.estado === 200 && (await filaDe(idRedondeo))?.cantidad_asignada === 1.01, `dio ${r.estado} ${JSON.stringify(await filaDe(idRedondeo))}`);

// Una asignación excedida se sube y deja de figurar como excedida.
const excedidosDe = async () => (await api('GET', '/analisis')).datos.proyectos.find((p) => p.id_proyecto === 2).materiales_excedidos;
const idArena = await nuevoMaterial('Arena D23 API', 'm3', 10);
// El consumo de 12 no entra por la API (CU22): se simula el exceso viejo en la copia local.
await page.evaluate((id) => {
  const k = Object.keys(localStorage).find((x) => x.startsWith('sgso_mock_db_'));
  const db = JSON.parse(localStorage.getItem(k));
  db.asignaciones.find((a) => a.id_asignacion === id).cantidad_asignada = 10;
  db.consumos.push({ id_consumo: 9000 + id, id_asignacion: id, fecha: '2026-03-02', cantidad_consumida: 12, observaciones: null });
  localStorage.setItem(k, JSON.stringify(db));
}, idArena);
await page.reload({ waitUntil: 'networkidle' });
await page.waitForTimeout(1500);
await entrar();
chequear('el exceso viejo figura como excedido', (await filaDe(idArena))?.excedido === true);
const excedidosAntes = await excedidosDe();
r = await api('PUT', `/proyectos/material/${idArena}`, { cantidad_asignada: 11 });
chequear('subirla pero no hasta lo consumido da 422', r.estado === 422, `dio ${r.estado}`);
r = await api('PUT', `/proyectos/material/${idArena}`, { cantidad_asignada: 12 });
chequear('subirla hasta lo consumido la deja sin exceso y sin restante',
  r.estado === 200 && (await filaDe(idArena)).excedido === false && (await filaDe(idArena)).restante === 0, `dio ${r.estado}`);
chequear('y el análisis ya no la cuenta como material excedido', (await excedidosDe()) === excedidosAntes - 1, `antes ${excedidosAntes}, ahora ${await excedidosDe()}`);

// El alta valida igual que PHP.
let contadorCatalogo = 0;
const materialNuevo = async () => (await api('POST', '/materiales', { nombre: `Pintura D23 API ${++contadorCatalogo}`, unidad: 'l' })).datos.id_material;
const idCatalogo = await materialNuevo();
r = await api('POST', '/proyectos/2/materiales', { cantidad_asignada: 5 });
chequear('asignar sin elegir material da 422 «Seleccioná un material»', r.estado === 422 && r.datos?.errors?.id_material === 'Seleccioná un material', `dio ${r.estado} ${JSON.stringify(r.datos)}`);
for (const valor of [0, -1, 'mucho', undefined, 10000000000]) {
  r = await api('POST', '/proyectos/2/materiales', { id_material: await materialNuevo(), cantidad_asignada: valor });
  chequear(`asignar con cantidad ${valor === undefined ? 'faltante' : valor} da 422 «Cantidad inválida»`,
    r.estado === 422 && errorDe(r) === 'Cantidad inválida', `dio ${r.estado} ${JSON.stringify(r.datos)}`);
}
chequear('y no se asigna ninguno de esos materiales',
  !(await api('GET', '/proyectos/2/materiales')).datos.some((m) => m.nombre.startsWith('Pintura D23 API')));
await api('POST', '/proyectos/2/materiales', { id_material: idCatalogo, cantidad_asignada: 5 });
r = await api('POST', '/proyectos/2/materiales', { id_material: idCatalogo, cantidad_asignada: 5 });
chequear('asignar el mismo material dos veces da 409 con el texto de PHP',
  r.estado === 409 && r.datos?.error === 'Ese material ya está asignado a la obra', `dio ${r.estado} ${JSON.stringify(r.datos)}`);

// Formatos que PHP no toma como número (0.004 se redondea a 0; hex y binario no
// son numéricos) y el espacio que sí tolera.
for (const valor of [0.004, '0x10', '0b11']) {
  r = await api('POST', '/proyectos/2/materiales', { id_material: await materialNuevo(), cantidad_asignada: valor });
  chequear(`asignar con cantidad ${JSON.stringify(valor)} da 422 «Cantidad inválida»`,
    r.estado === 422 && errorDe(r) === 'Cantidad inválida', `dio ${r.estado} ${JSON.stringify(r.datos)}`);
}
const idConEspacios = await materialNuevo();
r = await api('POST', '/proyectos/2/materiales', { id_material: idConEspacios, cantidad_asignada: ' 5 ' });
chequear('asignar con cantidad " 5 " se acepta como 5',
  r.estado === 201 && (await api('GET', '/proyectos/2/materiales')).datos.find((m) => m.id_material === idConEspacios)?.cantidad_asignada === 5,
  `dio ${r.estado} ${JSON.stringify(r.datos)}`);

// ---- 19. Parte E: la pantalla de Materiales (D-23) ----
const idUi = await nuevoMaterial('Yeso D23 UI', 'kg', 100);
await consumirD23(idUi, 30);
const idQuitar = await nuevoMaterial('Cal D23 UI', 'bolsa', 20);
await consumirD23(idQuitar, 5);

const abrirObra2 = async (p) => {
  await p.goto(BASE + '#/materiales', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);
  await p.getByRole('combobox').first().click();
  await p.getByRole('option', { name: 'Edificio Residencial Los Pinos' }).click();
  await p.waitForTimeout(1200);
};
const toasts = async (p) => (await p.locator('[data-sonner-toast]').allInnerTexts()).join(' | ');

await abrirObra2(page);
const ajustar = page.getByRole('button', { name: 'Ajustar asignación de Yeso D23 UI' });
chequear('quien gestiona ve «Ajustar asignación de {material}»', (await ajustar.count()) === 1);
chequear('y «Quitar {material} de la obra»', (await page.getByRole('button', { name: 'Quitar Yeso D23 UI de la obra' }).count()) === 1);
// Si falta un control, el clic vence por tiempo: se informa y se sigue con lo demás.
page.setDefaultTimeout(4000);
let dlg;
try {
await ajustar.click();
await page.waitForTimeout(400);
dlg = page.getByRole('dialog');
chequear('el diálogo muestra el input con la cantidad actual',
  (await dlg.getByRole('spinbutton').inputValue()) === '100', await dlg.getByRole('spinbutton').inputValue().catch(() => 'sin input'));
chequear('el diálogo avisa «Mínimo: lo ya consumido (30 kg)»', (await dlg.getByText('Mínimo: lo ya consumido (30 kg)').count()) === 1);
chequear('y tiene Cancelar y Guardar',
  (await dlg.getByRole('button', { name: 'Cancelar', exact: true }).count()) === 1 && (await dlg.getByRole('button', { name: 'Guardar', exact: true }).count()) === 1);
await dlg.getByRole('button', { name: 'Cancelar', exact: true }).click();
await page.waitForTimeout(400);
chequear('Cancelar cierra sin cambiar nada', (await page.getByRole('dialog').count()) === 0 && (await filaDe(idUi)).cantidad_asignada === 100);

await ajustar.click();
await page.waitForTimeout(400);
dlg = page.getByRole('dialog');
await dlg.getByRole('spinbutton').fill('20');
await dlg.getByRole('button', { name: 'Guardar', exact: true }).click();
await page.waitForTimeout(800);
chequear('guardar por debajo de lo consumido no cambia la cantidad', (await filaDe(idUi)).cantidad_asignada === 100, await toasts(page));
chequear('y el diálogo sigue abierto', (await page.getByRole('dialog').count()) === 1);
chequear('con el aviso «No podés asignar menos de lo ya consumido»',
  (await page.getByText(/No podés asignar menos de lo ya consumido/).count()) > 0, await toasts(page));
await page.getByRole('dialog').getByRole('spinbutton').fill('150');
await page.getByRole('dialog').getByRole('button', { name: 'Guardar', exact: true }).click();
await page.waitForTimeout(1000);
chequear('guardar muestra «Asignación actualizada»', /Asignación actualizada/.test(await toasts(page)), await toasts(page));
chequear('y la fila pasa a «30 / 150 kg · restante 120 kg»', (await page.getByText('30 / 150 kg · restante 120 kg').count()) === 1);
chequear('con el valor guardado en el simulador', (await filaDe(idUi)).cantidad_asignada === 150);
} catch (e) {
  chequear('el flujo de ajustar desde la pantalla se completa', false, String(e.message).split('\n')[0]);
  if (await page.getByRole('dialog').count()) await page.keyboard.press('Escape');
}

try {
await page.getByRole('button', { name: 'Quitar Cal D23 UI de la obra' }).click();
await page.waitForTimeout(400);
dlg = page.getByRole('dialog');
chequear('quitar pide confirmación y avisa que se borran los consumos',
  (await dlg.count()) === 1 && /consumos/i.test(await dlg.innerText()), await dlg.innerText().catch(() => 'sin diálogo'));
chequear('con Cancelar y Quitar',
  (await dlg.getByRole('button', { name: 'Cancelar', exact: true }).count()) === 1 && (await dlg.getByRole('button', { name: 'Quitar', exact: true }).count()) === 1);
await dlg.getByRole('button', { name: 'Cancelar', exact: true }).click();
await page.waitForTimeout(400);
chequear('Cancelar no borra la asignación', (await filaDe(idQuitar)) !== undefined);
await page.getByRole('button', { name: 'Quitar Cal D23 UI de la obra' }).click();
await page.waitForTimeout(400);
await page.getByRole('dialog').getByRole('button', { name: 'Quitar', exact: true }).click();
await page.waitForTimeout(1000);
chequear('Quitar borra la asignación y sus consumos',
  (await filaDe(idQuitar)) === undefined && (await page.getByText('Cal D23 UI').count()) === 0);
} catch (e) {
  chequear('el flujo de quitar con confirmación se completa', false, String(e.message).split('\n')[0]);
}
page.setDefaultTimeout(30000);

// Los roles que no gestionan obras no ven ninguno de los dos botones. Cada uno
// entra en su propia sesión (los datos base traen materiales en la obra 2).
for (const cuenta of [
  { rol: 'PersonalTecnico', email: 'tecnico@sgso.test', pass: 'tecnico123' },
  { rol: 'Gerente', email: 'gerente@sgso.test', pass: 'gerente123' },
]) {
  const ctx = await nav.newContext({ viewport: { width: 1400, height: 1000 } });
  const p = await ctx.newPage();
  await p.goto(BASE, { waitUntil: 'networkidle' });
  await p.waitForTimeout(700);
  await p.fill('input[type=email]', cuenta.email);
  await p.fill('input[type=password]', cuenta.pass);
  await p.click('button[type=submit]');
  await p.waitForTimeout(2500);
  await abrirObra2(p);
  chequear(`${cuenta.rol}: la obra muestra materiales`, (await p.getByText('Ladrillo comun').count()) > 0);
  chequear(`${cuenta.rol}: no ve «Ajustar asignación»`, (await p.getByRole('button', { name: /Ajustar asignación/ }).count()) === 0);
  chequear(`${cuenta.rol}: no ve «Quitar … de la obra»`, (await p.getByRole('button', { name: /Quitar .* de la obra/ }).count()) === 0);
  if (cuenta.rol === 'PersonalTecnico') {
    // El técnico sigue registrando consumos: ajustar no le quita nada.
    try {
      p.setDefaultTimeout(4000);
      chequear('PersonalTecnico: el material parte en «9000 / 15000 unidad»', (await p.getByText(/^9000 \/ 15000 unidad/).count()) === 1);
      await p.locator('input[placeholder^="Consumo"]').first().fill('1');
      await p.getByRole('button', { name: 'Registrar consumo' }).first().click();
      await p.waitForTimeout(1000);
      chequear('PersonalTecnico: registra un consumo y el consumido sube a 9001',
        (await p.getByText(/^9001 \/ 15000 unidad/).count()) === 1, await toasts(p));
    } catch (e) {
      chequear('PersonalTecnico: registra un consumo desde Materiales', false, String(e.message).split('\n')[0]);
    }
  }
  await ctx.close();
}

// ---- 20. Los importes solo llegan a quien esta en la lista blanca (RF20, A-15) ----
// La API decide con Permisos::VER_COSTOS y el simulador tiene que hacer lo mismo.
// La planificacion 4 es la de la obra 1.
const etapasComo = (token) => api('GET', '/planificacion/4/etapas', null, token);
for (const [rol, token] of [['AdministradorSistema', 'mock.1'], ['PersonalAdministrativo', 'mock.4'], ['Gerente', 'mock.5']]) {
  r = await etapasComo(token);
  chequear(`${rol}: las etapas traen presupuesto_base`,
    r.estado === 200 && r.datos.length > 0 && r.datos.every((e) => typeof e.presupuesto_base === 'number'), `dio ${r.estado} ${JSON.stringify(r.datos?.[0])}`);
}
r = await etapasComo('mock.3');
chequear('al Personal Tecnico le llegan las etapas sin presupuesto_base',
  r.estado === 200 && r.datos.length > 0 && r.datos.every((e) => !('presupuesto_base' in e)), `dio ${r.estado} ${JSON.stringify(r.datos?.[0])}`);

// Un rol que no esta en la lista no ve plata. La API no deja crearlo; el simulador
// si, y es la forma de probar que la lista es blanca y no negra.
const rolNuevo = { nombre: 'Rol nuevo', email: `auditor-${Date.now()}@sgso.test`, contrasena: 'obras-triwe-2026', rol: 'Auditor' };
const auditor = await api('POST', '/auth/register', rolNuevo);
const tokenAuditor = `mock.${auditor.datos?.id_usuario}`;
chequear('se crea una cuenta con un rol que no esta en la lista', auditor.estado === 201, `dio ${auditor.estado}`);
r = await etapasComo(tokenAuditor);
chequear('un rol nuevo recibe las etapas sin presupuesto_base',
  r.estado === 200 && r.datos.length > 0 && r.datos.every((e) => !('presupuesto_base' in e)), `dio ${r.estado} ${JSON.stringify(r.datos?.[0])}`);
r = await api('GET', '/proyectos/1', null, tokenAuditor);
chequear('un rol nuevo recibe la obra sin importes',
  r.estado === 200 && !('presupuesto' in r.datos) && !('certificado' in r.datos), JSON.stringify(Object.keys(r.datos ?? {})));
r = await api('GET', '/analisis', null, tokenAuditor);
chequear('un rol nuevo recibe el analisis sin importes',
  r.estado === 200 && r.datos.proyectos.every((p) => !('presupuesto' in p) && !('ejecutado' in p) && !('presupuesto_base_total' in p)), JSON.stringify(Object.keys(r.datos?.proyectos?.[0] ?? {})));

// En pantalla, puedeVerCostos usa la misma lista. Se entra en esta misma pagina
// porque la cuenta nueva vive en su localStorage.
const entrarYMirarTablero = async (email, pass) => {
  await page.evaluate(() => { localStorage.removeItem('sgso_token'); localStorage.removeItem('sgso_usuario'); });
  await page.goto(BASE + '#/', { waitUntil: 'networkidle' });
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForTimeout(700);
  await page.fill('input[type=email]', email);
  await page.fill('input[type=password]', pass);
  await page.click('button[type=submit]');
  await page.waitForTimeout(2500);
  return {
    entro: (await page.locator('input[type=password]').count()) === 0,
    presupuesto: (await page.getByText('Presupuesto Total', { exact: true }).count()) > 0,
  };
};
const tableroGerente = await entrarYMirarTablero('gerente@sgso.test', 'gerente123');
chequear('el Gerente ve «Presupuesto Total» en el tablero', tableroGerente.entro && tableroGerente.presupuesto, JSON.stringify(tableroGerente));
const tableroAuditor = await entrarYMirarTablero(rolNuevo.email, rolNuevo.contrasena);
chequear('un rol nuevo entra y no ve «Presupuesto Total»', tableroAuditor.entro && !tableroAuditor.presupuesto, JSON.stringify(tableroAuditor));

// ---- 21. Consumo esperado por hora de una maquina y ventana de la alerta (D-13, RF24) ----
// Antes, una maquina que siempre consumia de mas no alertaba nunca (se
// comparaba contra su propio promedio) y un registro anomalo alertaba para
// siempre. Ahora: con dato esperado se compara contra el, y solo alertan los
// registros de los ultimos 30 dias (borde inclusivo). La ventana no achica el
// promedio. Mismas reglas que Sgso\Reglas\ConsumoMaquinaria.
const MENSAJE_ESPERADO = 'Debe ser un número mayor a 0';
const esperadoDe = async (id) => (await api('GET', '/maquinaria')).datos.find((m) => m.id_maquinaria === id)?.consumo_esperado_por_hora;
const alertasDe = async (id) => (await api('GET', `/maquinaria/${id}/registros`)).datos.map((x) => x.alerta_consumo);
const cargarUso = (id, fecha, litros, horas = 1) =>
  api('POST', `/maquinaria/${id}/registros`, { fecha, horas_uso: horas, combustible_consumido: litros });
const nuevaMaquina = async (nombre, esperado) =>
  (await api('POST', '/maquinaria', { nombre, tipo: 'Excavacion', ...(esperado === undefined ? {} : { consumo_esperado_por_hora: esperado }) })).datos?.id_maquinaria;

// Crear: sin el campo sigue dando 201 y sin dato; con el, se guarda como numero.
r = await api('POST', '/maquinaria', { nombre: 'Sin esperado', tipo: 'Excavacion' });
chequear('crear una maquina sin consumo esperado da 201 y queda en null',
  r.estado === 201 && r.datos.consumo_esperado_por_hora === null && await esperadoDe(r.datos.id_maquinaria) === null, `dio ${r.estado}`);
r = await api('POST', '/maquinaria', { nombre: 'Con esperado', tipo: 'Excavacion', consumo_esperado_por_hora: '7.5' });
chequear('crear una maquina con consumo esperado lo guarda como numero',
  r.estado === 201 && await esperadoDe(r.datos.id_maquinaria) === 7.5, `dio ${r.estado}`);
for (const invalido of [0, -3, '0', 'abc', true, 1000000]) {
  r = await api('POST', '/maquinaria', { nombre: 'Invalida', tipo: 'Excavacion', consumo_esperado_por_hora: invalido });
  chequear(`crear con consumo esperado ${JSON.stringify(invalido)} da 422`,
    r.estado === 422 && Boolean(r.datos?.errors?.consumo_esperado_por_hora), `dio ${r.estado}`);
}
r = await api('POST', '/maquinaria', { nombre: 'Invalida', tipo: 'Excavacion', consumo_esperado_por_hora: 0 });
chequear('el mensaje del 422 es «Debe ser un número mayor a 0»', r.datos?.errors?.consumo_esperado_por_hora === MENSAJE_ESPERADO, JSON.stringify(r.datos));
chequear('y no se guarda ninguna de las invalidas', (await api('GET', '/maquinaria')).datos.every((m) => m.nombre !== 'Invalida'));

// PUT /maquinaria/{id}: cargar, cambiar y borrar; solo toca ese dato.
const idRetro = await nuevaMaquina('Retro de contrato');
r = await api('PUT', `/maquinaria/${idRetro}`, { consumo_esperado_por_hora: 8, nombre: 'Otro nombre', tipo: 'Otro' });
chequear('PUT carga el consumo esperado', r.estado === 200 && r.datos.consumo_esperado_por_hora === 8 && await esperadoDe(idRetro) === 8, `dio ${r.estado}`);
const retro = (await api('GET', '/maquinaria')).datos.find((m) => m.id_maquinaria === idRetro);
chequear('y no cambia el nombre ni el tipo', retro.nombre === 'Retro de contrato' && retro.tipo === 'Excavacion', JSON.stringify(retro));
r = await api('PUT', `/maquinaria/${idRetro}`, { consumo_esperado_por_hora: '9.25' });
chequear('PUT lo cambia', r.estado === 200 && await esperadoDe(idRetro) === 9.25, `dio ${r.estado}`);
for (const vacio of [null, '', '  ']) {
  await api('PUT', `/maquinaria/${idRetro}`, { consumo_esperado_por_hora: 8 });
  r = await api('PUT', `/maquinaria/${idRetro}`, { consumo_esperado_por_hora: vacio });
  chequear(`PUT con ${JSON.stringify(vacio)} borra el dato`, r.estado === 200 && await esperadoDe(idRetro) === null, `dio ${r.estado}`);
}
await api('PUT', `/maquinaria/${idRetro}`, { consumo_esperado_por_hora: 8 });
for (const invalido of [0, -1, 'abc']) {
  r = await api('PUT', `/maquinaria/${idRetro}`, { consumo_esperado_por_hora: invalido });
  chequear(`PUT con ${JSON.stringify(invalido)} da 422 con el mensaje`,
    r.estado === 422 && r.datos?.errors?.consumo_esperado_por_hora === MENSAJE_ESPERADO, `dio ${r.estado} ${JSON.stringify(r.datos)}`);
}
r = await api('PUT', `/maquinaria/${idRetro}`, {});
chequear('PUT sin el campo da 422 y no borra el dato', r.estado === 422 && await esperadoDe(idRetro) === 8, `dio ${r.estado}`);
r = await api('PUT', '/maquinaria/99999', { consumo_esperado_por_hora: 8 });
chequear('PUT de una maquina inexistente da 404', r.estado === 404 && r.datos?.error === 'Maquinaria no encontrada', `dio ${r.estado} ${JSON.stringify(r.datos)}`);
chequear('PUT /maquinaria/falla/{id} sigue siendo el de las fallas',
  (await api('PUT', '/maquinaria/falla/99999', { resuelto: true })).datos?.error === 'Falla no encontrada');
r = await api('PUT', `/maquinaria/${idRetro}`, { consumo_esperado_por_hora: 5 }, 'mock.3');
chequear('el Personal Tecnico no puede cargar el consumo esperado', r.estado === 403, `dio ${r.estado}`);
r = await api('PUT', `/maquinaria/${idRetro}`, { consumo_esperado_por_hora: 5 }, 'mock.5');
chequear('el Gerente tampoco', r.estado === 403, `dio ${r.estado}`);
r = await api('PUT', `/maquinaria/${idRetro}`, { consumo_esperado_por_hora: 5 }, 'mock.4');
chequear('el Personal Administrativo si', r.estado === 200, `dio ${r.estado}`);
for (const [rol, token] of [['PersonalTecnico', 'mock.3'], ['Gerente', 'mock.5']]) {
  const visto = (await api('GET', '/maquinaria', null, token)).datos.find((m) => m.id_maquinaria === idRetro);
  chequear(`${rol} ve el consumo esperado (son litros, no importes)`, visto?.consumo_esperado_por_hora === 5, JSON.stringify(visto));
}
await api('PUT', `/maquinaria/${idRetro}`, { consumo_esperado_por_hora: null });

// La maquina que siempre consume el doble de lo esperado: sin dato nunca alerta, con dato siempre.
const idSinDato = await nuevaMaquina('Siempre el doble, sin dato');
const idConDato = await nuevaMaquina('Siempre el doble, con dato', 8);
for (const id of [idSinDato, idConDato]) {
  for (const dias of [3, 2, 1]) await cargarUso(id, hace(dias), 16);
}
chequear('sin dato esperado, la que siempre consume el doble no alerta',
  (await alertasDe(idSinDato)).every((a) => a === false));
chequear('con dato esperado, la que siempre consume el doble alerta en todos sus registros',
  (await alertasDe(idConDato)).length === 3 && (await alertasDe(idConDato)).every((a) => a === true));
let feedD13 = (await api('GET', '/analisis')).datos.alertas.filter((a) => a.tipo === 'maquinaria');
chequear('el feed la trae con el texto del esperado, sin «1,5» fijo en otro lado',
  feedD13.find((a) => a.maquina === 'Siempre el doble, con dato')?.mensaje ===
    `3 registros de uso consumieron más de 1,5 veces el combustible por hora esperado de la máquina (8 L/h); el último, del ${dmy(hace(1))}.`,
  JSON.stringify(feedD13.find((a) => a.maquina === 'Siempre el doble, con dato')));
chequear('y a la que no tiene dato no la trae', !feedD13.some((a) => a.maquina === 'Siempre el doble, sin dato'));

// Borrar el dato devuelve la maquina a su promedio.
await api('PUT', `/maquinaria/${idConDato}`, { consumo_esperado_por_hora: null });
chequear('al borrar el dato, la maquina vuelve a compararse contra su promedio y deja de alertar',
  (await alertasDe(idConDato)).every((a) => a === false));

// Borde de 1,5 contra el esperado y de la ventana.
const idBorde = await nuevaMaquina('Borde', 8);
await cargarUso(idBorde, hace(1), 24, 2);
await cargarUso(idBorde, hace(2), 24.1, 2);
chequear('justo 1,5 veces lo esperado no alerta; apenas mas, si',
  JSON.stringify(await alertasDe(idBorde)) === JSON.stringify([false, true]), JSON.stringify(await alertasDe(idBorde)));

const idVentana = await nuevaMaquina('Ventana', 8);
await cargarUso(idVentana, hace(31), 40);
await cargarUso(idVentana, hace(30), 40);
await cargarUso(idVentana, hace(0), 40);
chequear('hace 31 dias no alerta; hace 30 y hoy, si',
  JSON.stringify(await alertasDe(idVentana)) === JSON.stringify([true, true, false]), JSON.stringify(await alertasDe(idVentana)));
feedD13 = (await api('GET', '/analisis')).datos.alertas.filter((a) => a.tipo === 'maquinaria');
chequear('el feed cuenta los mismos dos y no el de hace 31 dias',
  /^2 registros/.test(feedD13.find((a) => a.maquina === 'Ventana')?.mensaje ?? ''), JSON.stringify(feedD13.find((a) => a.maquina === 'Ventana')));

// La ventana no achica el promedio: (2 + 2 + 2 + 30) / 4 = 9 y el umbral es 13,5; si
// el promedio saliera solo de la ventana seria 30 y el registro reciente no alertaria.
const idPromedio = await nuevaMaquina('Promedio completo');
for (const dias of [60, 61, 62]) await cargarUso(idPromedio, hace(dias), 2);
await cargarUso(idPromedio, hace(2), 30);
chequear('el promedio sigue saliendo de todos los registros: el reciente alerta',
  (await alertasDe(idPromedio))[0] === true, JSON.stringify(await alertasDe(idPromedio)));

// Un registro con fecha futura no esta en los ultimos 30 dias: no alerta, ni en el
// listado ni en el feed. Uno de hoy sigue alertando.
const idFuturo = await nuevaMaquina('Fecha futura', 8);
await cargarUso(idFuturo, '2099-01-01', 40);
await cargarUso(idFuturo, hace(0), 40);
chequear('un registro con fecha futura no alerta y el de hoy si',
  JSON.stringify(await alertasDe(idFuturo)) === JSON.stringify([false, true]), JSON.stringify(await alertasDe(idFuturo)));
feedD13 = (await api('GET', '/analisis')).datos.alertas.filter((a) => a.tipo === 'maquinaria');
chequear('el feed cuenta solo el de hoy y no el futuro',
  feedD13.find((a) => a.maquina === 'Fecha futura')?.mensaje?.startsWith(`Un registro de uso, del ${dmy(hace(0))}`) === true,
  JSON.stringify(feedD13.find((a) => a.maquina === 'Fecha futura')));

// Borde exacto de 1,5 veces lo esperado con datos de 2 decimales: no depende de como
// la coma flotante redondea el producto (5,10 * 1,5 da 7,6499999...).
for (const [esperado, horas, litros] of [[5.1, 2, 15.3], [5.1, 4, 30.6], [4.1, 2, 12.3], [3.3, 10, 49.5], [6.6, 5, 49.5], [8.2, 5, 61.5], [1.9, 10, 28.5]]) {
  const idB = await nuevaMaquina(`Borde ${esperado} x ${horas}`, esperado);
  await cargarUso(idB, hace(1), litros, horas);
  chequear(`justo 1,5 veces el esperado (${esperado} L/h; ${litros} L en ${horas} h) no alerta`,
    JSON.stringify(await alertasDe(idB)) === JSON.stringify([false]), JSON.stringify(await alertasDe(idB)));
}
const idBordeArriba = await nuevaMaquina('Borde 5,10 apenas arriba', 5.1);
await cargarUso(idBordeArriba, hace(1), 15.32, 2);
chequear('y 7,66 L/h contra un umbral de 7,65 si alerta',
  JSON.stringify(await alertasDe(idBordeArriba)) === JSON.stringify([true]), JSON.stringify(await alertasDe(idBordeArriba)));

// El PUT redondea a 2 decimales como la API (PHP round(1.005, 2) da 1,01).
r = await api('PUT', `/maquinaria/${idRetro}`, { consumo_esperado_por_hora: 1.005 });
chequear('PUT con 1.005 guarda 1,01, como la API',
  r.estado === 200 && r.datos?.consumo_esperado_por_hora === 1.01 && await esperadoDe(idRetro) === 1.01, `dio ${r.estado} ${JSON.stringify(r.datos)}`);

// Un id con basura detras no es la maquina: 404 y el dato no se toca.
await api('PUT', `/maquinaria/${idRetro}`, { consumo_esperado_por_hora: 8 });
r = await api('PUT', `/maquinaria/${idRetro}abc`, { consumo_esperado_por_hora: 3 });
chequear('PUT /maquinaria/{id}abc da 404 y no cambia la maquina',
  r.estado === 404 && await esperadoDe(idRetro) === 8, `dio ${r.estado} ${JSON.stringify(r.datos)}`);

// El feed y el listado de cada maquina siguen diciendo lo mismo, con y sin dato.
feedD13 = (await api('GET', '/analisis')).datos.alertas.filter((a) => a.tipo === 'maquinaria');
discrepan = [];
for (const m of (await api('GET', '/maquinaria')).datos) {
  const marcados = (await alertasDe(m.id_maquinaria)).filter(Boolean).length;
  const alerta = feedD13.find((a) => a.maquina === m.nombre);
  const cuenta = !alerta ? 0 : /^Un registro/.test(alerta.mensaje) ? 1 : Number(alerta.mensaje.match(/^(\d+) registros/)?.[1]);
  if (cuenta !== marcados) discrepan.push(`${m.nombre}: listado ${marcados}, feed ${cuenta}`);
}
chequear('con la ventana y el dato esperado, el feed y el listado de cada maquina coinciden', discrepan.length === 0, discrepan.join('; '));

// En pantalla. Cada cuenta entra en su propio contexto, que parte de los datos base.
const entrarComo = async (email, pass) => {
  const ctx = await nav.newContext({ viewport: { width: 1400, height: 1000 } });
  const p = await ctx.newPage();
  await p.goto(BASE, { waitUntil: 'networkidle' });
  await p.waitForTimeout(700);
  await p.fill('input[type=email]', email);
  await p.fill('input[type=password]', pass);
  await p.click('button[type=submit]');
  await p.waitForTimeout(2500);
  await p.goto(BASE + '#/maquinaria', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1000);
  return { ctx, p };
};
const tarjeta = (p, nombre) => p.locator('[data-slot=card]').filter({ hasText: nombre }).first();

{
  const { ctx, p } = await entrarComo('admin@sgso.test', 'admin123');
  const retroTarjeta = tarjeta(p, 'Retroexcavadora CAT 320');
  chequear('el administrador ve «sin dato esperado» en una maquina sin dato',
    (await retroTarjeta.innerText()).includes('sin dato esperado'));
  chequear('y el campo opcional «Consumo esperado (L/h)» al crear una maquina',
    (await p.getByLabel('Consumo esperado (L/h)').count()) === 1);

  await p.getByLabel('Nueva máquina').fill('Pala de pantalla');
  await p.getByLabel('Tipo').fill('Carga');
  await p.getByLabel('Consumo esperado (L/h)').fill('6.5');
  await p.getByRole('button', { name: 'Agregar' }).click();
  await p.waitForTimeout(800);
  chequear('crear una maquina con consumo esperado lo muestra junto al real',
    (await tarjeta(p, 'Pala de pantalla').innerText()).includes('esperado: 6,5 L/h'));

  await p.locator('#esperado-editar-1').click();
  await p.waitForTimeout(400);
  chequear('«Consumo esperado» abre el dialogo con el campo enfocable y su etiqueta',
    (await p.getByRole('dialog').getByLabel('Consumo esperado (L/h)').count()) === 1);
  await p.getByRole('dialog').getByLabel('Consumo esperado (L/h)').fill('0');
  await p.getByRole('dialog').getByRole('button', { name: 'Guardar' }).click();
  await p.waitForTimeout(600);
  chequear('un valor invalido muestra el error del servidor, asociado al campo',
    (await p.getByRole('alert').filter({ hasText: MENSAJE_ESPERADO }).count()) === 1 &&
      (await p.locator('#esperado-valor').getAttribute('aria-describedby')).includes('esperado-error') &&
      (await p.locator('#esperado-valor').getAttribute('aria-invalid')) === 'true');
  chequear('y deja el foco en el campo', await p.evaluate(() => document.activeElement?.id === 'esperado-valor'));
  await p.locator('#esperado-valor').fill('8');
  await p.getByRole('dialog').getByRole('button', { name: 'Guardar' }).click();
  await p.waitForTimeout(800);
  chequear('guardar un valor valido cierra el dialogo y la tarjeta muestra el esperado',
    (await p.getByRole('dialog').count()) === 0 && (await retroTarjeta.innerText()).includes('esperado: 8 L/h'));
  chequear('y el foco vuelve al boton que abrio el dialogo', await p.evaluate(() => document.activeElement?.id === 'esperado-editar-1'));

  await p.locator('#esperado-editar-1').click();
  await p.waitForTimeout(400);
  await p.locator('#esperado-valor').fill('');
  await p.getByRole('dialog').getByRole('button', { name: 'Guardar' }).click();
  await p.waitForTimeout(800);
  chequear('dejar el campo vacio quita el dato',
    (await retroTarjeta.innerText()).includes('sin dato esperado'));
  await ctx.close();
}

for (const cuenta of [
  { rol: 'PersonalTecnico', email: 'tecnico@sgso.test', pass: 'tecnico123' },
  { rol: 'Gerente', email: 'gerente@sgso.test', pass: 'gerente123' },
]) {
  const { ctx, p } = await entrarComo(cuenta.email, cuenta.pass);
  // Se carga el dato con el administrador, en el mismo contexto, y se vuelve a mirar.
  await p.evaluate(() => window.sgsoMockFetch('/maquinaria/1', {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer mock.1' },
    body: JSON.stringify({ consumo_esperado_por_hora: 8 }),
  }));
  await p.reload({ waitUntil: 'networkidle' });
  await p.waitForTimeout(1000);
  chequear(`${cuenta.rol}: ve el consumo esperado en la tarjeta`,
    (await tarjeta(p, 'Retroexcavadora CAT 320').innerText()).includes('esperado: 8 L/h'));
  chequear(`${cuenta.rol}: no puede editarlo`,
    (await p.getByRole('button', { name: 'Consumo esperado' }).count()) === 0 &&
      (await p.getByLabel('Consumo esperado (L/h)').count()) === 0);
  await ctx.close();
}

// Entrada invalida en el dialogo: con keyboard.type (fill() no la reproduce) un campo
// numerico con "1e" o "-" queda con value "". Eso no es "dejar vacio para quitar el
// dato": el dato se conserva y se avisa.
for (const tecleado of ['1e', '-']) {
  const { ctx, p } = await entrarComo('admin@sgso.test', 'admin123');
  await p.evaluate(() => window.sgsoMockFetch('/maquinaria/1', {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer mock.1' },
    body: JSON.stringify({ consumo_esperado_por_hora: 8 }),
  }));
  await p.reload({ waitUntil: 'networkidle' });
  await p.waitForTimeout(1000);
  await p.locator('#esperado-editar-1').click();
  await p.waitForTimeout(400);
  await p.locator('#esperado-valor').click();
  await p.keyboard.press('Control+A');
  await p.keyboard.press('Delete');
  await p.keyboard.type(tecleado);
  await p.getByRole('dialog').getByRole('button', { name: 'Guardar' }).click();
  await p.waitForTimeout(800);
  const guardado = await p.evaluate(async () => (await (await window.sgsoMockFetch('/maquinaria', {
    headers: { Authorization: 'Bearer mock.1' },
  })).json()).find((m) => m.id_maquinaria === 1)?.consumo_esperado_por_hora);
  chequear(`teclear ${JSON.stringify(tecleado)} y Guardar conserva el dato esperado`, guardado === 8, `quedo ${JSON.stringify(guardado)}`);
  chequear(`y muestra un error en el dialogo, no «Consumo esperado quitado»`,
    (await p.getByRole('dialog').count()) === 1 && (await p.getByRole('dialog').getByRole('alert').count()) === 1 &&
      (await p.getByText('Consumo esperado quitado').count()) === 0);
  await ctx.close();
}

await nav.close();
console.log(`\n${ok.length}/${ok.length + mal.length} OK`);
if (mal.length) process.exit(1);
