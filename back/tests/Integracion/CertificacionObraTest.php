<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\AnalisisController;
use Sgso\MySqlProyectoRepository;
use Sgso\ProyectoController;

/**
 * La certificacion de una obra a la fecha la calcula la API (RF15, D-09).
 *
 * Antes la calculaba el navegador, redondeada al peso, y el analisis mostraba
 * el mismo monto redondeado al centavo. Y como es un importe, el Personal
 * Tecnico no la puede recibir (RF20).
 */
#[CoversClass(ProyectoController::class)]
#[CoversClass(AnalisisController::class)]
final class CertificacionObraTest extends CasoConBase
{
    public function testElDetalleDeLaObraTraeElMontoCertificado(): void
    {
        $idProyecto = $this->obra(987654.32, 25);

        $obra = $this->detalle($idProyecto, 'PersonalAdministrativo');

        self::assertNumero(246913.58, $obra['certificado'] ?? null);
    }

    /** RF20: ni la clave ni el numero, por si alguien lo agrega con otro nombre. */
    public function testElPersonalTecnicoNoRecibeLaCertificacion(): void
    {
        $idProyecto = $this->obra(987654.32, 25);

        $respuesta = $this->capturar(fn () => $this->proyectos()->mostrar((string) $idProyecto, 'PersonalTecnico'));

        self::assertSame(200, $respuesta['codigo']);
        self::assertArrayNotHasKey('certificado', $respuesta['cuerpo']);
        self::assertArrayNotHasKey('presupuesto', $respuesta['cuerpo']);
        $crudo = (string) json_encode($respuesta['cuerpo']);
        self::assertStringNotContainsString('246913', $crudo);
        self::assertStringNotContainsString('987654', $crudo);
    }

    /** El listado tambien lo trae: de ahi sale el grafico de ejecutado del Dashboard. */
    public function testElListadoTraeElMontoYAlTecnicoNo(): void
    {
        $this->obra(987654.32, 25);

        $gerente = $this->capturar(fn () => $this->proyectos()->listar(null, 'Gerente'))['cuerpo'];
        $tecnico = $this->capturar(fn () => $this->proyectos()->listar(null, 'PersonalTecnico'))['cuerpo'];

        self::assertNumero(246913.58, $gerente[0]['certificado'] ?? null);
        self::assertArrayNotHasKey('certificado', $tecnico[0]);
        self::assertStringNotContainsString('246913', (string) json_encode($tecnico));
    }

    /** El detalle y el analisis tienen que decir el mismo monto para la misma obra. */
    public function testElDetalleYElAnalisisDicenElMismoMonto(): void
    {
        $idProyecto = $this->obra(1000.50, 33);

        $certificado = $this->detalle($idProyecto, 'Gerente')['certificado'] ?? null;
        $analisis = $this->capturar(fn () => (new AnalisisController($this->base()))->resumen('Gerente'))['cuerpo'];
        $ejecutado = array_values(array_filter($analisis['proyectos'], fn (array $p): bool => $p['id_proyecto'] === $idProyecto))[0]['ejecutado'];

        self::assertNumero(330.17, $certificado);
        self::assertNumero(330.17, $ejecutado);
    }

    private function obra(float $presupuesto, float $avance): int
    {
        $idProyecto = $this->crearProyecto('en_ejecucion', 'Obra certificada');
        $this->base()->prepare('UPDATE proyecto SET presupuesto = ?, avance = ? WHERE id_proyecto = ?')
            ->execute([$presupuesto, $avance, $idProyecto]);

        return $idProyecto;
    }

    /** @return array<string, mixed> */
    private function detalle(int $idProyecto, string $rol): array
    {
        return $this->capturar(fn () => $this->proyectos()->mostrar((string) $idProyecto, $rol))['cuerpo'];
    }

    private function proyectos(): ProyectoController
    {
        return new ProyectoController(new MySqlProyectoRepository($this->base()));
    }
}
