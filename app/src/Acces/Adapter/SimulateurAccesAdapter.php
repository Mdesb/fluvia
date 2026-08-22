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
use App\Acces\Enum\EtatControleur;
use App\Acces\Port\AccessDriverCapabilities;
use App\Acces\Enum\CredentialEncoding;
use App\Acces\Enum\DecisionPoint;
use App\Acces\Enum\PassageReporting;
use App\Acces\Enum\RevocationCapability;
use App\Acces\Port\PiloteAcces;

/**
 * Simulateur logiciel (§2.2 du plan) : ouvre/refuse en mémoire, faux heartbeat toujours « en ligne ».
 * Débloque le développement et les tests sans matériel réel (CA-3/4/8). Sélectionné par défaut en
 * dev/test via l'alias `App\Acces\Port\PiloteAcces` (config/services.yaml).
 */
final class SimulateurAccesAdapter implements PiloteAcces
{
    public function capabilities(): AccessDriverCapabilities
    {
        // Le simulateur tranche en memoire, cote serveur : il peut donc tout, immediatement. Il
        // n'encode rien en revanche — ecrire une autorisation sur un medium suppose un encodeur
        // physique, que rien ne simule utilement (ACC-2).
        return new AccessDriverCapabilities(
            DecisionPoint::Server,
            RevocationCapability::Immediate,
            CredentialEncoding::None,
            PassageReporting::RealTime,
        );
    }
    /** @var list<array{equipement: string, manuelle: bool}> Journal des ouvertures, pour les tests. */
    private array $ouvertures = [];

    public function ouvrir(Equipement $equipement, OuvertureContexte $contexte): ResultatCommande
    {
        $this->ouvertures[] = ['equipement' => (string) $equipement->getId(), 'manuelle' => $contexte->manuelle];

        return ResultatCommande::ok('Ouverture simulée de ' . $equipement->getLibelle());
    }

    public function recevoirEvenement(EvenementPassageDto $evenement): void
    {
        // In-process : l'ingestion réelle passe par POST /acces/passages (ValidationPassageHandler).
    }

    public function heartbeat(Controleur $controleur): EtatControleurDto
    {
        return new EtatControleurDto(EtatControleur::EnLigne, new \DateTimeImmutable());
    }

    public function pousserListeRevocation(Controleur $controleur, ListeRevocation $liste): ResultatCommande
    {
        return ResultatCommande::ok(sprintf('Liste de révocation v%d poussée à %s (simulée).', $liste->getVersion(), $controleur->getLibelle()));
    }

    /** @return list<array{equipement: string, manuelle: bool}> */
    public function ouvertures(): array
    {
        return $this->ouvertures;
    }
}
