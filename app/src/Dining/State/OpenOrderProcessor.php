<?php

declare(strict_types=1);

namespace App\Dining\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dining\Entity\DiningOrder;
use App\Dining\Security\DiningScopeGuard;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Ouverture d une addition (ACT-4). Corps attendu : { "tableLabel": "12", "covers": 4 }
 *
 * **L etablissement vient de la session serveur, jamais du corps** (D41/D3). Accepter un
 * `establishment` dans la charge utile laisserait l appelant choisir son propre perimetre.
 *
 * @implements ProcessorInterface<mixed, DiningOrder>
 */
final class OpenOrderProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ContexteEtablissement $contexte,
        private readonly DiningScopeGuard $scopeGuard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DiningOrder
    {
        $corps = $this->lecteur->corps();
        $etablissement = $this->scopeGuard->verify($this->contexte->etablissementActif());

        $table = $corps['tableLabel'] ?? null;
        if (!is_string($table) || '' === trim($table)) {
            throw new UnprocessableEntityHttpException('dining.error.table_label_required');
        }

        $couverts = $corps['covers'] ?? 1;
        if (!is_int($couverts) || $couverts < 1) {
            throw new UnprocessableEntityHttpException('dining.error.covers_invalid');
        }

        $addition = new DiningOrder(
            $etablissement,
            $this->reference(),
            trim($table),
            $couverts,
            new \DateTimeImmutable('now'),
        );

        $this->em->persist($addition);
        $this->em->flush();

        return $addition;
    }

    /**
     * Reference lisible, unique par etablissement grace a la contrainte de schema.
     *
     * Volontairement **non sequentielle**, comme celle du sejour : un compteur par etablissement
     * demanderait un verrou et rendrait les references devinables — or l indiscernabilite 404 du garde
     * perd de sa valeur si « ADD-0002 » se deduit de « ADD-0001 ».
     */
    private function reference(): string
    {
        return 'ADD-' . strtoupper(substr(Uuid::v7()->toBase58(), 0, 10));
    }
}
