<?php

declare(strict_types=1);

namespace Sgso\Seguridad;

use Closure;
use PDO;

/**
 * Registro de los logins fallidos, por cuenta (plan de producto, A-03).
 *
 * Cuánto hay que esperar lo decide PoliticaIntentos; esta clase solo guarda los
 * contadores en la tabla `intento_login`.
 *
 * - **La clave es el email normalizado y hasheado.** Así el límite vale igual
 *   para un email con cuenta que para uno inventado (si no, el bloqueo delataría
 *   qué emails existen), y la tabla no guarda en claro los emails que alguien
 *   probó.
 * - **El incremento es atómico**, en una sola sentencia: dos intentos
 *   simultáneos no pueden contar como uno.
 * - **La tabla la crea una migración**, no esta clase. Hasta B-03 se creaba acá
 *   en cada arranque, porque no había forma de aplicar un cambio de esquema a
 *   una base viva. Ahora es `sql/migraciones/0004-intento-login.sql`.
 */
final class IntentosLogin
{
    /** @var Closure(): int */
    private Closure $reloj;

    /** @param (Closure(): int)|null $reloj para que las pruebas controlen el tiempo */
    public function __construct(private PDO $db, ?Closure $reloj = null)
    {
        $this->reloj = $reloj ?? static fn (): int => time();
    }

    public static function claveDeCuenta(string $email): string
    {
        return 'cuenta:' . hash('sha256', mb_strtolower(trim($email)));
    }

    /** Segundos que faltan para poder volver a intentar; 0 si no hay bloqueo. */
    public function segundosDeEspera(string $clave): int
    {
        $stmt = $this->db->prepare('SELECT fallos, ultimo_fallo FROM intento_login WHERE clave = ?');
        $stmt->execute([$clave]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($fila)) {
            return 0;
        }

        // Con un reloj apenas atrasado la diferencia daría negativa y alargaría la
        // espera; se toma como cero.
        return PoliticaIntentos::segundosDeEspera(
            (int) $fila['fallos'],
            max(0, $this->ahora() - (int) $fila['ultimo_fallo'])
        );
    }

    public function registrarFallo(string $clave): void
    {
        $ahora = $this->ahora();

        // MySQL evalúa las asignaciones en orden: `fallos` todavía ve el
        // `ultimo_fallo` anterior, que es el que decide si el contador se reinicia.
        //
        // Se compara en vez de restar: `ultimo_fallo` es UNSIGNED, y una resta que
        // diera negativa (un reloj apenas atrasado) haría fallar la sentencia.
        $stmt = $this->db->prepare(
            'INSERT INTO intento_login (clave, fallos, ultimo_fallo) VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE
               fallos = IF(ultimo_fallo <= ?, 1, fallos + 1),
               ultimo_fallo = ?'
        );
        $stmt->execute([$clave, $ahora, $ahora - PoliticaIntentos::VENTANA, $ahora]);
    }

    /** Un login correcto borra el historial de fallos de la cuenta. */
    public function limpiar(string $clave): void
    {
        $this->db->prepare('DELETE FROM intento_login WHERE clave = ?')->execute([$clave]);
    }

    private function ahora(): int
    {
        return ($this->reloj)();
    }
}
