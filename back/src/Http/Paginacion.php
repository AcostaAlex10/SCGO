<?php

declare(strict_types=1);

namespace Sgso\Http;

use PDO;

/**
 * Paginacion a pedido de los listados que crecen con el tiempo (C-03, RNF08).
 *
 * Es opcional a proposito: sin `?limite=` el listado responde entero, como
 * siempre, y ningun cliente pierde filas en silencio. Con `?limite=N&desde=M`
 * devuelve a lo sumo N filas a partir de la M, y el total en el encabezado
 * `X-Total-Count`, para que la pantalla sepa si quedan mas sin pedirlas.
 *
 * El limite tiene un maximo: pedir mas de MAXIMO devuelve MAXIMO. Un valor que
 * no es un entero valido no se interpreta: es un 422, porque adivinar ahi
 * termina en listas cortadas sin que nadie lo note.
 */
final class Paginacion
{
    public const MAXIMO = 200;

    /** El total del listado sin paginar, una vez informado. */
    private ?int $total = null;

    private function __construct(
        public readonly int $limite,
        public readonly int $desde,
    ) {
    }

    /**
     * Lee `limite` y `desde` de la consulta.
     *
     * @param array<string, mixed> $consulta normalmente $_GET
     * @return self|null|array<string, string> la paginacion, null si no se pidio,
     *                                         o los errores por parametro
     */
    public static function desdeConsulta(array $consulta): self|array|null
    {
        $limite = $consulta['limite'] ?? null;
        $desde = $consulta['desde'] ?? null;
        if (($limite === null || $limite === '') && ($desde === null || $desde === '')) {
            return null;
        }

        $errores = [];
        if (!self::esEntero($limite) || (int) $limite < 1) {
            $errores['limite'] = 'Debe ser un entero mayor o igual a 1';
        }
        if ($desde !== null && $desde !== '' && (!self::esEntero($desde) || (int) $desde < 0)) {
            $errores['desde'] = 'Debe ser un entero mayor o igual a 0';
        }
        if ($errores !== []) {
            return $errores;
        }

        return new self(min((int) $limite, self::MAXIMO), (int) ($desde ?? 0));
    }

    /**
     * El fragmento SQL. Los dos numeros ya estan validados como enteros, asi que
     * van escritos en la consulta: como parametros, MariaDB los rechaza si el
     * driver los manda como texto.
     */
    public function sql(): string
    {
        return " LIMIT {$this->limite} OFFSET {$this->desde}";
    }

    /**
     * Cuenta el listado completo, lo informa en `X-Total-Count` y devuelve el
     * fragmento para agregarle a la consulta. Es lo unico que tiene que hacer
     * cada controlador: una linea.
     *
     * @param list<mixed> $parametros los mismos del WHERE del listado
     */
    public function aplicar(PDO $db, string $sqlConteo, array $parametros): string
    {
        $stmt = $db->prepare($sqlConteo);
        $stmt->execute($parametros);
        $this->total = (int) $stmt->fetchColumn();
        if (!headers_sent()) {
            header('X-Total-Count: ' . $this->total);
        }

        return $this->sql();
    }

    /** El total informado; null si todavia no se aplico. En la consola, header() no deja rastro. */
    public function total(): ?int
    {
        return $this->total;
    }

    private static function esEntero(mixed $valor): bool
    {
        return is_int($valor) || (is_string($valor) && preg_match('/^\d{1,9}$/', $valor) === 1);
    }
}
