<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\ReporteController;

#[CoversClass(ReporteController::class)]
final class CierrePorReporteFinalTest extends CasoConBase
{
    public function testEnviarElReporteFinalDejaLaObraEnRevision(): void
    {
        $idProyecto = $this->crearProyecto();
        $idReporte = $this->crearReporte($idProyecto, $this->crearUsuario(), true);

        $respuesta = $this->capturar(fn () => $this->reportes()->enviar((string) $idReporte));

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame('en_revision', $this->estadoDeLaObra($idProyecto));
    }

    public function testAprobarElReporteFinalFinalizaLaObra(): void
    {
        $idProyecto = $this->crearProyecto();
        $idReporte = $this->crearReporte($idProyecto, $this->crearUsuario(), true);
        $envio = $this->capturar(fn () => $this->reportes()->enviar((string) $idReporte));

        self::assertSame(200, $envio['codigo']);
        self::assertSame('en_revision', $this->estadoDeLaObra($idProyecto));

        $respuesta = $this->capturar(fn () => $this->reportes()->aprobar((string) $idReporte, []));

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame('finalizada', $this->estadoDeLaObra($idProyecto));
    }

    public function testRechazarElReporteFinalDevuelveLaObraAEjecucion(): void
    {
        $idProyecto = $this->crearProyecto();
        $idReporte = $this->crearReporte($idProyecto, $this->crearUsuario(), true);
        $envio = $this->capturar(fn () => $this->reportes()->enviar((string) $idReporte));

        self::assertSame(200, $envio['codigo']);
        self::assertSame('en_revision', $this->estadoDeLaObra($idProyecto));

        $respuesta = $this->capturar(fn () => $this->reportes()->rechazar((string) $idReporte, [
            'observacion' => 'Falta documentar el cierre',
        ]));

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame('en_ejecucion', $this->estadoDeLaObra($idProyecto));
    }

    public function testNoSePuedeEnviarElReporteFinalDeUnaObraPausada(): void
    {
        $idProyecto = $this->crearProyecto('pausada');
        $idReporte = $this->crearReporte($idProyecto, $this->crearUsuario(), true);

        $respuesta = $this->capturar(fn () => $this->reportes()->enviar((string) $idReporte));

        self::assertSame(409, $respuesta['codigo']);
        self::assertSame('pausada', $this->estadoDeLaObra($idProyecto));
    }

    public function testUnReporteNoFinalNoMueveLaObraAlEnviarloNiAlAprobarlo(): void
    {
        $idProyecto = $this->crearProyecto();
        $idReporte = $this->crearReporte($idProyecto, $this->crearUsuario());

        $envio = $this->capturar(fn () => $this->reportes()->enviar((string) $idReporte));

        self::assertSame(200, $envio['codigo']);
        self::assertSame('en_ejecucion', $this->estadoDeLaObra($idProyecto));

        $respuesta = $this->capturar(fn () => $this->reportes()->aprobar((string) $idReporte, []));

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame('en_ejecucion', $this->estadoDeLaObra($idProyecto));
    }

    public function testAprobarUnFinalDeUnaObraCanceladaRegistraLaResolucionSinRevivirla(): void
    {
        $idProyecto = $this->crearProyecto();
        $idReporte = $this->crearReporte($idProyecto, $this->crearUsuario(), true);
        $envio = $this->capturar(fn () => $this->reportes()->enviar((string) $idReporte));

        self::assertSame(200, $envio['codigo']);
        self::assertSame('en_revision', $this->estadoDeLaObra($idProyecto));

        $this->base()->prepare('UPDATE proyecto SET estado = ? WHERE id_proyecto = ?')
            ->execute(['cancelada', $idProyecto]);

        $respuesta = $this->capturar(fn () => $this->reportes()->aprobar((string) $idReporte, []));

        self::assertSame(200, $respuesta['codigo']);
        self::assertSame('aprobado', $this->estadoDelReporte($idReporte));
        self::assertSame('cancelada', $this->estadoDeLaObra($idProyecto));
    }

    private function reportes(): ReporteController
    {
        return new ReporteController($this->base());
    }

    private function estadoDelReporte(int $idReporte): string
    {
        $stmt = $this->base()->prepare('SELECT estado FROM reporte WHERE id_reporte = ?');
        $stmt->execute([$idReporte]);

        return (string) $stmt->fetchColumn();
    }
}
