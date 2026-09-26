<?php

declare(strict_types=1);

namespace Sgso;

/**
 * Contrato del repositorio de Proyectos (RF01: registrar, modificar,
 * eliminar y consultar proyectos de obra).
 *
 * Cualquier clase que implemente esta interfaz puede usarse en
 * ProyectoController sin cambiar una línea del controlador. La
 * implementación es MySqlProyectoRepository, con PDO. La primera versión
 * guardaba los proyectos en un archivo JSON; se borró en C-11 porque ya
 * no la usaba nada.
 */
interface ProyectoRepositoryInterface
{
    /**
     * Lista todos los proyectos, opcionalmente filtrados por nombre o
     * ubicación (para el buscador del frontend).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listar(?string $busqueda = null): array;

    /**
     * @return array<string, mixed>|null null si no existe
     */
    public function buscarPorId(string $id): ?array;

    /**
     * @param array<string, mixed> $datos
     * @return array<string, mixed> el proyecto creado, con su id asignado
     */
    public function crear(array $datos): array;

    /**
     * @param array<string, mixed> $datos
     * @return array<string, mixed>|null null si no existe el id
     */
    public function actualizar(string $id, array $datos): ?array;

    public function eliminar(string $id): bool;

    /**
     * CU1, flujo alternativo "Obra ya existente": un proyecto se
     * considera duplicado si coincide nombre + ubicación.
     * $idExcluido se usa al modificar, para no comparar el proyecto
     * contra sí mismo.
     */
    public function existeDuplicado(string $nombre, string $ubicacion, ?string $idExcluido = null): bool;
}
