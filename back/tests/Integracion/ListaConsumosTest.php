<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Sgso\MaterialObraController;

/**
 * La lista de consumos que el encargado carga y envía junta (CU22, D-16):
 * "se cargan en una lista la cantidad de materiales; se guarda y se envía la
 * lista". DEF-04 del informe de testing del Grupo 9.
 *
 * La lista entra entera o no entra: si un material no tiene stock, no se
 * guarda ningún consumo. Guardar la mitad dejaría al encargado sin saber qué
 * volver a cargar.
 */
#[CoversClass(MaterialObraController::class)]
final class ListaConsumosTest extends CasoConBase
{
    public function testUnaListaValidaGuardaTodosLosConsumosConLaMismaFecha(): void
    {
        $idProyecto = $this->crearProyecto();
        $cemento = $this->asignarMaterial($idProyecto, $this->crearMaterial('Cemento', 'bolsa'), 10);
        $arena = $this->asignarMaterial($idProyecto, $this->crearMaterial('Arena', 'm3'), 5);

        $respuesta = $this->enviar($idProyecto, [
            ['id_asignacion' => $cemento, 'cantidad_consumida' => 4],
            ['id_asignacion' => $arena, 'cantidad_consumida' => 2.5],
        ]);

        self::assertSame(201, $respuesta['codigo']);
        self::assertSame(2, $respuesta['cuerpo']['registrados']);
        self::assertSame([4.0, 2.5], [$this->consumido($cemento), $this->consumido($arena)]);
        $fechas = $this->base()->query('SELECT DISTINCT fecha FROM consumo_material')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['2026-03-02'], $fechas);
    }

    public function testSiUnMaterialNoTieneStockNoSeGuardaNinguno(): void
    {
        $idProyecto = $this->crearProyecto();
        $cemento = $this->asignarMaterial($idProyecto, $this->crearMaterial('Cemento', 'bolsa'), 10);
        $arena = $this->asignarMaterial($idProyecto, $this->crearMaterial('Arena', 'm3'), 5);

        $respuesta = $this->enviar($idProyecto, [
            ['id_asignacion' => $cemento, 'cantidad_consumida' => 4],
            ['id_asignacion' => $arena, 'cantidad_consumida' => 6],
        ]);

        self::assertSame(422, $respuesta['codigo']);
        $mensajes = implode(' ', $respuesta['cuerpo']['errors']);
        self::assertStringContainsString('Arena', $mensajes);
        self::assertStringContainsString('Stock insuficiente: quedan 5 m3', $mensajes);
        self::assertStringNotContainsString('Cemento', $mensajes, 'el que alcanza no se marca');
        self::assertSame([0.0, 0.0], [$this->consumido($cemento), $this->consumido($arena)]);
    }

    /** Dos renglones del mismo material suman: cada uno entra, juntos no. */
    public function testDosRenglonesDelMismoMaterialSumanContraElStock(): void
    {
        $idProyecto = $this->crearProyecto();
        $cemento = $this->asignarMaterial($idProyecto, $this->crearMaterial(), 10);

        $respuesta = $this->enviar($idProyecto, [
            ['id_asignacion' => $cemento, 'cantidad_consumida' => 6],
            ['id_asignacion' => $cemento, 'cantidad_consumida' => 6],
        ]);

        self::assertSame(422, $respuesta['codigo']);
        self::assertSame(0.0, $this->consumido($cemento));
    }

    public function testUnMaterialDeOtraObraNoEntraEnLaLista(): void
    {
        $idProyecto = $this->crearProyecto('en_ejecucion', 'Obra propia');
        $propio = $this->asignarMaterial($idProyecto, $this->crearMaterial(), 10);
        $ajeno = $this->asignarMaterial($this->crearProyecto('en_ejecucion', 'Obra ajena'), $this->crearMaterial('Arena', 'm3'), 10);

        $respuesta = $this->enviar($idProyecto, [
            ['id_asignacion' => $propio, 'cantidad_consumida' => 1],
            ['id_asignacion' => $ajeno, 'cantidad_consumida' => 1],
        ]);

        self::assertSame(422, $respuesta['codigo']);
        self::assertSame([0.0, 0.0], [$this->consumido($propio), $this->consumido($ajeno)]);
    }

    public function testUnaListaVaciaOConCantidadesInvalidasSeRechaza(): void
    {
        $idProyecto = $this->crearProyecto();
        $cemento = $this->asignarMaterial($idProyecto, $this->crearMaterial(), 10);

        foreach ([[], [['id_asignacion' => $cemento, 'cantidad_consumida' => 0]], [['id_asignacion' => $cemento, 'cantidad_consumida' => 'diez']], [['cantidad_consumida' => 1]]] as $lista) {
            self::assertSame(422, $this->enviar($idProyecto, $lista)['codigo'], json_encode($lista) ?: '');
        }
        self::assertSame(422, $this->capturar(fn () => $this->materiales()->crearListaConsumos((string) $idProyecto, [
            'fecha' => '2026-03-02',
            'consumos' => 'no es una lista',
        ]))['codigo']);
        self::assertSame(422, $this->capturar(fn () => $this->materiales()->crearListaConsumos((string) $idProyecto, [
            'fecha' => '2/3/2026',
            'consumos' => [['id_asignacion' => $cemento, 'cantidad_consumida' => 1]],
        ]))['codigo']);
        self::assertSame(0.0, $this->consumido($cemento));
    }

    /** @return array<string, array{string}> */
    public static function fechasImposibles(): array
    {
        return [
            'dia que no existe' => ['2026-02-31'],
            'salto de linea al final' => ["2026-01-01\n"],
        ];
    }

    /** Una fecha imposible no pasa por la base (C-13): 422 y la lista entera queda afuera. */
    #[DataProvider('fechasImposibles')]
    public function testUnaFechaImposibleDa422YNoGuardaNingunaLinea(string $fecha): void
    {
        $idProyecto = $this->crearProyecto();
        $cemento = $this->asignarMaterial($idProyecto, $this->crearMaterial('Cemento', 'bolsa'), 10);
        $arena = $this->asignarMaterial($idProyecto, $this->crearMaterial('Arena', 'm3'), 5);

        $respuesta = $this->capturar(fn () => $this->materiales()->crearListaConsumos((string) $idProyecto, [
            'fecha' => $fecha,
            'consumos' => [
                ['id_asignacion' => $cemento, 'cantidad_consumida' => 4],
                ['id_asignacion' => $arena, 'cantidad_consumida' => 2.5],
            ],
        ]));

        self::assertSame(422, $respuesta['codigo']);
        self::assertSame('Formato esperado: YYYY-MM-DD', $respuesta['cuerpo']['errors']['fecha']);
        self::assertSame([0.0, 0.0], [$this->consumido($cemento), $this->consumido($arena)]);
        self::assertSame(0, (int) $this->base()->query('SELECT COUNT(*) FROM consumo_material')->fetchColumn());
    }

    public function testUnaObraInexistenteDa404(): void
    {
        self::assertSame(404, $this->enviar(999999, [['id_asignacion' => 1, 'cantidad_consumida' => 1]])['codigo']);
    }

    // ----------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $consumos
     * @return array{codigo: int, cuerpo: mixed}
     */
    private function enviar(int $idProyecto, array $consumos): array
    {
        return $this->capturar(fn () => $this->materiales()->crearListaConsumos((string) $idProyecto, [
            'fecha' => '2026-03-02',
            'consumos' => $consumos,
        ]));
    }

    private function consumido(int $idAsignacion): float
    {
        $stmt = $this->base()->prepare('SELECT COALESCE(SUM(cantidad_consumida), 0) FROM consumo_material WHERE id_asignacion = ?');
        $stmt->execute([$idAsignacion]);

        return (float) $stmt->fetchColumn();
    }

    private function materiales(): MaterialObraController
    {
        return new MaterialObraController($this->base());
    }
}
