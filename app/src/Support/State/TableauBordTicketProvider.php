<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Support\Entity\TicketSupport;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * GET /support/tickets/tableau-de-bord (US-SUP-14, CA-13) : vue de restitution filtrable (statut,
 * priorité, module, niveau, agent affecté) — y compris les tickets **sans agent affecté**. Restreint
 * aux établissements où l'agent support possède une `Affectation` (même règle que la branche
 * admin/N1/N2 de `PerimetreSupportExtension`, non appliquée ici car ce Provider est custom).
 *
 * @implements ProviderInterface<list<TicketSupport>>
 */
final class TableauBordTicketProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly CalculateurDroits $calculateur,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return [];
        }

        $qb = $this->em->getRepository(TicketSupport::class)->createQueryBuilder('t')
            ->innerJoin(
                Affectation::class,
                'aff_tdb',
                Join::WITH,
                'IDENTITY(aff_tdb.etablissement) = IDENTITY(t.etablissement) AND IDENTITY(aff_tdb.utilisateur) = :tdb_utilisateur',
            )
            ->setParameter('tdb_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct()
            ->orderBy('t.dateCreation', 'DESC');

        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null) {
            foreach (['statut' => 't.statut', 'priorite' => 't.priorite', 'niveauAffectation' => 't.niveauAffectation'] as $param => $champ) {
                $valeur = $request->query->get($param);
                if (\is_string($valeur) && $valeur !== '') {
                    $qb->andWhere($champ . ' = :' . $param)->setParameter($param, $valeur);
                }
            }
            $moduleConcerne = $request->query->get('moduleConcerne');
            if (\is_string($moduleConcerne) && $moduleConcerne !== '') {
                $qb->andWhere('t.moduleConcerne LIKE :moduleConcerne')->setParameter('moduleConcerne', '%' . $moduleConcerne . '%');
            }
            $affecteA = $request->query->get('affecteA');
            if (\is_string($affecteA) && $affecteA !== '') {
                $qb->andWhere('t.affecteA = :affecteA')->setParameter('affecteA', basename($affecteA), 'uuid');
            }
        }

        /** @var list<TicketSupport> */
        return $qb->getQuery()->getResult();
    }
}
