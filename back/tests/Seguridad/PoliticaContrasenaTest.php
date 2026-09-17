<?php

declare(strict_types=1);

namespace Sgso\Tests\Seguridad;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sgso\Seguridad\PoliticaContrasena;

/**
 * Reglas de la contraseña al darla de alta o cambiarla (plan de producto, A-05).
 *
 * Hasta ahora el mínimo era de 6 caracteres, que se prueban por fuerza bruta en
 * minutos. La política es pura: no sabe de base de datos ni de HTTP, así que se
 * prueba sola.
 */
#[CoversClass(PoliticaContrasena::class)]
final class PoliticaContrasenaTest extends TestCase
{
    public function testUnaContrasenaMasCortaQueElMinimoSeRechaza(): void
    {
        self::assertNotNull(PoliticaContrasena::validar('nueve-car'));
    }

    public function testElLargoJustoAlcanza(): void
    {
        self::assertNull(PoliticaContrasena::validar('diez-carac'));
    }

    public function testElLargoSeCuentaEnCaracteresNoEnBytes(): void
    {
        // En UTF-8 la "ñ" ocupa dos bytes: con strlen() esta contraseña de nueve
        // caracteres pasaría por tener diez bytes.
        self::assertNotNull(PoliticaContrasena::validar('añoañoañ'));
    }

    public function testLasContrasenasMasUsadasSeRechazanAunqueSeanLargas(): void
    {
        self::assertNotNull(PoliticaContrasena::validar('1234567890'));
        self::assertNotNull(PoliticaContrasena::validar('password123'));
        self::assertNotNull(PoliticaContrasena::validar('qwertyuiop'));
    }

    public function testLaListaDeComunesNoDistingueMayusculas(): void
    {
        self::assertNotNull(PoliticaContrasena::validar('Password123'));
        self::assertNotNull(PoliticaContrasena::validar('QWERTYUIOP'));
    }

    public function testUnSoloCaracterRepetidoNoAlcanza(): void
    {
        self::assertNotNull(PoliticaContrasena::validar('aaaaaaaaaaaa'));
    }

    public function testUnaContrasenaRazonablePasa(): void
    {
        self::assertNull(PoliticaContrasena::validar('obras-triwe-2026'));
    }

    public function testElMensajeExplicaQueCorregir(): void
    {
        // El mensaje va a la pantalla: si dice solo "inválida", el usuario prueba
        // al azar hasta que acierta.
        self::assertStringContainsString(
            (string) PoliticaContrasena::LARGO_MINIMO,
            (string) PoliticaContrasena::validar('corta')
        );
    }
}
