<?php

declare(strict_types=1);

namespace App\Caisse\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caisse\Entity\SessionCaisse;
use App\Caisse\Enum\EtatCaisse;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Réouverture d'une caisse sécurisée (CA-2). Une caisse 🔒 sécurisée (post-Z) exige le code régisseur
 * pour repasser 🟢 ouverte, autorisant une nouvelle session. Corps attendu : { "codeRegisseur": "…" }.
 *
 * @implements ProcessorInterface<SessionCaisse, SessionCaisse>
 */
final class RouvrirCaisseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SessionCaisse
    {
        \assert($data instanceof SessionCaisse);
        $caisse = $data->getCaisse();
        if ($caisse === null) {
            throw new UnprocessableEntityHttpException('Session sans caisse.');
        }

        if ($caisse->getEtat() === EtatCaisse::Securisee) {
            $code = \is_string($this->lecteur->corps()['codeRegisseur'] ?? null) ? trim($this->lecteur->corps()['codeRegisseur']) : '';
            if ($code === '') {
                throw new UnprocessableEntityHttpException('Caisse sécurisée : code régisseur requis pour la rouvrir (CA-2).');
            }
        }

        $caisse->setEtat(EtatCaisse::Ouverte);
        $this->em->flush();

        return $data;
    }
}
