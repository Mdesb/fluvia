<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\VenteImpayeeRegie;
use App\Compta\Service\UnpaidSaleSettlement;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * `POST /compta/ventes-impayees-regie/{id}/regler` — la vente impayée a finalement été encaissée.
 *
 * Corps : `{ "motif": string }` — par quel moyen elle est rentrée.
 *
 * ⚠ CE PROCESSEUR NE DÉCIDE DE RIEN. Il lit le corps, résout l'auteur, et délègue à
 * `UnpaidSaleSettlement`. Les règles — un seul règlement, un motif obligatoire — sont du domaine :
 * les écrire ici les aurait rendues intestables, `LecteurCorps` et `Security` étant `final`.
 *
 * @implements ProcessorInterface<mixed, VenteImpayeeRegie>
 */
final class SettleUnpaidSaleProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly UnpaidSaleSettlement $reglement,
        private readonly Security $security,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): VenteImpayeeRegie
    {
        \assert($data instanceof VenteImpayeeRegie);

        $corps = $this->lecteur->corps();
        $auteur = $this->security->getUser();

        return $this->reglement->settle(
            $data,
            \is_string($corps['motif'] ?? null) ? $corps['motif'] : '',
            $auteur instanceof Utilisateur ? $auteur : null,
        );
    }
}
