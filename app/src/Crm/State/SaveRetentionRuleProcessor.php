<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Audit\Service\JournalAudit;
use App\Crm\Entity\RegleConservation;
use App\Crm\Enum\ActionConservation;
use App\Crm\Security\CustomerGroupGuard;
use App\Crm\Service\PurgeRequestReader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /rgpd/retention-rule (#344) : fixe la durée de conservation des fiches clients de la
 * collectivité courante. Corps : `{ months: int }` (1 à 240).
 *
 * Le groupe n'est JAMAIS lu du corps : il vient de `CustomerGroupGuard`. L'action à échéance est
 * toujours l'anonymisation — la suppression physique d'une fiche client n'existe pas dans
 * l'application (les ventes scellées y restent rattachées, NF525), et l'écran ne propose pas un
 * choix que la tâche refuserait.
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class SaveRetentionRuleProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CustomerGroupGuard $guard,
        private readonly EntityManagerInterface $em,
        private readonly PurgeRequestReader $reader,
        private readonly JournalAudit $journal,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $groupe = $this->guard->activeGroup();
        $mois = $this->reader->body()['months'] ?? null;
        if (!\is_int($mois) || $mois < 1 || $mois > PurgeRequestReader::MAX_MONTHS) {
            throw new UnprocessableEntityHttpException(sprintf('months : un nombre entier de mois, de 1 à %d.', PurgeRequestReader::MAX_MONTHS));
        }

        $regle = $this->em->getRepository(RegleConservation::class)->findOneBy(['groupe' => $groupe, 'categorieDonnee' => RetentionRuleProvider::CATEGORY]);
        if (!$regle instanceof RegleConservation) {
            $regle = (new RegleConservation())->setGroupe($groupe)->setCategorieDonnee(RetentionRuleProvider::CATEGORY);
            $this->em->persist($regle);
        }
        $regle->setDureeMois($mois)->setActionEcheance(ActionConservation::Anonymisation);
        $this->journal->enregistrer('crm.rgpd.conservation', 'RegleConservation', (string) $regle->getId());
        $this->em->flush();

        return new JsonResponse(RetentionRuleProvider::present($regle));
    }
}
