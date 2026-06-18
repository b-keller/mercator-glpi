<?php

namespace App\Services\Glpi\Contracts;

interface SupportsGlpiItemDetail
{
    /**
     * Paramètres supplémentaires utilisés pour récupérer chaque item individuellement
     * (GET /apirest.php/{itemtype}/{id}) après la requête de collection.
     *
     * Certains paramètres GLPI (with_networkports, with_devices, with_disks,
     * with_infocoms…) ne sont pas fiables sur les requêtes de collection paginées
     * (ils peuvent faire échouer silencieusement la requête et renvoyer 0 item) :
     * ils doivent être demandés item par item.
     */
    public function glpiDetailParams(): array;
}
