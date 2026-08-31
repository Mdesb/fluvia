<?php

declare(strict_types=1);

namespace App\Marketing\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Marketing\Entity\Referral;
use App\Marketing\Service\MarketingContext;
use App\Marketing\Service\ReferralService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /marketing/parrainages` — la liste, avec l'état calculé de chacun.
 *
 * L'état ne vient pas de la base : il se déduit des ventes du filleul à chaque lecture. Un statut
 * stocké resterait « en attente » le jour où le filleul achète, jusqu'à ce qu'un traitement pense à
 * le mettre à jour — et personne ne verrait qu'il ne l'a pas fait.
 *
 * Le paramètre `?parrain=<uuid>` restreint à un client : c'est ce que la fiche client appelle.
 * Sans lui, la liste rend les parrainages de l'établissement actif — et **seulement** de lui.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final readonly class ReferralListProvider implements ProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketingContext $contexte,
        private ReferralService $parrainage,
        private RequestStack $requetes,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        [, $etablissement] = $this->contexte->exigerAutorite('fidelite', 'lire');

        $qb = $this->entityManager->getRepository(Referral::class)->createQueryBuilder('r')
            // ⚠ D58 vaut aussi pour l'association : passer l'ENTITÉ lie son identifiant sans
            // son type `uuid`, et la liste rend zéro parrainage sans la moindre erreur.
            ->andWhere('IDENTITY(r.establishment) = :etab')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->orderBy('r.createdAt', 'DESC');

        // ⚠ ILLISIBLE ⇒ AUCUN RÉSULTAT, JAMAIS TOUS. La forme précédente sautait le filtre sur une
        // valeur invalide : on demandait les filleuls d'un parrain et on recevait la liste entière de
        // l'établissement. Ici on ne refuse pas en erreur — cette collection alimente une liste, pas
        // un export — mais on rend une condition impossible, pour que « je n'ai pas compris » ne se
        // traduise jamais par « en voici davantage ».
        $parrain = $this->requetes->getCurrentRequest()?->query->get('parrain');
        if (\is_string($parrain) && $parrain !== '' && !Uuid::isValid($parrain)) {
            $qb->andWhere('1 = 0');
        }
        if (\is_string($parrain) && Uuid::isValid($parrain)) {
            // ⚠ D58 — sans le type explicite, la comparaison ne trouve RIEN et ne lève pas : la
            // fiche client afficherait « aucun filleul » à un parrain qui en a dix.
            $qb->andWhere('r.sponsorRef = :parrain')
                ->setParameter('parrain', Uuid::fromString($parrain), 'uuid');
        }

        /** @var list<Referral> $liens */
        $liens = $qb->getQuery()->getResult();

        $lignes = [];
        $aRecompenser = 0;
        foreach ($liens as $lien) {
            $etat = $this->parrainage->etat($lien);
            $aRecompenser += $etat['etat'] === 'eligible' ? 1 : 0;

            $lignes[] = [
                'id' => (string) $lien->getId(),
                'parrain' => (string) $lien->getSponsorRef(),
                'filleul' => (string) $lien->getRefereeRef(),
                'code' => $lien->getCode(),
                'le' => $lien->getCreatedAt()->format(\DATE_ATOM),
                'pointsVerses' => $lien->getRewardedPoints(),
                'recompenseLe' => $lien->getRewardedAt()?->format(\DATE_ATOM),
                ...$etat,
            ];
        }

        return new JsonResponse([
            // Le chiffre qui fait agir : « 3 à récompenser » se traite, « 47 parrainages » se
            // regarde.
            'aRecompenser' => $aRecompenser,
            'parrainages' => $lignes,
        ]);
    }
}
