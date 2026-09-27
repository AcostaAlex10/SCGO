<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\MaterialObraController;

/**
 * Materiales asignados a una obra y sus consumos (RF10), y la marca de exceso
 * (RF12). Es stock: un error aca hace que la obra compre de mas o se quede sin
 * material, y nadie se entera hasta que falta.
 */
#[CoversClass(MaterialObraController::class)]
final class MaterialObraTest extends CasoConBase
{
    public function testAsignarUnMaterialLoDejaEnLaObraSinConsumo(): void
    {
        $idProyecto = $this->crearProyecto();
        $idMaterial = $this->crearMaterial('Cemento', 'bolsa');

        $respuesta = $this->capturar(fn () => $this->materiales()->asignar((string) $idProyecto, [
            'id_material' => $idMaterial,
            'cantidad_asignada' => 100,
        ]));

        self::assertSame(201, $respuesta['codigo']);
        self::assertIsInt($respuesta['cuerpo']['id_asignacion']);

        $listado = $this->listado($idProyecto);
        self::assertCount(1, $listado);
        self::assertSame('Cemento', $listado[0]['nombre']);
        self::assertSame('bolsa', $listado[0]['unidad']);
        self::assertNumero(100.0, $listado[0]['cantidad_asignada']);
        self::assertNumero(0.0, $listado[0]['consumido']);
        self::assertNumero(100.0, $listado[0]['restante']);
        self::assertFalse($listado[0]['excedido']);
    }

    public function testElListadoSumaLosConsumosYCalculaLoQueResta(): void
    {
        $idProyecto = $this->crearProyecto();
        $idAsignacion = $this->asignarMaterial($idProyecto, $this->crearMaterial(), 100);
        $this->registrarConsumo($idAsignacion, 30);
        $this->registrarConsumo($idAsignacion, 25.5);

        $fila = $this->listado($idProyecto)[0];

        self::assertNumero(55.5, $fila['consumido']);
        self::assertNumero(44.5, $fila['restante']);
        self::assertFalse($fila['excedido']);
    }

    /**
     * RF12 pide alertar el exceso, no impedirlo: en obra se puede usar mas de lo
     * previsto, y lo que importa es que quede registrado y a la vista.
     */
    public function testConsumirMasDeLoAsignadoSeRegistraYQuedaMarcadoComoExcedido(): void
    {
        $idProyecto = $this->crearProyecto();
        $idAsignacion = $this->asignarMaterial($idProyecto, $this->crearMaterial(), 10);

        $respuesta = $this->capturar(fn () => $this->materiales()->crearConsumo((string) $idAsignacion, [
            'cantidad_consumida' => 12,
            'fecha' => '2026-03-02',
        ]));

        self::assertSame(201, $respuesta['codigo']);

        $fila = $this->listado($idProyecto)[0];
        self::assertNumero(12.0, $fila['consumido']);
        self::assertNumero(-2.0, $fila['restante']);
        self::assertTrue($fila['excedido']);
    }

    public function testConsumirExactamenteLoAsignadoNoEsExceso(): void
    {
        $idProyecto = $this->crearProyecto();
        $idAsignacion = $this->asignarMaterial($idProyecto, $this->crearMaterial(), 10);
        $this->registrarConsumo($idAsignacion, 10);

        $fila = $this->listado($idProyecto)[0];

        self::assertNumero(0.0, $fila['restante']);
        self::assertFalse($fila['excedido']);
    }

    public function testUnMaterialSeAsignaUnaSolaVezPorObraPeroPuedeIrAOtraObra(): void
    {
        $idObra = $this->crearProyecto('en_ejecucion', 'Obra A');
        $idOtraObra = $this->crearProyecto('en_ejecucion', 'Obra B');
        $idMaterial = $this->crearMaterial();
        $this->asignarMaterial($idObra, $idMaterial, 50);

        $repetida = $this->capturar(fn () => $this->materiales()->asignar((string) $idObra, [
            'id_material' => $idMaterial,
            'cantidad_asignada' => 20,
        ]));
        $enOtraObra = $this->capturar(fn () => $this->materiales()->asignar((string) $idOtraObra, [
            'id_material' => $idMaterial,
            'cantidad_asignada' => 20,
        ]));

        self::assertSame(409, $repetida['codigo']);
        self::assertCount(1, $this->listado($idObra));
        self::assertNumero(50.0, $this->listado($idObra)[0]['cantidad_asignada']);
        self::assertSame(201, $enOtraObra['codigo']);
    }

    public function testElListadoDeUnaObraNoMuestraLosMaterialesDeOtra(): void
    {
        $idObra = $this->crearProyecto('en_ejecucion', 'Obra A');
        $idOtraObra = $this->crearProyecto('en_ejecucion', 'Obra B');
        $this->asignarMaterial($idObra, $this->crearMaterial('Cemento'), 10);
        $this->asignarMaterial($idOtraObra, $this->crearMaterial('Arena', 'm3'), 5);

        $listado = $this->listado($idObra);

        self::assertCount(1, $listado);
        self::assertSame('Cemento', $listado[0]['nombre']);
    }

    public function testAsignarAUnaObraInexistenteDa404(): void
    {
        $respuesta = $this->capturar(fn () => $this->materiales()->asignar('999', [
            'id_material' => $this->crearMaterial(),
            'cantidad_asignada' => 10,
        ]));

        self::assertSame(404, $respuesta['codigo']);
    }

    public function testAsignarUnMaterialInexistenteDa404(): void
    {
        $idProyecto = $this->crearProyecto();

        $respuesta = $this->capturar(fn () => $this->materiales()->asignar((string) $idProyecto, [
            'id_material' => 999,
            'cantidad_asignada' => 10,
        ]));

        self::assertSame(404, $respuesta['codigo']);
        self::assertSame([], $this->listado($idProyecto));
    }

    public function testAsignarRechazaUnaCantidadNulaNegativaONoNumerica(): void
    {
        $idProyecto = $this->crearProyecto();
        $idMaterial = $this->crearMaterial();

        foreach ([0, -5, 'mucho', null] as $cantidad) {
            $respuesta = $this->capturar(fn () => $this->materiales()->asignar((string) $idProyecto, [
                'id_material' => $idMaterial,
                'cantidad_asignada' => $cantidad,
            ]));

            self::assertSame(422, $respuesta['codigo'], 'cantidad: ' . var_export($cantidad, true));
            self::assertArrayHasKey('cantidad_asignada', $respuesta['cuerpo']['errors']);
        }

        self::assertSame([], $this->listado($idProyecto));
    }

    public function testAsignarSinElegirMaterialDa422(): void
    {
        $idProyecto = $this->crearProyecto();

        $respuesta = $this->capturar(fn () => $this->materiales()->asignar((string) $idProyecto, [
            'cantidad_asignada' => 10,
        ]));

        self::assertSame(422, $respuesta['codigo']);
        self::assertArrayHasKey('id_material', $respuesta['cuerpo']['errors']);
    }

    public function testRegistrarUnConsumoValidaCantidadYFecha(): void
    {
        $idProyecto = $this->crearProyecto();
        $idAsignacion = $this->asignarMaterial($idProyecto, $this->crearMaterial(), 10);

        $sinCantidad = $this->capturar(fn () => $this->materiales()->crearConsumo((string) $idAsignacion, [
            'cantidad_consumida' => 0,
            'fecha' => '2026-03-02',
        ]));
        $sinFecha = $this->capturar(fn () => $this->materiales()->crearConsumo((string) $idAsignacion, [
            'cantidad_consumida' => 3,
            'fecha' => '02/03/2026',
        ]));

        self::assertSame(422, $sinCantidad['codigo']);
        self::assertArrayHasKey('cantidad_consumida', $sinCantidad['cuerpo']['errors']);
        self::assertSame(422, $sinFecha['codigo']);
        self::assertArrayHasKey('fecha', $sinFecha['cuerpo']['errors']);
        self::assertNumero(0.0, $this->listado($idProyecto)[0]['consumido']);
    }

    public function testRegistrarUnConsumoEnUnaAsignacionInexistenteDa404(): void
    {
        $respuesta = $this->capturar(fn () => $this->materiales()->crearConsumo('999', [
            'cantidad_consumida' => 3,
            'fecha' => '2026-03-02',
        ]));

        self::assertSame(404, $respuesta['codigo']);
    }

    public function testLosConsumosSeListanDelMasRecienteAlMasViejo(): void
    {
        $idProyecto = $this->crearProyecto();
        $idAsignacion = $this->asignarMaterial($idProyecto, $this->crearMaterial(), 100);
        $this->registrarConsumo($idAsignacion, 1, '2026-03-01');
        $this->registrarConsumo($idAsignacion, 3, '2026-03-03');
        $this->registrarConsumo($idAsignacion, 2, '2026-03-02');

        $respuesta = $this->capturar(fn () => $this->materiales()->listarConsumos((string) $idAsignacion));

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame(['2026-03-03', '2026-03-02', '2026-03-01'], array_column($respuesta['cuerpo'], 'fecha'));
        foreach ([3.0, 2.0, 1.0] as $i => $cantidad) {
            self::assertNumero($cantidad, $respuesta['cuerpo'][$i]['cantidad_consumida']);
        }
        self::assertIsInt($respuesta['cuerpo'][0]['id_consumo']);
    }

    public function testEliminarUnConsumoLoDescuentaDelTotal(): void
    {
        $idProyecto = $this->crearProyecto();
        $idAsignacion = $this->asignarMaterial($idProyecto, $this->crearMaterial(), 10);
        $this->registrarConsumo($idAsignacion, 4);
        $idConsumo = $this->registrarConsumo($idAsignacion, 8);

        self::assertTrue($this->listado($idProyecto)[0]['excedido']);

        $respuesta = $this->capturar(fn () => $this->materiales()->eliminarConsumo((string) $idConsumo));
        $otraVez = $this->capturar(fn () => $this->materiales()->eliminarConsumo((string) $idConsumo));

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame(404, $otraVez['codigo']);
        self::assertNumero(4.0, $this->listado($idProyecto)[0]['consumido']);
        self::assertFalse($this->listado($idProyecto)[0]['excedido']);
    }

    /**
     * Borrar una asignacion borra tambien sus consumos (`ON DELETE CASCADE`):
     * el registro de lo que se uso en obra se pierde. Esta prueba fija el
     * comportamiento actual para que un cambio sea a conciencia; si hace falta
     * conservar ese historial, es parte de D-05 (registro de cambios).
     */
    public function testEliminarUnaAsignacionBorraTambienSusConsumos(): void
    {
        $idProyecto = $this->crearProyecto();
        $idAsignacion = $this->asignarMaterial($idProyecto, $this->crearMaterial(), 10);
        $this->registrarConsumo($idAsignacion, 4);

        $respuesta = $this->capturar(fn () => $this->materiales()->eliminarAsignacion((string) $idAsignacion));
        $otraVez = $this->capturar(fn () => $this->materiales()->eliminarAsignacion((string) $idAsignacion));

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame(404, $otraVez['codigo']);
        self::assertSame([], $this->listado($idProyecto));

        $consumos = $this->base()->prepare('SELECT COUNT(*) FROM consumo_material WHERE id_asignacion = ?');
        $consumos->execute([$idAsignacion]);
        self::assertSame(0, (int) $consumos->fetchColumn());
    }

    private function materiales(): MaterialObraController
    {
        return new MaterialObraController($this->base());
    }

    /** @return list<array<string, mixed>> */
    private function listado(int $idProyecto): array
    {
        $respuesta = $this->capturar(fn () => $this->materiales()->listarPorProyecto((string) $idProyecto));
        self::assertSame(200, $respuesta['codigo']);

        return $respuesta['cuerpo'];
    }
}
