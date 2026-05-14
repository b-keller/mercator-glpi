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
     * Synchronise un type d'item GLPI vers Mercator.
     *
     * Les clients sont passés en paramètre (interfaces) plutôt qu'injectés
     * en constructeur : cela facilite les tests (mock des interfaces, pas
     * des classes concrètes avec readonly) et garantit l'instance authentifiée.
     *
     * @return array{created: int, updated: int, deleted: int, marked_old: int, errors: int}
     */
    public function sync(
        GlpiClientInterface     $glpi,
        MercatorClientInterface $mercator,
        SyncHandler             $handler,
        bool                    $dryRun = false,
    ): array {
        $stats = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'marked_old' => 0, 'errors' => 0];

        // ── 1. Chargement des données ─────────────────────────────────────────

        $buildingsMap = $this->buildBuildingsMap($mercator);

        $glpiItems = $glpi->getItems(
            $handler->glpiItemType(),
            $handler->glpiQueryParams()
        );

        $mercatorItems = $mercator->getAll($handler->mercatorEndpoint());

        // ── 2. Construction des index ─────────────────────────────────────────

        // Index GLPI  : lower(name) => item complet
        $glpiMap = [];
        foreach ($glpiItems as $item) {
            $glpiMap[strtolower($item['name'])] = $item;
        }

        // Index Mercator : lower(name) => ['id' => int, 'name' => string, 'glpi_id' => int|null]
        $mercMap = [];
        foreach ($mercatorItems as $item) {
            $mercMap[strtolower($item['name'])] = [
                'id'      => $item['id'],
                'name'    => $item['name'],
                'glpi_id' => $this->extractGlpiId($item['description'] ?? ''),
            ];
        }

        $context = ['buildings_map' => $buildingsMap];

        // ── 3. GLPI → Mercator : créer ou mettre à jour ───────────────────────

        foreach ($glpiItems as $glpiItem) {
            $key = strtolower($glpiItem['name']);

            try {
                $payload = $handler->map($glpiItem, $context);

                if (isset($mercMap[$key])) {
                    if (! $dryRun) {
                        $mercator->update(
                            $handler->mercatorEndpoint(),
                            $mercMap[$key]['id'],
                            $payload
                        );
                    }
                    $stats['updated']++;
                    Log::info("[{$handler->mercatorEndpoint()}] Mis à jour : {$glpiItem['name']}");
                } else {
                    if (! $dryRun) {
                        $mercator->create($handler->mercatorEndpoint(), $payload);
                    }
                    $stats['created']++;
                    Log::info("[{$handler->mercatorEndpoint()}] Créé : {$glpiItem['name']}");
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                Log::error("[{$handler->mercatorEndpoint()}] Erreur sur {$glpiItem['name']} : " . $e->getMessage());
            }
        }

        // ── 4. Mercator → nettoyage : supprimer ou marquer OLD ────────────────
        // Ignoré si le handler indique de ne pas traiter les orphelins.

        if ($handler->processOrphans()) {
            foreach ($mercMap as $key => $mercItem) {
                if (isset($glpiMap[$key])) {
                    continue;
                }

                try {
                    if ($mercItem['glpi_id'] !== null) {
                        if (! $dryRun) {
                            $mercator->delete($handler->mercatorEndpoint(), $mercItem['id']);
                        }
                        $stats['deleted']++;
                        Log::info("[{$handler->mercatorEndpoint()}] Supprimé : {$mercItem['name']}");
                    } else {
                        $oldName = $mercItem['name'];
                        if (! str_starts_with($oldName, '[OLD]')) {
                            if (! $dryRun) {
                                $mercator->update(
                                    $handler->mercatorEndpoint(),
                                    $mercItem['id'],
                                    ['name' => '[OLD] ' . $oldName]
                                );
                            }
                            $stats['marked_old']++;
                            Log::info("[{$handler->mercatorEndpoint()}] Marqué OLD : {$oldName}");
                        }
                    }
                } catch (Throwable $e) {
                    $stats['errors']++;
                    Log::error("[{$handler->mercatorEndpoint()}] Erreur nettoyage {$mercItem['name']} : " . $e->getMessage());
                }
            }
        }

        return $stats;
    }

    /**
     * Synchronise les liens workstation↔application depuis GLPI vers Mercator.
     *
     * Lit les Computer_SoftwareVersion de GLPI (liens poste ↔ logiciel),
     * résout les IDs Mercator correspondants, puis met à jour chaque workstation
     * avec la liste de ses application_ids.
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

        // Liste des computers (sans logiciels — with_softwares non supporté sur la collection)
        $computers = $glpi->getItems('Computer', [
            'range'            => '0-999',
            'expand_dropdowns' => 1,
        ]);

        $wsItems  = $mercator->getAll('workstations');
        $appItems = $mercator->getAll('applications');

        // ── 2. Index Mercator ─────────────────────────────────────────────────

        $wsMap  = [];
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
                continue; // Poste absent de Mercator → ignoré
            }

            // N+1 : with_softwares=1 ne fonctionne que sur les items individuels
            $detail    = $glpi->getItem('Computer', $computer['id'], [
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
                        'applications' => $uniqueAppIds, // champ attendu par WorkstationController::update()
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
     * Extrait le nom du logiciel depuis un enregistrement _softwares GLPI.
     *
     * Gère les cas :
     *   - softwares_id = "Firefox"  (expand_dropdowns=1 a expandé le dropdown)
     *   - softwares_id = 10          (int, non expandé) + name = "Firefox"
     *   - name = "Firefox"           (champ direct depuis le JOIN GLPI)
     */
    private function extractSoftwareName(array $software): string
    {
        // Cas 1 : softwares_id est une chaîne non numérique (dropdown expandé)
        $softwaresId = $software['softwares_id'] ?? null;
        if (is_string($softwaresId) && ! is_numeric($softwaresId)) {
            return strtolower(trim($softwaresId));
        }

        // Cas 2 : champ 'name' présent (JOIN avec glpi_softwares)
        // Attention : dans _softwares, 'name' peut être le nom du LOGICIEL
        // ou le nom de la VERSION selon la version de GLPI
        // On préfère 'softname' s'il existe (GLPI le fournit parfois)
        if (! empty($software['softname'])) {
            return strtolower(trim($software['softname']));
        }

        // Cas 3 : 'name' = nom du logiciel (GLPI sans version séparée)
        if (! empty($software['name'])) {
            return strtolower(trim($software['name']));
        }

        return '';
    }

    // -------------------------------------------------------------------------
    // Helpers
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
