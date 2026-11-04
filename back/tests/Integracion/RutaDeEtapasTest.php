<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversNothing;
use RuntimeException;
use Sgso\Jwt;

/**
 * RF20 de punta a punta: el presupuesto de una etapa solo llega a quien ve costos.
 *
 * `EtapaPlanificacionTest` llama directo al controlador y le pasa el rol a mano,
 * asi que no ve el eslabon que falta: que `public/index.php` se lo pase. Si esa
 * linea lo perdiera, nadie veria el presupuesto y esa prueba seguiria en verde.
 * Como index.php no se puede incluir (corta con exit y manda headers), aca se
 * levanta con `php -S`, y se le pega por HTTP con un token de verdad.
 *
 * SEGURIDAD. index.php carga `back/.env`, que apunta a produccion, y `Env` solo
 * respeta lo que ya esta definido. Pasarle todo por entorno no alcanza: en Windows
 * una variable vacia no llega al hijo, y una clave vacia se tomaria del archivo;
 * lo mismo cualquier variable que aca no se nombre, como el DSN de Sentry. Por eso,
 * si el archivo existe, esta prueba se saltea. En el CI no existe, y ahi corre.
 */
#[CoversNothing]
final class RutaDeEtapasTest extends CasoConBase
{
    /** @var resource|null */
    private $servidor = null;
    private string $direccion = '';
    private string $bitacora = '';
    private string $secreto = '';

    protected function tearDown(): void
    {
        if (is_resource($this->servidor)) {
            proc_terminate($this->servidor);
            proc_close($this->servidor);
        }
        if ($this->bitacora !== '' && is_file($this->bitacora)) {
            unlink($this->bitacora);
        }

        parent::tearDown();
    }

    public function testIndexLePasaElRolDeLaSesionAlListadoDeEtapas(): void
    {
        if (is_file(dirname(__DIR__, 2) . '/.env')) {
            self::markTestSkipped('Levantar index.php leeria back/.env, que apunta a produccion. Corre en el CI.');
        }

        $idPlan = $this->crearPlanificacion($this->crearProyecto());
        $this->crearEtapa($idPlan, 70, '2026-01-01', '2026-03-31', 55555.55);
        $this->levantarServidor();

        foreach (['AdministradorSistema', 'PersonalAdministrativo', 'Gerente'] as $rol) {
            $respuesta = $this->pedirEtapas($idPlan, $rol);

            self::assertSame(200, $respuesta['codigo'], $rol . ': ' . $this->leerBitacora());
            self::assertNumero(55555.55, $respuesta['cuerpo'][0]['presupuesto_base'] ?? null, $rol);
        }

        $respuesta = $this->pedirEtapas($idPlan, 'PersonalTecnico');

        self::assertSame(200, $respuesta['codigo'], $this->leerBitacora());
        self::assertCount(1, $respuesta['cuerpo']);
        self::assertArrayNotHasKey('presupuesto_base', $respuesta['cuerpo'][0]);
    }

    /** @return array{codigo: int, cuerpo: mixed} */
    private function pedirEtapas(int $idPlan, string $rol): array
    {
        $contexto = stream_context_create(['http' => [
            'header' => 'Authorization: Bearer ' . $this->tokenDe($rol),
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);
        $cuerpo = file_get_contents("http://{$this->direccion}/api/planificacion/{$idPlan}/etapas", false, $contexto);

        preg_match('#^HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $codigo);

        return [
            'codigo' => (int) ($codigo[1] ?? 0),
            'cuerpo' => json_decode((string) $cuerpo, true),
        ];
    }

    /** Una sesion vigente de una cuenta con ese rol: el rol lo lee la API de la base (A-14). */
    private function tokenDe(string $rol): string
    {
        $idUsuario = $this->crearUsuario($rol);
        $sesion = bin2hex(random_bytes(16));
        $this->base()
            ->prepare('UPDATE usuario SET sesion_token = ? WHERE id_usuario = ?')
            ->execute([$sesion, $idUsuario]);

        return Jwt::generar(['id_usuario' => $idUsuario, 'sid' => $sesion], $this->secreto, 60);
    }

    private function levantarServidor(): void
    {
        $this->secreto = bin2hex(random_bytes(32));
        $this->direccion = '127.0.0.1:' . $this->puertoLibre();
        $this->bitacora = (string) tempnam(sys_get_temp_dir(), 'sgso-api');
        $base = self::datosDeConexion();

        $servidor = proc_open(
            [PHP_BINARY, '-S', $this->direccion, '-t', dirname(__DIR__, 2) . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->bitacora, 'a'], 2 => ['file', $this->bitacora, 'a']],
            $tuberias,
            null,
            array_merge(getenv(), [
                'JWT_SECRET' => $this->secreto,
                'DB_HOST' => $base['host'],
                'DB_PORT' => $base['puerto'],
                'DB_NAME' => $base['nombre'],
                'DB_USER' => $base['usuario'],
                'DB_PASSWORD' => $base['clave'],
                'DB_SSL' => 'false',
                // Un DSN sin esquema apaga el envio: un error de prueba no va a Sentry.
                'SENTRY_DSN' => 'apagado',
            ])
        );
        if (!is_resource($servidor)) {
            throw new RuntimeException('No se pudo levantar php -S');
        }
        $this->servidor = $servidor;

        $limite = microtime(true) + 10;
        while (microtime(true) < $limite) {
            $conexion = @stream_socket_client('tcp://' . $this->direccion, $codigo, $mensaje, 1);
            if ($conexion !== false) {
                fclose($conexion);
                return;
            }
            usleep(100_000);
        }

        throw new RuntimeException('php -S no arranco: ' . $this->leerBitacora());
    }

    /** El sistema elige un puerto libre; se suelta para que lo tome el servidor. */
    private function puertoLibre(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('No se encontro un puerto libre');
        }
        $nombre = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) strrchr($nombre, ':'), 1);
    }

    private function leerBitacora(): string
    {
        return (string) @file_get_contents($this->bitacora);
    }
}
