<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Crm\Entity\Opportunity;
use App\Crm\Enum\OpportunityStage;
use App\Facturation\Enum\DocumentStatus;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * `GET /crm/pipeline` — le tableau des affaires, colonne par colonne.
 *
 * **L'ÉTAPE RENDUE EST CALCULÉE, PAS RELUE.**
 *
 * Dès qu'un devis est rattaché à une affaire, c'est lui qui dit où elle en est : émis, accepté,
 * refusé. L'étape stockée ne gouverne plus que la période d'avant.
 *
 * On aurait pu recopier le statut du devis dans la table à chaque transition. Ce serait plus simple à
 * lire et faux à l'usage : une copie prend du retard — un devis accepté hors de l'application, une
 * transition qui échoue à mi-chemin — et **une étape en retard est pire qu'absente, parce qu'elle a
 * l'air d'être à jour**.
 *
 * > Une étape que le logiciel peut déduire ne doit pas être tenue à la main, sinon le pipeline ment.
 *
 * **Le statut du devis est lu en SQL, et c'est la convention du dépôt.** `Opportunity` référence
 * `CommercialDocument` par un `?Uuid` nu, parce qu'une relation Doctrine créerait une dépendance de
 * mapping entre deux modules qui doivent vivre séparément (D2). Le prix de cette convention est D58 :
 * sur une colonne `uuid` nue, Doctrine ne convertit pas **et ne s'en plaint pas**. D'où le SQL, avec
 * ses identifiants passés en binaire explicite — une comparaison non typée rendrait « aucun devis »
 * sur toutes les affaires, ce qui ressemble à un pipeline neuf.
 *
 * @cloisonnement-verifie: la requête filtre sur l'établissement ACTIF résolu par
 * `ContexteEtablissement`, et `PermissionVoter` a déjà refusé l'accès si l'utilisateur n'y est pas
 * affecté — les droits sont l'union des permissions des affectations SUR cet établissement.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class PipelineProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $actif = $this->contexte->idActif();
        if ($actif === null) {
            // Fermeture par défaut : sans établissement actif, un pipeline vide. Rendre « tout »
            // serait la seule erreur irrattrapable de ce fichier.
            return new JsonResponse(['colonnes' => $this->colonnesVides(), 'total' => 0]);
        }

        /** @var list<Opportunity> $affaires */
        $affaires = $this->em->getRepository(Opportunity::class)->createQueryBuilder('o')
            ->andWhere('IDENTITY(o.establishment) = :etab')
            ->setParameter('etab', $actif, 'uuid')
            ->orderBy('o.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $statuts = $this->statutsDesDevis($affaires);

        $colonnes = $this->colonnesVides();
        foreach ($affaires as $affaire) {
            $etape = $this->etapeEffective($affaire, $statuts[(string) $affaire->getCommercialDocumentRef()] ?? null);

            $colonnes[$etape->value]['affaires'][] = [
                'id' => (string) $affaire->getId(),
                'titre' => $affaire->getTitle(),
                'client' => $affaire->getCustomer()?->getRaisonSociale()
                    ?: trim(($affaire->getCustomer()?->getPrenom() ?? '') . ' ' . ($affaire->getCustomer()?->getNom() ?? '')) ?: null,
                'montantEstime' => $affaire->getEstimatedAmount(),
                'echeance' => $affaire->getExpectedCloseDate()?->format('Y-m-d'),
                'devis' => (string) $affaire->getCommercialDocumentRef() ?: null,
                // On dit quand l'étape vient du devis : sans ça, un utilisateur cherche pourquoi il ne
                // peut pas déplacer sa carte, et conclut à une panne.
                'etapePosePar' => $etape->manuallySettable() ? 'humain' : 'devis',
                'motifPerte' => $affaire->getLossReason()?->label(),
                'commentairePerte' => $affaire->getLossComment(),
            ];
            $colonnes[$etape->value]['montantTotal'] = bcadd(
                $colonnes[$etape->value]['montantTotal'],
                $affaire->getEstimatedAmount(),
                2,
            );
        }

        return new JsonResponse([
            'colonnes' => array_values($colonnes),
            'total' => \count($affaires),
        ]);
    }

    /**
     * L'étape réelle : celle du devis s'il existe, celle qui est stockée sinon.
     */
    private function etapeEffective(Opportunity $affaire, ?string $statutDevis): OpportunityStage
    {
        if ($statutDevis === null) {
            return $affaire->getStage();
        }

        return match ($statutDevis) {
            DocumentStatus::Accepted->value, DocumentStatus::Converted->value => OpportunityStage::Won,
            DocumentStatus::Rejected->value, DocumentStatus::Expired->value => OpportunityStage::Lost,
            DocumentStatus::Issued->value => OpportunityStage::QuoteSent,
            // Un devis en BROUILLON n'est pas un devis envoyé : l'affaire reste où l'humain l'a mise.
            // C'est le cas qui distingue « on prépare une proposition » de « le client l'a reçue ».
            default => $affaire->getStage(),
        };
    }

    /**
     * @param list<Opportunity> $affaires
     *
     * @return array<string, string> identifiant de devis -> statut
     */
    private function statutsDesDevis(array $affaires): array
    {
        $refs = [];
        foreach ($affaires as $affaire) {
            $ref = $affaire->getCommercialDocumentRef();
            if ($ref !== null) {
                $refs[] = $ref->toBinary();
            }
        }

        if ($refs === []) {
            return [];
        }

        // ⚠ D58 — `IN (:liste)` sur une colonne `uuid` ne trouve RIEN et ne lève pas. Aucun type
        // scalaire ne s'applique à une liste : on passe donc les identifiants en binaire, en SQL.
        $lignes = $this->em->getConnection()->executeQuery(
            'SELECT id, statut FROM billing_document WHERE id IN (?)',
            [$refs],
            [Connection::PARAM_STR_ARRAY],
        )->fetchAllAssociative();

        $statuts = [];
        foreach ($lignes as $ligne) {
            $statuts[(string) \Symfony\Component\Uid\Uuid::fromBinary($ligne['id'])] = (string) $ligne['statut'];
        }

        return $statuts;
    }

    /** @return array<string, array{etape: string, libelle: string, montantTotal: string, affaires: list<array<string, mixed>>}> */
    private function colonnesVides(): array
    {
        $colonnes = [];
        foreach (OpportunityStage::cases() as $etape) {
            $colonnes[$etape->value] = [
                'etape' => $etape->value,
                'libelle' => $etape->label(),
                'montantTotal' => '0.00',
                'affaires' => [],
            ];
        }

        return $colonnes;
    }
}
