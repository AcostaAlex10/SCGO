<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\ItemExcedenteController;

/** Items o trabajos excedentes no contemplados en la planificacion (RF22). */
#[CoversClass(ItemExcedenteController::class)]
final class ItemExcedenteTest extends CasoConBase
{
    public function testRegistrarUnItemConTodosSusDatos(): void
    {
        $idProyecto = $this->crearProyecto();

        $respuesta = $this->registrar($idProyecto, [
            'descripcion' => '  Muro de contencion extra  ',
            'cantidad' => '12.5',
            'unidad' => 'm2',
            'fecha' => '2026-03-02',
            'motivo' => 'Talud inestable',
        ]);

        self::assertSame(201, $respuesta['codigo']);
        $item = $this->listado($idProyecto)[0];
        self::assertSame($respuesta['cuerpo']['id_item'], $item['id_item']);
        self::assertSame('Muro de contencion extra', $item['descripcion']);
        self::assertNumero(12.5, $item['cantidad']);
        self::assertSame('m2', $item['unidad']);
        self::assertSame('2026-03-02', $item['fecha']);
        self::assertSame('Talud inestable', $item['motivo']);
    }

    public function testCantidadUnidadYMotivoSonOpcionales(): void
    {
        $idProyecto = $this->crearProyecto();

        $respuesta = $this->registrar($idProyecto, ['descripcion' => 'Reparacion', 'fecha' => '2026-03-02', 'unidad' => '  ']);

        self::assertSame(201, $respuesta['codigo']);
        $item = $this->listado($idProyecto)[0];
        self::assertNull($item['cantidad']);
        self::assertNull($item['unidad']);
        self::assertNull($item['motivo']);
    }

    public function testLaDescripcionEsObligatoriaYLaCantidadTieneQueSerUnNumero(): void
    {
        $idProyecto = $this->crearProyecto();

        $sinDescripcion = $this->registrar($idProyecto, ['descripcion' => '   ', 'fecha' => '2026-03-02']);
        $cantidadMal = $this->registrar($idProyecto, ['descripcion' => 'X', 'cantidad' => 'mucho', 'fecha' => '2026-03-02']);

        self::assertSame(422, $sinDescripcion['codigo']);
        self::assertArrayHasKey('descripcion', $sinDescripcion['cuerpo']['errors']);
        self::assertSame(422, $cantidadMal['codigo']);
        self::assertArrayHasKey('cantidad', $cantidadMal['cuerpo']['errors']);
        self::assertSame([], $this->listado($idProyecto));
    }

    public function testElListadoVaDelMasRecienteAlMasViejoYNoMezclaObras(): void
    {
        $idObra = $this->crearProyecto('en_ejecucion', 'Obra A');
        $idOtra = $this->crearProyecto('en_ejecucion', 'Obra B');
        foreach (['2026-03-01', '2026-03-03', '2026-03-02'] as $fecha) {
            $this->registrar($idObra, ['descripcion' => "Item {$fecha}", 'fecha' => $fecha]);
        }
        $this->registrar($idOtra, ['descripcion' => 'De otra obra', 'fecha' => '2026-03-09']);

        self::assertSame(['2026-03-03', '2026-03-02', '2026-03-01'], array_column($this->listado($idObra), 'fecha'));
    }

    public function testUnaObraInexistenteDa404YEliminarDosVecesDa404(): void
    {
        $idProyecto = $this->crearProyecto();
        $id = $this->registrar($idProyecto, ['descripcion' => 'X', 'fecha' => '2026-03-02'])['cuerpo']['id_item'];

        self::assertSame(404, $this->registrar(999, ['descripcion' => 'X', 'fecha' => '2026-03-02'])['codigo']);
        self::assertSame(200, $this->capturar(fn () => $this->items()->eliminar((string) $id))['codigo']);
        self::assertSame(404, $this->capturar(fn () => $this->items()->eliminar((string) $id))['codigo']);
    }

    /**
     * @param array<string, mixed> $datos
     * @return array{codigo: int, cuerpo: mixed}
     */
    private function registrar(int $idProyecto, array $datos): array
    {
        return $this->capturar(fn () => $this->items()->crear((string) $idProyecto, $datos));
    }

    /** @return list<array<string, mixed>> */
    private function listado(int $idProyecto): array
    {
        return $this->capturar(fn () => $this->items()->listarPorProyecto((string) $idProyecto))['cuerpo'];
    }

    private function items(): ItemExcedenteController
    {
        return new ItemExcedenteController($this->base());
    }
}
