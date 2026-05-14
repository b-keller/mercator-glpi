<?php

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Mercator\Contracts\MercatorClientInterface;

// ── Fixtures ──────────────────────────────────────────────────────────────────
// glpiComputersWithSoftwareFixture() est définie dans tests/Pest.php

function mercatorApplicationsFixture(): array
{
    return json_decode(
        file_get_contents(__DIR__ . '/../Fixtures/mercator_applications.json'),
        true
    )['data'];
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Mock GlpiClient :
 * - getItems('Computer') → liste des computers (sans logiciels)
 * - getItem('Computer', id) → computer individuel avec _softwares
 */
function linkGlpiMock(array $computers): \Mockery\MockInterface
{
    $mock = Mockery::mock(GlpiClientInterface::class);

    // Collection sans logiciels (limitation GLPI sur with_softwares en collection)
    $mock->shouldReceive('getItems')
        ->with('Computer', \Mockery::type('array'))
        ->andReturn(array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name']], $computers));

    // Item individuel avec _softwares (appelé pour chaque computer présent dans Mercator)
    foreach ($computers as $computer) {
        $mock->shouldReceive('getItem')
            ->with('Computer', $computer['id'], \Mockery::type('array'))
            ->andReturn($computer);
    }

    return $mock;
}

function linkMercatorMock(
    array $workstations,
    array $applications,
): \Mockery\MockInterface {
    $mock = Mockery::mock(MercatorClientInterface::class);
    $mock->shouldReceive('getAll')->with('workstations')->andReturn($workstations);
    $mock->shouldReceive('getAll')->with('applications')->andReturn($applications);
    return $mock;
}

// Workstations Mercator incluant les deux postes de la fixture GLPI
function wsWithNewPC(): array
{
    return [
        ...mercatorWorkstationsFixture(),
        ['id' => 13, 'name' => 'PC-NOUVEAU-01', 'description' => '[glpi_id:43]'],
    ];
}

// ── Tests ─────────────────────────────────────────────────────────────────────

it('associe les applications aux workstations correspondantes', function () {
    $updated = [];

    $mercator = linkMercatorMock(wsWithNewPC(), mercatorApplicationsFixture());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');
            return [];
        });

    (new GlpiSyncService())->syncLinks(
        linkGlpiMock(glpiComputersWithSoftwareFixture()),
        $mercator,
    );

    // PC-DIDIER-01 (Mercator id 10) → Firefox (20) + LibreOffice (21)
    $ws10 = collect($updated)->first(fn($u) => $u['id'] === 10);
    expect($ws10)->not->toBeNull();
    expect($ws10['payload']['applications'])->toContain(20)->toContain(21);
    // Le name est toujours inclus dans le payload (requis par l'API Mercator)
    expect($ws10['payload'])->toHaveKey('name');
});

it('n\'associe pas deux fois le même logiciel', function () {
    $updated = [];

    // Firefox deux fois dans softwares (deux versions différentes du même logiciel)
    $computers = [[
        'id'       => 42,
        'name'     => 'PC-DIDIER-01',
        'softwares' => [
            ['softwares_id' => 'Firefox', 'softwareversions_id' => '120.0'],
            ['softwares_id' => 'Firefox', 'softwareversions_id' => '121.0'],
        ],
    ]];

    $mercator = linkMercatorMock(mercatorWorkstationsFixture(), mercatorApplicationsFixture());
    $mercator->shouldReceive('update')
        ->andReturnUsing(function (string $ep, int $id, array $payload) use (&$updated) {
            $updated[] = compact('id', 'payload');
            return [];
        });

    (new GlpiSyncService())->syncLinks(linkGlpiMock($computers), $mercator);

    $ws10   = collect($updated)->first(fn($u) => $u['id'] === 10);
    $appIds = $ws10['payload']['applications'];

    // Firefox (20) ne doit apparaître qu'une seule fois
    expect(array_count_values($appIds)[20])->toBe(1);
});

it('ignore un computer dont le poste n\'existe pas dans Mercator', function () {
    $computers = [[
        'id'       => 99,
        'name'     => 'PC-INCONNU',
        'softwares' => [['softwares_id' => 'Firefox', 'softwareversions_id' => '120']],
    ]];

    $mercator = linkMercatorMock(mercatorWorkstationsFixture(), mercatorApplicationsFixture());
    $mercator->shouldReceive('update')->andReturn([])->zeroOrMoreTimes();

    $stats = (new GlpiSyncService())->syncLinks(linkGlpiMock($computers), $mercator);

    expect($stats['updated'])->toBe(0);
});

it('compte comme skipped les logiciels absents de Mercator', function () {
    $computers = [[
        'id'       => 42,
        'name'     => 'PC-DIDIER-01',
        'softwares' => [
            ['softwares_id' => 'Firefox',         'softwareversions_id' => '120'],
            ['softwares_id' => 'LogicielInconnu', 'softwareversions_id' => '1.0'],
        ],
    ]];

    $mercator = linkMercatorMock(mercatorWorkstationsFixture(), mercatorApplicationsFixture());
    $mercator->shouldReceive('update')->andReturn([]);

    $stats = (new GlpiSyncService())->syncLinks(linkGlpiMock($computers), $mercator);

    // Firefox résolu, LogicielInconnu ignoré
    expect($stats['updated'])->toBe(1);
    expect($stats['skipped'])->toBe(1);
});

it('ne fait aucune écriture en mode dry-run', function () {
    $mercator = linkMercatorMock(wsWithNewPC(), mercatorApplicationsFixture());
    $mercator->shouldNotReceive('update');

    (new GlpiSyncService())->syncLinks(
        linkGlpiMock(glpiComputersWithSoftwareFixture()),
        $mercator,
        dryRun: true,
    );
});

it('retourne les statistiques correctes', function () {
    $mercator = linkMercatorMock(wsWithNewPC(), mercatorApplicationsFixture());
    $mercator->shouldReceive('update')->andReturn([]);

    // 2 computers avec logiciels connus → 2 workstations mises à jour
    $stats = (new GlpiSyncService())->syncLinks(
        linkGlpiMock(glpiComputersWithSoftwareFixture()),
        $mercator,
    );

    expect($stats['updated'])->toBe(2);
    expect($stats['errors'])->toBe(0);
});
