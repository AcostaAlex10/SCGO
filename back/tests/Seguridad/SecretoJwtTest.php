<?php

declare(strict_types=1);

namespace Sgso\Tests\Seguridad;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sgso\Seguridad\SecretoJwt;

/**
 * Secreto de los tokens de sesión (plan de producto, A-02).
 */
#[CoversClass(SecretoJwt::class)]
final class SecretoJwtTest extends TestCase
{
    /** @return iterable<string, array{?string, string}> */
    public static function secretosInaceptables(): iterable
    {
        yield 'variable ausente' => [null, 'no está definido'];
        yield 'vacio' => ['', 'no está definido'];
        yield 'solo espacios' => ['   ', 'no está definido'];
        yield 'el valor por defecto del codigo viejo' => ['cambiar_esta_clave', 'valor de ejemplo'];
        yield 'el valor de .env.example, largo pero publico' => ['cambiar_por_una_clave_larga_y_secreta', 'valor de ejemplo'];
        yield 'demasiado corto' => ['clave-de-31-caracteres-exactos!', 'demasiado corto'];
    }

    #[DataProvider('secretosInaceptables')]
    public function testFallaCerradoAnteUnSecretoInaceptable(?string $valor, string $motivo): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($motivo);

        SecretoJwt::validar($valor);
    }

    public function testElMensajeDeErrorNuncaIncluyeElValorRecibido(): void
    {
        // El mensaje termina en el log: no puede filtrar el secreto.
        $corto = 'secreto-corto-pero-real';

        try {
            SecretoJwt::validar($corto);
            self::fail('Tendría que haber rechazado el secreto corto');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString($corto, $e->getMessage());
        }
    }

    public function testAceptaUnSecretoGeneradoDe32Bytes(): void
    {
        $secreto = bin2hex(random_bytes(32));

        self::assertSame($secreto, SecretoJwt::validar($secreto));
    }

    public function testAceptaExactamenteElLargoMinimo(): void
    {
        $secreto = str_repeat('k', SecretoJwt::LARGO_MINIMO);

        self::assertSame($secreto, SecretoJwt::validar($secreto));
    }
}
