<?php

declare(strict_types=1);

namespace App\Facturation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\Facture;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * GET /crm/clients/{clientId}/factures — les factures d'un client, pour sa fiche (G-8).
 *
 * ── POURQUOI UNE OPÉRATION DÉDIÉE ET PAS UN FILTRE ──────────────────────────────────────────────
 *
 * J'ai d'abord déclaré `ApiFilter(SearchFilter, ['destinataire.clientRef' => 'exact'])`. Mesuré : il
 * rend **zéro résultat**, toujours. `clientRef` est stocké en `BINARY(16)` par le type Doctrine `uuid`,
 * et le comparer à une chaîne de 36 caractères ne trouve rien — sans rien lever. Le dépôt possède un
 * décorateur pour ce piège (`UuidAwareSearchFilter`, 145 propriétés concernées), mais il traite une
 * propriété DIRECTE, pas un chemin imbriqué comme `destinataire.clientRef`. L'étendre toucherait un
 * mécanisme partagé par 28 modules pour un seul besoin.
 *
 * On reprend donc le patron de {@see MesFacturesProvider}, qui résout exactement la même comparaison
 * — et dont la clé est le troisième argument : `setParameter(..., 'uuid')`.
 *
 * ── ⚠ UN PROVIDER SUR MESURE N'EST PAS CLOISONNÉ TOUT SEUL ──────────────────────────────────────
 *
 * `PerimetreFacturationExtension` protège les collections construites par Doctrine ; une requête
 * écrite à la main lui échappe **entièrement**. Deux fuites de ce dépôt sont nées exactement comme
 * ça. Le périmètre est donc posé ici, explicitement, depuis la session serveur — jamais depuis un
 * identifiant du corps ou de l'URL (D3).
 *
 * @implements ProviderInterface<list<Facture>>
 */
final class FacturesDuClientProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    /** @return list<Facture> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        // Le paramètre de requête, pas une variable d'URL — voir le commentaire de l'opération.
        $clientId = $context['filters']['clientRef'] ?? null;
        if (!\is_string($clientId) || !Uuid::isValid($clientId)) {
            throw new NotFoundHttpException('« clientRef » est requis et doit être un identifiant valide.');
        }

        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            // Échec fermé : sans périmètre, on ne rend rien plutôt que tout (D3).
            throw new NotFoundHttpException('Aucun établissement actif.');
        }

        return $this->em->createQueryBuilder()
            ->select('f')
            ->from(Facture::class, 'f')
            ->innerJoin(DestinataireFacturation::class, 'd', 'WITH', 'd = f.destinataire')
            ->andWhere('d.clientRef = :clientRef')
            // ⚠ LE PÉRIMÈTRE, POSÉ ICI PARCE QUE PERSONNE D'AUTRE NE LE FERA. Sans cette ligne, la
            //    fiche d'un client afficherait aussi les factures qu'un AUTRE établissement lui a
            //    émises — et rien ne le signalerait, la liste ayant l'air parfaitement normale.
            ->andWhere('f.etablissement = :etab')
            ->setParameter('clientRef', Uuid::fromString($clientId), 'uuid')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->orderBy('f.creeLe', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
