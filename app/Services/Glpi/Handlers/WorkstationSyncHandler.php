<?php

namespace App\Services\Glpi\Handlers;

use App\Services\Glpi\Contracts\SyncHandler;
use App\Services\Glpi\Mappers\WorkstationMapper;

class WorkstationSyncHandler implements SyncHandler
{
    public function __construct(private readonly WorkstationMapper $mapper) {}

    public function glpiItemType(): string
    {
        return 'Computer';
    }

    public function mercatorEndpoint(): string
    {
        return 'workstations';
    }

    public function glpiQueryParams(): array
    {
        return [
            'range'             => '0-999',
            'expand_dropdowns'  => 1,
            'with_networkports' => 1,
            'with_devices'      => 1,
            'with_disks'        => 1,
            'with_infocoms'     => 1,
        ];
    }

    public function processOrphans(): bool
    {
        return false; // Les workstations Mercator absentes de GLPI sont conservées telles quelles
    }

    public function map(array $glpiItem, array $context): array
    {
        return $this->mapper->map($glpiItem, $context);
    }
}
