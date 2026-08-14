<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Vente\Entity\Vente;
use App\Vente\Service\LecteurCorps;
use App\Vente\Service\PanierCalculateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Retire une ligne du panier (POST /ventes/{id}/retirer-ligne, CA-3). Interdit si la vente est
 * validée (NF525). Recalcul instantané. Corps : { "ligne": uuid }.
 *
 * @implements ProcessorInterface<Vente, Vente>
 */
final class RetirerLigneProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly PanierCalculateur $calc,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Vente
    {
        \assert($data instanceof Vente);
        if ($data->estScellee()) {
            throw new ConflictHttpException('Vente validée : panier figé (NF525).');
        }

        $reference = $this->lecteur->corps()['ligne'] ?? null;
        $segment = \is_string($reference) && str_contains($reference, '/') ? basename($reference) : $reference;
        if (!\is_string($segment) || !Uuid::isValid($segment)) {
            throw new UnprocessableEntityHttpException('Référence de ligne invalide.');
        }
        $id = Uuid::fromString($segment);

        foreach ($data->getLignes() as $ligne) {
            if ($ligne->getId()->equals($id)) {
                $data->removeLigne($ligne);
                $this->em->remove($ligne);
                $this->calc->recalculerVente($data);
                $this->em->flush();

                return $data;
            }
        }

        throw new UnprocessableEntityHttpException('Ligne introuvable sur cette vente.');
    }
}
