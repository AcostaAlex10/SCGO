<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Sgso\AnalisisController;
use Sgso\AsistenciaController;
use Sgso\AuthController;
use Sgso\AuthMiddleware;
use Sgso\AvanceController;
use Sgso\Cors;
use Sgso\Database;
use Sgso\DocumentoController;
use Sgso\Env;
use Sgso\EtapaPlanificacionController;
use Sgso\Http\ManejadorErrores;
use Sgso\Http\Salud;
use Sgso\Monitoreo\Sentry;
use Sgso\InactividadController;
use Sgso\IncidenciaController;
use Sgso\ItemExcedenteController;
use Sgso\MaquinariaController;
use Sgso\MaterialController;
use Sgso\MaterialObraController;
use Sgso\MySqlProyectoRepository;
use Sgso\PlanificacionController;
use Sgso\ProyectoController;
use Sgso\Reglas\Permisos;
use Sgso\ReporteController;
use Sgso\Ruteo\Despachador;
use Sgso\Ruteo\Resolucion;
use Sgso\Ruteo\Tabla;
use Sgso\Seguridad\SecretoJwt;
use Sgso\UsuarioController;

// Cualquier error sin atrapar termina acá: el detalle va al log del servidor y
// el cliente recibe un 500 genérico con un código de referencia (A-04). Va
// primero para cubrir también los fallos de configuración y de conexión.
set_exception_handler(static function (Throwable $error): void {
    $pedido = [
        'metodo' => (string) ($_SERVER['REQUEST_METHOD'] ?? '-'),
        'ruta' => (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/'),
    ];
    $cuerpo = ManejadorErrores::atender($error, 'error_log', $pedido);

    // Además del log, el error va a Sentry si hay SENTRY_DSN en el entorno
    // (B-04). Sin esa variable el monitoreo queda apagado y no pasa nada más.
    // Los valores de la base se le pasan para que no salgan del servidor: el
    // mensaje de un PDOException los trae.
    Sentry::desdeDsn(
        Env::get('SENTRY_DSN'),
        (string) Env::get('SENTRY_ENTORNO', 'produccion'),
        array_values(array_filter([
            Env::get('DB_HOST'),
            Env::get('DB_USER'),
            Env::get('DB_PASSWORD'),
            Env::get('JWT_SECRET'),
            Env::get('BREVO_API_KEY'),
        ], static fn (?string $valor): bool => $valor !== null && $valor !== ''))
    )?->reportar($error, $cuerpo['referencia'], $pedido);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
});

Env::cargar(__DIR__ . '/../.env');

// Los errores de PHP nunca se muestran al cliente, salvo en desarrollo local
// con APP_DEBUG=1.
ini_set('display_errors', Env::get('APP_DEBUG') === '1' ? '1' : '0');

// El servidor (ej. Render) suele correr en UTC. Fijamos la zona horaria local
// para que las validaciones de fecha (ej. "no anterior a hoy") usen la fecha
// correcta del usuario y no la de UTC.
date_default_timezone_set('America/Argentina/Buenos_Aires');

Cors::enviarHeaders();

// Headers de seguridad (A-07). Van acá, antes del preflight y de cualquier
// posible error, para que salgan en TODAS las respuestas: también en el 500 y en
// el OPTIONS, que son las que se olvidan.
//
// La API solo devuelve JSON, así que puede permitirse la CSP más cerrada que
// existe: sin permitir nada y sin dejar que ningún sitio la embeba. Es la
// segunda barrera si alguna vez una respuesta se sirviera como HTML.
foreach ([
    "Content-Security-Policy: default-src 'none'; frame-ancestors 'none'",
    // Sin esto, un navegador puede "adivinar" que un JSON es HTML y ejecutarlo.
    'X-Content-Type-Options: nosniff',
    // Los tokens viajan en la URL de ningún lado, pero una URL con un id de obra
    // ya dice de más: no se manda referer a ningún destino.
    'Referrer-Policy: no-referrer',
] as $cabecera) {
    header($cabecera);
}

// Bajo el servidor embebido de PHP (php -S, mono-hilo) cerramos la conexion
// despues de cada respuesta para que no se bloquee con las conexiones del
// navegador. En Apache/produccion no aplica (se mantiene keep-alive).
if (php_sapi_name() === 'cli-server') {
    header('Connection: close');
}

// Preflight CORS (el navegador manda OPTIONS antes de POST/PUT/DELETE).
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// --- Configuracion / dependencias ---
// Sin un secreto aceptable no se atiende ningún pedido: es preferible una caída
// visible a una API que acepta tokens fabricados (A-02).
$jwtSecreto = SecretoJwt::validar(Env::get('JWT_SECRET'));
$jwtSegundos = (int) Env::get('JWT_SEGUNDOS', '28800'); // 8 horas por defecto

$db = Database::conexion();

$controlador = new ProyectoController(new MySqlProyectoRepository($db));
$auth = new AuthController($db, $jwtSecreto, $jwtSegundos);
$planificacion = new PlanificacionController($db);
$avance = new AvanceController($db);
$asistencia = new AsistenciaController($db);
$incidencia = new IncidenciaController($db);
$material = new MaterialController($db);
$materialObra = new MaterialObraController($db);
$documento = new DocumentoController($db);
$reporte = new ReporteController($db);
$inactividad = new InactividadController($db);
$itemExcedente = new ItemExcedenteController($db);
$analisis = new AnalisisController($db);
$maquinaria = new MaquinariaController($db);
$etapaCtrl = new EtapaPlanificacionController($db);
$usuarioCtrl = new UsuarioController($db);

/**
 * Manejadores: la unica parte que conoce a los controladores.
 *
 * El metodo, el camino y la guarda de rol de cada endpoint son dato y viven en
 * Sgso\Ruteo\Tabla, que se puede recorrer en las pruebas. Aca solo esta a quien
 * se le llama, indexado por la clave que la tabla declara.
 *
 * Son funciones flecha a proposito: capturan los controladores sin repetir un
 * `use` en cada una. No llevan tipo de retorno porque una funcion flecha
 * siempre devuelve el valor de su expresion, y `: void` no lo admite.
 *
 * @var array<string, callable(array<string, string>, ?array<string, mixed>): mixed> $manejadores
 */
$manejadores = [
    // Autenticacion (RF19)
    'auth.login' => fn (array $p, ?array $u) => $auth->login(leerCuerpoJson()),
    'auth.olvide' => fn (array $p, ?array $u) => $auth->olvide(leerCuerpoJson()),
    'auth.restablecer' => fn (array $p, ?array $u) => $auth->restablecer(leerCuerpoJson()),
    'auth.register' => fn (array $p, ?array $u) => $auth->registrar(leerCuerpoJson(), (array) $u),
    'auth.me' => fn (array $p, ?array $u) => $auth->yo((array) $u),
    'health.mostrar' => fn (array $p, ?array $u) => responder(200, Salud::comprobar($db)),

    // Reportes y aprobacion (RF21/RF17)
    'reportes.listar' => fn (array $p, ?array $u) => $reporte->listar($_GET['estado'] ?? null),
    'reportes.crear' => fn (array $p, ?array $u) => $reporte->crear(leerCuerpoJson(), (array) $u),
    'reportes.enviar' => fn (array $p, ?array $u) => $reporte->enviar($p['id']),
    'reportes.aprobar' => fn (array $p, ?array $u) => $reporte->aprobar($p['id'], leerCuerpoJson()),
    'reportes.rechazar' => fn (array $p, ?array $u) => $reporte->rechazar($p['id'], leerCuerpoJson()),
    'reportes.editar' => fn (array $p, ?array $u) => $reporte->editar($p['id'], leerCuerpoJson()),
    'reportes.eliminar' => fn (array $p, ?array $u) => $reporte->eliminar($p['id']),

    // Maquinaria (RF23/RF24/RF27/RF28)
    'maquinaria.listar' => fn (array $p, ?array $u) => $maquinaria->listar(),
    'maquinaria.crear' => fn (array $p, ?array $u) => $maquinaria->crear(leerCuerpoJson()),
    'maquinaria.operarios' => fn (array $p, ?array $u) => $maquinaria->rendimientoOperarios(),
    'maquinaria.eliminar' => fn (array $p, ?array $u) => $maquinaria->eliminar($p['id']),
    'maquinaria.registro.eliminar' => fn (array $p, ?array $u) => $maquinaria->eliminarRegistro($p['id']),
    'maquinaria.falla.eliminar' => fn (array $p, ?array $u) => $maquinaria->eliminarFalla($p['id']),
    'maquinaria.registros.listar' => fn (array $p, ?array $u) => $maquinaria->listarRegistros($p['id']),
    'maquinaria.registros.crear' => fn (array $p, ?array $u) => $maquinaria->crearRegistro($p['id'], leerCuerpoJson()),
    'maquinaria.fallas.listar' => fn (array $p, ?array $u) => $maquinaria->listarFallas($p['id']),
    'maquinaria.fallas.crear' => fn (array $p, ?array $u) => $maquinaria->crearFalla($p['id'], leerCuerpoJson()),

    // Usuarios (HU16) y analisis (RF11/RF13)
    'usuarios.listar' => fn (array $p, ?array $u) => $usuarioCtrl->listar(),
    'usuarios.actualizar' => fn (array $p, ?array $u) => $usuarioCtrl->actualizar($p['id'], leerCuerpoJson(), (array) $u),
    'analisis.resumen' => fn (array $p, ?array $u) => $analisis->resumen($u['rol'] ?? null),

    // Catalogo de materiales (RF04)
    'materiales.listar' => fn (array $p, ?array $u) => $material->listar(),
    'materiales.crear' => fn (array $p, ?array $u) => $material->crear(leerCuerpoJson()),

    // Planificacion, etapas y avances
    'planificacion.actualizar' => fn (array $p, ?array $u) => $planificacion->actualizar($p['id'], leerCuerpoJson()),
    'planificacion.eliminar' => fn (array $p, ?array $u) => $planificacion->eliminar($p['id']),
    'planificacion.proyecto.obtener' => fn (array $p, ?array $u) => $planificacion->obtenerPorProyecto($p['id']),
    'planificacion.proyecto.crear' => fn (array $p, ?array $u) => $planificacion->crear($p['id'], leerCuerpoJson()),
    'etapa.listar' => fn (array $p, ?array $u) => $etapaCtrl->listar($p['id']),
    'etapa.crear' => fn (array $p, ?array $u) => $etapaCtrl->crear($p['id'], leerCuerpoJson()),
    'etapa.actualizar' => fn (array $p, ?array $u) => $etapaCtrl->actualizar($p['id'], leerCuerpoJson()),
    'etapa.eliminar' => fn (array $p, ?array $u) => $etapaCtrl->eliminar($p['id']),
    'avance.mostrar' => fn (array $p, ?array $u) => $avance->mostrar($p['id']),
    'avance.actualizar' => fn (array $p, ?array $u) => $avance->actualizar($p['id'], leerCuerpoJson()),
    'avance.eliminar' => fn (array $p, ?array $u) => $avance->eliminar($p['id']),
    'avance.listar' => fn (array $p, ?array $u) => $avance->listarPorPlan($p['id']),
    'avance.crear' => fn (array $p, ?array $u) => $avance->crear($p['id'], leerCuerpoJson()),
    'avance.resumen' => fn (array $p, ?array $u) => $avance->resumen($p['id']),

    // Subrecursos de la obra
    'asistencias.listar' => fn (array $p, ?array $u) => $asistencia->listarPorProyecto($p['id']),
    'asistencias.crear' => fn (array $p, ?array $u) => $asistencia->crear($p['id'], leerCuerpoJson()),
    'asistencia.eliminar' => fn (array $p, ?array $u) => $asistencia->eliminar($p['id']),
    'incidencias.listar' => fn (array $p, ?array $u) => $incidencia->listarPorProyecto($p['id']),
    'incidencias.crear' => fn (array $p, ?array $u) => $incidencia->crear($p['id'], leerCuerpoJson()),
    'incidencia.eliminar' => fn (array $p, ?array $u) => $incidencia->eliminar($p['id']),
    'materiales.proyecto.listar' => fn (array $p, ?array $u) => $materialObra->listarPorProyecto($p['id']),
    'materiales.proyecto.asignar' => fn (array $p, ?array $u) => $materialObra->asignar($p['id'], leerCuerpoJson()),
    'material.consumos.listar' => fn (array $p, ?array $u) => $materialObra->listarConsumos($p['id']),
    'material.consumos.crear' => fn (array $p, ?array $u) => $materialObra->crearConsumo($p['id'], leerCuerpoJson()),
    'material.asignacion.eliminar' => fn (array $p, ?array $u) => $materialObra->eliminarAsignacion($p['id']),
    'material.consumo.eliminar' => fn (array $p, ?array $u) => $materialObra->eliminarConsumo($p['id']),
    'documentos.listar' => fn (array $p, ?array $u) => $documento->listarPorProyecto($p['id']),
    'documentos.crear' => fn (array $p, ?array $u) => $documento->crear($p['id'], leerCuerpoJson()),
    'documento.eliminar' => fn (array $p, ?array $u) => $documento->eliminar($p['id']),
    'inactividades.listar' => fn (array $p, ?array $u) => $inactividad->listarPorProyecto($p['id']),
    'inactividades.crear' => fn (array $p, ?array $u) => $inactividad->crear($p['id'], leerCuerpoJson()),
    'inactividad.cerrar' => fn (array $p, ?array $u) => $inactividad->cerrar($p['id'], leerCuerpoJson()),
    'inactividad.eliminar' => fn (array $p, ?array $u) => $inactividad->eliminar($p['id']),
    'excedentes.listar' => fn (array $p, ?array $u) => $itemExcedente->listarPorProyecto($p['id']),
    'excedentes.crear' => fn (array $p, ?array $u) => $itemExcedente->crear($p['id'], leerCuerpoJson()),
    'excedente.eliminar' => fn (array $p, ?array $u) => $itemExcedente->eliminar($p['id']),

    // Obras (CU1, CU2, CU3)
    'proyectos.listar' => fn (array $p, ?array $u) => $controlador->listar($_GET['q'] ?? null, $u['rol'] ?? null),
    'proyectos.mostrar' => fn (array $p, ?array $u) => $controlador->mostrar($p['id'], $u['rol'] ?? null),
    'proyectos.crear' => fn (array $p, ?array $u) => $controlador->registrar(leerCuerpoJson()),
    'proyectos.editar' => fn (array $p, ?array $u) => $controlador->modificar($p['id'], leerCuerpoJson()),
    'proyectos.eliminar' => fn (array $p, ?array $u) => $controlador->eliminar($p['id']),
];

/**
 * Subrecursos nombrados sin id: /proyectos/asistencia, /maquinaria/registro y
 * compania.
 *
 * Existen como rutas propias porque, sin ellas, /proyectos/asistencia caeria en
 * /proyectos/{id} y la API contestaria como si "asistencia" fuera el id de una
 * obra. Los textos son los que ya respondia el ruteo anterior.
 */
$manejadores['error.falta.registro'] = fn (array $p, ?array $u) => responder(404, ['error' => 'Falta el id del registro']);
$manejadores['error.falta.falla'] = fn (array $p, ?array $u) => responder(404, ['error' => 'Falta el id de la falla']);
$manejadores['error.falta.planificacion'] = fn (array $p, ?array $u) => responder(404, ['error' => 'Falta el id de planificacion']);
$manejadores['error.falta.avance'] = fn (array $p, ?array $u) => responder(404, ['error' => 'Falta el id del avance']);
$manejadores['error.falta.etapa'] = fn (array $p, ?array $u) => responder(404, ['error' => 'Falta el id de la etapa']);
$manejadores['error.falta.asistencia'] = fn (array $p, ?array $u) => responder(404, ['error' => 'Falta el id de asistencia']);
$manejadores['error.falta.incidencia'] = fn (array $p, ?array $u) => responder(404, ['error' => 'Falta el id de incidencia']);
$manejadores['error.falta.asignacion'] = fn (array $p, ?array $u) => responder(404, ['error' => 'Falta el id de asignación']);
$manejadores['error.falta.consumo'] = fn (array $p, ?array $u) => responder(404, ['error' => 'Falta el id de consumo']);
$manejadores['error.falta.documento'] = fn (array $p, ?array $u) => responder(404, ['error' => 'Falta el id de documento']);
$manejadores['error.falta.periodo'] = fn (array $p, ?array $u) => responder(404, ['error' => 'Falta el id de período']);
$manejadores['error.falta.item'] = fn (array $p, ?array $u) => responder(404, ['error' => 'Falta el id de ítem']);

// --- Ruteo ---
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$ruta = preg_replace('#^/api#', '', rtrim($uri, '/')) ?? '';

$resultado = Despachador::resolver($_SERVER['REQUEST_METHOD'], $ruta, Tabla::rutas());

if ($resultado->estado === Resolucion::METODO_NO_PERMITIDO) {
    // En un camino protegido el token va primero, como en el ruteo anterior:
    // si no, un anonimo podria mapear la API a fuerza de probar metodos.
    if (!Despachador::caminoEsPublico($ruta, Tabla::rutas())) {
        exigirAutenticacion($jwtSecreto);
    }
    responder(405, ['error' => 'Metodo no permitido']);
    exit;
}

$rutaDeclarada = $resultado->ruta;
if ($rutaDeclarada === null) {
    responder(404, ['error' => str_starts_with($ruta, '/auth')
        ? 'Ruta de autenticacion no encontrada'
        : 'Recurso no encontrado']);
    exit;
}

// Una ruta publica no lleva roles: lo garantiza la prueba de la tabla.
$usuario = null;
if (!$rutaDeclarada->publica) {
    $usuario = exigirAutenticacion($jwtSecreto);
    if ($rutaDeclarada->roles !== null) {
        exigirRol($usuario, $rutaDeclarada->roles);
    }
}

if (!isset($manejadores[$rutaDeclarada->manejador])) {
    // No deberia pasar: TablaTest verifica que toda clave tenga su manejador.
    responder(500, ['error' => 'Manejador de ruta no configurado']);
    exit;
}

$manejadores[$rutaDeclarada->manejador]($resultado->parametros, $usuario);

// ------------------------------------------------------------
/** @return array<string, mixed> */
function leerCuerpoJson(): array
{
    $datos = json_decode(file_get_contents('php://input'), true);
    return is_array($datos) ? $datos : [];
}
function responder(int $codigo, mixed $cuerpo): void
{
    http_response_code($codigo);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
}
function noAutenticado(): void
{
    responder(401, ['error' => 'Falta el token de autenticacion']);
}
/**
 * Exige un token valido. Si no lo hay, corta con 401. Devuelve el payload
 * del usuario (id_usuario, email, rol) para usarlo en las guardas de rol.
 * @return array<string, mixed>
 */
function exigirAutenticacion(string $secreto): array
{
    $usuario = AuthMiddleware::usuarioAutenticado($secreto);
    if ($usuario === null) { noAutenticado(); exit; }
    return $usuario;
}
/**
 * Exige que el rol del usuario este dentro de los permitidos (RF19).
 * Si no, corta con 403.
 * @param array<string, mixed> $usuario
 * @param list<string> $rolesPermitidos
 */
function exigirRol(array $usuario, array $rolesPermitidos): void
{
    if (!Permisos::puede(isset($usuario['rol']) ? (string) $usuario['rol'] : null, $rolesPermitidos)) {
        responder(403, ['error' => 'No tenés permisos para esta acción']);
        exit;
    }
}
