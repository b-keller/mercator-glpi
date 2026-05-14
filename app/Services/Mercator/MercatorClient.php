<?php

namespace App\Services\Mercator;

use App\Services\Mercator\Contracts\MercatorClientInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MercatorClient implements MercatorClientInterface
{
    private ?string $accessToken = null;

    public function __construct(private readonly array $config) {}

    // -------------------------------------------------------------------------
    // Session
    // -------------------------------------------------------------------------

    public function authenticate(): void
    {
        $response = Http::post($this->url('login'), [
            'login'    => $this->config['login'],
            'password' => $this->config['password'],
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Authentification Mercator échouée : ' . $response->status()
            );
        }

        $this->accessToken = $response->json('access_token');
    }

    // -------------------------------------------------------------------------
    // Buildings (pour résolution building_id)
    // -------------------------------------------------------------------------

    public function getBuildings(): array
    {
        $response = $this->request()->get($this->url('buildings'), [
            'per_page' => 1000,
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Erreur lors de la récupération des buildings Mercator : ' . $response->status()
            );
        }

        return $this->extractItems($response);
    }

    // -------------------------------------------------------------------------
    // CRUD générique (workstations, phones, peripherals…)
    // -------------------------------------------------------------------------

    public function getAll(string $endpoint): array
    {
        $response = $this->request()->get($this->url($endpoint), [
            'per_page' => 1000,
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Erreur lors de la récupération de {$endpoint} : " . $response->status()
            );
        }

        return $this->extractItems($response);
    }

    public function create(string $endpoint, array $payload): array
    {
        $response = $this->request()->post($this->url($endpoint), $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                "Erreur lors de la création dans {$endpoint} : " . $response->status()
                . ' — ' . $response->body()
            );
        }

        return $response->json() ?? [];
    }

    public function update(string $endpoint, int $id, array $payload): array
    {
        $response = $this->request()->put($this->url("{$endpoint}/{$id}"), $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                "Erreur lors de la mise à jour de {$endpoint}/{$id} : " . $response->status()
                . ' — ' . $response->body()
            );
        }

        return $response->json() ?? [];
    }

    public function delete(string $endpoint, int $id): void
    {
        $response = $this->request()->delete($this->url("{$endpoint}/{$id}"));

        if ($response->failed()) {
            throw new RuntimeException(
                "Erreur lors de la suppression de {$endpoint}/{$id} : " . $response->status()
            );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Extrait le tableau d'items depuis la réponse Mercator.
     *
     * Gère les deux formats possibles :
     *   - Paginé Laravel  : {"data": [...], "links": {...}, "meta": {...}}
     *   - Tableau direct  : [{...}, {...}, ...]
     */
    private function extractItems(\Illuminate\Http\Client\Response $response): array
    {
        $json = $response->json();

        if (! is_array($json)) {
            return [];
        }

        // Format paginé : clé "data" présente
        if (array_key_exists('data', $json) && is_array($json['data'])) {
            return $json['data'];
        }

        // Format tableau direct (liste de objets indexés numériquement)
        if (array_is_list($json)) {
            return $json;
        }

        return [];
    }

    private function request(): PendingRequest
    {
        if (! $this->accessToken) {
            throw new RuntimeException('MercatorClient non authentifié. Appeler authenticate() d\'abord.');
        }

        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ]);
    }

    private function url(string $endpoint): string
    {
        return rtrim($this->config['url'], '/') . '/api/' . $endpoint;
    }
}
