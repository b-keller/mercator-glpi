<?php

$parseIds = fn(?string $s): array => array_values(
    array_filter(array_map('trim', explode(',', $s ?? '')))
);

return [

    'glpi' => [
        'url'        => env('GLPI_URL'),
        'app_token'  => env('GLPI_APP_TOKEN'),
        'user_token' => env('GLPI_USER_TOKEN'),
        'entity_id'  => filled(env('GLPI_ENTITY_ID')) ? (int) env('GLPI_ENTITY_ID') : null,
    ],

    'mercator' => [
        'url'      => env('MERCATOR_URL'),
        'login'    => env('MERCATOR_LOGIN'),
        'password' => env('MERCATOR_PASSWORD'),
    ],

    'sync' => [
        'dry_run' => env('SYNC_DRY_RUN', false),
    ],

    // Filtrage par statut GLPI (states_id) — vide = tous statuts acceptés
    'allowed_states' => [
        'default'          => $parseIds(env('GLPI_ALLOWED_STATES')),
        'Computer'         => $parseIds(env('GLPI_ALLOWED_STATES_COMPUTERS')),
        'Phone'            => $parseIds(env('GLPI_ALLOWED_STATES_PHONES')),
        'Peripheral'       => $parseIds(env('GLPI_ALLOWED_STATES_PERIPHERALS')),
        'Software'         => $parseIds(env('GLPI_ALLOWED_STATES_SOFTWARE')),
        'NetworkEquipment' => $parseIds(env('GLPI_ALLOWED_STATES_NETWORK_EQUIPMENT')),
        'Rack'             => $parseIds(env('GLPI_ALLOWED_STATES_RACKS')),
    ],

    // Routage des Computer par sous-type (computertypes_id) — IDs ou noms GLPI
    'computer_types' => [
        'workstations'     => $parseIds(env('GLPI_COMPUTER_TYPES_WORKSTATIONS')),
        'logical_servers'  => $parseIds(env('GLPI_COMPUTER_TYPES_LOGICAL_SERVERS')),
        'physical_servers' => $parseIds(env('GLPI_COMPUTER_TYPES_PHYSICAL_SERVERS')),
    ],

];
