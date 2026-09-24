<?php

declare(strict_types=1);

namespace Sgso\Tests\Monitoreo;

use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sgso\Monitoreo\Sentry;

/**
 * Los errores de producción viajan a Sentry (plan de producto, B-04).
 *
 * Antes, un 500 quedaba solo en el log de Render: nadie se enteraba salvo que
 * fuera a mirar. Ahora el manejador global además lo reporta.
 *
 * Dos cosas que esta clase tiene que garantizar sí o sí:
 *
 * 1. **No puede romper la API.** Si el envío falla, el usuario ya está viendo
 *    su 500: un error del monitoreo encima sería peor que no monitorear.
 * 2. **No puede filtrar secretos.** El mensaje de un PDOException trae el host
 *    y el usuario de la base, y Sentry es un servicio de afuera.
 */
#[CoversClass(Sentry::class)]
final class SentryTest extends TestCase
{
    private const DSN = 'https://clavepublica@o12345.ingest.sentry.io/678';

    /** @var list<array{url: string, cuerpo: string, cabeceras: list<string>}> */
    private array $enviados = [];

    public function testSinDsnQuedaDeshabilitado(): void
    {
        self::assertNull(Sentry::desdeDsn(null));
        self::assertNull(Sentry::desdeDsn(''));
        self::assertNull(Sentry::desdeDsn('   '));
    }

    /** Un DSN mal copiado no puede tumbar la API al arrancar. */
    public function testUnDsnInvalidoQuedaDeshabilitadoYNoLanza(): void
    {
        self::assertNull(Sentry::desdeDsn('esto-no-es-un-dsn'));
        self::assertNull(Sentry::desdeDsn('https://sin-clave.ingest.sentry.io/678'));
        self::assertNull(Sentry::desdeDsn('https://clave@sin-proyecto.ingest.sentry.io'));
    }

    public function testArmaLaUrlYLaAutenticacionAPartirDelDsn(): void
    {
        $this->sentry()->reportar(new RuntimeException('algo'), 'ref123');

        self::assertCount(1, $this->enviados);
        self::assertSame('https://o12345.ingest.sentry.io/api/678/envelope/', $this->enviados[0]['url']);
        self::assertContains(
            'X-Sentry-Auth: Sentry sentry_version=7, sentry_key=clavepublica, sentry_client=scgo/1.0',
            $this->enviados[0]['cabeceras']
        );
    }

    public function testMandaElTipoElMensajeYLaReferencia(): void
    {
        $this->sentry()->reportar(new RuntimeException('se cayó el reporte'), 'ref123', [
            'ruta' => '/reportes/7',
            'metodo' => 'POST',
        ]);

        $evento = $this->evento();
        self::assertSame('RuntimeException', $evento['exception']['values'][0]['type']);
        self::assertSame('se cayó el reporte', $evento['exception']['values'][0]['value']);
        self::assertSame('ref123', $evento['tags']['referencia']);
        self::assertSame('/reportes/7', $evento['tags']['ruta']);
        self::assertSame('POST', $evento['tags']['metodo']);
        self::assertSame('error', $evento['level']);
    }

    /**
     * El mensaje real de PDO cuando no puede conectarse trae el host y el
     * usuario de la base. Eso no puede salir del servidor.
     */
    public function testOcultaLosSecretosDelMensaje(): void
    {
        $sentry = $this->sentry(['sgso-db.aivencloud.com', 'avnadmin', 'clave-larguisima']);

        $sentry->reportar(
            new PDOException('SQLSTATE[HY000] [2002] Connection refused (host: sgso-db.aivencloud.com, user: avnadmin)'),
            'ref123'
        );

        $crudo = $this->enviados[0]['cuerpo'];
        self::assertStringNotContainsString('aivencloud', $crudo);
        self::assertStringNotContainsString('avnadmin', $crudo);
        self::assertStringContainsString('[oculto]', $crudo);
        // Lo que sí sirve para diagnosticar se conserva.
        self::assertStringContainsString('SQLSTATE[HY000]', $crudo);
    }

    /** Un valor vacío en la lista no puede convertir todo el texto en [oculto]. */
    public function testIgnoraLosSecretosVacios(): void
    {
        $this->sentry(['', '   '])->reportar(new RuntimeException('mensaje intacto'), 'ref123');

        self::assertStringContainsString('mensaje intacto', $this->enviados[0]['cuerpo']);
    }

    /** La traza sirve para ubicar el error, pero los argumentos pueden traer datos. */
    public function testLaTrazaNoIncluyeLosArgumentosDeLasLlamadas(): void
    {
        $this->sentry()->reportar($this->errorConArgumento('un-token-secreto'), 'ref123');

        self::assertStringNotContainsString('un-token-secreto', $this->enviados[0]['cuerpo']);
        $evento = $this->evento();
        self::assertNotSame([], $evento['exception']['values'][0]['stacktrace']['frames']);
    }

    public function testSiElEnvioFallaNoPropagaNada(): void
    {
        $sentry = Sentry::desdeDsn(self::DSN, 'pruebas', [], static function (): void {
            throw new RuntimeException('la red no anda');
        });
        self::assertNotNull($sentry);

        self::assertFalse($sentry->reportar(new RuntimeException('algo'), 'ref123'));
    }

    // ----------------------------------------------------------------

    /** @param list<string> $secretos */
    private function sentry(array $secretos = []): Sentry
    {
        $this->enviados = [];
        $sentry = Sentry::desdeDsn(
            self::DSN,
            'pruebas',
            $secretos,
            function (string $url, string $cuerpo, array $cabeceras): void {
                $this->enviados[] = ['url' => $url, 'cuerpo' => $cuerpo, 'cabeceras' => $cabeceras];
            }
        );
        self::assertNotNull($sentry);

        return $sentry;
    }

    /**
     * El cuerpo es un envelope: una línea de encabezado, una por ítem y el
     * evento al final.
     *
     * @return array<string, mixed>
     */
    private function evento(): array
    {
        $lineas = array_values(array_filter(explode("\n", $this->enviados[0]['cuerpo'])));
        $evento = json_decode((string) end($lineas), true);
        self::assertIsArray($evento);

        return $evento;
    }

    private function errorConArgumento(string $secreto): RuntimeException
    {
        try {
            (static function (string $token): void {
                throw new RuntimeException('falló con un argumento');
            })($secreto);
        } catch (RuntimeException $error) {
            return $error;
        }
    }
}
