<?php

namespace App\Services\Glpi\Contracts;

interface SyncHandler
{
    /**
     * Itemtype GLPI source : 'Computer', 'Phone', 'Peripheral', 'Software'
     */
    public function glpiItemType(): string;

    /**
     * Endpoint Mercator cible : 'workstations', 'applications', 'phones'…
     */
    public function mercatorEndpoint(): string;

    /**
     * Paramètres de requête supplémentaires pour l'API GLPI
     */
    public function glpiQueryParams(): array;

    /**
     * Mappe un item GLPI vers un payload Mercator.
     *
     * @param  array  $glpiItem  Item brut retourné par l'API GLPI
     * @param  array  $context   Données de contexte (ex: buildings_map)
     */
    public function map(array $glpiItem, array $context): array;

    /**
     * Indique si les items présents dans Mercator mais absents de GLPI
     * doivent être traités (supprimés ou marqués [OLD]).
     *
     * true  → comportement par défaut (workstations)
     * false → les orphelins Mercator sont ignorés (applications)
     */
    public function processOrphans(): bool;
}
