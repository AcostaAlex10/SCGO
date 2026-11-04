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

    /** C-13: 2026-02-31 daba 500 en la base. Ahora 422 con el texto de formato y nada guardado. */
    public function testUnRegistroConUnaFechaImposibleDa422YNoSeGuarda(): void
    {
        $idMaquina = $this->crearMaquina();

        $respuesta = $this->capturar(fn () => $this->maquinaria()->crearRegistro((string) $idMaquina, ['fecha' => '2026-02-31']));

        self::assertSame(422, $respuesta['codigo']);
        self::assertSame('Formato esperado: YYYY-MM-DD', $respuesta['cuerpo']['errors']['fecha'] ?? null);
        self::assertSame([], $this->capturar(fn () => $this->maquinaria()->listarRegistros((string) $idMaquina))['cuerpo']);
    }

    public function testUnaFallaConUnaFechaImposibleDa422YNoSeGuarda(): void
    {
        $idMaquina = $this->crearMaquina();

        $respuesta = $this->capturar(fn () => $this->maquinaria()->crearFalla((string) $idMaquina, [
            'fecha' => '2026-02-31',
            'descripcion' => 'Perdida de aceite',
        ]));

        self::assertSame(422, $respuesta['codigo']);
        self::assertSame('Formato esperado: YYYY-MM-DD', $respuesta['cuerpo']['errors']['fecha'] ?? null);
        $stmt = $this->base()->prepare('SELECT COUNT(*) FROM falla_maquinaria WHERE id_maquinaria = ?');
        $stmt->execute([$idMaquina]);
        self::assertSame(0, (int) $stmt->fetchColumn());
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

    /**
     * RF27 pide conservar el historial: antes, la unica forma de bajar el
     * contador de fallas abiertas era borrar la falla (D-11).
     */
    public function testMarcarUnaFallaComoResueltaBajaElContadorYLaConserva(): void
    {
        $idMaquina = $this->crearMaquina();
        $idFalla = $this->cargarFalla($idMaquina);
        $this->cargarFalla($idMaquina);

        $respuesta = $this->capturar(fn () => $this->maquinaria()->actualizarFalla((string) $idFalla, ['resuelto' => true]));
        $maquinas = $this->capturar(fn () => $this->maquinaria()->listar())['cuerpo'];
        $fallas = $this->capturar(fn () => $this->maquinaria()->listarFallas((string) $idMaquina))['cuerpo'];

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame(1, $this->maquina($maquinas, $idMaquina)['fallas_abiertas']);
        self::assertCount(2, $fallas, 'la falla resuelta sigue en el historial');
        self::assertTrue(array_column($fallas, 'resuelto', 'id_falla')[$idFalla]);
    }

    public function testUnaFallaResueltaSePuedeReabrir(): void
    {
        $idMaquina = $this->crearMaquina();
        $idFalla = $this->cargarFalla($idMaquina, resuelto: true);

        $respuesta = $this->capturar(fn () => $this->maquinaria()->actualizarFalla((string) $idFalla, ['resuelto' => false]));
        $maquinas = $this->capturar(fn () => $this->maquinaria()->listar())['cuerpo'];

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame(1, $this->maquina($maquinas, $idMaquina)['fallas_abiertas']);
    }

    public function testActualizarUnaFallaExigeResueltoBooleanoYUnaFallaExistente(): void
    {
        $idMaquina = $this->crearMaquina();
        $idFalla = $this->cargarFalla($idMaquina);

        $sinCampo = $this->capturar(fn () => $this->maquinaria()->actualizarFalla((string) $idFalla, []));
        $texto = $this->capturar(fn () => $this->maquinaria()->actualizarFalla((string) $idFalla, ['resuelto' => 'si']));
        $inexistente = $this->capturar(fn () => $this->maquinaria()->actualizarFalla('999', ['resuelto' => true]));

        self::assertSame(422, $sinCampo['codigo']);
        self::assertArrayHasKey('resuelto', $sinCampo['cuerpo']['errors']);
        self::assertSame(422, $texto['codigo']);
        self::assertSame(404, $inexistente['codigo']);
        $maquinas = $this->capturar(fn () => $this->maquinaria()->listar())['cuerpo'];
        self::assertSame(1, $this->maquina($maquinas, $idMaquina)['fallas_abiertas'], 'nada cambio');
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
    //  D-13: consumo esperado por hora y ventana de la alerta (RF24)
    // ----------------------------------------------------------------

    public function testCrearSinElConsumoEsperadoSigueDando201YQuedaSinDato(): void
    {
        $respuesta = $this->capturar(fn () => $this->maquinaria()->crear(['nombre' => 'Grua', 'tipo' => 'Elevacion']));
        $maquinas = $this->capturar(fn () => $this->maquinaria()->listar())['cuerpo'];

        self::assertSame(201, $respuesta['codigo']);
        self::assertNull($respuesta['cuerpo']['consumo_esperado_por_hora']);
        self::assertNull($this->maquina($maquinas, $respuesta['cuerpo']['id_maquinaria'])['consumo_esperado_por_hora']);
    }

    public function testCrearConElConsumoEsperadoLoGuardaYLoDevuelveComoNumero(): void
    {
        $respuesta = $this->capturar(fn () => $this->maquinaria()->crear([
            'nombre' => 'Grua',
            'tipo' => 'Elevacion',
            'consumo_esperado_por_hora' => '7.5',
        ]));
        $maquinas = $this->capturar(fn () => $this->maquinaria()->listar())['cuerpo'];

        self::assertSame(201, $respuesta['codigo']);
        self::assertNumero(7.5, $respuesta['cuerpo']['consumo_esperado_por_hora']);
        self::assertNumero(7.5, $this->maquina($maquinas, $respuesta['cuerpo']['id_maquinaria'])['consumo_esperado_por_hora']);
    }

    public function testElConsumoEsperadoInvalidoAlCrearDa422YNoGuardaNada(): void
    {
        foreach ([0, -3, '0', 'abc', true, '12 litros', 1000000] as $invalido) {
            $respuesta = $this->capturar(fn () => $this->maquinaria()->crear([
                'nombre' => 'Grua',
                'tipo' => 'Elevacion',
                'consumo_esperado_por_hora' => $invalido,
            ]));

            self::assertSame(422, $respuesta['codigo'], var_export($invalido, true));
            self::assertArrayHasKey('consumo_esperado_por_hora', $respuesta['cuerpo']['errors'], var_export($invalido, true));
        }
        $mensaje = $this->capturar(fn () => $this->maquinaria()->crear([
            'nombre' => 'Grua',
            'tipo' => 'Elevacion',
            'consumo_esperado_por_hora' => 0,
        ]))['cuerpo']['errors']['consumo_esperado_por_hora'];
        self::assertSame('Debe ser un número mayor a 0', $mensaje);
        self::assertSame([], $this->capturar(fn () => $this->maquinaria()->listar())['cuerpo']);
    }

    public function testActualizarCargaCambiaYBorraElConsumoEsperado(): void
    {
        $id = $this->crearMaquina('Retro');

        $carga = $this->capturar(fn () => $this->maquinaria()->actualizar((string) $id, ['consumo_esperado_por_hora' => 8]));
        self::assertSame(200, $carga['codigo']);
        self::assertNumero(8.0, $carga['cuerpo']['consumo_esperado_por_hora']);
        self::assertNumero(8.0, $this->maquina($this->capturar(fn () => $this->maquinaria()->listar())['cuerpo'], $id)['consumo_esperado_por_hora']);

        $this->capturar(fn () => $this->maquinaria()->actualizar((string) $id, ['consumo_esperado_por_hora' => '9.25']));
        self::assertNumero(9.25, $this->maquina($this->capturar(fn () => $this->maquinaria()->listar())['cuerpo'], $id)['consumo_esperado_por_hora']);

        foreach ([null, '', '  '] as $vacio) {
            $this->capturar(fn () => $this->maquinaria()->actualizar((string) $id, ['consumo_esperado_por_hora' => 8]));
            $borra = $this->capturar(fn () => $this->maquinaria()->actualizar((string) $id, ['consumo_esperado_por_hora' => $vacio]));

            self::assertSame(200, $borra['codigo'], var_export($vacio, true));
            self::assertNull($this->maquina($this->capturar(fn () => $this->maquinaria()->listar())['cuerpo'], $id)['consumo_esperado_por_hora']);
        }
    }

    public function testActualizarSoloCambiaElConsumoEsperado(): void
    {
        $id = $this->crearMaquina('Retro');

        $this->capturar(fn () => $this->maquinaria()->actualizar((string) $id, [
            'consumo_esperado_por_hora' => 8,
            'nombre' => 'Otro nombre',
            'tipo' => 'Otro tipo',
            'activa' => false,
        ]));
        $maquina = $this->maquina($this->capturar(fn () => $this->maquinaria()->listar())['cuerpo'], $id);

        self::assertSame('Retro', $maquina['nombre']);
        self::assertSame('Excavacion', $maquina['tipo']);
        self::assertTrue($maquina['activa']);
    }

    public function testActualizarConUnValorInvalidoDa422YConservaElDato(): void
    {
        $id = $this->crearMaquina('Retro', 8);

        foreach ([0, -1, 'abc'] as $invalido) {
            $respuesta = $this->capturar(fn () => $this->maquinaria()->actualizar((string) $id, ['consumo_esperado_por_hora' => $invalido]));

            self::assertSame(422, $respuesta['codigo'], var_export($invalido, true));
            self::assertSame('Debe ser un número mayor a 0', $respuesta['cuerpo']['errors']['consumo_esperado_por_hora']);
        }
        // Un cuerpo sin el campo tampoco borra el dato.
        self::assertSame(422, $this->capturar(fn () => $this->maquinaria()->actualizar((string) $id, []))['codigo']);
        self::assertNumero(8.0, $this->maquina($this->capturar(fn () => $this->maquinaria()->listar())['cuerpo'], $id)['consumo_esperado_por_hora']);
    }

    public function testActualizarUnaMaquinaInexistenteDa404(): void
    {
        $respuesta = $this->capturar(fn () => $this->maquinaria()->actualizar('999', ['consumo_esperado_por_hora' => 8]));

        self::assertSame(404, $respuesta['codigo']);
        self::assertSame(['error' => 'Maquinaria no encontrada'], $respuesta['cuerpo']);
    }

    /**
     * Hallazgo del revisor: un id que no es un numero entero ("1abc") no es la
     * maquina 1. MySQL compara '1abc' = 1 y lo daria por existente.
     */
    public function testActualizarConUnIdConBasuraDetrasDa404YNoTocaLaMaquina(): void
    {
        $id = $this->crearMaquina('Retro', 8);

        $respuesta = $this->capturar(fn () => $this->maquinaria()->actualizar($id . 'abc', ['consumo_esperado_por_hora' => 3]));

        self::assertSame(404, $respuesta['codigo']);
        self::assertNumero(8.0, $this->maquina($this->capturar(fn () => $this->maquinaria()->listar())['cuerpo'], $id)['consumo_esperado_por_hora']);
    }

    public function testElListadoDeRegistrosTraeElConsumoEsperadoComoNumeroONull(): void
    {
        $con = $this->crearMaquina('Con dato', 8);
        $sin = $this->crearMaquina('Sin dato');
        $this->registrar($con, horas: 1, combustible: 8);
        $this->registrar($sin, horas: 1, combustible: 8);

        $deLaCon = $this->capturar(fn () => $this->maquinaria()->listarRegistros((string) $con))['cuerpo'][0];
        $deLaSin = $this->capturar(fn () => $this->maquinaria()->listarRegistros((string) $sin))['cuerpo'][0];

        self::assertNumero(8.0, $deLaCon['consumo_esperado_por_hora']);
        self::assertNull($deLaSin['consumo_esperado_por_hora']);
    }

    /** El caso que motivo D-13: contra su propio promedio, nunca se aparta. */
    public function testUnaMaquinaQueSiempreConsumeElDobleDeLoEsperadoAlertaEnSusRegistros(): void
    {
        $sinDato = $this->crearMaquina('Sin dato');
        $conDato = $this->crearMaquina('Con dato', 8);
        foreach ([$sinDato, $conDato] as $id) {
            for ($dia = 1; $dia <= 3; $dia++) {
                $this->registrar($id, horas: 1, combustible: 16, fecha: $this->fechaHace($dia));
            }
        }

        $sinAlertas = $this->capturar(fn () => $this->maquinaria()->listarRegistros((string) $sinDato))['cuerpo'];
        $conAlertas = $this->capturar(fn () => $this->maquinaria()->listarRegistros((string) $conDato))['cuerpo'];

        self::assertSame([false, false, false], array_column($sinAlertas, 'alerta_consumo'), 'sin dato, contra su promedio');
        self::assertSame([true, true, true], array_column($conAlertas, 'alerta_consumo'), '16 L/h contra 8 esperados');
    }

    public function testContraElEsperadoJustoUnaVezYMediaNoAlerta(): void
    {
        $id = $this->crearMaquina('Con dato', 8);
        $justo = $this->registrar($id, horas: 2, combustible: 24);
        $pasado = $this->registrar($id, horas: 2, combustible: 24.1);

        $alertas = array_column($this->capturar(fn () => $this->maquinaria()->listarRegistros((string) $id))['cuerpo'], 'alerta_consumo', 'id_registro');

        self::assertFalse($alertas[$justo], '12 L/h contra 12 de umbral');
        self::assertTrue($alertas[$pasado]);
    }

    /** Hace 30 dias todavia entra; hace 31, no. */
    public function testSoloAlertanLosRegistrosDeLosUltimosTreintaDias(): void
    {
        $id = $this->crearMaquina('Con dato', 8);
        $hoy = $this->registrar($id, horas: 1, combustible: 40, fecha: $this->fechaHace(0));
        $borde = $this->registrar($id, horas: 1, combustible: 40, fecha: $this->fechaHace(30));
        $viejo = $this->registrar($id, horas: 1, combustible: 40, fecha: $this->fechaHace(31));

        $alertas = array_column($this->capturar(fn () => $this->maquinaria()->listarRegistros((string) $id))['cuerpo'], 'alerta_consumo', 'id_registro');

        self::assertTrue($alertas[$hoy]);
        self::assertTrue($alertas[$borde], 'hace exactamente 30 dias entra');
        self::assertFalse($alertas[$viejo], 'hace 31 dias ya no alerta');
    }

    /** La ventana decide quien alerta, no achica el promedio de una maquina sin dato. */
    public function testLaVentanaNoAchicaElPromedioDeUnaMaquinaSinDato(): void
    {
        $id = $this->crearMaquina();
        // Promedio de todos: (2 + 2 + 2 + 30) / 4 = 9 L/h y umbral 13,5. Si solo
        // contara la ventana, el promedio seria 30 y el registro reciente no alertaria.
        foreach ([60, 61, 62] as $dias) {
            $this->registrar($id, horas: 1, combustible: 2, fecha: $this->fechaHace($dias));
        }
        $reciente = $this->registrar($id, horas: 1, combustible: 30, fecha: $this->fechaHace(2));

        $alertas = array_column($this->capturar(fn () => $this->maquinaria()->listarRegistros((string) $id))['cuerpo'], 'alerta_consumo', 'id_registro');

        self::assertTrue($alertas[$reciente]);
    }

    // ----------------------------------------------------------------
    //  Ayudas
    // ----------------------------------------------------------------

    private function maquinaria(): MaquinariaController
    {
        return new MaquinariaController($this->base());
    }

    private function crearMaquina(string $nombre = 'Maquina de prueba', ?float $esperado = null): int
    {
        $this->base()->prepare('INSERT INTO maquinaria (nombre, tipo, consumo_esperado_por_hora) VALUES (?, ?, ?)')
            ->execute([$nombre, 'Excavacion', $esperado]);

        return (int) $this->base()->lastInsertId();
    }

    private function registrar(
        int $idMaquina,
        float $horas = 0,
        float $combustible = 0,
        float $produccion = 0,
        ?string $operario = 'Operario',
        ?string $fecha = null
    ): int {
        $this->base()->prepare(
            'INSERT INTO registro_maquinaria (id_maquinaria, fecha, operario, horas_uso, combustible_consumido, produccion_realizada)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$idMaquina, $fecha ?? $this->fechaHace(1), $operario, $horas, $combustible, $produccion]);

        return (int) $this->base()->lastInsertId();
    }

    private function cargarFalla(int $idMaquina, bool $resuelto = false): int
    {
        $this->base()->prepare(
            'INSERT INTO falla_maquinaria (id_maquinaria, fecha, descripcion, resuelto) VALUES (?, ?, ?, ?)'
        )->execute([$idMaquina, '2026-03-01', 'Falla', $resuelto ? 1 : 0]);

        return (int) $this->base()->lastInsertId();
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
