<?php

namespace App\Services\Glpi\Mappers;

class LocationMapper
{
    /**
     * Mappe une Location GLPI (expand_dropdowns=1) vers un payload Mercator buildings.
     *
     * Mercator Building : name, description.
     *
     * @param  array  $item     Location GLPI brut
     * @param  array  $context  Réservé
     */
    public function map(array $item, array $context = []): array
    {
        return array_filter([
            'name'        => $item['name'],
            'description' => $this->buildDescription($item),
        ], fn($v) => $v !== null);
    }

    private function buildDescription(array $item): string
    {
        $tag     = '[glpi_id:' . $item['id'] . ']';
        $comment = trim($item['comment'] ?? '');

        return $comment ? "{$tag} {$comment}" : $tag;
    }
}
