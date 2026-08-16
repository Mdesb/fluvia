<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caisse\Entity\SessionCaisse;
use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Enum\StatutFacturationNoShow;
use App\Reservation\Facturation\ResolveurStrategieFacturation;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Émission différée d'une vente/avoir M2 pour une `FacturationNoShow` (CA-11, `reservation.facturer`) :
 * délègue à la stratégie résolue depuis `RegleAnnulation.modeFacturation` (décision structurante n°4
 * du plan). Pour `vente_differee_agent`, une `session` de caisse ouverte est requise dans le corps.
 *
 * @implements ProcessorInterface<mixed, FacturationNoShow>
 */
final class EmettreVenteNoShowProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
        private readonly ResolveurStrategieFacturation $strategies,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FacturationNoShow
    {
        \assert($data instanceof FacturationNoShow);

        if ($data->getStatut() !== StatutFacturationNoShow::AFacturer) {
            throw new ConflictHttpException('Cette facturation no-show n\'est plus « à facturer » (statut : ' . $data->getStatut()->value . ').');
        }

        $regle = $data->getRegleAppliquee();
        if ($regle === null) {
            throw new UnprocessableEntityHttpException('Aucune règle d\'annulation rattachée à cette facturation.');
        }

        $strategie = $this->strategies->pour($regle->getModeFacturation()->value);
        if ($strategie === null) {
            throw new UnprocessableEntityHttpException(sprintf('Aucune stratégie de facturation enregistrée pour le mode « %s ».', $regle->getModeFacturation()->value));
        }

        $corps = $this->lecteur->corps();
        $contexte = [];
        if (isset($corps['session']) && \is_string($corps['session'])) {
            $segment = str_contains($corps['session'], '/') ? basename($corps['session']) : $corps['session'];
            if (Uuid::isValid($segment)) {
                $contexte['session'] = $this->em->getRepository(SessionCaisse::class)->find(Uuid::fromString($segment));
            }
        }

        $utilisateur = $this->security->getUser();
        $agent = $utilisateur instanceof Utilisateur ? $utilisateur : null;

        $resultat = $strategie->appliquer($data, $agent, $contexte);
        if (!$resultat->succes) {
            throw new UnprocessableEntityHttpException($resultat->motif ?? 'Émission de la vente no-show impossible.');
        }

        return $data;
    }
}
