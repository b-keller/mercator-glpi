<?php

uses(Tests\TestCase::class)->in('Feature', 'Unit');

// ── Fixtures centralisées ─────────────────────────────────────────────────────
// Définies ici pour éviter toute redéclaration entre fichiers de test.

/**
 * Retourne la liste des Computer GLPI (fixture complète, 2 items).
 */
function glpiComputersFixture(): array
{
    return json_decode(
        file_get_contents(__DIR__ . '/Fixtures/glpi_computers.json'),
        true
    );
}

/**
 * Retourne le tableau data[] tel que MercatorClient::getAll('workstations') le retourne.
 */
function mercatorWorkstationsFixture(): array
{
    return json_decode(
        file_get_contents(__DIR__ . '/Fixtures/mercator_workstations.json'),
        true
    )['data'];
}

/**
 * Retourne le tableau data[] tel que MercatorClient::getBuildings() le retourne.
 */
function mercatorBuildingsFixture(): array
{
    return json_decode(
        file_get_contents(__DIR__ . '/Fixtures/mercator_buildings.json'),
        true
    )['data'];
}

function glpiComputersWithSoftwareFixture(): array
{
    return json_decode(
        file_get_contents(__DIR__ . '/Fixtures/glpi_computers_with_software.json'),
        true
    );
}
