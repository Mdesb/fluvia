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
use App\Acces\Port\AccessDriverCapabilities;
use App\Acces\Port\PiloteAcces;

/**
 * Squelette d'adaptateur pour le concentrateur ITBOX (§2.2, Risque n°1). Le contrat d'échange (API
 * REST/temps réel : commande d'ouverture, remontée d'événement, heartbeat, format de la liste de
 * révocation embarquée) n'est pas encore spécifié par IT Cotation. Ne pas activer en production tant
 * que le protocole n'est pas cadré : lève une exception explicite pour éviter un faux silence.
 *
 * ⚠ À CADRER AVEC IT COTATION avant intégration matérielle réelle (point ouvert n°1 de la spec).
 */
final class ItboxAdapter implements PiloteAcces
{
    public function capabilities(): AccessDriverCapabilities
    {
        // Protocole non cadre par IT Cotation (E-4 du registre des bloqueurs externes, D19) : les
        // quatre operations levent une exception. Declarer au plus pessimiste est la seule honnetete
        // possible — supposer des capacites non verifiees reproduirait le defaut que D17 corrige.
        return AccessDriverCapabilities::unspecified();
    }
    public function ouvrir(Equipement $equipement, OuvertureContexte $contexte): ResultatCommande
    {
        throw new \RuntimeException('ItboxAdapter : protocole ITBOX non cadré (à confirmer avec IT Cotation).');
    }

    public function recevoirEvenement(EvenementPassageDto $evenement): void
    {
        throw new \RuntimeException('ItboxAdapter : protocole ITBOX non cadré (à confirmer avec IT Cotation).');
    }

    public function heartbeat(Controleur $controleur): EtatControleurDto
    {
        throw new \RuntimeException('ItboxAdapter : protocole ITBOX non cadré (à confirmer avec IT Cotation).');
    }

    public function pousserListeRevocation(Controleur $controleur, ListeRevocation $liste): ResultatCommande
    {
        throw new \RuntimeException('ItboxAdapter : protocole ITBOX non cadré (à confirmer avec IT Cotation).');
    }
}
