<?php

declare(strict_types=1);

namespace App\Group\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Group\Entity\GroupBooking;
use App\Group\Entity\GroupGratuite;
use App\Group\Entity\GroupGratuiteContingent;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Accorde des gratuités à une réservation depuis un contingent
 * (POST /group/bookings/{id}/grant-gratuite). Corps : { "contingent": iri|uuid, "quantite": int,
 * "motif"? }.
 *
 * Refuse si le contingent n'a plus assez de places (409). Le contingent doit appartenir à
 * l'établissement actif (RG-SOCLE-05, `find()` ne passe pas par l'extension de périmètre).
 *
 * @implements ProcessorInterface<GroupBooking, GroupBooking>
 */
final class GrantGratuiteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GroupBooking
    {
        \assert($data instanceof GroupBooking);

        $actif = $this->contexte->etablissementActif();
        if ($actif === null) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        $corps = $this->lecteur->corps();

        $reference = $corps['contingent'] ?? null;
        $segment = \is_string($reference) && str_contains($reference, '/') ? basename($reference) : $reference;
        if (!\is_string($segment) || !Uuid::isValid($segment)) {
            throw new UnprocessableEntityHttpException('Référence « contingent » obligatoire (UUID ou IRI).');
        }
        $contingent = $this->em->getRepository(GroupGratuiteContingent::class)->find(Uuid::fromString($segment));
        if (!$contingent instanceof GroupGratuiteContingent) {
            throw new NotFoundHttpException('Contingent introuvable.');
        }
        $etab = $contingent->getEtablissement();
        if ($etab === null || !$etab->getId()->equals($actif->getId())) {
            throw new NotFoundHttpException('Contingent introuvable dans l\'établissement actif.');
        }

        $quantite = isset($corps['quantite']) ? max(1, (int) $corps['quantite']) : 0;
        if ($quantite < 1) {
            throw new UnprocessableEntityHttpException('Champ « quantite » obligatoire (≥ 1).');
        }
        if ($contingent->getPlacesRestantes() < $quantite) {
            throw new ConflictHttpException(sprintf(
                'Contingent épuisé : %d gratuité(s) restante(s) pour %d demandée(s).',
                $contingent->getPlacesRestantes(),
                $quantite,
            ));
        }

        $motif = \is_string($corps['motif'] ?? null) && trim($corps['motif']) !== '' ? trim($corps['motif']) : null;

        $gratuite = (new GroupGratuite())
            ->setBooking($data)
            ->setContingent($contingent)
            ->setQuantite($quantite)
            ->setMotif($motif);
        $this->em->persist($gratuite);
        $contingent->setConsomme($contingent->getConsomme() + $quantite);

        $this->em->flush();

        return $data;
    }
}
