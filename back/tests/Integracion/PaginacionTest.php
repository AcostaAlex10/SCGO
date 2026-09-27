<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\AsistenciaController;
use Sgso\AvanceController;
use Sgso\DocumentoController;
use Sgso\Http\Paginacion;
use Sgso\IncidenciaController;
use Sgso\MaquinariaController;
use Sgso\MaterialObraController;
use Sgso\ReporteController;

/**
 * Los siete listados que crecen con el tiempo, paginados a pedido (C-03).
 *
 * Cada uno tiene su propia consulta de conteo, y un error ahi no se ve: la
 * pagina llega bien y el total miente. Por eso se recorren todos con los
 * mismos tres casos: sin pagina va entero, la primera pagina trae las mas
 * recientes con el total real, y la ultima trae lo que sobra.
 */
#[CoversClass(Paginacion::class)]
#[CoversClass(AsistenciaController::class)]
#[CoversClass(IncidenciaController::class)]
#[CoversClass(AvanceController::class)]
#[CoversClass(ReporteController::class)]
#[CoversClass(DocumentoController::class)]
#[CoversClass(MaquinariaController::class)]
#[CoversClass(MaterialObraController::class)]
final class PaginacionTest extends CasoConBase
{
    /** Tres filas por listado, en tres dias distintos: la del 3 es la mas reciente. */
    private const FECHAS = ['2026-03-01', '2026-03-03', '2026-03-02'];

    public function testCadaListadoPaginaConSuTotalReal(): void
    {
        foreach ($this->listados() as $nombre => $listar) {
            $entero = $listar(null);
            $primera = Paginacion::desdeConsulta(['limite' => '2']);
            $ultima = Paginacion::desdeConsulta(['limite' => '2', 'desde' => '2']);
            self::assertInstanceOf(Paginacion::class, $primera);
            self::assertInstanceOf(Paginacion::class, $ultima);

            $paginaUno = $listar($primera);
            $paginaDos = $listar($ultima);

            self::assertCount(3, $entero, "{$nombre}: sin pagina va entero");
            self::assertCount(2, $paginaUno, "{$nombre}: la primera pagina");
            self::assertSame(3, $primera->total(), "{$nombre}: el total de la primera");
            self::assertCount(1, $paginaDos, "{$nombre}: la ultima pagina");
            self::assertSame(3, $ultima->total(), "{$nombre}: el total de la ultima");
            self::assertSame(
                array_slice($entero, 0, 2),
                $paginaUno,
                "{$nombre}: la primera pagina son las dos primeras del listado entero"
            );
            self::assertSame(array_slice($entero, 2), $paginaDos, "{$nombre}: la ultima es la que sobra");
        }
    }

    /** Sin pedir pagina no se cuenta nada: el listado es el de siempre. */
    public function testSinPaginaNoHayTotal(): void
    {
        $idProyecto = $this->crearProyecto();
        $this->base()->prepare(
            "INSERT INTO asistencia (id_proyecto, fecha, trabajador, estado) VALUES (?, '2026-03-01', 'Ana', 'presente')"
        )->execute([$idProyecto]);

        $respuesta = $this->capturar(fn () => (new AsistenciaController($this->base()))->listarPorProyecto((string) $idProyecto));

        self::assertSame(200, $respuesta['codigo']);
        self::assertCount(1, $respuesta['cuerpo']);
    }

    /** El filtro por estado de los reportes tambien vale para el conteo. */
    public function testElTotalDeLosReportesRespetaElFiltroPorEstado(): void
    {
        $idProyecto = $this->crearProyecto();
        $autor = $this->crearUsuario();
        $this->crearReporte($idProyecto, $autor, false, 'en_revision');
        $this->crearReporte($idProyecto, $autor, false, 'en_revision');
        $this->crearReporte($idProyecto, $autor, false, 'aprobado');
        $pagina = Paginacion::desdeConsulta(['limite' => '1']);
        self::assertInstanceOf(Paginacion::class, $pagina);

        $respuesta = $this->capturar(fn () => (new ReporteController($this->base()))->listar('en_revision', $pagina));

        self::assertCount(1, $respuesta['cuerpo']);
        self::assertSame('en_revision', $respuesta['cuerpo'][0]['estado']);
        self::assertSame(2, $pagina->total());
    }

    /**
     * Cuatro avances del mismo dia: sin un orden total, paginar podia devolver
     * el mismo en las dos paginas y saltear otro. Con el id como desempate, el
     * ultimo cargado va primero, igual que en los demas listados.
     */
    public function testDosRegistrosDelMismoDiaNoSeRepitenEntrePaginas(): void
    {
        $idPlan = $this->crearPlanificacion($this->crearProyecto());
        $cargados = [];
        for ($i = 0; $i < 4; $i++) {
            $this->base()->prepare(
                "INSERT INTO avance_fisico (cantidad_ejecutada, porcentaje_avance, fecha, id_planificacion) VALUES (1, ?, '2026-03-01', ?)"
            )->execute([10 + $i, $idPlan]);
            $cargados[] = (int) $this->base()->lastInsertId();
        }

        $ids = [];
        foreach ([0, 2] as $desde) {
            $pagina = Paginacion::desdeConsulta(['limite' => '2', 'desde' => (string) $desde]);
            self::assertInstanceOf(Paginacion::class, $pagina);
            $cuerpo = $this->capturar(fn () => (new AvanceController($this->base()))->listarPorPlan((string) $idPlan, $pagina))['cuerpo'];
            $ids = [...$ids, ...array_column($cuerpo, 'id_avance')];
        }

        self::assertSame(array_reverse($cargados), $ids);
    }

    // ----------------------------------------------------------------
    //  Ayudas
    // ----------------------------------------------------------------

    /**
     * Cada listado con tres filas cargadas, y como pedirlo. Cada uno tiene
     * tambien una fila de otra obra (o de otra maquina, plan o asignacion) que
     * no tiene que aparecer ni contarse: un conteo sin el WHERE daria 4.
     *
     * @return array<string, Closure(?Paginacion): list<array<string, mixed>>>
     */
    private function listados(): array
    {
        $db = $this->base();
        $autor = $this->crearUsuario();
        $idMaterial = $this->crearMaterial();
        $idProyecto = $this->crearProyecto();
        $idPlan = $this->crearPlanificacion($idProyecto);
        $idAsignacion = $this->asignarMaterial($idProyecto, $idMaterial, 100);
        $idMaquina = $this->crearMaquina('Grua');
        $this->cargarFilas($idProyecto, $idPlan, $idAsignacion, $idMaquina, self::FECHAS);

        $idOtra = $this->crearProyecto('en_ejecucion', 'Otra obra');
        $this->cargarFilas(
            $idOtra,
            $this->crearPlanificacion($idOtra),
            $this->asignarMaterial($idOtra, $idMaterial, 100),
            $this->crearMaquina('Otra grua'),
            ['2026-03-09']
        );

        // Los reportes se listan de todas las obras juntas: solo los de la primera.
        foreach (self::FECHAS as $fecha) {
            $db->prepare("INSERT INTO reporte (id_proyecto, id_usuario, titulo, contenido, fecha_creacion) VALUES (?, ?, 'R', 'C', ?)")
                ->execute([$idProyecto, $autor, $fecha . ' 10:00:00']);
        }

        $cuerpo = fn (callable $accion): array => $this->capturar($accion)['cuerpo'];

        return [
            'asistencias' => fn (?Paginacion $p) => $cuerpo(fn () => (new AsistenciaController($db))->listarPorProyecto((string) $idProyecto, $p)),
            'incidencias' => fn (?Paginacion $p) => $cuerpo(fn () => (new IncidenciaController($db))->listarPorProyecto((string) $idProyecto, $p)),
            'avances' => fn (?Paginacion $p) => $cuerpo(fn () => (new AvanceController($db))->listarPorPlan((string) $idPlan, $p)),
            'documentos' => fn (?Paginacion $p) => $cuerpo(fn () => (new DocumentoController($db))->listarPorProyecto((string) $idProyecto, $p)),
            'registros de maquinaria' => fn (?Paginacion $p) => $cuerpo(fn () => (new MaquinariaController($db))->listarRegistros((string) $idMaquina, $p)),
            'consumos' => fn (?Paginacion $p) => $cuerpo(fn () => (new MaterialObraController($db))->listarConsumos((string) $idAsignacion, $p)),
            'reportes' => fn (?Paginacion $p) => $cuerpo(fn () => (new ReporteController($db))->listar(null, $p)),
        ];
    }

    /** @param list<string> $fechas */
    private function cargarFilas(int $idProyecto, int $idPlan, int $idAsignacion, int $idMaquina, array $fechas): void
    {
        $db = $this->base();
        foreach ($fechas as $i => $fecha) {
            $db->prepare("INSERT INTO asistencia (id_proyecto, fecha, trabajador, estado) VALUES (?, ?, ?, 'presente')")
                ->execute([$idProyecto, $fecha, "Trabajador {$i}"]);
            $db->prepare("INSERT INTO incidencia (id_proyecto, fecha, tipo, gravedad, descripcion) VALUES (?, ?, 'otro', 'baja', 'X')")
                ->execute([$idProyecto, $fecha]);
            $db->prepare('INSERT INTO avance_fisico (cantidad_ejecutada, porcentaje_avance, fecha, id_planificacion) VALUES (1, ?, ?, ?)')
                ->execute([10 * ($i + 1), $fecha, $idPlan]);
            $db->prepare("INSERT INTO documento (id_proyecto, nombre, tipo, url, fecha_carga) VALUES (?, ?, 'pdf', 'https://docs.test/x.pdf', ?)")
                ->execute([$idProyecto, "Documento {$i}", $fecha]);
            $db->prepare('INSERT INTO registro_maquinaria (id_maquinaria, fecha, horas_uso) VALUES (?, ?, 1)')
                ->execute([$idMaquina, $fecha]);
            $db->prepare('INSERT INTO consumo_material (id_asignacion, fecha, cantidad_consumida) VALUES (?, ?, 1)')
                ->execute([$idAsignacion, $fecha]);
        }
    }

    private function crearMaquina(string $nombre): int
    {
        $this->base()->prepare("INSERT INTO maquinaria (nombre, tipo) VALUES (?, 'Elevacion')")->execute([$nombre]);
        return (int) $this->base()->lastInsertId();
    }
}
