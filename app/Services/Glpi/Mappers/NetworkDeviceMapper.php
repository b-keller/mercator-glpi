<?php

namespace App\Services\Glpi\Mappers;

class NetworkDeviceMapper
{
    /**
     * Mappe un NetworkEquipment GLPI (expand_dropdowns=1) vers un payload Mercator physical-switches.
     *
     * Mercator PhysicalSwitch : name, type, description, site_id, building_id, bay_id.
     *
     * @param  array  $item     NetworkEquipment GLPI brut
     * @param  array  $context  ['buildings_map' => [...]]
     */
    public function map(array $item, array $context): array
    {
        $buildingsMap = $context['buildings_map'] ?? [];
        $building     = $this->resolveBuilding($item['locations_id'] ?? null, $buildingsMap);

        return array_filter([
            'name'        => $item['name'],
            'description' => $this->buildDescription($item),
            'type'        => $this->nullable($item['networkequipmenttypes_id'] ?? null),
            'building_id' => $building['id'] ?? null,
            'site_id'     => $building['site_id'] ?? null,
        ], fn($v) => $v !== null);
    }

    private function buildDescription(array $item): string
    {
        $tag     = '[glpi_id:' . $item['id'] . ']';
        $comment = trim($item['comment'] ?? '');

        return $comment ? "{$tag} {$comment}" : $tag;
    }

    private function resolveBuilding(mixed $locationName, array $buildingsMap): ?array
    {
        if (! $locationName || is_int($locationName)) {
            return null;
        }

        return $buildingsMap[strtolower($locationName)] ?? null;
    }

    private function nullable(mixed $value): mixed
    {
        if ($value === null || $value === 0 || $value === '0' || $value === '') {
            return null;
        }

        return $value;
    }
}
