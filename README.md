# mercator-glpi

> Connecteur de synchronisation **GLPI → Mercator** — synchronise automatiquement les postes de travail, applications, périphériques et téléphones de votre inventaire GLPI vers la cartographie du SI Mercator.

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://php.net)
[![Laravel Zero](https://img.shields.io/badge/Laravel%20Zero-11.x-red)](https://laravel-zero.com)
[![Licence GPL](https://img.shields.io/badge/Licence-GPL-green)](LICENSE)

---

## Table des matières

- [Ce que fait ce connecteur](#ce-que-fait-ce-connecteur)
- [Architecture](#architecture)
- [Prérequis](#prérequis)
- [Installation](#installation)
- [Configuration](#configuration)
- [Utilisation](#utilisation)
- [Types synchronisés](#types-synchronisés)
- [Logique de synchronisation](#logique-de-synchronisation)
- [Planification automatique](#planification-automatique)
- [Tests](#tests)
- [Étendre le connecteur](#étendre-le-connecteur)
- [Dépannage](#dépannage)

---

## Ce que fait ce connecteur

`mercator-glpi` est une application PHP en ligne de commande qui interroge l'API REST de **GLPI** et met à jour **Mercator** (cartographie du système d'information) en conséquence :

| Source GLPI | Destination Mercator | Clé de réconciliation |
|---|---|---|
| `Computer` | `workstations` | `name` |
| `Software` | `applications` | `name` |
| `Peripheral` | `peripherals` | `name` |
| `Phone` | `phones` | `name` |
| `Computer_SoftwareVersion` | liens `application_workstation` | — |

La synchronisation est **unidirectionnelle** : GLPI est la source de vérité, Mercator est la destination. Aucune écriture n'est effectuée dans GLPI.

Les éléments présents dans Mercator mais absents de GLPI sont **conservés tels quels** (comportement non-destructif).

---

## Architecture

```
mercator-glpi/
├── app/
│   ├── Commands/
│   │   └── GlpiSyncCommand.php          # Commande artisan : glpi:sync
│   ├── Services/
│   │   ├── Glpi/
│   │   │   ├── Contracts/
│   │   │   │   ├── GlpiClientInterface.php
│   │   │   │   └── SyncHandler.php      # Interface des handlers
│   │   │   ├── Handlers/                # Un handler par type d'item
│   │   │   │   ├── WorkstationSyncHandler.php
│   │   │   │   ├── ApplicationSyncHandler.php
│   │   │   │   ├── PeripheralSyncHandler.php
│   │   │   │   └── PhoneSyncHandler.php
│   │   │   ├── Mappers/                 # Mapping champs GLPI → Mercator
│   │   │   │   ├── WorkstationMapper.php
│   │   │   │   ├── ApplicationMapper.php
│   │   │   │   ├── PeripheralMapper.php
│   │   │   │   └── PhoneMapper.php
│   │   │   ├── GlpiClient.php           # Client HTTP GLPI (API v1)
│   │   │   └── GlpiSyncService.php      # Orchestration de la sync
│   │   └── Mercator/
│   │       ├── Contracts/
│   │       │   └── MercatorClientInterface.php
│   │       └── MercatorClient.php       # Client HTTP Mercator
│   └── Providers/
│       └── AppServiceProvider.php
├── config/
│   └── glpi.php                         # Configuration (lu depuis .env)
└── tests/
    ├── Unit/                            # Tests unitaires (mappers, service)
    ├── Feature/                         # Tests d'intégration (commande)
    └── Fixtures/                        # Données de test JSON
```

Le connecteur repose sur le patron **Strategy** : chaque type d'item implémente l'interface `SyncHandler` avec ses propres paramètres de requête GLPI, son endpoint Mercator et sa logique de mapping. Ajouter un nouveau type revient à créer un handler et un mapper sans toucher au reste.

---

## Prérequis

| Composant | Version minimale |
|---|---|
| PHP | 8.2 |
| Composer | 2.x |
| GLPI | 10.x |
| Mercator | dernière version stable |
| Extension PHP `curl` | — |
| Extension PHP `json` | — |

**Côté GLPI**, l'API REST doit être activée et les tokens configurés (voir [Configuration GLPI](#configuration-glpi)).

---

## Installation

```bash
# 1. Cloner le dépôt
git clone https://github.com/dbarzin/mercator-glpi
cd mercator-glpi

# 2. Installer les dépendances
composer install --no-dev --optimize-autoloader

# 3. Copier et compléter la configuration
cp .env.example .env
```

### GLPI en Docker (développement / test)

Un stack Docker est fourni pour tester contre une instance GLPI locale :

```bash
# Démarrer GLPI
./glpi.sh start

# Voir le statut
./glpi.sh status

# Consulter les logs
./glpi.sh logs

# Arrêter
./glpi.sh stop
```

GLPI sera accessible sur `http://localhost:8080`. Un wizard d'installation s'affiche au premier démarrage.

---

## Configuration

### Fichier `.env`

```ini
# ── GLPI ──────────────────────────────────────────────────────────────────────
GLPI_URL=https://glpi.exemple.fr
GLPI_APP_TOKEN=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
GLPI_USER_TOKEN=yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy

# ── Mercator ──────────────────────────────────────────────────────────────────
MERCATOR_URL=https://mercator.exemple.fr
MERCATOR_LOGIN=admin@exemple.fr
MERCATOR_PASSWORD=motdepasse

# ── Synchronisation ───────────────────────────────────────────────────────────
SYNC_DRY_RUN=false
```

### Configuration GLPI

#### 1. Activer l'API REST

> **Configuration → Générale → API**
> - Activer l'API REST : **Oui**
> - Activer la connexion avec credentials : **Oui**

#### 2. Créer l'`APP_TOKEN`

> **Configuration → Générale → API → Clients de l'API → Ajouter**
> - Nom : `mercator-glpi-connector`
> - Copier le token généré → `GLPI_APP_TOKEN`

#### 3. Créer le `USER_TOKEN`

> **Mon compte → Mes préférences → Accès distant (API) → Régénérer**
> - Copier le token → `GLPI_USER_TOKEN`

Le compte associé doit avoir accès en lecture aux types à synchroniser (Ordinateurs, Logiciels, Périphériques, Téléphones).

#### 4. Vérifier la connexion

```bash
php application glpi:sync --dry-run --type=workstations
```

### Résolution de la localisation

La localisation GLPI (`locations_id`) est résolue vers un `building_id` Mercator par **correspondance exacte du nom** (insensible à la casse). Si le nom de la salle GLPI correspond au nom d'un bâtiment Mercator, le `building_id` et le `site_id` associé sont automatiquement renseignés.

```
GLPI : locations_id = "Salle 101"
         ↓ (lookup insensible à la casse)
Mercator : buildings.name = "Salle 101" → building_id = 5, site_id = 1
```

Si aucun bâtiment ne correspond, `building_id` et `site_id` restent `null`.

---

## Utilisation

### Synchronisation complète

```bash
php application glpi:sync
```

Lance la synchronisation de tous les types dans l'ordre :
`workstations → applications → peripherals → phones → links`

### Synchronisation d'un type spécifique

```bash
php application glpi:sync --type=workstations
php application glpi:sync --type=applications
php application glpi:sync --type=peripherals
php application glpi:sync --type=phones
php application glpi:sync --type=links        # liens workstation↔application
```

### Mode simulation (dry-run)

Simule la synchronisation sans écrire dans Mercator. Utile pour valider la configuration avant le premier run réel.

```bash
php application glpi:sync --dry-run
```

### Exemple de sortie

```
╔══════════════════════════════════════╗
║   Mercator ← GLPI Synchronisation    ║
╚══════════════════════════════════════╝
  Authentification GLPI…
  ✔ GLPI connecté
  Authentification Mercator…
  ✔ Mercator connecté

  ─── workstations ───
  +3 créés  ~12 mis à jour  -0 supprimés  0 OLD  0 erreurs

  ─── applications ───
  +1 créés  ~8 mis à jour  -0 supprimés  0 OLD  0 erreurs

  ─── peripherals ───
  +2 créés  ~5 mis à jour  -0 supprimés  0 OLD  0 erreurs

  ─── phones ───
  +0 créés  ~4 mis à jour  -0 supprimés  0 OLD  0 erreurs

  ─── links ───
  ~10 mis à jour  2 ignorés  0 erreurs

─── Résumé ───────────────────────────────
  +6 créés  ~39 mis à jour  -0 supprimés  0 OLD  0 erreurs
  Durée : 3.2s
```

---

## Types synchronisés

### Postes de travail (`Computer` → `workstations`)

| Champ GLPI | Champ Mercator |
|---|---|
| `name` | `name` |
| `comment` | `description` |
| `computertypes_id` | `type` |
| `manufacturers_id` | `manufacturer` |
| `computermodels_id` | `model` |
| `serial` | `serial_number` |
| `otherserial` | `inventaire_number` |
| `operatingsystems_id` | `operating_system` |
| `states_id` | `status` |
| `users_id` | `other_user` |
| `locations_id` | `building_id` + `site_id` |
| Premier port réseau IPv4 | `address_ip` |
| Premier port MAC | `mac_address` |
| `ram` | `memory` |
| Premier CPU (`_devices`) | `cpu` |
| Somme des disques | `disk` |
| `infocoms.buy_date` | `purchase_date` |
| `infocoms.warranty_expiration` | `warranty_end_date` |
| `"GLPI"` | `update_source` |

### Logiciels (`Software` → `applications`)

| Champ GLPI | Champ Mercator |
|---|---|
| `name` | `name` + `product` |
| `comment` | `description` |
| `manufacturers_id` | `vendor` + `editor` |
| `softwarecategories_id` | `type` |
| `users_id_tech` | `responsible` |
| `date` | `install_date` |

### Périphériques (`Peripheral` → `peripherals`)

| Champ GLPI | Champ Mercator |
|---|---|
| `name` | `name` |
| `comment` | `description` |
| `manufacturers_id` | `vendor` |
| `peripheraltypes_id` | `type` |
| `peripheralmodels_id` | `product` |
| `users_id_tech` | `responsible` |
| `locations_id` | `building_id` + `site_id` |
| Premier port réseau IPv4 | `address_ip` |

### Téléphones (`Phone` → `phones`)

| Champ GLPI | Champ Mercator |
|---|---|
| `name` | `name` |
| `comment` | `description` |
| `manufacturers_id` | `vendor` |
| `phonetypes_id` | `type` |
| `phonemodels_id` | `product` |
| `locations_id` | `building_id` + `site_id` |
| Premier port réseau IPv4 | `address_ip` |

### Liens logiciels (`links`)

Pour chaque poste de travail présent dans Mercator, le connecteur récupère les logiciels installés depuis GLPI (`GET /Computer/{id}?with_softwares=1`) et met à jour les associations `application_workstation` dans Mercator.

---

## Logique de synchronisation

```
Pour chaque item GLPI :
  ├─ Si le nom existe dans Mercator → MISE À JOUR (PUT)
  └─ Sinon                          → CRÉATION (POST)

Pour chaque item Mercator absent de GLPI :
  └─ Conservé tel quel (aucune modification)
```

**Traçabilité** : lors de la création, le connecteur embarque l'identifiant GLPI dans le champ `description` sous la forme `[glpi_id:42]`. Cela permet d'identifier l'origine de chaque entrée Mercator.

**Non-destructif** : le connecteur ne supprime jamais d'entrée dans Mercator. Les éléments présents dans Mercator mais absents de GLPI sont ignorés — ils peuvent avoir été créés manuellement ou provenir d'une autre source.

---

## Planification automatique

Pour une synchronisation quotidienne à 02h00 via le planificateur Laravel :

```bash
# Ajouter au crontab du serveur
* * * * * cd /opt/mercator-glpi && php application schedule:run >> /dev/null 2>&1
```

La fréquence est configurable dans `app/Console/Kernel.php` :

```php
$schedule->command('glpi:sync')->dailyAt('02:00');
```

Les logs de synchronisation sont écrits dans `storage/logs/laravel.log`.

---

## Tests

```bash
# Tous les tests
./vendor/bin/pest

# Suite spécifique
./vendor/bin/pest tests/Unit/WorkstationMapperTest.php
./vendor/bin/pest tests/Unit/GlpiSyncServiceTest.php
./vendor/bin/pest tests/Unit/GlpiSyncServiceLinksTest.php

# Avec couverture de code
./vendor/bin/pest --coverage
```

Les tests utilisent **Mockery** pour simuler les clients HTTP (aucun appel réseau réel). Les fixtures JSON dans `tests/Fixtures/` représentent des réponses réalistes des APIs GLPI et Mercator.

| Suite | Fichier | Ce qui est testé |
|---|---|---|
| Unit | `WorkstationMapperTest` | Mapping de tous les champs workstation |
| Unit | `ApplicationMapperTest` | Mapping des champs application |
| Unit | `PeripheralMapperTest` | Mapping des champs périphérique |
| Unit | `PhoneMapperTest` | Mapping des champs téléphone |
| Unit | `GlpiSyncServiceTest` | Logique create/update/orphelin |
| Unit | `GlpiSyncServiceLinksTest` | Synchronisation des liens |
| Feature | `GlpiSyncCommandTest` | Intégration commande CLI |

---

## Étendre le connecteur

Pour ajouter un nouveau type d'item GLPI (ex. `NetworkEquipment` → `network_devices`) :

**1. Créer le mapper**

```php
// app/Services/Glpi/Mappers/NetworkDeviceMapper.php
class NetworkDeviceMapper
{
    public function map(array $item, array $context): array
    {
        return array_filter([
            'name'        => $item['name'],
            'description' => '[glpi_id:' . $item['id'] . '] ' . ($item['comment'] ?? ''),
            // ... autres champs
        ], fn($v) => $v !== null);
    }
}
```

**2. Créer le handler**

```php
// app/Services/Glpi/Handlers/NetworkDeviceSyncHandler.php
class NetworkDeviceSyncHandler implements SyncHandler
{
    public function glpiItemType(): string    { return 'NetworkEquipment'; }
    public function mercatorEndpoint(): string { return 'network_devices'; }
    public function processOrphans(): bool    { return false; }
    public function glpiQueryParams(): array  { return ['range' => '0-999', 'expand_dropdowns' => 1]; }
    public function map(array $item, array $context): array { return $this->mapper->map($item, $context); }
}
```

**3. Enregistrer dans `AppServiceProvider` et `GlpiSyncCommand`**

```php
// AppServiceProvider::register()
$this->app->singleton(NetworkDeviceSyncHandler::class, fn($app) =>
    new NetworkDeviceSyncHandler($app->make(NetworkDeviceMapper::class))
);

// GlpiSyncCommand::$handlers
'network_devices' => NetworkDeviceSyncHandler::class,
```

---

## Dépannage

### Authentification GLPI échoue

```
✘ Échec de l'authentification : 401
```

- Vérifier que l'API REST est activée dans GLPI (**Configuration → Générale → API**)
- Vérifier les valeurs de `GLPI_APP_TOKEN` et `GLPI_USER_TOKEN` dans le `.env`
- Tester manuellement :
  ```bash
  curl -H "Authorization: user_token $USER_TOKEN" \
       -H "App-Token: $APP_TOKEN" \
       "$GLPI_URL/apirest.php/initSession"
  ```

### Aucun lien workstation↔application créé

Les liens ne sont créés que si :
1. Le poste de travail existe dans Mercator (sync `workstations` effectuée)
2. Le logiciel existe dans Mercator (sync `applications` effectuée)
3. Le logiciel est installé sur le poste dans GLPI (via GLPI Agent ou saisie manuelle)

Vérifier les logs : `grep "\[links\]" storage/logs/laravel.log`

### La localisation n'est pas résolue (`building_id` null)

Le nom de la **salle GLPI** doit correspondre exactement (insensible à la casse) au nom d'un **bâtiment Mercator**. Vérifier :

```bash
# Noms des salles GLPI
curl -H "Session-Token: $SESSION" -H "App-Token: $APP_TOKEN" \
  "$GLPI_URL/apirest.php/Location" | jq '.[].name'

# Noms des bâtiments Mercator
curl -H "Authorization: Bearer $TOKEN" \
  "$MERCATOR_URL/api/buildings" | jq '.data[].name'
```

### Logs de débogage

Passer le niveau de log en `debug` dans `config/logging.php` pour voir les payloads envoyés à Mercator :

```php
'level' => env('LOG_LEVEL', 'debug'),
```
