<?php

namespace App\Services\Glpi\Mappers;

class PhysicalServerMapper
{
    public function __construct(private readonly WorkstationMapper $base) {}

    /**
     * Mappe un Computer GLPI vers un payload Mercator physical_servers.
     * Réutilise la logique du WorkstationMapper — les champs sont identiques.
     */
    public function map(array $item, array $context): array
    {
        return $this->base->map($item, $context);
    }
}
