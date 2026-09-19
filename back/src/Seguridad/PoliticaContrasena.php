<?php

declare(strict_types=1);

namespace Sgso\Seguridad;

/**
 * Reglas de la contraseña al darla de alta o cambiarla (plan de producto, A-05).
 *
 * El mínimo era de 6 caracteres. Con seis, probar todas las combinaciones es
 * cuestión de minutos: el límite de intentos de A-03 frena el ataque contra la
 * API, pero no sirve de nada si alguna vez se filtra la tabla de usuarios y el
 * ataque pasa a ser contra los hashes, sin límite de velocidad.
 *
 * La regla es pura —no toca base ni HTTP— y es el único lugar donde vive el
 * mínimo: `AuthController` la usa tanto al registrar como al restablecer.
 *
 * Lo que NO hace, a propósito: exigir mayúsculas, números y símbolos. Esa regla
 * empuja a la gente a "Contrasena1!", que es corta y previsible; el largo rinde
 * mucho más. Es lo que recomienda el NIST desde 2017.
 */
final class PoliticaContrasena
{
    public const LARGO_MINIMO = 10;

    /**
     * Las que aparecen primero en cualquier ataque de diccionario. No pretende
     * ser exhaustiva: la lista completa son millones y va contra una base de
     * datos. Esto ataja lo que alguien escribiría hoy en el formulario.
     *
     * @var list<string>
     */
    private const COMUNES = [
        '1234567890',
        '12345678910',
        '0987654321',
        'qwertyuiop',
        'contrasena',
        'contrasena1',
        'contrasena123',
        'contraseña123',
        'password123',
        'password1234',
        'passw0rd123',
        'administrador',
        'admin123456',
        'administrator',
        'iloveyou123',
        'bienvenido1',
        'usuario123',
        'constructora',
        'obras2026',
    ];

    /** @return string|null el motivo del rechazo, o null si la contraseña sirve */
    public static function validar(string $contrasena): ?string
    {
        // mb_strlen cuenta caracteres; strlen cuenta bytes, y en UTF-8 una "ñ"
        // ocupa dos. Con strlen, "añoañoañ" pasaría por tener diez bytes.
        if (mb_strlen($contrasena) < self::LARGO_MINIMO) {
            return 'Debe tener al menos ' . self::LARGO_MINIMO . ' caracteres';
        }

        if (in_array(mb_strtolower(trim($contrasena)), self::COMUNES, true)) {
            return 'Es una de las contraseñas más usadas: elegí otra';
        }

        if (preg_match('/^(.)\1*$/u', $contrasena) === 1) {
            return 'No puede ser un mismo carácter repetido';
        }

        return null;
    }
}
