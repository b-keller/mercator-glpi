<?php

namespace App\Services\Glpi\Mappers;

use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;

class PhoneMapper
{
    use AppendsUnmappedFields;
    /**
     * Mappe un Phone GLPI (expand_dropdowns=1) vers un payload Mercator.
     *
     * @param  array  $item     Phone GLPI brut
     * @param  array  $context  ['buildings_map' => ['nom salle (lower)' => ['id' => X, 'site_id' => Y]]]
     */
    public function map(array $item, array $context): array
    {
        $buildingsMap = $context['buildings_map'] ?? [];
        $building     = $this->resolveBuilding($item['locations_id'] ?? null, $buildingsMap);

        return array_filter([
            'name'        => $item['name'],
            'description' => $this->buildDescription($item, [
                'phonetypes_id', 'manufacturers_id', 'phonemodels_id', 'locations_id',
            ]),
            'type'        => $this->nullable($item['phonetypes_id'] ?? null),
            'vendor'      => $this->nullable($item['manufacturers_id'] ?? null),
            'product'     => $this->nullable($item['phonemodels_id'] ?? null),
            'building_id' => $building['id'] ?? null,
            'site_id'     => $building['site_id'] ?? null,
            'address_ip'  => $this->extractIp($item),
        ], fn($v) => $v !== null);
    }

    // -------------------------------------------------------------------------
    // Résolution building_id / site_id
    // -------------------------------------------------------------------------

    private function resolveBuilding(mixed $locationName, array $buildingsMap): ?array
    {
        if (! $locationName || is_int($locationName)) {
            return null;
        }

        return $buildingsMap[strtolower($locationName)] ?? null;
    }

    // -------------------------------------------------------------------------
    // Réseau
    // -------------------------------------------------------------------------

    private function extractIp(array $item): ?string
    {
        $ports = $item['_networkports'] ?? [];

        foreach ([...$ports['NetworkPortEthernet'] ?? [], ...$ports['NetworkPortWifi'] ?? []] as $port) {
            foreach ($port['NetworkName']['IPAddress'] ?? [] as $addr) {
                $ip = $addr['name'] ?? '';
                if ($ip && $ip !== '0.0.0.0' && ! str_starts_with($ip, '127.')) {
                    return $ip;
                }
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Utilitaires
    // -------------------------------------------------------------------------

    private function nullable(mixed $value): mixed
    {
        if ($value === null || $value === 0 || $value === '0' || $value === '') {
            return null;
        }

        return $value;
    }
}
