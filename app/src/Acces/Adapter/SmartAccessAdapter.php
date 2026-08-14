<?php

declare(strict_types=1);

namespace App\Acces\Adapter;

use App\Acces\Dto\EtatControleurDto;
use App\Acces\Dto\EvenementPassageDto;
use App\Acces\Dto\OuvertureContexte;
use App\Acces\Dto\ResultatCommande;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\ListeRevocation;
use App\Acces\Port\PiloteAcces;

/**
 * Squelette d'adaptateur pour le logiciel SmartAccess / lecteurs iDTRONIC (§2.2, Risque n°1). Le
 * protocole (OSDP et/ou API) n'est pas spécifié. Comme `ItboxAdapter`, ne pas activer tant que le
 * contrat n'est pas cadré avec IT Cotation.
 */
final class SmartAccessAdapter implements PiloteAcces
{
    public function ouvrir(Equipement $equipement, OuvertureContexte $contexte): ResultatCommande
    {
        throw new \RuntimeException('SmartAccessAdapter : protocole OSDP/API non cadré (à confirmer avec IT Cotation).');
    }

    public function recevoirEvenement(EvenementPassageDto $evenement): void
    {
        throw new \RuntimeException('SmartAccessAdapter : protocole OSDP/API non cadré (à confirmer avec IT Cotation).');
    }

    public function heartbeat(Controleur $controleur): EtatControleurDto
    {
        throw new \RuntimeException('SmartAccessAdapter : protocole OSDP/API non cadré (à confirmer avec IT Cotation).');
    }

    public function pousserListeRevocation(Controleur $controleur, ListeRevocation $liste): ResultatCommande
    {
        throw new \RuntimeException('SmartAccessAdapter : protocole OSDP/API non cadré (à confirmer avec IT Cotation).');
    }
}
