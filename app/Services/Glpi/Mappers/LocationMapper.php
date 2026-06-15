<?php

namespace App\Services\Glpi\Mappers;

use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;

class LocationMapper
{
    use AppendsUnmappedFields;
    /**
     * Mappe une Location GLPI (expand_dropdowns=1) vers un payload Mercator buildings.
     *
     * Mercator Building : name, description, building_id (parent).
     *
     * @param  array  $item     Location GLPI brut
     * @param  array  $context  ['buildings_map' => ['nom (lower)' => ['id' => X, 'site_id' => Y]]]
     */
    public function map(array $item, array $context = []): array
    {
        $buildingsMap = $context['buildings_map'] ?? [];
        $parent       = $this->resolveParent($item['locations_id'] ?? null, $buildingsMap);

        return array_filter([
            'name'        => $item['name'],
            'description' => $this->buildDescription($item, ['locations_id']),
            'building_id' => $parent['id'] ?? null,
        ], fn($v) => $v !== null);
    }

    private function resolveParent(mixed $parentName, array $buildingsMap): ?array
    {
        if (! $parentName || is_int($parentName) || $parentName === '0') {
            return null;
        }

        return $buildingsMap[strtolower($parentName)] ?? null;
    }

}
