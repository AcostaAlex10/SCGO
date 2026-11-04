<?php

declare(strict_types=1);

namespace Sgso\Tests\Reglas;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sgso\Reglas\ConsumoMaquinaria;

/** Cuándo un registro de uso de una máquina consume de más (RF24). */
#[CoversClass(ConsumoMaquinaria::class)]
final class ConsumoMaquinariaTest extends TestCase
{
    public function testElConsumoPorHoraEsElCombustibleSobreLasHoras(): void
    {
        self::assertEqualsWithDelta(12.5, ConsumoMaquinaria::porHora(50, 4), 0.0001);
    }

    /** Un registro sin horas no divide por cero: no consumió nada por hora. */
    public function testSinHorasElConsumoPorHoraEsCero(): void
    {
        self::assertSame(0.0, ConsumoMaquinaria::porHora(30, 0));
    }

    public function testEsAnomaloAlSuperarUnaVezYMediaElPromedio(): void
    {
        self::assertTrue(ConsumoMaquinaria::esAnomalo(15.01, 10));
    }

    /** El umbral es estricto: justo una vez y media todavía es normal. */
    public function testJustoUnaVezYMediaNoEsAnomalo(): void
    {
        self::assertFalse(ConsumoMaquinaria::esAnomalo(15, 10));
        self::assertFalse(ConsumoMaquinaria::esAnomalo(12, 10));
    }

    /** Sin promedio no hay contra qué comparar: ningún registro alerta. */
    public function testSinPromedioNadaEsAnomalo(): void
    {
        self::assertFalse(ConsumoMaquinaria::esAnomalo(40, 0));
    }

    // ----------------------------------------------------------------
    //  D-13: consumo esperado y ventana
    // ----------------------------------------------------------------

    public function testConDatoEsperadoLaReferenciaEsElEsperado(): void
    {
        self::assertSame(8.0, ConsumoMaquinaria::referencia(8.0, 20.0));
    }

    public function testSinDatoEsperadoLaReferenciaEsElPromedio(): void
    {
        self::assertSame(20.0, ConsumoMaquinaria::referencia(null, 20.0));
    }

    /** Un 0 guardado no es un dato: no puede dejar a la máquina sin alertas. */
    public function testUnEsperadoEnCeroNoCuentaComoDato(): void
    {
        self::assertSame(20.0, ConsumoMaquinaria::referencia(0.0, 20.0));
    }

    /** Lo que no se aparta de su propio promedio sí se aparta de lo esperado. */
    public function testUnaMaquinaQueSiempreConsumeDoblePasaElUmbralDelEsperado(): void
    {
        // Esperado 8 L/h, consume 16 siempre: el promedio también es 16 y nunca alertaría.
        self::assertFalse(ConsumoMaquinaria::esAnomalo(16, ConsumoMaquinaria::referencia(null, 16)));
        self::assertTrue(ConsumoMaquinaria::esAnomalo(16, ConsumoMaquinaria::referencia(8, 16)));
    }

    /** El umbral contra el esperado también es estricto. */
    public function testContraElEsperadoJustoUnaVezYMediaNoEsAnomalo(): void
    {
        self::assertFalse(ConsumoMaquinaria::esAnomalo(12, ConsumoMaquinaria::referencia(8, 3)));
        self::assertTrue(ConsumoMaquinaria::esAnomalo(12.01, ConsumoMaquinaria::referencia(8, 3)));
    }

    /** Referencia 0: ni división por cero ni alerta. */
    public function testConReferenciaCeroNadaEsAnomalo(): void
    {
        self::assertFalse(ConsumoMaquinaria::esAnomalo(0, 0));
        self::assertFalse(ConsumoMaquinaria::esAnomalo(40, ConsumoMaquinaria::referencia(null, 0)));
    }

    public function testLaVentanaEmpiezaTreintaDiasAntesDeHoy(): void
    {
        $hoy = new DateTimeImmutable('2026-11-04', new DateTimeZone(ConsumoMaquinaria::ZONA));

        self::assertSame(30, ConsumoMaquinaria::VENTANA_DIAS);
        self::assertSame('2026-10-05', ConsumoMaquinaria::desde($hoy));
    }

    public function testLaVentanaCruzaElCambioDeAnio(): void
    {
        self::assertSame('2025-12-31', ConsumoMaquinaria::desde(new DateTimeImmutable('2026-01-30')));
    }

    /** El borde es inclusivo: hace exactamente 30 días entra, hace 31 no. */
    public function testElBordeDeLaVentanaEsInclusivo(): void
    {
        $desde = ConsumoMaquinaria::desde(new DateTimeImmutable('2026-11-04'));

        self::assertTrue(ConsumoMaquinaria::enVentana('2026-10-05', $desde), 'hace 30 días');
        self::assertFalse(ConsumoMaquinaria::enVentana('2026-10-04', $desde), 'hace 31 días');
        self::assertTrue(ConsumoMaquinaria::enVentana('2026-11-04', $desde), 'hoy');
    }

    public function testUnRegistroAnomaloFueraDeLaVentanaNoMarcaAlerta(): void
    {
        $desde = '2026-10-05';

        self::assertTrue(ConsumoMaquinaria::marcaAlerta('2026-10-05', 1, 40, 10, $desde));
        self::assertFalse(ConsumoMaquinaria::marcaAlerta('2026-10-04', 1, 40, 10, $desde));
    }

    public function testUnRegistroSinHorasNuncaMarcaAlerta(): void
    {
        self::assertFalse(ConsumoMaquinaria::marcaAlerta('2026-11-01', 0, 50, 10, '2026-10-05'));
    }

    public function testElBordeExactoDeUnaVezYMediaNoMarcaAlertaDentroDeLaVentana(): void
    {
        self::assertFalse(ConsumoMaquinaria::marcaAlerta('2026-11-01', 2, 30, 10, '2026-10-05'), '15 L/h contra 10');
        self::assertTrue(ConsumoMaquinaria::marcaAlerta('2026-11-01', 2, 30.02, 10, '2026-10-05'));
    }

    /**
     * RF24 (D-13), hallazgo del revisor: un registro con fecha futura no esta en
     * los ultimos 30 dias, asi que no alerta. Uno de hoy sigue alertando.
     */
    public function testUnRegistroConFechaFuturaNoMarcaAlerta(): void
    {
        $desde = '2026-10-05';

        self::assertFalse(ConsumoMaquinaria::marcaAlerta('2099-01-01', 1, 40, 8, $desde), 'fecha futura');
        self::assertTrue(ConsumoMaquinaria::marcaAlerta('2026-11-04', 1, 40, 8, $desde), 'un registro de hoy sigue alertando');
    }

    /**
     * RF24 (D-13), hallazgo del revisor: el borde de 1,5 veces lo esperado es
     * exacto aunque el producto no lo sea en coma flotante (5,10 * 1,5 da
     * 7,6499999...). Con datos de 2 decimales, justo el umbral no alerta.
     *
     * @return array<string, array{float, float, float}>
     */
    public static function bordesConDosDecimales(): array
    {
        return [
            'esperado 5,10; 15,30 L en 2 h' => [5.10, 2, 15.30],
            'esperado 5,10; 30,60 L en 4 h' => [5.10, 4, 30.60],
            'esperado 4,10; 12,30 L en 2 h' => [4.10, 2, 12.30],
            'esperado 3,30; 49,50 L en 10 h' => [3.30, 10, 49.50],
            'esperado 6,60; 49,50 L en 5 h' => [6.60, 5, 49.50],
            'esperado 8,20; 61,50 L en 5 h' => [8.20, 5, 61.50],
            'esperado 1,90; 28,50 L en 10 h' => [1.90, 10, 28.50],
            'esperado 2,20; 16,50 L en 5 h' => [2.20, 5, 16.50],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bordesConDosDecimales')]
    public function testJustoElUmbralConDosDecimalesNoMarcaAlerta(float $esperado, float $horas, float $litros): void
    {
        self::assertFalse(ConsumoMaquinaria::marcaAlerta('2026-11-01', $horas, $litros, $esperado, '2026-10-05'));
    }

    /** Y un centavo de litro por encima del umbral (7,66 contra 7,65) si alerta. */
    public function testApenasPorEncimaDelUmbralConDosDecimalesMarcaAlerta(): void
    {
        self::assertTrue(ConsumoMaquinaria::marcaAlerta('2026-11-01', 2, 15.32, 5.10, '2026-10-05'), '7,66 contra 7,65');
    }

    public function testLosNumerosSeEscribenConComaYSinCerosDeMas(): void
    {
        self::assertSame('1,5', ConsumoMaquinaria::formatear(ConsumoMaquinaria::FACTOR));
        self::assertSame('8', ConsumoMaquinaria::formatear(8.0));
        self::assertSame('7,25', ConsumoMaquinaria::formatear(7.25));
        self::assertSame('1250', ConsumoMaquinaria::formatear(1250.0));
    }

    public function testElTextoDiceConQueSeComparo(): void
    {
        self::assertSame('esperado de la máquina (8 L/h)', ConsumoMaquinaria::textoDeReferencia(8.0));
        self::assertSame('promedio de la máquina', ConsumoMaquinaria::textoDeReferencia(null));
    }

    /** Las 22:00 de Buenos Aires ya son el día siguiente en UTC: "hoy" es el de Buenos Aires. */
    public function testHoyEsLaFechaDeBuenosAiresAunqueEnUtcYaSeaMañana(): void
    {
        $instante = new DateTimeImmutable('2026-11-05 01:00:00', new DateTimeZone('UTC')); // 22:00 del 4 en Buenos Aires

        self::assertSame('2026-11-05', $instante->format('Y-m-d'));
        self::assertSame('2026-11-04', ConsumoMaquinaria::hoy($instante)->format('Y-m-d'));
        self::assertSame('2026-10-05', ConsumoMaquinaria::desde(ConsumoMaquinaria::hoy($instante)));
    }

    public function testHoyAceptaUnInstanteEnCualquierZona(): void
    {
        $instante = new DateTimeImmutable('2026-11-04 23:30:00', new DateTimeZone('Asia/Tokyo')); // 11:30 del 4 en Buenos Aires

        self::assertSame('2026-11-04', ConsumoMaquinaria::hoy($instante)->format('Y-m-d'));
    }

    /** La ventana va de hace 30 días a hoy, ambos inclusive; mañana ya no. */
    public function testLaVentanaTambienTieneLimiteSuperiorHoyInclusive(): void
    {
        $desde = '2026-10-05'; // hoy: 2026-11-04

        self::assertTrue(ConsumoMaquinaria::enVentana('2026-11-04', $desde));
        self::assertFalse(ConsumoMaquinaria::enVentana('2026-11-05', $desde));
    }

    /** Un promedio con más de dos decimales: 40,60 / 3 h, y 20,3 L en 1 h es justo 1,5 veces. */
    public function testElBordeContraUnPromedioSinDosDecimalesEsExacto(): void
    {
        $promedio = ConsumoMaquinaria::fraccionDeReferencia(null, 4060, 300);

        self::assertFalse(ConsumoMaquinaria::marcaAlertaExacta('2026-11-01', 1, 20.3, $promedio, '2026-10-05'), 'justo 1,5 veces');
        self::assertTrue(ConsumoMaquinaria::marcaAlertaExacta('2026-11-01', 1, 20.31, $promedio, '2026-10-05'));
    }

    public function testElEsperadoGanaAlPromedioEnLaFraccion(): void
    {
        self::assertSame([510, 100], ConsumoMaquinaria::fraccionDeReferencia(5.1, 4060, 300));
        self::assertSame([4060, 300], ConsumoMaquinaria::fraccionDeReferencia(null, 4060, 300));
    }

    public function testConReferenciaCeroNoHayFraccionQueAlerte(): void
    {
        self::assertFalse(ConsumoMaquinaria::marcaAlertaExacta('2026-11-01', 1, 40, [0, 0], '2026-10-05'));
    }
}
