<?php

declare(strict_types=1);

namespace App\Crm\Service;

use App\Crm\Entity\Client;
use App\Crm\Entity\DemandeRGPD;
use App\Crm\Enum\StatutClient;
use App\Crm\Enum\StatutDemandeRgpd;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Droit à l'effacement / anonymisation (RG-M4-08/09, CA-17, §3.3 plan-crm.md) : purge les champs
 * nominatifs de `Client` **uniquement** (aucune cascade : les autres entités M4 ne portent pas de PII,
 * décision structurante §1). Aucun impact sur M2 : `Vente.client`/`LigneVente.beneficiaire` restent
 * des UUID logiques sans FK dure — l'intégrité NF525 n'est jamais rompue par une anonymisation M4.
 */
final class EffacementRgpdHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function traiter(DemandeRGPD $demande, Utilisateur $administrateur): Client
    {
        if ($demande->getStatut() === StatutDemandeRgpd::Realisee) {
            throw new ConflictHttpException('Demande RGPD déjà réalisée : immuable (RG-SOCLE-07).');
        }
        if ($demande->getStatut() === StatutDemandeRgpd::Refusee) {
            throw new ConflictHttpException('Demande RGPD refusée : ne peut plus être traitée.');
        }

        $client = $demande->getClient();
        if ($client === null) {
            throw new ConflictHttpException('Demande RGPD sans client associé.');
        }

        $this->anonymiser($client);

        $demande->setStatut(StatutDemandeRgpd::Realisee);
        $demande->setDateTraitement(new \DateTimeImmutable());
        $demande->setTraitePar($administrateur);

        return $client;
    }

    /** Purge les champs nominatifs, conserve statut/agrégats pour l'historique comptable/statistique. */
    private function anonymiser(Client $client): void
    {
        $client->setCivilite(null);
        $client->setNom(null);
        $client->setPrenom(null);
        $client->setRaisonSociale(null);
        $client->setSiret(null);
        $client->setEmail(null);
        $client->setTelephone(null);
        $client->setAdresse(null);
        $client->setDateNaissance(null);
        $client->setChampManuel(null);
        $client->setStatut(StatutClient::Anonymise);
        $client->setDateMaj(new \DateTimeImmutable());
        $client->setMajPar('rgpd:anonymisation');
    }
}
