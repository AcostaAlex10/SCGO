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
 * - **La tabla se crea si falta.** Todavía no hay un sistema de migraciones
 *   (plan: B-03), y sin la tabla el login dejaría de funcionar en el primer
 *   deploy. Cuando exista ese sistema, esto se va.
 */
final class IntentosLogin
{
    private static bool $tablaVerificada = false;

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
        $this->asegurarTabla();

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
        $this->asegurarTabla();
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
        $this->asegurarTabla();

        $this->db->prepare('DELETE FROM intento_login WHERE clave = ?')->execute([$clave]);
    }

    private function ahora(): int
    {
        return ($this->reloj)();
    }

    private function asegurarTabla(): void
    {
        if (self::$tablaVerificada) {
            return;
        }

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS intento_login (
               clave         VARCHAR(191) NOT NULL PRIMARY KEY,
               fallos        INT UNSIGNED NOT NULL,
               ultimo_fallo  INT UNSIGNED NOT NULL
             )'
        );
        self::$tablaVerificada = true;
    }
}
