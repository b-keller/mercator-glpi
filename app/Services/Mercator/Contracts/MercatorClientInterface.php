<?php

namespace App\Services\Mercator\Contracts;

interface MercatorClientInterface
{
    public function authenticate(): void;
    public function getBuildings(): array;
    public function getSites(): array;
    public function getAll(string $endpoint): array;
    public function create(string $endpoint, array $payload): array;
    public function update(string $endpoint, int $id, array $payload): array;
    public function delete(string $endpoint, int $id): void;
}
