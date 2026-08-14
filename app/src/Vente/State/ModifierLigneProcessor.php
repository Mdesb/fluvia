<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Vente;
use App\Vente\Service\LecteurCorps;
use App\Vente\Service\PanierCalculateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Modifie une ligne du panier (POST /ventes/{id}/modifier-ligne, CA-3) : quantité (≥ 1) et/ou note.
 * Interdit si la vente est validée (NF525). Recalcul instantané. Corps :
 *   { "ligne": uuid, "quantite"?: int, "note"?: string }
 *
 * @implements ProcessorInterface<Vente, Vente>
 */
final class ModifierLigneProcessor implements ProcessorInterface
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

        $corps = $this->lecteur->corps();
        $ligne = $this->resoudreLigne($data, $corps['ligne'] ?? null);

        if (isset($corps['quantite'])) {
            $quantite = (int) $corps['quantite'];
            if ($quantite < 1) {
                throw new UnprocessableEntityHttpException('La quantité doit être au minimum de 1.');
            }
            $ligne->setQuantite($quantite);
        }
        if (\array_key_exists('note', $corps)) {
            $ligne->setNote(\is_string($corps['note']) ? $corps['note'] : null);
        }

        $this->calc->recalculerLigne($ligne);
        $this->calc->recalculerVente($data);
        $this->em->flush();

        return $data;
    }

    private function resoudreLigne(Vente $vente, mixed $reference): LigneVente
    {
        $segment = \is_string($reference) && str_contains($reference, '/') ? basename($reference) : $reference;
        if (!\is_string($segment) || !Uuid::isValid($segment)) {
            throw new UnprocessableEntityHttpException('Référence de ligne invalide.');
        }
        $id = Uuid::fromString($segment);
        foreach ($vente->getLignes() as $ligne) {
            if ($ligne->getId()->equals($id)) {
                return $ligne;
            }
        }
        throw new UnprocessableEntityHttpException('Ligne introuvable sur cette vente.');
    }
}
