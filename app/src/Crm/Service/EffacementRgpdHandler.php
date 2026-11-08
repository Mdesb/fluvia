<?php

declare(strict_types=1);

namespace App\Crm\Service;

use App\Crm\Entity\Client;
use App\Crm\Entity\DemandeRGPD;
use App\Crm\Enum\PrivacyRequestOrigin;
use App\Crm\Enum\StatutClient;
use App\Crm\Enum\StatutDemandeRgpd;
use App\Crm\Enum\TypeDemandeRgpd;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Droit à l'effacement / anonymisation (RG-M4-08/09, CA-17, §3.3 plan-crm.md) : purge les champs
 * nominatifs de `Client` **uniquement** (aucune cascade : les autres entités M4 ne portent pas de PII,
 * décision structurante §1). Aucun impact sur M2 : `Vente.client`/`LigneVente.beneficiaire` restent
 * des UUID logiques sans FK dure — l'intégrité NF525 n'est jamais rompue par une anonymisation M4.
 *
 * C'est le SEUL endroit qui anonymise. La demande d'une personne (`traiter`), l'épuration sélective
 * et la purge à échéance (`anonymiseWithoutRequest`) y passent toutes : jusqu'au #344, la tâche de
 * conservation recopiait l'anonymisation à la main (#207), et deux copies d'un effacement finissent
 * toujours par effacer des champs différents.
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

        $this->anonymiser($client, 'rgpd:anonymisation');

        $demande->setStatut(StatutDemandeRgpd::Realisee);
        $demande->setDateTraitement(new \DateTimeImmutable());
        $demande->setTraitePar($administrateur);

        return $client;
    }

    /**
     * Anonymise une fiche que personne n'a demandé à effacer — épuration ou conservation échue — et
     * en laisse la preuve : une demande réalisée, datée, d'origine `purge` ou `retention`, signée de
     * l'administrateur (`null` pour la tâche planifiée, qui n'a pas d'auteur humain).
     *
     * Persiste la demande ; le `flush` reste à l'appelant (une épuration flushe par lot).
     */
    public function anonymiseWithoutRequest(Client $client, PrivacyRequestOrigin $origin, ?Utilisateur $administrateur): DemandeRGPD
    {
        if ($origin === PrivacyRequestOrigin::Request) {
            throw new \LogicException('Une demande de la personne se traite par traiter(), pas ici.');
        }
        if ($client->getStatut() === StatutClient::Anonymise) {
            throw new ConflictHttpException('Fiche déjà anonymisée.');
        }

        $demande = new DemandeRGPD(TypeDemandeRgpd::Anonymisation);
        $demande->setClient($client);
        $demande->setOrigin($origin);
        $this->anonymiser($client, $origin === PrivacyRequestOrigin::Purge ? 'rgpd:epuration' : 'rgpd:conservation-echue');
        $demande->setStatut(StatutDemandeRgpd::Realisee);
        $demande->setDateTraitement(new \DateTimeImmutable());
        $demande->setTraitePar($administrateur);
        $this->em->persist($demande);

        return $demande;
    }

    /** Purge les champs nominatifs, conserve statut/agrégats pour l'historique comptable/statistique. */
    private function anonymiser(Client $client, string $trace): void
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
        // La référence dans l'ancien logiciel relie la fiche anonyme à une identité restée dans un
        // export de reprise : la garder, c'est garder la clé de ré-identification.
        $client->setExternalRef(null);
        $client->setStatut(StatutClient::Anonymise);
        $client->setDateMaj(new \DateTimeImmutable());
        $client->setMajPar($trace);
    }
}
