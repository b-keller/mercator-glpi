<?php

namespace App\Services\Glpi\Handlers;

use App\Services\Glpi\Contracts\SyncHandler;
use App\Services\Glpi\Mappers\PeripheralMapper;

class PeripheralSyncHandler implements SyncHandler
{
    public function __construct(private readonly PeripheralMapper $mapper) {}

    public function glpiItemType(): string
    {
        return 'Peripheral';
    }

    public function mercatorEndpoint(): string
    {
        return 'peripherals';
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

    public function map(array $glpiItem, array $context): array
    {
        return $this->mapper->map($glpiItem, $context);
    }
}
