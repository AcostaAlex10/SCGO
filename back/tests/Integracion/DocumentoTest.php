<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\DocumentoController;

/**
 * Carga de documentos contra la base real (plan de producto, A-01).
 *
 * Antes del arreglo, la primera prueba fallaba: el controlador respondía 201 y
 * guardaba el enlace que ejecuta código.
 */
#[CoversClass(DocumentoController::class)]
final class DocumentoTest extends CasoConBase
{
    public function testRechazaUnEnlaceQueEjecutaCodigoYNoLoGuarda(): void
    {
        $idProyecto = $this->crearProyecto();

        $respuesta = $this->capturar(fn () => $this->documentos()->crear((string) $idProyecto, [
            'nombre' => 'Plano de planta',
            'url' => 'javascript://x%0Aalert(document.cookie)',
            'tipo' => 'pdf',
        ]));

        self::assertSame(422, $respuesta['codigo']);
        self::assertIsArray($respuesta['cuerpo']);
        self::assertArrayHasKey('url', $respuesta['cuerpo']['errors'] ?? []);
        self::assertSame(0, $this->documentosGuardados($idProyecto));
    }

    public function testGuardaUnEnlaceHttps(): void
    {
        $idProyecto = $this->crearProyecto();

        $respuesta = $this->capturar(fn () => $this->documentos()->crear((string) $idProyecto, [
            'nombre' => 'Plano de planta',
            'url' => 'https://drive.google.com/file/d/abc123/view',
            'tipo' => 'pdf',
        ]));

        self::assertSame(201, $respuesta['codigo']);
        self::assertSame(1, $this->documentosGuardados($idProyecto));
    }

    /** C-13: una fecha de carga que no existe daba 500 (o se reemplazaba en silencio por hoy). */
    public function testUnaFechaDeCargaImposibleOMalEscritaDa422YNoGuardaNada(): void
    {
        $idProyecto = $this->crearProyecto();

        foreach (['2026-02-31', '03/02/2026'] as $fecha) {
            $respuesta = $this->crearDocumento($idProyecto, ['fecha_carga' => $fecha]);

            self::assertSame(422, $respuesta['codigo'], $fecha);
            self::assertSame('Formato esperado: YYYY-MM-DD', $respuesta['cuerpo']['errors']['fecha_carga'] ?? null, $fecha);
        }
        self::assertSame(0, $this->documentosGuardados($idProyecto));
    }

    public function testSinFechaDeCargaSeGuardaConLaDeHoy(): void
    {
        $idProyecto = $this->crearProyecto();

        $respuesta = $this->crearDocumento($idProyecto);

        self::assertSame(201, $respuesta['codigo']);
        self::assertSame(date('Y-m-d'), $respuesta['cuerpo']['fecha_carga']);
        self::assertSame(1, $this->documentosGuardados($idProyecto));
    }

    public function testUnEnlaceInvalidoYUnaFechaMalaDevuelvenLosDosErroresJuntos(): void
    {
        $idProyecto = $this->crearProyecto();

        $respuesta = $this->crearDocumento($idProyecto, ['url' => 'javascript:alert(1)', 'fecha_carga' => '2026-02-31']);

        self::assertSame(422, $respuesta['codigo']);
        self::assertArrayHasKey('url', $respuesta['cuerpo']['errors']);
        self::assertSame('Formato esperado: YYYY-MM-DD', $respuesta['cuerpo']['errors']['fecha_carga'] ?? null);
        self::assertSame(0, $this->documentosGuardados($idProyecto));
    }

    /**
     * @param array<string, mixed> $cambios
     * @return array{codigo: int, cuerpo: mixed}
     */
    private function crearDocumento(int $idProyecto, array $cambios = []): array
    {
        return $this->capturar(fn () => $this->documentos()->crear((string) $idProyecto, $cambios + [
            'nombre' => 'Plano de planta',
            'url' => 'https://drive.google.com/file/d/abc123/view',
            'tipo' => 'pdf',
        ]));
    }

    private function documentos(): DocumentoController
    {
        return new DocumentoController($this->base());
    }

    private function documentosGuardados(int $idProyecto): int
    {
        $stmt = $this->base()->prepare('SELECT COUNT(*) FROM documento WHERE id_proyecto = ?');
        $stmt->execute([$idProyecto]);

        return (int) $stmt->fetchColumn();
    }
}
