<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\AsistenciaController;

/** Asistencia diaria del personal en obra (RF06) y su justificacion (RF08). */
#[CoversClass(AsistenciaController::class)]
final class AsistenciaTest extends CasoConBase
{
    public function testRegistrarUnaAsistenciaLaDejaEnElListadoDeLaObra(): void
    {
        $idProyecto = $this->crearProyecto();

        $respuesta = $this->registrar($idProyecto, [
            'fecha' => '2026-03-02',
            'trabajador' => '  Juan Perez  ',
            'estado' => 'tarde',
            'justificacion' => '  Colectivo demorado  ',
        ]);

        self::assertSame(201, $respuesta['codigo']);
        $listado = $this->listado($idProyecto);
        self::assertCount(1, $listado);
        self::assertSame($respuesta['cuerpo']['id_asistencia'], $listado[0]['id_asistencia']);
        self::assertSame('Juan Perez', $listado[0]['trabajador']);
        self::assertSame('tarde', $listado[0]['estado']);
        self::assertSame('Colectivo demorado', $listado[0]['justificacion']);
    }

    public function testUnaJustificacionEnBlancoSeGuardaComoVacia(): void
    {
        $idProyecto = $this->crearProyecto();

        $this->registrar($idProyecto, ['fecha' => '2026-03-02', 'trabajador' => 'Ana', 'estado' => 'presente', 'justificacion' => '   ']);

        self::assertNull($this->listado($idProyecto)[0]['justificacion']);
    }

    public function testSoloAceptaLosTresEstados(): void
    {
        $idProyecto = $this->crearProyecto();

        foreach (['presente', 'ausente', 'tarde'] as $estado) {
            self::assertSame(201, $this->registrar($idProyecto, [
                'fecha' => '2026-03-02', 'trabajador' => "Trabajador {$estado}", 'estado' => $estado,
            ])['codigo'], $estado);
        }
        $invalido = $this->registrar($idProyecto, ['fecha' => '2026-03-02', 'trabajador' => 'X', 'estado' => 'franco']);

        self::assertSame(422, $invalido['codigo']);
        self::assertArrayHasKey('estado', $invalido['cuerpo']['errors']);
        self::assertCount(3, $this->listado($idProyecto));
    }

    public function testFaltanCamposObligatorios(): void
    {
        $idProyecto = $this->crearProyecto();

        $respuesta = $this->registrar($idProyecto, ['trabajador' => '   ']);

        self::assertSame(422, $respuesta['codigo']);
        foreach (['trabajador', 'fecha', 'estado'] as $campo) {
            self::assertArrayHasKey($campo, $respuesta['cuerpo']['errors']);
        }
    }

    public function testElListadoVaDelDiaMasRecienteAlMasViejoYNoMezclaObras(): void
    {
        $idObra = $this->crearProyecto('en_ejecucion', 'Obra A');
        $idOtra = $this->crearProyecto('en_ejecucion', 'Obra B');
        foreach (['2026-03-01', '2026-03-03', '2026-03-02'] as $fecha) {
            $this->registrar($idObra, ['fecha' => $fecha, 'trabajador' => 'Ana', 'estado' => 'presente']);
        }
        $this->registrar($idOtra, ['fecha' => '2026-03-09', 'trabajador' => 'Otro', 'estado' => 'presente']);

        self::assertSame(['2026-03-03', '2026-03-02', '2026-03-01'], array_column($this->listado($idObra), 'fecha'));
    }

    public function testUnaObraInexistenteDa404(): void
    {
        $respuesta = $this->registrar(999, ['fecha' => '2026-03-02', 'trabajador' => 'Ana', 'estado' => 'presente']);

        self::assertSame(404, $respuesta['codigo']);
    }

    public function testEliminarUnaAsistencia(): void
    {
        $idProyecto = $this->crearProyecto();
        $id = $this->registrar($idProyecto, ['fecha' => '2026-03-02', 'trabajador' => 'Ana', 'estado' => 'presente'])['cuerpo']['id_asistencia'];

        self::assertSame(200, $this->capturar(fn () => $this->asistencias()->eliminar((string) $id))['codigo']);
        self::assertSame(404, $this->capturar(fn () => $this->asistencias()->eliminar((string) $id))['codigo']);
        self::assertSame([], $this->listado($idProyecto));
    }

    /**
     * @param array<string, mixed> $datos
     * @return array{codigo: int, cuerpo: mixed}
     */
    private function registrar(int $idProyecto, array $datos): array
    {
        return $this->capturar(fn () => $this->asistencias()->crear((string) $idProyecto, $datos));
    }

    /** @return list<array<string, mixed>> */
    private function listado(int $idProyecto): array
    {
        return $this->capturar(fn () => $this->asistencias()->listarPorProyecto((string) $idProyecto))['cuerpo'];
    }

    private function asistencias(): AsistenciaController
    {
        return new AsistenciaController($this->base());
    }
}
