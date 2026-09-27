<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\MaquinariaController;

/**
 * Maquinaria: uso diario (RF23), alerta de consumo (RF24), historial de fallas
 * (RF27) y rendimiento por operario (RF28). Del uso cargado salen los
 * promedios con los que se decide la alerta, asi que un registro invalido no
 * solo queda mal: tambien corre el umbral de todos los demas.
 */
#[CoversClass(MaquinariaController::class)]
final class MaquinariaTest extends CasoConBase
{
    public function testElListadoSumaElUsoYCalculaLosPromediosPorHora(): void
    {
        $idMaquina = $this->crearMaquina('Retroexcavadora');
        $this->registrar($idMaquina, horas: 8, combustible: 40, produccion: 120);
        $this->registrar($idMaquina, horas: 2, combustible: 10, produccion: 30);
        $this->crearMaquina('Sin uso');

        $maquinas = $this->capturar(fn () => $this->maquinaria()->listar())['cuerpo'];
        $retro = $this->maquina($maquinas, $idMaquina);
        $sinUso = $maquinas[array_search('Sin uso', array_column($maquinas, 'nombre'), true)];

        self::assertNumero(10.0, $retro['horas']);
        self::assertNumero(50.0, $retro['combustible']);
        self::assertNumero(150.0, $retro['produccion']);
        self::assertNumero(5.0, $retro['combustible_por_hora']);
        self::assertNumero(15.0, $retro['produccion_por_hora']);
        self::assertNumero(0.0, $sinUso['combustible_por_hora'], 'sin horas no divide por cero');
    }

    public function testCuentaSoloLasFallasSinResolver(): void
    {
        $idMaquina = $this->crearMaquina();
        $this->cargarFalla($idMaquina, resuelto: false);
        $this->cargarFalla($idMaquina, resuelto: false);
        $this->cargarFalla($idMaquina, resuelto: true);

        $maquinas = $this->capturar(fn () => $this->maquinaria()->listar())['cuerpo'];

        self::assertSame(2, $this->maquina($maquinas, $idMaquina)['fallas_abiertas']);
    }

    public function testCrearUnaMaquinaExigeNombreYTipo(): void
    {
        $bien = $this->capturar(fn () => $this->maquinaria()->crear(['nombre' => 'Grua', 'tipo' => 'Elevacion']));
        $mal = $this->capturar(fn () => $this->maquinaria()->crear(['nombre' => '  ']));

        self::assertSame(201, $bien['codigo']);
        self::assertIsInt($bien['cuerpo']['id_maquinaria']);
        self::assertSame(422, $mal['codigo']);
        self::assertArrayHasKey('nombre', $mal['cuerpo']['errors']);
        self::assertArrayHasKey('tipo', $mal['cuerpo']['errors']);
    }

    /** RF24: un registro que consume mas de 1,5 veces el promedio de la maquina alerta. */
    public function testAlertaElRegistroQueConsumeMasDeUnaVezYMediaElPromedio(): void
    {
        $idMaquina = $this->crearMaquina();
        // Promedio: (30 + 30 + 90) / (10 + 10 + 10) = 5 litros por hora.
        $normal = $this->registrar($idMaquina, horas: 10, combustible: 30);
        $this->registrar($idMaquina, horas: 10, combustible: 30);
        $excesivo = $this->registrar($idMaquina, horas: 10, combustible: 90);

        $registros = $this->capturar(fn () => $this->maquinaria()->listarRegistros((string) $idMaquina))['cuerpo'];
        $alertas = array_column($registros, 'alerta_consumo', 'id_registro');

        self::assertTrue($alertas[$excesivo], '9 l/h contra un promedio de 5');
        self::assertFalse($alertas[$normal], '3 l/h contra un promedio de 5');
    }

    public function testUnRegistroNecesitaUnaMaquinaExistenteYUnaFechaValida(): void
    {
        $idMaquina = $this->crearMaquina();

        $sinMaquina = $this->capturar(fn () => $this->maquinaria()->crearRegistro('999', ['fecha' => '2026-03-01']));
        $sinFecha = $this->capturar(fn () => $this->maquinaria()->crearRegistro((string) $idMaquina, ['fecha' => 'ayer']));

        self::assertSame(404, $sinMaquina['codigo']);
        self::assertSame(422, $sinFecha['codigo']);
        self::assertSame([], $this->capturar(fn () => $this->maquinaria()->listarRegistros((string) $idMaquina))['cuerpo']);
    }

    /**
     * Horas, combustible o produccion negativos pasaban sin validar, y entran
     * al promedio con el que se decide la alerta de RF24: un registro con
     * combustible negativo baja el umbral de todos los demas.
     */
    public function testNoSeAceptanHorasCombustibleNiProduccionNegativos(): void
    {
        $idMaquina = $this->crearMaquina();

        foreach (['horas_uso', 'combustible_consumido', 'produccion_realizada'] as $campo) {
            $respuesta = $this->capturar(fn () => $this->maquinaria()->crearRegistro((string) $idMaquina, [
                'fecha' => '2026-03-01',
                $campo => -5,
            ]));

            self::assertSame(422, $respuesta['codigo'], $campo);
            self::assertArrayHasKey($campo, $respuesta['cuerpo']['errors'] ?? [], $campo);
        }
        self::assertSame([], $this->capturar(fn () => $this->maquinaria()->listarRegistros((string) $idMaquina))['cuerpo']);
    }

    /**
     * Un registro que apunta a una obra que no existe violaba la clave foranea,
     * y el cliente recibia un 500 en vez de un error del dato.
     */
    public function testUnRegistroConUnaObraInexistenteSeRechazaSinError500(): void
    {
        $idMaquina = $this->crearMaquina();

        $respuesta = $this->capturar(fn () => $this->maquinaria()->crearRegistro((string) $idMaquina, [
            'fecha' => '2026-03-01',
            'id_proyecto' => 999,
            'horas_uso' => 4,
        ]));

        self::assertSame(422, $respuesta['codigo']);
        self::assertArrayHasKey('id_proyecto', $respuesta['cuerpo']['errors']);
    }

    public function testUnRegistroPuedeImputarseAUnaObra(): void
    {
        $idMaquina = $this->crearMaquina();
        $idProyecto = $this->crearProyecto();

        $respuesta = $this->capturar(fn () => $this->maquinaria()->crearRegistro((string) $idMaquina, [
            'fecha' => '2026-03-01',
            'id_proyecto' => $idProyecto,
            'operario' => '  Juan  ',
            'horas_uso' => 4,
        ]));

        self::assertSame(201, $respuesta['codigo']);
        $registro = $this->capturar(fn () => $this->maquinaria()->listarRegistros((string) $idMaquina))['cuerpo'][0];
        self::assertSame($idProyecto, $registro['id_proyecto']);
        self::assertSame('Juan', $registro['operario']);
    }

    /** RF28: ordenado por produccion, y sin los registros que no dicen quien opero. */
    public function testElRendimientoPorOperarioSeOrdenaPorProduccion(): void
    {
        $idMaquina = $this->crearMaquina();
        $this->registrar($idMaquina, horas: 10, produccion: 50, operario: 'Ana');
        $this->registrar($idMaquina, horas: 5, produccion: 100, operario: 'Beto');
        $this->registrar($idMaquina, horas: 5, produccion: 30, operario: 'Ana');
        $this->registrar($idMaquina, horas: 8, produccion: 999, operario: null);

        $operarios = $this->capturar(fn () => $this->maquinaria()->rendimientoOperarios())['cuerpo'];

        self::assertSame(['Beto', 'Ana'], array_column($operarios, 'operario'));
        self::assertNumero(80.0, $operarios[1]['produccion']);
        self::assertNumero(15.0, $operarios[1]['horas']);
        self::assertNumero(5.33, $operarios[1]['produccion_por_hora']);
    }

    public function testLasFallasSeListanConSusMarcasComoBooleanos(): void
    {
        $idMaquina = $this->crearMaquina();

        $respuesta = $this->capturar(fn () => $this->maquinaria()->crearFalla((string) $idMaquina, [
            'fecha' => '2026-03-01',
            'componente' => 'Bomba hidraulica',
            'descripcion' => 'Perdida de aceite',
            'reemplazo' => true,
        ]));
        $fallas = $this->capturar(fn () => $this->maquinaria()->listarFallas((string) $idMaquina))['cuerpo'];

        self::assertSame(201, $respuesta['codigo']);
        self::assertCount(1, $fallas);
        self::assertTrue($fallas[0]['reemplazo']);
        self::assertFalse($fallas[0]['resuelto']);
        self::assertSame('Bomba hidraulica', $fallas[0]['componente']);
    }

    public function testUnaFallaExigeDescripcionYFechaYUnaMaquinaExistente(): void
    {
        $idMaquina = $this->crearMaquina();

        $mal = $this->capturar(fn () => $this->maquinaria()->crearFalla((string) $idMaquina, ['fecha' => 'x']));
        $sinMaquina = $this->capturar(fn () => $this->maquinaria()->crearFalla('999', [
            'fecha' => '2026-03-01',
            'descripcion' => 'Algo',
        ]));

        self::assertSame(422, $mal['codigo']);
        self::assertArrayHasKey('fecha', $mal['cuerpo']['errors']);
        self::assertArrayHasKey('descripcion', $mal['cuerpo']['errors']);
        self::assertSame(404, $sinMaquina['codigo']);
    }

    public function testEliminarUnaMaquinaBorraSusRegistrosYFallas(): void
    {
        $idMaquina = $this->crearMaquina();
        $this->registrar($idMaquina, horas: 1);
        $this->cargarFalla($idMaquina);

        $respuesta = $this->capturar(fn () => $this->maquinaria()->eliminar((string) $idMaquina));
        $otraVez = $this->capturar(fn () => $this->maquinaria()->eliminar((string) $idMaquina));

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame(404, $otraVez['codigo']);
        foreach (['registro_maquinaria', 'falla_maquinaria'] as $tabla) {
            $stmt = $this->base()->prepare("SELECT COUNT(*) FROM {$tabla} WHERE id_maquinaria = ?");
            $stmt->execute([$idMaquina]);
            self::assertSame(0, (int) $stmt->fetchColumn(), $tabla);
        }
    }

    public function testEliminarUnRegistroOUnaFallaInexistentesDa404(): void
    {
        self::assertSame(404, $this->capturar(fn () => $this->maquinaria()->eliminarRegistro('999'))['codigo']);
        self::assertSame(404, $this->capturar(fn () => $this->maquinaria()->eliminarFalla('999'))['codigo']);
    }

    // ----------------------------------------------------------------
    //  Ayudas
    // ----------------------------------------------------------------

    private function maquinaria(): MaquinariaController
    {
        return new MaquinariaController($this->base());
    }

    private function crearMaquina(string $nombre = 'Maquina de prueba'): int
    {
        $this->base()->prepare('INSERT INTO maquinaria (nombre, tipo) VALUES (?, ?)')->execute([$nombre, 'Excavacion']);

        return (int) $this->base()->lastInsertId();
    }

    private function registrar(
        int $idMaquina,
        float $horas = 0,
        float $combustible = 0,
        float $produccion = 0,
        ?string $operario = 'Operario'
    ): int {
        $this->base()->prepare(
            'INSERT INTO registro_maquinaria (id_maquinaria, fecha, operario, horas_uso, combustible_consumido, produccion_realizada)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$idMaquina, '2026-03-01', $operario, $horas, $combustible, $produccion]);

        return (int) $this->base()->lastInsertId();
    }

    private function cargarFalla(int $idMaquina, bool $resuelto = false): void
    {
        $this->base()->prepare(
            'INSERT INTO falla_maquinaria (id_maquinaria, fecha, descripcion, resuelto) VALUES (?, ?, ?, ?)'
        )->execute([$idMaquina, '2026-03-01', 'Falla', $resuelto ? 1 : 0]);
    }

    /**
     * @param list<array<string, mixed>> $maquinas
     * @return array<string, mixed>
     */
    private function maquina(array $maquinas, int $idMaquina): array
    {
        foreach ($maquinas as $maquina) {
            if ($maquina['id_maquinaria'] === $idMaquina) {
                return $maquina;
            }
        }
        self::fail("La maquina {$idMaquina} no esta en el listado");
    }
}
