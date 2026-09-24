<?php

declare(strict_types=1);

namespace Sgso\Monitoreo;

use Throwable;

/**
 * Manda los errores de producción a Sentry (plan de producto, B-04).
 *
 * Antes, un 500 quedaba solo en el log de Render: nadie se enteraba salvo que
 * fuera a mirar. Ahora el manejador global, además de registrarlo, lo reporta
 * con la misma referencia que ve el usuario.
 *
 * **Por qué no el SDK oficial.** El backend no tiene ninguna dependencia de
 * runtime, y la imagen de Docker no descarga nada al construirse (ADR-001). El
 * protocolo que hace falta acá es un POST con un envelope JSON, así que se
 * escribe a mano y esa propiedad se conserva. Lo que se resigna son los
 * breadcrumbs, el tracing y el manejo de releases, que hoy no se usan.
 *
 * **Dos garantías que no se negocian:**
 *
 * 1. **No rompe la API.** Cualquier falla del envío se traga: el usuario ya
 *    está viendo un 500, y un error del monitoreo encima sería peor que no
 *    monitorear. Sin DSN, o con uno inválido, queda deshabilitado y listo.
 * 2. **No filtra secretos.** El mensaje de un `PDOException` trae el host y el
 *    usuario de la base, y Sentry es un servicio de afuera: los valores que se
 *    le pasen en `$secretos` se reemplazan por `[oculto]` antes de salir. Por
 *    lo mismo, la traza va sin los argumentos de las llamadas.
 */
final class Sentry
{
    private const CLIENTE = 'scgo/1.0';

    /** Suficiente para ubicar el error sin mandar el archivo entero. */
    private const MARCOS_DE_TRAZA = 20;

    /** Si Sentry no contesta, la respuesta del error no puede quedar colgada. */
    private const ESPERA_SEGUNDOS = 2;

    /**
     * @param list<string> $secretos valores que nunca pueden salir del servidor
     * @param callable(string, string, list<string>): void $transporte
     */
    private function __construct(
        private string $url,
        private string $clavePublica,
        private string $entorno,
        private array $secretos,
        private $transporte
    ) {
    }

    /**
     * Devuelve null —monitoreo apagado— si no hay DSN o si no se entiende.
     *
     * @param list<string> $secretos
     * @param (callable(string, string, list<string>): void)|null $transporte
     */
    public static function desdeDsn(
        ?string $dsn,
        string $entorno = 'produccion',
        array $secretos = [],
        ?callable $transporte = null
    ): ?self {
        $dsn = trim((string) $dsn);
        if ($dsn === '') {
            return null;
        }

        $partes = parse_url($dsn);
        if (!is_array($partes)) {
            return null;
        }

        $esquema = $partes['scheme'] ?? '';
        $host = $partes['host'] ?? '';
        $clave = $partes['user'] ?? '';
        $proyecto = trim($partes['path'] ?? '', '/');

        if ($esquema === '' || $host === '' || $clave === '' || $proyecto === '') {
            return null;
        }

        $puerto = isset($partes['port']) ? ':' . $partes['port'] : '';

        return new self(
            "{$esquema}://{$host}{$puerto}/api/{$proyecto}/envelope/",
            $clave,
            $entorno,
            array_values(array_filter($secretos, static fn (string $s): bool => trim($s) !== '')),
            $transporte ?? self::transportePorHttp(...)
        );
    }

    /**
     * Reporta el error. Devuelve si se pudo enviar, para que quien llame pueda
     * registrarlo; nunca lanza.
     *
     * @param array<string, string> $etiquetas por ejemplo la ruta y el método
     */
    public function reportar(Throwable $error, string $referencia, array $etiquetas = []): bool
    {
        try {
            $cuerpo = $this->envelope($error, $referencia, $etiquetas);
            ($this->transporte)($this->url, $cuerpo, $this->cabeceras());

            return true;
        } catch (Throwable) {
            // El monitoreo nunca puede empeorar la respuesta que el usuario ya
            // está recibiendo.
            return false;
        }
    }

    /** @return list<string> */
    private function cabeceras(): array
    {
        return [
            'Content-Type: application/x-sentry-envelope',
            sprintf(
                'X-Sentry-Auth: Sentry sentry_version=7, sentry_key=%s, sentry_client=%s',
                $this->clavePublica,
                self::CLIENTE
            ),
        ];
    }

    /** @param array<string, string> $etiquetas */
    private function envelope(Throwable $error, string $referencia, array $etiquetas): string
    {
        $id = bin2hex(random_bytes(16));
        $ahora = gmdate('Y-m-d\TH:i:s\Z');

        $evento = [
            'event_id' => $id,
            'timestamp' => $ahora,
            'platform' => 'php',
            'level' => 'error',
            'logger' => 'scgo',
            'environment' => $this->entorno,
            'tags' => ['referencia' => $referencia] + $etiquetas,
            'exception' => ['values' => [[
                'type' => $error::class,
                'value' => $this->ocultarSecretos($error->getMessage()),
                'stacktrace' => ['frames' => $this->marcos($error)],
            ]]],
        ];

        return implode("\n", [
            (string) json_encode(['event_id' => $id, 'sent_at' => $ahora]),
            (string) json_encode(['type' => 'event']),
            $this->ocultarSecretos((string) json_encode($evento, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);
    }

    /**
     * Los marcos, del más viejo al más nuevo, como los espera Sentry. Sin
     * `args`: ahí viajan contraseñas y tokens que se pasaron por parámetro.
     *
     * @return list<array{filename: string, lineno: int, function: string}>
     */
    private function marcos(Throwable $error): array
    {
        $marcos = [[
            'filename' => $this->ocultarSecretos($error->getFile()),
            'lineno' => $error->getLine(),
            'function' => '',
        ]];

        foreach (array_slice($error->getTrace(), 0, self::MARCOS_DE_TRAZA) as $marco) {
            $marcos[] = [
                'filename' => $this->ocultarSecretos((string) ($marco['file'] ?? '[interno]')),
                'lineno' => (int) ($marco['line'] ?? 0),
                'function' => $marco['function'],
            ];
        }

        return array_reverse($marcos);
    }

    private function ocultarSecretos(string $texto): string
    {
        return $this->secretos === []
            ? $texto
            : str_replace($this->secretos, '[oculto]', $texto);
    }

    /** @param list<string> $cabeceras */
    private static function transportePorHttp(string $url, string $cuerpo, array $cabeceras): void
    {
        $contexto = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $cabeceras),
            'content' => $cuerpo,
            'timeout' => self::ESPERA_SEGUNDOS,
            // Un 4xx de Sentry no tiene que emitir un warning de PHP: lo que
            // pase con la respuesta ya no cambia nada para el usuario.
            'ignore_errors' => true,
        ]]);

        @file_get_contents($url, false, $contexto);
    }
}
