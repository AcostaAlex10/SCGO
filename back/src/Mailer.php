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
    public static function enviar(string $para, string $asunto, string $html): bool
    {
        $apiKey = getenv('BREVO_API_KEY') ?: '';
        $remitente = getenv('BREVO_SENDER') ?: '';
        if ($apiKey === '' || $remitente === '') {
            return false; // sin credenciales configuradas
        }

        $payload = json_encode([
            'sender' => ['email' => $remitente, 'name' => 'SGSO'],
            'to' => [['email' => $para]],
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

        $respuesta = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $contexto);
        if ($respuesta === false) {
            return false;
        }

        // $http_response_header contiene la linea de estado (ej. "HTTP/1.1 201 Created").
        $estado = $http_response_header[0] ?? '';
        return (bool) preg_match('/\s20\d\s/', $estado);
    }
}
