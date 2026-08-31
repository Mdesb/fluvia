<?php

declare(strict_types=1);

namespace App\Calendar\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Calendar\Entity\IcsSubscription;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /agenda/abonnement-ics` — l'URL d'abonnement de CE compte sur CET établissement.
 *
 * ── CRÉÉE À LA PREMIÈRE LECTURE, ET C'EST VOULU ─────────────────────────────────────────────────
 *
 * Provisionner un jeton pour chaque compte à la création serait poser des milliers de secrets dont
 * personne ne se servira. On le crée quand quelqu'un ouvre l'écran et demande l'URL — c'est-à-dire
 * quand il en a besoin, et jamais avant.
 *
 * Rend une COLLECTION d'un seul élément plutôt qu'un item : un `Get` par identifiant obligerait le
 * client à connaître l'identifiant avant de pouvoir le demander.
 *
 * @implements ProviderInterface<IcsSubscription>
 */
final readonly class IcsSubscriptionProvider implements ProviderInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private ContexteEtablissement $contexte,
        private Security $security,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return list<IcsSubscription>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $etablissement = $this->contexte->etablissementActif();
        $utilisateur = $this->security->getUser();
        if (!$etablissement instanceof Etablissement || !$utilisateur instanceof Utilisateur) {
            throw new NotFoundHttpException('Aucun contexte d’agenda.');
        }

        $abonnement = $this->em->getRepository(IcsSubscription::class)
            ->findOneBy(['user' => $utilisateur, 'establishment' => $etablissement]);

        if (!$abonnement instanceof IcsSubscription) {
            $abonnement = (new IcsSubscription())
                ->setUser($utilisateur)
                ->setEstablishment($etablissement);
            $this->em->persist($abonnement);
            $this->em->flush();
        }

        return [$abonnement];
    }
}
