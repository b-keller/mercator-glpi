<?php

namespace App\Services\Glpi;

use App\Services\Glpi\Contracts\GlpiClientInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GlpiClient implements GlpiClientInterface
{
    private ?string $sessionToken = null;

    public function __construct(private readonly array $config) {}

    // -------------------------------------------------------------------------
    // Session
    // -------------------------------------------------------------------------

    public function authenticate(): void
    {
        $response = Http::withHeaders([
            'Authorization' => 'user_token ' . $this->config['user_token'],
            'App-Token'     => $this->config['app_token'],
        ])->get($this->url('initSession'));

        if ($response->failed()) {
            throw new RuntimeException(
                'Authentification GLPI échouée : ' . $response->status()
            );
        }

        $this->sessionToken = $response->json('session_token');
    }

    public function killSession(): void
    {
        if (! $this->sessionToken) {
            return;
        }

        $this->request()->get($this->url('killSession'));
        $this->sessionToken = null;
    }

    // -------------------------------------------------------------------------
    // Computers
    // -------------------------------------------------------------------------

    /**
     * Récupère tous les ordinateurs GLPI (< 1000 postes supposés).
     *
     * @param  array  $extraParams  Paramètres supplémentaires (with_devices, etc.)
     */
    public function getComputers(array $extraParams = []): array
    {
        $params = array_merge([
            'range'             => '0-999',
            'expand_dropdowns'  => 1,
            'with_networkports' => 1,
            'with_devices'      => 1,
            'with_disks'        => 1,
            'with_infocoms'     => 1,
        ], $extraParams);

        $response = $this->request()->get($this->url('Computer'), $params);

        if ($response->status() === 206) {
            // 206 Partial Content = résultats dans range, c'est normal
            return $response->json() ?? [];
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Erreur lors de la récupération des ordinateurs GLPI : ' . $response->status()
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Récupère un item GLPI par son ID avec ses données annexes (with_softwares, etc.)
     */
    public function getItem(string $itemType, int $id, array $params = []): array
    {
        $response = $this->request()->get($this->url("{$itemType}/{$id}"), $params);

        if ($response->failed()) {
            throw new RuntimeException(
                "Erreur lors de la récupération de {$itemType}/{$id} : " . $response->status()
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Récupère les items d'un itemtype donné (Phone, Peripheral…)
     */
    public function getItems(string $itemType, array $extraParams = []): array
    {
        $params = array_merge([
            'range'            => '0-999',
            'expand_dropdowns' => 1,
        ], $extraParams);

        $response = $this->request()->get($this->url($itemType), $params);

        if ($response->failed() && $response->status() !== 206) {
            throw new RuntimeException(
                "Erreur lors de la récupération de {$itemType} : " . $response->status()
            );
        }

        return $response->json() ?? [];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function request(): PendingRequest
    {
        if (! $this->sessionToken) {
            throw new RuntimeException('GlpiClient non authentifié. Appeler authenticate() d\'abord.');
        }

        return Http::withHeaders([
            'Session-Token' => $this->sessionToken,
            'App-Token'     => $this->config['app_token'],
            'Content-Type'  => 'application/json',
        ]);
    }

    private function url(string $endpoint): string
    {
        return rtrim($this->config['url'], '/') . '/apirest.php/' . $endpoint;
    }
}
