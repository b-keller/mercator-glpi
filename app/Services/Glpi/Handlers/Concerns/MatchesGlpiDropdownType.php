<?php

namespace App\Services\Glpi\Handlers\Concerns;

trait MatchesGlpiDropdownType
{
    /**
     * Compare la valeur d'un dropdown GLPI (ID brut ou nom, selon expand_dropdowns)
     * à une liste de valeurs autorisées issues de la config (IDs ou noms, .env).
     */
    private function matchesType(mixed $typeValue, array $allowed): bool
    {
        if ($typeValue === null || $typeValue === 0 || $typeValue === '') {
            return false;
        }

        $typeStr = (string) $typeValue;

        foreach ($allowed as $a) {
            if (is_numeric($a) && is_numeric($typeStr) && (int) $a === (int) $typeStr) {
                return true;
            }
            if (strtolower($typeStr) === strtolower((string) $a)) {
                return true;
            }
        }

        return false;
    }
}
