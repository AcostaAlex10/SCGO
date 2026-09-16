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
