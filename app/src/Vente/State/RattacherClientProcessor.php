<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Vente\Entity\Vente;
use App\Vente\Port\ClientM4Interface;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Rattache (ou crée rapidement) un client à la vente via M4 (POST /ventes/{id}/client, CA-7). Le
 * rattachement est optionnel : la vente comptoir anonyme reste possible. Corps (un des trois) :
 *   { "client": uuid }  |  { "recherche": "nom|email|n°compte" }  |  { "creer": { … } }
 *
 * @implements ProcessorInterface<Vente, Vente>
 */
final class RattacherClientProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ClientM4Interface $crm,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Vente
    {
        \assert($data instanceof Vente);
        if ($data->estScellee()) {
            throw new ConflictHttpException('Vente validée : rattachement client impossible (NF525).');
        }

        $corps = $this->lecteur->corps();

        if (isset($corps['client']) && \is_string($corps['client']) && Uuid::isValid((string) $corps['client'])) {
            $data->setClient(Uuid::fromString((string) $corps['client']));
        } elseif (isset($corps['recherche']) && \is_string($corps['recherche'])) {
            $trouve = $this->crm->rechercher($corps['recherche']);
            if ($trouve === null) {
                throw new UnprocessableEntityHttpException('Aucun client trouvé pour ce critère (M4).');
            }
            $data->setClient($trouve);
        } elseif (isset($corps['creer']) && \is_array($corps['creer'])) {
            $data->setClient($this->crm->creerRapide($corps['creer']));
        } else {
            throw new UnprocessableEntityHttpException('Fournir « client », « recherche » ou « creer ».');
        }

        $this->em->flush();

        return $data;
    }
}
