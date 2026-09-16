<?php

declare(strict_types=1);

namespace Sgso\Seguridad;

use RuntimeException;

/**
 * El secreto con el que se firman los tokens de sesión (plan de producto, A-02).
 *
 * Quien conoce el secreto puede fabricarse un token de cualquier usuario,
 * incluido un administrador. Antes, si `JWT_SECRET` no estaba definido, la API
 * firmaba con un valor escrito en el código, que además es público porque el
 * repositorio lo es.
 *
 * Ahora falla cerrado: sin un secreto aceptable la API no atiende ningún
 * pedido. Es preferible una caída visible a una API que acepta tokens falsos.
 */
final class SecretoJwt
{
    /**
     * 32 bytes es el largo de la firma HMAC-SHA256. Un secreto más corto
     * debilita la firma, y uno escrito a mano suele ser mucho más corto.
     */
    public const LARGO_MINIMO = 32;

    /**
     * Valores que aparecen escritos en el repositorio. Cualquiera puede leerlos,
     * así que usarlos equivale a no tener secreto, aunque sean largos.
     */
    public const VALORES_PUBLICOS = [
        'cambiar_esta_clave',
        'cambiar_por_una_clave_larga_y_secreta',
    ];

    /**
     * Devuelve el secreto si es aceptable; si no, lanza una excepción que
     * explica qué falta. El mensaje nunca incluye el valor recibido.
     */
    public static function validar(?string $valor): string
    {
        if ($valor === null || trim($valor) === '') {
            throw new RuntimeException(
                'JWT_SECRET no está definido. Generá uno con: php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        if (in_array($valor, self::VALORES_PUBLICOS, true)) {
            throw new RuntimeException(
                'JWT_SECRET tiene un valor de ejemplo que figura en el repositorio. Reemplazalo por uno generado.'
            );
        }

        if (strlen($valor) < self::LARGO_MINIMO) {
            throw new RuntimeException(
                sprintf('JWT_SECRET es demasiado corto: necesita al menos %d caracteres.', self::LARGO_MINIMO)
            );
        }

        return $valor;
    }
}
