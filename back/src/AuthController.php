<?php

declare(strict_types=1);

namespace Sgso;

use PDO;
use Sgso\Seguridad\IntentosLogin;
use Sgso\Seguridad\PoliticaContrasena;


/**
 * Controlador de autenticacion (login). Equivalente PHP del backend de
 * login: valida credenciales contra la tabla `usuario`, compara la
 * contrasena con su hash bcrypt y emite un JWT. Base para RF19 (roles).
 *
 * Seguridad:
 *  - Las contrasenas se guardan SIEMPRE hasheadas (password_hash/bcrypt).
 *  - Ante un login fallido se responde un mensaje generico (no se revela
 *    si fallo el email o la contrasena).
 *  - El token nunca incluye el hash de la contrasena.
 */
final class AuthController
{
    private const ROLES = [
        'PersonalAdministrativo',
        'PersonalTecnico',
        'Gerente',
        'AdministradorSistema',
    ];

    /**
     * Hash bcrypt de una contraseña que nadie conoce. Cuando el email no existe
     * se verifica contra este hash igual: sin eso, la respuesta sale más rápido y
     * el tiempo delata qué emails tienen cuenta.
     */
    private const HASH_FICTICIO = '$2y$10$j/7xecvBAaFbF18R2vNRYuRCkmKw.1FR7QNqT80xPEcK6Bx6QnUBS';

    /** Cuánto vale el enlace de recuperación que llega por correo. */
    private const VALIDEZ_TOKEN = 3600;

    /**
     * Mínimo entre dos correos de recuperación para la misma cuenta (A-13).
     *
     * Cinco minutos alcanzan para que un correo llegue: si el usuario vuelve a
     * pedirlo porque no lo ve, casi siempre es que todavía está en camino.
     */
    private const ESPERA_ENTRE_PEDIDOS = 300;

    private IntentosLogin $intentos;

    public function __construct(
        private PDO $db,
        private string $jwtSecreto,
        private int $jwtSegundosValidez,
        ?IntentosLogin $intentos = null
    ) {
        $this->intentos = $intentos ?? new IntentosLogin($db);
    }

    /** @param array<string, mixed> $datos */
    public function login(array $datos): void
    {
        $email = trim((string) ($datos['email'] ?? ''));
        $contrasena = (string) ($datos['contrasena'] ?? '');

        if ($email === '' || $contrasena === '') {
            $this->json(400, ['error' => 'Email y contrasena son obligatorios']);
            return;
        }

        // Límite de intentos (A-03). Se revisa antes de mirar la contraseña: durante
        // el bloqueo no se puede probar ninguna, ni siquiera la correcta.
        $clave = IntentosLogin::claveDeCuenta($email);
        $espera = $this->intentos->segundosDeEspera($clave);
        if ($espera > 0) {
            if (!headers_sent()) {
                header('Retry-After: ' . $espera);
            }
            $minutos = (int) ceil($espera / 60);
            $this->json(429, [
                'error' => sprintf(
                    'Demasiados intentos fallidos. Esperá %d %s y volvé a probar.',
                    $minutos,
                    $minutos === 1 ? 'minuto' : 'minutos'
                ),
                'reintentar_en_segundos' => $espera,
            ]);
            return;
        }

        $stmt = $this->db->prepare('SELECT * FROM usuario WHERE email = ?');
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        // Se verifica siempre, exista o no la cuenta, para que el tiempo de
        // respuesta no delate cuáles existen.
        $hash = $usuario === false ? self::HASH_FICTICIO : (string) $usuario['contrasena'];
        $contrasenaValida = password_verify($contrasena, $hash) && $usuario !== false;

        if (!$contrasenaValida) {
            $this->intentos->registrarFallo($clave);
            $this->json(401, ['error' => 'Credenciales invalidas']);
            return;
        }

        // Que la cuenta está inactiva se informa recién con la contraseña
        // correcta: antes, cualquiera podía confirmar que un email existía.
        if ((int) $usuario['activo'] !== 1) {
            $this->json(403, ['error' => 'Usuario inactivo']);
            return;
        }

        $this->intentos->limpiar($clave);

        // Sesion unica: generamos un id de sesion nuevo y lo guardamos. Esto
        // invalida cualquier sesion anterior del usuario (el ultimo login gana).
        $sid = bin2hex(random_bytes(16));
        $upd = $this->db->prepare('UPDATE usuario SET sesion_token = ? WHERE id_usuario = ?');
        $upd->execute([$sid, (int) $usuario['id_usuario']]);

        $token = Jwt::generar(
            [
                'id_usuario' => (int) $usuario['id_usuario'],
                'email' => $usuario['email'],
                'rol' => $usuario['rol'],
                'sid' => $sid,
            ],
            $this->jwtSecreto,
            $this->jwtSegundosValidez
        );

        $this->json(200, [
            'token' => $token,
            'usuario' => [
                'id_usuario' => (int) $usuario['id_usuario'],
                'nombre' => $usuario['nombre'],
                'email' => $usuario['email'],
                'rol' => $usuario['rol'],
            ],
        ]);
    }

    /**
     * Crea un usuario. Solo lo puede hacer un AdministradorSistema.
     * @param array<string, mixed> $datos
     * @param array<string, mixed> $solicitante  payload del token (ya validado)
     */
    public function registrar(array $datos, array $solicitante): void
    {
        if (($solicitante['rol'] ?? '') !== 'AdministradorSistema') {
            $this->json(403, ['error' => 'No tenes permisos para esta accion']);
            return;
        }

        $nombre = trim((string) ($datos['nombre'] ?? ''));
        $email = trim((string) ($datos['email'] ?? ''));
        $contrasena = (string) ($datos['contrasena'] ?? '');
        $rol = (string) ($datos['rol'] ?? '');

        $errores = [];
        if ($nombre === '') {
            $errores['nombre'] = 'Obligatorio';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errores['email'] = 'Email invalido';
        }
        $problema = PoliticaContrasena::validar($contrasena);
        if ($problema !== null) {
            $errores['contrasena'] = $problema;
        }
        if (!in_array($rol, self::ROLES, true)) {
            $errores['rol'] = 'Rol invalido';
        }
        if (!empty($errores)) {
            $this->json(422, ['errors' => $errores]);
            return;
        }

        $stmt = $this->db->prepare('SELECT id_usuario FROM usuario WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch() !== false) {
            $this->json(409, ['error' => 'Ya existe un usuario con ese email']);
            return;
        }

        $hash = password_hash($contrasena, PASSWORD_BCRYPT);
        $stmt = $this->db->prepare('INSERT INTO usuario (nombre, email, contrasena, rol) VALUES (?, ?, ?, ?)');
        $stmt->execute([$nombre, $email, $hash, $rol]);

        $this->json(201, [
            'id_usuario' => (int) $this->db->lastInsertId(),
            'nombre' => $nombre,
            'email' => $email,
            'rol' => $rol,
        ]);
    }

    /** @param array<string, mixed> $solicitante */
    public function yo(array $solicitante): void
    {
        // Sesion unica: el id de sesion del token debe coincidir con el ultimo
        // guardado en la base. Si no, hubo un login mas nuevo en otro lado.
        $stmt = $this->db->prepare('SELECT sesion_token FROM usuario WHERE id_usuario = ?');
        $stmt->execute([(int) ($solicitante['id_usuario'] ?? 0)]);
        $fila = $stmt->fetch();

        if ($fila === false || ($solicitante['sid'] ?? null) !== $fila['sesion_token']) {
            $this->json(401, ['error' => 'Tu sesión se cerró: se inició sesión en otro dispositivo']);
            return;
        }

        $this->json(200, ['usuario' => $solicitante]);
    }

    /**
     * Paso 1 de recuperacion: el usuario ingresa su email y le enviamos un
     * enlace con un token temporal (vence en 1 hora).
     * Por seguridad respondemos siempre 200, exista o no el email.
     * @param array<string, mixed> $datos
     */
    public function olvide(array $datos): void
    {
        $email = trim((string) ($datos['email'] ?? ''));
        $respuestaGenerica = ['mensaje' => 'Si el email está registrado, te enviamos instrucciones para recuperar la contraseña.'];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->json(200, $respuestaGenerica);
            return;
        }

        $token = bin2hex(random_bytes(16));
        // Vencimiento en UTC (1 hora) para que coincida con el NOW() de la
        // base (que esta en UTC). gmdate siempre devuelve UTC, sin importar
        // la zona horaria por defecto de PHP.
        $expira = gmdate('Y-m-d H:i:s', time() + self::VALIDEZ_TOKEN);

        // Límite de pedidos (A-13). Cada pedido manda un correo: sin freno, alguien
        // que conozca un email le llena la casilla y gasta la cuota de Brevo.
        //
        // El límite y la reserva son la MISMA sentencia, a propósito. Preguntar
        // primero "¿puedo?" y escribir después deja una ventana en la que veinte
        // pedidos simultáneos contestan que sí todos juntos, que es exactamente el
        // ataque que esto tiene que frenar. Acá gana uno solo: los demás no tocan
        // ninguna fila.
        //
        // La condición es "el token anterior se emitió hace más de ESPERA_ENTRE_PEDIDOS".
        // Como `reset_expira` es el momento de emisión más VALIDEZ_TOKEN, esa cuenta
        // se hace restando.
        $emitidoHaceRato = gmdate('Y-m-d H:i:s', time() + self::VALIDEZ_TOKEN - self::ESPERA_ENTRE_PEDIDOS);
        $reserva = $this->db->prepare(
            'UPDATE usuario SET reset_token = ?, reset_expira = ?
             WHERE email = ? AND (reset_expira IS NULL OR reset_expira <= ?)'
        );
        $reserva->execute([$token, $expira, $email, $emitidoHaceRato]);

        // Cero filas es el email que no existe y también el que ya pidió hace un
        // rato: los dos casos salen por acá con la misma respuesta de siempre. Un
        // 429 convertiría este endpoint en un delator de qué emails tienen cuenta.
        if ($reserva->rowCount() === 1) {
            $stmt = $this->db->prepare('SELECT nombre FROM usuario WHERE email = ?');
            $stmt->execute([$email]);
            $usuario = $stmt->fetch();

            $base = rtrim(getenv('APP_URL') ?: (getenv('CORS_ORIGIN') ?: ''), '/');
            $enlace = $base . '/restablecer?token=' . $token;
            $html = '<h2>Recuperación de contraseña - SGSO</h2>'
                . '<p>Hola ' . htmlspecialchars((string) $usuario['nombre']) . ',</p>'
                . '<p>Para definir una nueva contraseña, hacé clic en el siguiente enlace (vence en 1 hora):</p>'
                . '<p><a href="' . htmlspecialchars($enlace) . '">' . htmlspecialchars($enlace) . '</a></p>'
                . '<p>Si no solicitaste esto, ignorá este correo.</p>';

            Mailer::enviar($email, 'Recuperá tu contraseña - SGSO', $html);
        }

        $this->json(200, $respuestaGenerica);
    }

    /**
     * Paso 2 de recuperacion: con el token del email, el usuario define una
     * contrasena nueva.
     * @param array<string, mixed> $datos
     */
    public function restablecer(array $datos): void
    {
        $token = trim((string) ($datos['token'] ?? ''));
        $contrasena = (string) ($datos['contrasena'] ?? '');

        if ($token === '') {
            $this->json(400, ['error' => 'Falta el token']);
            return;
        }
        $problema = PoliticaContrasena::validar($contrasena);
        if ($problema !== null) {
            $this->json(422, ['errors' => ['contrasena' => $problema]]);
            return;
        }

        $stmt = $this->db->prepare('SELECT id_usuario FROM usuario WHERE reset_token = ? AND reset_expira > NOW()');
        $stmt->execute([$token]);
        $usuario = $stmt->fetch();

        if ($usuario === false) {
            $this->json(400, ['error' => 'El enlace no es válido o venció. Pedí uno nuevo.']);
            return;
        }

        // Nueva contrasena + invalidar el token y cualquier sesion activa.
        $hash = password_hash($contrasena, PASSWORD_BCRYPT);
        $this->db->prepare('UPDATE usuario SET contrasena = ?, reset_token = NULL, reset_expira = NULL, sesion_token = NULL WHERE id_usuario = ?')
            ->execute([$hash, (int) $usuario['id_usuario']]);

        $this->json(200, ['mensaje' => 'Contraseña actualizada. Ya podés iniciar sesión.']);
    }

    private function json(int $codigoHttp, mixed $cuerpo): void
    {
        http_response_code($codigoHttp);
        echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    }
}
