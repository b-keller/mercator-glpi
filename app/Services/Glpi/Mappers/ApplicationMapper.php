<?php

namespace App\Services\Glpi\Mappers;

use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;

class ApplicationMapper
{
    use AppendsUnmappedFields;
    /**
     * Mappe un Software GLPI (expand_dropdowns=1) vers un payload Application Mercator.
     *
     * @param  array  $item     Software GLPI brut
     * @param  array  $context  Réservé (extensions futures)
     */
    public function map(array $item, array $context = []): array
    {
        return array_filter([
            'name'         => $item['name'],
            'description'  => $this->buildDescription($item, [
                'manufacturers_id', 'softwarecategories_id', 'users_id_tech', 'date', 'locations_id',
            ]),
            'product'      => $item['name'],
            'vendor'       => $this->nullable($item['manufacturers_id'] ?? null),
            'editor'       => $this->nullable($item['manufacturers_id'] ?? null),
            'type'         => $this->nullable($item['softwarecategories_id'] ?? null),
            'responsible'  => $this->nullable($item['users_id_tech'] ?? null),
            'install_date' => $this->parseDate($item['date'] ?? null),
        ], fn($v) => $v !== null);
    }

    // -------------------------------------------------------------------------
    // Utilitaires
    // -------------------------------------------------------------------------

    /**
     * Retourne null si la valeur est vide, 0, ou "0".
     * GLPI retourne 0 pour les dropdowns non renseignés avec expand_dropdowns.
     */
    private function nullable(mixed $value): mixed
    {
        if ($value === null || $value === 0 || $value === '0' || $value === '') {
            return null;
        }

        return $value;
    }

    private function parseDate(?string $date): ?string
    {
        if (! $date || $date === '0000-00-00') {
            return null;
        }

        return $date;
    }
}
