<?php

namespace App\Services\Glpi\Mappers;

use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;

class LocationMapper
{
    use AppendsUnmappedFields;
    /**
     * Mappe une Location GLPI (expand_dropdowns=1) vers un payload Mercator buildings.
     *
     * Mercator Building : name, description, building_id (parent), site_id.
     *
     * Une Location racine (sans parent) donne un Building rattaché au Site Mercator
     * créé pour cette même racine (cf. SiteMapper). Une Location non racine donne un
     * Building rattaché au Building de son parent, et hérite du site_id de ce parent.
     *
     * @param  array  $item     Location GLPI brut
     * @param  array  $context  ['buildings_map' => ['nom (lower)' => ['id' => X, 'site_id' => Y]],
     *                           'sites_map'     => ['nom (lower)' => Y]]
     */
    public function map(array $item, array $context = []): array
    {
        $buildingsMap = $context['buildings_map'] ?? [];
        $sitesMap     = $context['sites_map'] ?? [];
        $parentName   = $item['locations_id'] ?? null;
        $isRoot       = ! $parentName || is_int($parentName) || $parentName === '0';

        if ($isRoot) {
            $buildingId = null;
            $siteId     = $sitesMap[strtolower($item['name'])] ?? null;
        } else {
            $parent     = $buildingsMap[strtolower($parentName)] ?? null;
            $buildingId = $parent['id'] ?? null;
            $siteId     = $parent['site_id'] ?? null;
        }

        return array_filter([
            'name'        => $item['name'],
            'description' => $this->buildDescription($item, ['locations_id']),
            'building_id' => $buildingId,
            'site_id'     => $siteId,
        ], fn($v) => $v !== null);
    }
}
