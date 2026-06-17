<?php

namespace App\Services\Glpi;

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\Contracts\SyncHandler;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

class GlpiSyncService
{
    /**
     * Itemtypes GLPI ne possédant pas d'attribut "statut" (states_id).
     * Le filtrage par statut est ignoré pour ces types, quelle que soit
     * la config GLPI_ALLOWED_STATES / GLPI_ALLOWED_STATES_<TYPE>.
     */
    private const STATELESS_ITEM_TYPES = ['Location'];

    /**
     * Synchronise un type d'item GLPI vers Mercator.
     *
     * Retourne un tableau de stats. Si l'endpoint Mercator n'existe pas (HTTP 404),
     * retourne les stats vides avec 'endpoint_missing' => true afin que la commande
     * puisse afficher un avertissement sans comptabiliser d'erreur.
     *
     * @return array{created: int, updated: int, deleted: int, marked_old: int, errors: int, endpoint_missing: bool}
     */
    public function sync(
        GlpiClientInterface     $glpi,
        MercatorClientInterface $mercator,
        SyncHandler             $handler,
        bool                    $dryRun = false,
    ): array {
        $stats    = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'marked_old' => 0, 'errors' => 0, 'endpoint_missing' => false];
        $endpoint = $handler->mercatorEndpoint();

        // ── 1. Chargement des données ─────────────────────────────────────────

        $buildingsMap = $this->buildBuildingsMap($mercator);

        $glpiItems = $glpi->getItems(
            $handler->glpiItemType(),
            $handler->glpiQueryParams()
        );

        Log::debug("[{$endpoint}] {$handler->glpiItemType()} GLPI : " . count($glpiItems) . ' item(s) reçu(s)');

        // ── 2. Filtrage par statut (Évolution 2) ──────────────────────────────

        $allowedStates = in_array($handler->glpiItemType(), self::STATELESS_ITEM_TYPES, true)
            ? []
            : $this->resolveAllowedStates($handler->glpiItemType());

        if (! empty($allowedStates)) {
            $before     = count($glpiItems);
            $glpiItems  = array_values(array_filter(
                $glpiItems,
                fn($item) => $this->matchesState($item, $allowedStates)
            ));
            $filtered = $before - count($glpiItems);
            Log::debug("[{$endpoint}] Filtre statut [{$handler->glpiItemType()}] : {$filtered} item(s) exclus, " . count($glpiItems) . ' conservé(s)');
        }

        // ── 3. Filtrage par sous-type (handler::filterItem) ───────────────────

        $before    = count($glpiItems);
        $glpiItems = array_values(array_filter($glpiItems, fn($item) => $handler->filterItem($item)));
        $excluded  = $before - count($glpiItems);

        if ($excluded > 0) {
            Log::debug("[{$endpoint}] Filtre sous-type : {$excluded} item(s) exclus, " . count($glpiItems) . ' conservé(s)');
        }

        // ── 4. Chargement Mercator ────────────────────────────────────────────

        try {
            $mercatorItems = $mercator->getAll($endpoint);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), ': 404')) {
                Log::warning("[{$endpoint}] Endpoint non disponible dans Mercator (404) — synchronisation ignorée");
                $stats['endpoint_missing'] = true;

                return $stats;
            }
            throw $e;
        }

        Log::debug("[{$endpoint}] Mercator : " . count($mercatorItems) . ' item(s) existant(s)');

        // ── 5. Construction des index ─────────────────────────────────────────

        $glpiMap = [];
        foreach ($glpiItems as $item) {
            $glpiMap[strtolower($item['name'])] = $item;
        }

        $mercMap = [];
        foreach ($mercatorItems as $item) {
            $mercMap[strtolower($item['name'])] = [
                'id'      => $item['id'],
                'name'    => $item['name'],
                'glpi_id' => $this->extractGlpiId($item['description'] ?? ''),
            ];
        }

        $context = ['buildings_map' => $buildingsMap];

        // ── 6. GLPI → Mercator : créer ou mettre à jour ───────────────────────

        foreach ($glpiItems as $glpiItem) {
            $key    = strtolower($glpiItem['name']);
            $action = isset($mercMap[$key]) ? 'UPDATE' : 'CREATE';

            try {
                $payload = $handler->map($glpiItem, $context);

                $payloadDebug = json_encode($payload, JSON_UNESCAPED_UNICODE);
                if (strlen($payloadDebug) > 500) {
                    $payloadDebug = substr($payloadDebug, 0, 500) . '…';
                }

                Log::debug("[{$endpoint}] {$action} {$glpiItem['name']} — payload: {$payloadDebug}");

                if ($action === 'UPDATE') {
                    if (! $dryRun) {
                        $mercator->update($endpoint, $mercMap[$key]['id'], $payload);
                    }
                    $stats['updated']++;
                    Log::info("[{$endpoint}] Mis à jour : {$glpiItem['name']}");
                } else {
                    if (! $dryRun) {
                        $mercator->create($endpoint, $payload);
                    }
                    $stats['created']++;
                    Log::info("[{$endpoint}] Créé : {$glpiItem['name']}");
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                Log::error("[{$endpoint}] Erreur sur {$glpiItem['name']} : " . $e->getMessage());
            }
        }

        // ── 7. Mercator → nettoyage : supprimer ou marquer OLD ────────────────

        if ($handler->processOrphans()) {
            foreach ($mercMap as $key => $mercItem) {
                if (isset($glpiMap[$key])) {
                    continue;
                }

                try {
                    if ($mercItem['glpi_id'] !== null) {
                        if (! $dryRun) {
                            $mercator->delete($endpoint, $mercItem['id']);
                        }
                        $stats['deleted']++;
                        Log::info("[{$endpoint}] Supprimé : {$mercItem['name']}");
                    } else {
                        $oldName = $mercItem['name'];
                        if (! str_starts_with($oldName, '[OLD]')) {
                            if (! $dryRun) {
                                $mercator->update($endpoint, $mercItem['id'], ['name' => '[OLD] ' . $oldName]);
                            }
                            $stats['marked_old']++;
                            Log::info("[{$endpoint}] Marqué OLD : {$oldName}");
                        }
                    }
                } catch (Throwable $e) {
                    $stats['errors']++;
                    Log::error("[{$endpoint}] Erreur nettoyage {$mercItem['name']} : " . $e->getMessage());
                }
            }
        }

        Log::debug(sprintf(
            '[%s] Stats : +%d créés, ~%d mis à jour, -%d supprimés, %d OLD, %d erreurs',
            $endpoint,
            $stats['created'],
            $stats['updated'],
            $stats['deleted'],
            $stats['marked_old'],
            $stats['errors'],
        ));

        return $stats;
    }

    /**
     * Synchronise les liens workstation↔application depuis GLPI vers Mercator.
     *
     * @return array{updated: int, skipped: int, errors: int}
     */
    public function syncLinks(
        GlpiClientInterface     $glpi,
        MercatorClientInterface $mercator,
        bool                    $dryRun = false,
    ): array {
        $stats = ['updated' => 0, 'skipped' => 0, 'errors' => 0];

        // ── 1. Chargement ─────────────────────────────────────────────────────

        $computers = $glpi->getItems('Computer', [
            'range'            => '0-999',
            'expand_dropdowns' => 1,
        ]);

        $wsItems  = $mercator->getAll('workstations');
        $appItems = $mercator->getAll('applications');

        // ── 2. Index Mercator ─────────────────────────────────────────────────

        $wsMap = [];
        foreach ($wsItems as $ws) {
            $wsMap[strtolower($ws['name'])] = [
                'id'   => $ws['id'],
                'name' => $ws['name'],
            ];
        }

        $appMap = [];
        foreach ($appItems as $app) {
            $appMap[strtolower($app['name'])] = $app['id'];
        }

        Log::info(sprintf(
            '[links] %d computers GLPI, %d workstations Mercator, %d applications Mercator',
            count($computers),
            count($wsMap),
            count($appMap),
        ));

        // ── 3. Pour chaque computer présent dans Mercator : récupérer ses logiciels ──

        foreach ($computers as $computer) {
            $computerName = strtolower(trim($computer['name'] ?? ''));

            if (! isset($wsMap[$computerName])) {
                continue;
            }

            $detail = $glpi->getItem('Computer', $computer['id'], [
                'with_softwares'   => 1,
                'expand_dropdowns' => 1,
            ]);

            $softwares = $detail['_softwares']
                ?? $detail['softwares']
                ?? $detail['_Computer_SoftwareVersion']
                ?? [];

            $applicationIds = [];

            foreach ($softwares as $software) {
                $softwareName = $this->extractSoftwareName($software);

                if (! $softwareName) {
                    continue;
                }

                if (isset($appMap[$softwareName])) {
                    $applicationIds[] = $appMap[$softwareName];
                } else {
                    $stats['skipped']++;
                    Log::debug("[links] Logiciel absent de Mercator : {$softwareName}");
                }
            }

            if (empty($applicationIds)) {
                continue;
            }

            $uniqueAppIds    = array_values(array_unique($applicationIds));
            $workstationId   = $wsMap[$computerName]['id'];
            $workstationName = $wsMap[$computerName]['name'];

            try {
                if (! $dryRun) {
                    $payload = [
                        'name'         => $workstationName,
                        'applications' => $uniqueAppIds,
                    ];

                    Log::debug(sprintf(
                        '[links] PUT workstations/%d payload: %s',
                        $workstationId,
                        json_encode($payload)
                    ));

                    $mercator->update('workstations', $workstationId, $payload);
                }
                $stats['updated']++;
                Log::info(sprintf('[links] %s → %d application(s) : [%s]',
                    $computerName,
                    count($uniqueAppIds),
                    implode(', ', $uniqueAppIds)
                ));
            } catch (Throwable $e) {
                $stats['errors']++;
                Log::error("[links] Erreur pour {$computerName} : " . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Synchronise les liens activité↔application depuis GLPI vers Mercator.
     *
     * Chaque Appliance GLPI est récupérée individuellement avec with_items=1.
     * Les logiciels liés (Software) sont mis en correspondance avec les
     * Application Mercator par nom. La mise à jour passe par le côté Application
     * (ApplicationController.update → activities()->sync([...])).
     *
     * @return array{updated: int, skipped: int, errors: int}
     */
    public function syncActivityLinks(
        GlpiClientInterface     $glpi,
        MercatorClientInterface $mercator,
        bool                    $dryRun = false,
    ): array {
        $stats = ['updated' => 0, 'skipped' => 0, 'errors' => 0];

        // ── 1. Chargement ─────────────────────────────────────────────────────

        $appliances = $glpi->getItems('Appliance', [
            'range'            => '0-999',
            'expand_dropdowns' => 1,
        ]);

        $activityItems = $mercator->getAll('activities');
        $appItems      = $mercator->getAll('applications');

        // ── 2. Index Mercator ─────────────────────────────────────────────────

        $activityMap = [];
        foreach ($activityItems as $act) {
            $activityMap[strtolower($act['name'])] = $act['id'];
        }

        $appMap = [];
        foreach ($appItems as $app) {
            $appMap[strtolower($app['name'])] = ['id' => $app['id'], 'name' => $app['name']];
        }

        Log::info(sprintf(
            '[activity_links] %d appliances GLPI, %d activités Mercator, %d applications Mercator',
            count($appliances),
            count($activityMap),
            count($appMap),
        ));

        // ── 3. Construire le map software_name → [activity_ids] ──────────────
        // Pour chaque Appliance, on récupère individuellement ses Software liés
        // (with_items=1 n'est pas garanti sur les requêtes de liste).

        $softwareToActivities = []; // lower(software_name) → [activity_id, ...]

        foreach ($appliances as $appliance) {
            $applianceKey = strtolower(trim($appliance['name'] ?? ''));
            $activityId   = $activityMap[$applianceKey] ?? null;

            if ($activityId === null) {
                $stats['skipped']++;
                Log::debug("[activity_links] Appliance sans activité Mercator : {$appliance['name']}");
                continue;
            }

            $detail        = $glpi->getItem('Appliance', $appliance['id'], [
                'with_items'       => 1,
                'expand_dropdowns' => 1,
            ]);
            $softwareItems = $detail['_items']['Software'] ?? [];

            if (empty($softwareItems)) {
                Log::debug("[activity_links] Appliance sans logiciels liés : {$appliance['name']}");
                continue;
            }

            foreach ($softwareItems as $sw) {
                $swName = strtolower(trim($sw['name'] ?? ''));
                if ($swName === '') {
                    continue;
                }
                $softwareToActivities[$swName][] = $activityId;
            }
        }

        // ── 4. Pour chaque Application Mercator : synchroniser ses activités ──

        foreach ($appMap as $appName => $appEntry) {
            $activityIds = array_values(array_unique($softwareToActivities[$appName] ?? []));

            if (empty($activityIds)) {
                continue;
            }

            try {
                if (! $dryRun) {
                    $mercator->update('applications', $appEntry['id'], [
                        'name'       => $appEntry['name'],
                        'activities' => $activityIds,
                    ]);
                }
                $stats['updated']++;
                Log::info(sprintf(
                    '[activity_links] %s → %d activité(s) : [%s]',
                    $appEntry['name'],
                    count($activityIds),
                    implode(', ', $activityIds),
                ));
            } catch (Throwable $e) {
                $stats['errors']++;
                Log::error("[activity_links] Erreur pour {$appEntry['name']} : " . $e->getMessage());
            }
        }

        return $stats;
    }

    // -------------------------------------------------------------------------
    // Helpers — filtrage statut (Évolution 2)
    // -------------------------------------------------------------------------

    /**
     * Retourne la liste des states_id autorisés pour un itemtype GLPI.
     * Priorité : config spécifique au type → config globale → [] (aucun filtre).
     */
    private function resolveAllowedStates(string $itemType): array
    {
        $typeStates = config("glpi.allowed_states.{$itemType}", []);

        if (! empty($typeStates)) {
            return array_map('strval', $typeStates);
        }

        $defaultStates = config('glpi.allowed_states.default', []);

        return array_map('strval', $defaultStates);
    }

    /**
     * Vérifie si l'item GLPI correspond à un statut autorisé.
     * Gère le cas où states_id est 0 (non renseigné) ou une chaîne expandée.
     */
    private function matchesState(array $item, array $allowedStates): bool
    {
        $stateValue = (string) ($item['states_id'] ?? '');

        // states_id = 0 ou vide = statut non défini dans GLPI
        if ($stateValue === '' || $stateValue === '0') {
            return in_array('0', $allowedStates, true);
        }

        return in_array($stateValue, $allowedStates, true);
    }

    // -------------------------------------------------------------------------
    // Helpers — liens
    // -------------------------------------------------------------------------

    /**
     * Extrait le nom du logiciel depuis un enregistrement _softwares GLPI.
     */
    private function extractSoftwareName(array $software): string
    {
        $softwaresId = $software['softwares_id'] ?? null;
        if (is_string($softwaresId) && ! is_numeric($softwaresId)) {
            return strtolower(trim($softwaresId));
        }

        if (! empty($software['softname'])) {
            return strtolower(trim($software['softname']));
        }

        if (! empty($software['name'])) {
            return strtolower(trim($software['name']));
        }

        return '';
    }

    // -------------------------------------------------------------------------
    // Helpers — buildings
    // -------------------------------------------------------------------------

    private function buildBuildingsMap(MercatorClientInterface $mercator): array
    {
        $map = [];

        foreach ($mercator->getBuildings() as $building) {
            $map[strtolower($building['name'])] = [
                'id'      => $building['id'],
                'site_id' => $building['site_id'] ?? null,
            ];
        }

        return $map;
    }

    private function extractGlpiId(?string $description): ?int
    {
        if (! $description) {
            return null;
        }

        preg_match('/^\[glpi_id:(\d+)\]/', $description, $matches);

        return isset($matches[1]) ? (int) $matches[1] : null;
    }
}
