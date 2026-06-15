<?php

namespace App\Services\Glpi\Handlers;

use App\Services\Glpi\Contracts\SyncHandler;
use App\Services\Glpi\Mappers\NetworkDeviceMapper;

class NetworkDeviceSyncHandler implements SyncHandler
{
    public function __construct(private readonly NetworkDeviceMapper $mapper) {}

    public function glpiItemType(): string
    {
        return 'NetworkEquipment';
    }

    public function mercatorEndpoint(): string
    {
        return 'physical-switches';
    }

    public function glpiQueryParams(): array
    {
        return [
            'range'             => '0-999',
            'expand_dropdowns'  => 1,
            'with_networkports' => 1,
        ];
    }

    public function processOrphans(): bool
    {
        return false;
    }

    public function filterItem(array $item): bool
    {
        return true;
    }

    public function map(array $glpiItem, array $context): array
    {
        return $this->mapper->map($glpiItem, $context);
    }
}
