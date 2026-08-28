<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Securite\Service\CalculateurDroits;
use App\Support\Entity\TicketSupport;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly ContexteEtablissement $contexte,
        private readonly CalculateurDroits $calculateur,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return [];
        }

        // Un tableau de bord compte ce que l'on REGARDE, pas ce a quoi on a droit. Filtrer sur le
        // perimetre affichait la somme de plusieurs sites sous le titre d'un seul -- des chiffres
        // justes au mauvais endroit, et rien pour le signaler.
        $actif = $this->contexte->idActif();
        if ($actif === null) {
            return [];
        }

        $qb = $this->em->getRepository(TicketSupport::class)->createQueryBuilder('t')
            ->andWhere('IDENTITY(t.etablissement) = :tdb_actif')
            ->setParameter('tdb_actif', $actif, 'uuid')
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
