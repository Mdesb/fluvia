<?php

declare(strict_types=1);

namespace App\Acces\Port;

use App\Acces\Dto\EtatControleurDto;
use App\Acces\Dto\EvenementPassageDto;
use App\Acces\Dto\OuvertureContexte;
use App\Acces\Dto\ResultatCommande;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\ListeRevocation;

/**
 * Port principal d'intégration matériel (§2.1 du plan) : contrat métier agnostique du transport.
 * Aucun protocole matériel (OSDP, API REST/temps réel ITBOX/SmartAccess) n'y transparaît — confiné
 * aux adaptateurs (§2.2). Sélection par alias configurable (`config/services.yaml`).
 */
interface PiloteAcces
{
    /**
     * Ce que ce pilote sait reellement faire (D17). **A implementer en premier** dans tout nouvel
     * adaptateur : la plateforme s'y fie pour ne promettre que le tenable, et une declaration
     * optimiste est pire que pas d'adaptateur du tout.
     */
    public function capabilities(): AccessDriverCapabilities;
    /** Commande l'ouverture physique d'un équipement (franchissement autorisé ou ouverture manuelle). */
    public function ouvrir(Equipement $equipement, OuvertureContexte $contexte): ResultatCommande;

    /** Ingestion d'un événement de passage remonté par le matériel (scan au lecteur). */
    public function recevoirEvenement(EvenementPassageDto $evenement): void;

    /** État/vivacité d'un contrôleur (online/offline, horodatage), alimente la supervision. */
    public function heartbeat(Controleur $controleur): EtatControleurDto;

    /** Pousse la liste de révocation embarquée à jour vers le contrôleur (propagation §4.7). */
    public function pousserListeRevocation(Controleur $controleur, ListeRevocation $liste): ResultatCommande;
}
