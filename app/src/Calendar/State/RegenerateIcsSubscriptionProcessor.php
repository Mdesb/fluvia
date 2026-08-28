<?php

declare(strict_types=1);

namespace App\Calendar\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Calendar\Entity\IcsSubscription;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /agenda/abonnement-ics/regenerer` — LA RÉVOCATION.
 *
 * Une URL capacitaire ne se protège pas, elle se remplace. C'est le seul geste qui rende un lien
 * partagé par erreur — dans un message, dans une capture d'écran — définitivement inerte. Sans lui,
 * la seule sortie serait de supprimer le compte.
 *
 * L'ancien jeton cesse de fonctionner immédiatement : les agendas déjà abonnés cesseront de se
 * mettre à jour, et c'est exactement l'effet recherché.
 *
 * @implements ProcessorInterface<mixed, IcsSubscription>
 */
final readonly class RegenerateIcsSubscriptionProcessor implements ProcessorInterface
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
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IcsSubscription
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
        } else {
            $abonnement->regenerate();
        }

        $this->em->flush();

        return $abonnement;
    }
}
