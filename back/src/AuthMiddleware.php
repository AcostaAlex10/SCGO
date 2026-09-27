<?php

declare(strict_types=1);

namespace Sgso;

use PDO;

/**
 * Lee el token del header "Authorization: Bearer <token>" y lo valida.
 * Devuelve el payload del usuario (id_usuario, email, rol) o null si no
 * hay sesion vigente. Es el equivalente al middleware verifyJWT.
 */
final class AuthMiddleware
{
    /**
     * El token prueba quien es el usuario, pero no alcanza (A-14): dura 8
     * horas, y en ese tiempo la cuenta puede darse de baja, cambiar de rol o
     * iniciar sesion en otro lado. Por eso cada pedido confirma contra la base
     * que la cuenta siga activa y que la sesion del token sea la vigente, y usa
     * el rol de la base, no el que quedo grabado en el token.
     *
     * Cortar el acceso es poner `sesion_token` en NULL o cambiarlo, y ya lo
     * hacen el cambio de rol y la baja (UsuarioController), el restablecimiento
     * de contrasena y cada login nuevo (AuthController).
     *
     * @return array<string, mixed>|null
     */
    public static function usuarioAutenticado(string $secreto, PDO $db): ?array
    {
        $headers = self::leerHeaders();
        $autorizacion = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (!str_starts_with($autorizacion, 'Bearer ')) {
            return null;
        }

        $payload = Jwt::verificar(substr($autorizacion, 7), $secreto);
        if ($payload === null) {
            return null;
        }

        // El login siempre pone `sid`: un token sin el no salio del login.
        $sid = $payload['sid'] ?? null;
        if (!is_string($sid) || $sid === '') {
            return null;
        }

        $stmt = $db->prepare('SELECT rol, activo, sesion_token FROM usuario WHERE id_usuario = ?');
        $stmt->execute([(int) ($payload['id_usuario'] ?? 0)]);
        $cuenta = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !is_array($cuenta)
            || (int) $cuenta['activo'] !== 1
            || !is_string($cuenta['sesion_token'])
            || !hash_equals($cuenta['sesion_token'], $sid)
        ) {
            return null;
        }

        $payload['rol'] = (string) $cuenta['rol'];

        return $payload;
    }

    /** @return array<string, string> */
    private static function leerHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return getallheaders() ?: [];
        }

        // Fallback por si getallheaders() no esta disponible.
        $headers = [];
        foreach ($_SERVER as $clave => $valor) {
            if (str_starts_with($clave, 'HTTP_')) {
                $nombre = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($clave, 5)))));
                $headers[$nombre] = $valor;
            }
        }
        return $headers;
    }
}
