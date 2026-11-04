<?php

declare(strict_types=1);

namespace Sgso\Seguridad;

use PDO;
use RuntimeException;

/**
 * Opciones de PDO para cifrar la conexion a la base y verificar quien contesta
 * (plan de producto, A-19).
 *
 * Antes, con DB_SSL=true la conexion iba cifrada pero con la verificacion del
 * certificado apagada: cualquiera en el camino podia hacerse pasar por la base
 * y leer usuario, contrasena y datos. Ahora se exige el certificado de la CA
 * (DB_SSL_CA) y, si falta, se falla cerrado: nunca se conecta sin verificar.
 *
 * Es la unica pieza que arma estas opciones: Database y los scripts de
 * back/sql la usan, asi la regla no se copia.
 */
final class OpcionesTls
{
    /**
     * @param string|null $ssl  valor de DB_SSL; solo 'true' activa TLS
     * @param string|null $ca   valor de DB_SSL_CA: ruta al certificado de la CA
     * @return array<int, mixed>  opciones de PDO a sumar a las demas; vacio sin TLS
     * @throws RuntimeException si DB_SSL=true y no hay un DB_SSL_CA legible
     */
    public static function desde(?string $ssl, ?string $ca): array
    {
        if ($ssl !== 'true') {
            return [];
        }

        $ruta = $ca === null ? '' : trim($ca);
        if ($ruta === '') {
            throw new RuntimeException(
                'DB_SSL=true requiere DB_SSL_CA: la ruta al certificado de la CA de la base. '
                . 'Sin el no se puede verificar a quien se conecta.'
            );
        }

        if (!is_file($ruta) || !is_readable($ruta)) {
            throw new RuntimeException(
                'DB_SSL_CA apunta a un archivo que no existe o no se puede leer. '
                . 'Revisa la ruta del certificado de la CA.'
            );
        }

        return [
            PDO::MYSQL_ATTR_SSL_CA => $ruta,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
        ];
    }
}
