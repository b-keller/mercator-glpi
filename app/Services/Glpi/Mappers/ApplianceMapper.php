<?php

namespace App\Services\Glpi\Mappers;

use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;

class ApplianceMapper
{
    use AppendsUnmappedFields;
    /**
     * Mappe une Appliance GLPI (expand_dropdowns=1) vers un payload Mercator activities.
     *
     * Mercator Activity : name, description, responsible.
     *
     * @param  array  $item     Appliance GLPI brut
     * @param  array  $context  Réservé
     */
    public function map(array $item, array $context = []): array
    {
        return array_filter([
            'name'        => $item['name'],
            'description' => $this->buildDescription($item, ['users_id_tech']),
            'responsible' => $this->nullable($item['users_id_tech'] ?? null),
        ], fn($v) => $v !== null);
    }

    private function nullable(mixed $value): mixed
    {
        if ($value === null || $value === 0 || $value === '0' || $value === '') {
            return null;
        }

        return $value;
    }
}
