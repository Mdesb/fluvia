<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Vente\Entity\Vente;
use App\Vente\Service\AjoutLigneHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Ajoute une ligne au panier (POST /ventes/{id}/lignes, CA-4/5/6). Prix via ResolveurPrix M1, garde
 * bénéficiaire (CA-5) et stock (CA-6), promotions automatiques (CA-4), recalcul instantané. Le
 * forçage de prix nécessite le droit vente.forcer_prix.
 *
 * @implements ProcessorInterface<Vente, Vente>
 */
final class AjoutLigneProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly AjoutLigneHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Vente
    {
        \assert($data instanceof Vente);

        $autoriseForcage = $this->security->isGranted('PERM', 'vente.forcer_prix');
        $this->handler->ajouter($data, $this->lecteur->corps(), $autoriseForcage);
        $this->em->flush();

        return $data;
    }
}
