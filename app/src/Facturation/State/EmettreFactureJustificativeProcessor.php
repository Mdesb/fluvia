<?php

declare(strict_types=1);

namespace App\Facturation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Facturation\Entity\Facture;
use App\Facturation\Service\EmissionFactureJustificativeHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Vente;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /factures/depuis-vente (US-FACT-01, CA-1/CA-2, `plan-facturation.md` §2). Corps :
 *   { "vente": iri|uuid, "destinataire"?: {...} }
 *
 * @implements ProcessorInterface<mixed, Facture>
 */
final class EmettreFactureJustificativeProcessor implements ProcessorInterface
{
    use LectureReferenceTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly EmissionFactureJustificativeHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Facture
    {
        $corps = $this->lecteur->corps();

        $venteId = $this->uuidDepuis($corps['vente'] ?? null);
        if ($venteId === null) {
            throw new UnprocessableEntityHttpException('Référence de vente obligatoire.');
        }
        $vente = $this->em->getRepository(Vente::class)->find($venteId);
        if ($vente === null) {
            throw new UnprocessableEntityHttpException('Vente introuvable.');
        }

        $auteur = $this->security->getUser();
        \assert($auteur instanceof Utilisateur);

        $destinataire = \is_array($corps['destinataire'] ?? null) ? $corps['destinataire'] : null;

        return $this->handler->emettre($vente, $destinataire, $auteur);
    }
}
