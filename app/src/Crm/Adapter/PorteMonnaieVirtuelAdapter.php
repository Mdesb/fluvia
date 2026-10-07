<?php

declare(strict_types=1);

namespace App\Crm\Adapter;

use App\Crm\Entity\Client;
use App\Crm\Entity\MouvementPmv;
use App\Crm\Entity\PorteMonnaieVirtuel;
use App\Crm\Enum\StatutPmv;
use App\Crm\Enum\TypeMouvementPmv;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Port\PorteMonnaieVirtuelInterface;
use App\Vente\Port\ResultatDebitPmv;
use App\Vente\Port\SoldePmv;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Implémentation réelle du port PMV (RG-M4-03, §2.2 plan-crm.md). Le débit est un **UPDATE
 * conditionnel atomique** (même patron que `App\Vente\Service\DecrementStockHandler`) : 0 ligne
 * affectée ⇒ refus (solde insuffisant OU PMV expiré/inexistant), sans lever d'exception métier
 * générique — le `PaiementHandler` (M2) traduit le refus en 422 (CA-8).
 */
final class PorteMonnaieVirtuelAdapter implements PorteMonnaieVirtuelInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function solde(Uuid $clientId): ?SoldePmv
    {
        $pmv = $this->trouverPmv($clientId);
        if ($pmv === null) {
            return new SoldePmv(existe: false);
        }

        return new SoldePmv(
            existe: true,
            solde: $pmv->getSolde(),
            statut: $pmv->getStatut()->value,
            dateEcheance: $pmv->getDateEcheance(),
        );
    }

    public function debiter(Uuid $clientId, string $montant, Uuid $venteId): ResultatDebitPmv
    {
        $pmv = $this->trouverPmv($clientId);
        if ($pmv === null) {
            return new ResultatDebitPmv(reussi: false, motifRefus: 'Aucun porte-monnaie virtuel pour ce client.');
        }

        // UPDATE conditionnel atomique (RG-M4-03, §2.2 plan-crm.md) : le statut ET le solde suffisant
        // sont vérifiés dans la même clause WHERE, évitant tout débit concurrent en survente négative.
        $hex = bin2hex($pmv->getId()->toBinary());
        $affectees = (int) $this->connection->executeStatement(
            "UPDATE crm_pmv SET solde = solde - :m WHERE id = UNHEX(:hex) AND statut = 'actif' AND solde >= :m",
            ['m' => $montant, 'hex' => $hex],
        );

        if ($affectees === 0) {
            $this->em->refresh($pmv);
            $motif = $pmv->getStatut() !== StatutPmv::Actif
                ? 'PMV expiré ou inactif (RG-M4-04).'
                : 'Solde PMV insuffisant (RG-M4-03).';

            return new ResultatDebitPmv(reussi: false, motifRefus: $motif);
        }

        $this->em->refresh($pmv);
        $this->journaliser($pmv, TypeMouvementPmv::DebitVente, '-' . $montant, $venteId, null, null);

        return new ResultatDebitPmv(reussi: true);
    }

    public function crediter(Uuid $clientId, string $montant, Uuid $venteId, string $motif): void
    {
        $pmv = $this->trouverPmv($clientId);
        if ($pmv === null) {
            return; // Rien à recréditer si le PMV n'existe plus (cas limite non prévu par le cahier).
        }

        $hex = bin2hex($pmv->getId()->toBinary());
        $this->connection->executeStatement(
            'UPDATE crm_pmv SET solde = solde + :m WHERE id = UNHEX(:hex)',
            ['m' => $montant, 'hex' => $hex],
        );
        $this->em->refresh($pmv);
        $this->journaliser($pmv, TypeMouvementPmv::RemboursementVente, $montant, $venteId, null, $motif);
    }

    public function recreditePourVente(Uuid $clientId, Uuid $venteId): string
    {
        $pmv = $this->trouverPmv($clientId);
        if ($pmv === null) {
            return '0.00';
        }

        // Référence libre `ref_vente_m2` (D58) : comparée en SQL avec UNHEX, jamais en DQL.
        return (string) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(montant), 0) FROM crm_mouvement_pmv WHERE pmv_id = UNHEX(:pmv) AND type = :type AND ref_vente_m2 = UNHEX(:vente)',
            [
                'pmv' => bin2hex($pmv->getId()->toBinary()),
                'type' => TypeMouvementPmv::RemboursementVente->value,
                'vente' => bin2hex($venteId->toBinary()),
            ],
        );
    }

    private function trouverPmv(Uuid $clientId): ?PorteMonnaieVirtuel
    {
        $client = $this->em->getRepository(Client::class)->find($clientId);
        if (!$client instanceof Client) {
            return null;
        }

        return $this->em->getRepository(PorteMonnaieVirtuel::class)->findOneBy(['client' => $client]);
    }

    private function journaliser(PorteMonnaieVirtuel $pmv, TypeMouvementPmv $type, string $montant, ?Uuid $venteId, ?string $canal, ?string $motif): void
    {
        $mouvement = new MouvementPmv($type);
        $mouvement->setPmv($pmv);
        $mouvement->setMontant($montant);
        $mouvement->setSoldeApres($pmv->getSolde());
        $mouvement->setRefVenteM2($venteId);
        $mouvement->setMotif($motif);
        $mouvement->setEtablissement($this->contexte->etablissementActif() ?? $this->etablissementDuClient($pmv));

        $utilisateur = $this->security->getUser();
        $mouvement->setUtilisateur($utilisateur instanceof Utilisateur ? $utilisateur : null);

        // Pas de flush ici : le débit SQL (conditionnel, ci-dessus) est déjà atomique et commité
        // immédiatement (comme `DecrementStockHandler`) ; le mouvement, lui, est persisté dans la
        // même transaction applicative que le Paiement (flush laissé au State Processor appelant).
        $this->em->persist($mouvement);
    }

    /**
     * L'établissement du client, jamais « le premier venu » : `findOneBy([])` rattachait le mouvement
     * à un établissement quelconque, donc peut-être chez un autre client de la plateforme (04/10/2026).
     */
    private function etablissementDuClient(PorteMonnaieVirtuel $pmv): Etablissement
    {
        $etablissement = $pmv->getClient()?->getEtablissementCreation();
        if (!$etablissement instanceof Etablissement) {
            throw new \LogicException('Mouvement de porte-monnaie refusé : le client n’a pas d’établissement.');
        }

        return $etablissement;
    }
}
