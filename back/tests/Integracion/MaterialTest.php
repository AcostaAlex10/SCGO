<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\MaterialController;

/** El catalogo de materiales (RF04): de ahi salen las asignaciones a cada obra. */
#[CoversClass(MaterialController::class)]
final class MaterialTest extends CasoConBase
{
    public function testElCatalogoSeListaPorNombre(): void
    {
        $this->crearMaterial('Hierro del 8', 'kg');
        $this->crearMaterial('Arena', 'm3');
        $this->crearMaterial('Cemento', 'bolsa');

        $respuesta = $this->capturar(fn () => $this->materiales()->listar());

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame(['Arena', 'Cemento', 'Hierro del 8'], array_column($respuesta['cuerpo'], 'nombre'));
        self::assertIsInt($respuesta['cuerpo'][0]['id_material']);
    }

    public function testAgregarUnMaterialAlCatalogo(): void
    {
        $respuesta = $this->capturar(fn () => $this->materiales()->crear(['nombre' => '  Cal hidratada  ', 'unidad' => ' bolsa ']));

        self::assertSame(201, $respuesta['codigo']);
        self::assertSame('Cal hidratada', $respuesta['cuerpo']['nombre']);
        self::assertSame('bolsa', $respuesta['cuerpo']['unidad']);
        $real = (int) $this->base()->query('SELECT MAX(id_material) FROM material')->fetchColumn();
        self::assertSame($real, $respuesta['cuerpo']['id_material']);
    }

    /** "cemento" y "Cemento" son el mismo material: el catalogo no los duplica. */
    public function testNoSeDuplicaUnMaterialAunqueCambienLasMayusculas(): void
    {
        $this->crearMaterial('Cemento', 'bolsa');

        $respuesta = $this->capturar(fn () => $this->materiales()->crear(['nombre' => 'cemento', 'unidad' => 'kg']));

        self::assertSame(409, $respuesta['codigo']);
        self::assertSame(1, (int) $this->base()->query('SELECT COUNT(*) FROM material')->fetchColumn());
    }

    public function testNombreYUnidadSonObligatorios(): void
    {
        $respuesta = $this->capturar(fn () => $this->materiales()->crear(['nombre' => '   ']));

        self::assertSame(422, $respuesta['codigo']);
        self::assertArrayHasKey('nombre', $respuesta['cuerpo']['errors']);
        self::assertArrayHasKey('unidad', $respuesta['cuerpo']['errors']);
    }

    private function materiales(): MaterialController
    {
        return new MaterialController($this->base());
    }
}
