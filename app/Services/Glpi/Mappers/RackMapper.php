<?php

namespace App\Services\Glpi\Mappers;

use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;

class RackMapper
{
    use AppendsUnmappedFields;
    /**
     * Mappe un Rack GLPI (expand_dropdowns=1) vers un payload Mercator bays.
     *
     * Mercator Bay : name, description, room_id (FK vers buildings).
     *
     * @param  array  $item     Rack GLPI brut
     * @param  array  $context  ['buildings_map' => [...]]
     */
    public function map(array $item, array $context): array
    {
        $buildingsMap = $context['buildings_map'] ?? [];
        $building     = $this->resolveBuilding($item['locations_id'] ?? null, $buildingsMap);

        return array_filter([
            'name'        => $item['name'],
            'description' => $this->buildDescription($item, ['locations_id']),
            'room_id'     => $building['id'] ?? null,
        ], fn($v) => $v !== null);
    }

    private function resolveBuilding(mixed $locationName, array $buildingsMap): ?array
    {
        if (! $locationName || is_int($locationName)) {
            return null;
        }

        return $buildingsMap[strtolower($locationName)] ?? null;
    }
}
