<?php

declare(strict_types=1);

namespace Sgso;

/**
 * Envio de emails transaccionales usando la API HTTP de Brevo (ex-Sendinblue).
 * No necesita librerias (file_get_contents). Las credenciales vienen por
 * variables de entorno:
 *   BREVO_API_KEY  -> la API key (xkeysib-...)
 *   BREVO_SENDER   -> email remitente verificado en Brevo
 */
final class Mailer
{
    /**
     * Un solo correo, aunque vaya a varios destinatarios.
     *
     * @param string|list<string> $para
     */
    public static function enviar(string|array $para, string $asunto, string $html): bool
    {
        $apiKey = getenv('BREVO_API_KEY') ?: '';
        $remitente = getenv('BREVO_SENDER') ?: '';
        if ($apiKey === '' || $remitente === '') {
            $faltan = array_keys(array_filter(
                ['BREVO_API_KEY' => $apiKey, 'BREVO_SENDER' => $remitente],
                static fn (string $valor): bool => $valor === ''
            ));
            error_log(sprintf(
                'Mailer: no salió "%s": %s %s en el entorno',
                $asunto,
                count($faltan) === 1 ? 'falta' : 'faltan',
                implode(' y ', $faltan)
            ));
            return false;
        }

        $payload = json_encode([
            'sender' => ['email' => $remitente, 'name' => 'SGSO'],
            'to' => array_map(static fn (string $email): array => ['email' => $email], (array) $para),
            'subject' => $asunto,
            'htmlContent' => $html,
        ], JSON_UNESCAPED_UNICODE);

        $contexto = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "api-key: {$apiKey}\r\ncontent-type: application/json\r\naccept: application/json\r\n",
                'content' => $payload,
                'timeout' => 12,
                'ignore_errors' => true,
            ],
            // Sin opciones 'ssl': PHP verifica el certificado de Brevo contra las
            // raices del sistema. El pedido lleva la API key y el enlace para
            // restablecer la contrasena (A-16).
        ]);

        error_clear_last();
        $respuesta = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $contexto);

        // $http_response_header contiene la linea de estado (ej. "HTTP/1.1 201 Created").
        $estado = $http_response_header[0] ?? '';
        $motivo = self::motivoDeFalla($respuesta, $estado, (string) (error_get_last()['message'] ?? ''), $apiKey);
        if ($motivo !== null) {
            error_log(sprintf('Mailer: no salió "%s": %s', $asunto, $motivo));
            return false;
        }

        return true;
    }

    /**
     * Por que Brevo no acepto el correo, o null si lo acepto. Va al log del
     * servidor: quien llama solo recibe `false`, y la recuperacion de
     * contrasena le contesta al usuario lo mismo salga o no el correo.
     *
     * @param string|false $respuesta  el cuerpo que devolvio Brevo, o false si no respondio
     * @param string $estado  la linea de estado HTTP
     * @param string $errorDeRed  el aviso de PHP cuando no hubo respuesta (red, DNS, certificado)
     * @param string $clave  la API key, para que nunca quede en el log
     */
    public static function motivoDeFalla(string|false $respuesta, string $estado, string $errorDeRed, string $clave): ?string
    {
        if ($respuesta !== false && preg_match('/\s20\d\s/', $estado) === 1) {
            return null;
        }

        if ($respuesta === false) {
            $motivo = 'Brevo no respondió: ' . ($errorDeRed !== '' ? $errorDeRed : 'sin detalle');
        } else {
            // Brevo explica el rechazo en {"code": ..., "message": ...}.
            $error = json_decode($respuesta, true);
            $detalle = is_array($error) && isset($error['message'])
                ? trim(sprintf('%s %s', (string) ($error['code'] ?? ''), (string) $error['message']))
                : $respuesta;
            $motivo = sprintf('Brevo contestó "%s": %s', $estado, mb_substr($detalle, 0, 300));
        }

        return $clave === '' ? $motivo : str_replace($clave, '[oculto]', $motivo);
    }
}
