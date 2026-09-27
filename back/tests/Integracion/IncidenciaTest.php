<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\IncidenciaController;

/**
 * Incidencias externas que justifican retrasos (RF09), clasificadas por tipo y
 * por gravedad (RF26). Los avisos segun la gravedad son D-02: estas pruebas
 * fijan la clasificacion sobre la que se van a apoyar.
 */
#[CoversClass(IncidenciaController::class)]
final class IncidenciaTest extends CasoConBase
{
    public function testRegistrarUnaIncidenciaLaDejaEnElListadoDeLaObra(): void
    {
        $idProyecto = $this->crearProyecto();

        $respuesta = $this->registrar($idProyecto, [
            'fecha' => '2026-03-02',
            'tipo' => 'clima',
            'gravedad' => 'alta',
            'descripcion' => '  Tormenta, obra inundada  ',
            'dias_retraso' => 3,
        ]);

        self::assertSame(201, $respuesta['codigo']);
        $incidencia = $this->listado($idProyecto)[0];
        self::assertSame($respuesta['cuerpo']['id_incidencia'], $incidencia['id_incidencia']);
        self::assertSame('clima', $incidencia['tipo']);
        self::assertSame('alta', $incidencia['gravedad']);
        self::assertSame('Tormenta, obra inundada', $incidencia['descripcion']);
        self::assertSame(3, (int) $incidencia['dias_retraso']);
    }

    public function testSoloAceptaLosTiposYGravedadesDelModelo(): void
    {
        $idProyecto = $this->crearProyecto();
        $base = ['fecha' => '2026-03-02', 'descripcion' => 'Algo'];

        foreach (['clima', 'falla_maquinaria', 'proveedor', 'otro'] as $tipo) {
            self::assertSame(201, $this->registrar($idProyecto, $base + ['tipo' => $tipo, 'gravedad' => 'media'])['codigo'], $tipo);
        }
        foreach (['baja', 'media', 'alta'] as $gravedad) {
            self::assertSame(201, $this->registrar($idProyecto, $base + ['tipo' => 'otro', 'gravedad' => $gravedad])['codigo'], $gravedad);
        }
        $tipoMal = $this->registrar($idProyecto, $base + ['tipo' => 'huelga', 'gravedad' => 'media']);
        $gravedadMal = $this->registrar($idProyecto, $base + ['tipo' => 'otro', 'gravedad' => 'critica']);

        self::assertSame(422, $tipoMal['codigo']);
        self::assertArrayHasKey('tipo', $tipoMal['cuerpo']['errors']);
        self::assertSame(422, $gravedadMal['codigo']);
        self::assertArrayHasKey('gravedad', $gravedadMal['cuerpo']['errors']);
        self::assertCount(7, $this->listado($idProyecto));
    }

    public function testLosDiasDeRetrasoNuncaQuedanNegativos(): void
    {
        $idProyecto = $this->crearProyecto();

        $this->registrar($idProyecto, [
            'fecha' => '2026-03-02', 'tipo' => 'otro', 'gravedad' => 'baja', 'descripcion' => 'X', 'dias_retraso' => -4,
        ]);

        self::assertSame(0, (int) $this->listado($idProyecto)[0]['dias_retraso']);
    }

    public function testFaltanCamposObligatorios(): void
    {
        $idProyecto = $this->crearProyecto();

        $respuesta = $this->registrar($idProyecto, ['descripcion' => '  ']);

        self::assertSame(422, $respuesta['codigo']);
        foreach (['fecha', 'tipo', 'gravedad', 'descripcion'] as $campo) {
            self::assertArrayHasKey($campo, $respuesta['cuerpo']['errors']);
        }
    }

    public function testElListadoVaDeLaMasRecienteALaMasViejaYNoMezclaObras(): void
    {
        $idObra = $this->crearProyecto('en_ejecucion', 'Obra A');
        $idOtra = $this->crearProyecto('en_ejecucion', 'Obra B');
        foreach (['2026-03-01', '2026-03-03', '2026-03-02'] as $fecha) {
            $this->registrar($idObra, ['fecha' => $fecha, 'tipo' => 'otro', 'gravedad' => 'baja', 'descripcion' => 'X']);
        }
        $this->registrar($idOtra, ['fecha' => '2026-03-09', 'tipo' => 'otro', 'gravedad' => 'baja', 'descripcion' => 'Y']);

        self::assertSame(['2026-03-03', '2026-03-02', '2026-03-01'], array_column($this->listado($idObra), 'fecha'));
    }

    public function testUnaObraInexistenteDa404YEliminarDosVecesDa404(): void
    {
        $idProyecto = $this->crearProyecto();
        $id = $this->registrar($idProyecto, ['fecha' => '2026-03-02', 'tipo' => 'otro', 'gravedad' => 'baja', 'descripcion' => 'X'])['cuerpo']['id_incidencia'];

        self::assertSame(404, $this->registrar(999, ['fecha' => '2026-03-02', 'tipo' => 'otro', 'gravedad' => 'baja', 'descripcion' => 'X'])['codigo']);
        self::assertSame(200, $this->capturar(fn () => $this->incidencias()->eliminar((string) $id))['codigo']);
        self::assertSame(404, $this->capturar(fn () => $this->incidencias()->eliminar((string) $id))['codigo']);
    }

    /**
     * @param array<string, mixed> $datos
     * @return array{codigo: int, cuerpo: mixed}
     */
    private function registrar(int $idProyecto, array $datos): array
    {
        return $this->capturar(fn () => $this->incidencias()->crear((string) $idProyecto, $datos));
    }

    /** @return list<array<string, mixed>> */
    private function listado(int $idProyecto): array
    {
        return $this->capturar(fn () => $this->incidencias()->listarPorProyecto((string) $idProyecto))['cuerpo'];
    }

    private function incidencias(): IncidenciaController
    {
        return new IncidenciaController($this->base());
    }
}
