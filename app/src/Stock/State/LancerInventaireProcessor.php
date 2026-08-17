<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Service\ContexteEtablissement;
use App\Stock\Entity\Inventaire;
use App\Stock\Enum\PerimetreInventaire;
use App\Stock\Service\InventaireRegularisationHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /stock/inventaires` (US-STOCK-11, RG-STOCK-12, CA-13) : lancement, snapshot théorique figé.
 * Corps : { "perimetre": "tous"|"rayon"|"selection", "filtre"?: [uuid,...] }.
 *
 * @implements ProcessorInterface<mixed, Inventaire>
 */
final class LancerInventaireProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ContexteEtablissement $contexte,
        private readonly InventaireRegularisationHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Inventaire
    {
        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        $corps = $this->lecteur->corps();
        $perimetre = PerimetreInventaire::tryFrom(\is_string($corps['perimetre'] ?? null) ? $corps['perimetre'] : '') ?? PerimetreInventaire::Tous;
        $filtre = isset($corps['filtre']) && \is_array($corps['filtre']) ? array_map('strval', $corps['filtre']) : null;

        $inventaire = $this->handler->lancer($etablissement, $perimetre, $filtre);
        $this->em->flush();

        return $inventaire;
    }
}
