<?php

namespace App\Services\Glpi\Contracts;

interface GlpiClientInterface
{
    public function authenticate(): void;
    public function killSession(): void;
    public function getItem(string $itemType, int $id, array $params = []): array;
    public function getItems(string $itemType, array $extraParams = []): array;
}
