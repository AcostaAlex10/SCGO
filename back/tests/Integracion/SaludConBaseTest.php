<?php

declare(strict_types=1);

namespace Sgso\Tests\Integracion;

use PHPUnit\Framework\Attributes\CoversClass;
use Sgso\Http\Salud;

/**
 * `/api/health` contra un MariaDB de verdad (plan de producto, B-05).
 *
 * La prueba unitaria simula la base; esta confirma que `SELECT 1` devuelve lo
 * que `Salud` espera con el driver y las opciones de conexión reales.
 */
#[CoversClass(Salud::class)]
final class SaludConBaseTest extends CasoConBase
{
    public function testConLaBaseArribaReportaOk(): void
    {
        self::assertSame(['status' => 'ok', 'db' => 'ok'], Salud::comprobar($this->base()));
    }
}
