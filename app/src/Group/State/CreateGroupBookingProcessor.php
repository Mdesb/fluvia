<?php

declare(strict_types=1);

namespace App\Group\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Group\Entity\GroupBooking;
use App\Group\Entity\ParticipantGroup;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Activite;
use App\Reservation\Entity\Creneau;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Crée une réservation de groupe (POST /group/bookings).
 * Corps : { "group", "creneau"?, "activite"?, "effectif"?, "accompagnateurs"?, "optionExpiresAt"?,
 * "payer"? }.
 *
 * ── PARTAGE DES RÔLES, DÉLIBÉRÉ ─────────────────────────────────────────────────────────────────
 * Les scalaires du corps (`effectif`, `accompagnateurs`, `paymentStatus`, `optionExpiresAt`, `payer`)
 * sont désérialisés par API Platform dans `$data` et **validés par leurs contraintes AVANT ce
 * processeur** — c'est bien la valeur du client qu'elles doivent contrôler. On ne les réécrit donc pas
 * ici : poser une valeur qu'une contrainte a déjà vue passer serait la fabriquer sans contrôle
 * (garde-fou n°34).
 *
 * Ce processeur ne s'occupe QUE des références rattachées à un établissement (`group`, `creneau`,
 * `activite`), qu'il résout lui-même pour **vérifier qu'elles appartiennent à l'établissement actif** :
 * `find()` ne passe pas par l'extension de périmètre (RG-SOCLE-05). Il estampille enfin
 * l'établissement depuis la session, jamais depuis le corps (D41).
 *
 * @implements ProcessorInterface<GroupBooking, GroupBooking>
 */
final class CreateGroupBookingProcessor implements ProcessorInterface
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

        $corps = $this->lecteur->corps();

        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        $group = $this->resoudre(ParticipantGroup::class, $corps['group'] ?? null, 'group');
        \assert($group instanceof ParticipantGroup);
        $this->memeEtablissement($group->getEtablissement(), $etablissement, 'group');
        $data->setGroup($group);

        if (($corps['creneau'] ?? null) !== null && $corps['creneau'] !== '') {
            $creneau = $this->resoudre(Creneau::class, $corps['creneau'], 'creneau');
            \assert($creneau instanceof Creneau);
            $this->memeEtablissement($creneau->getEtablissement(), $etablissement, 'creneau');
            $data->setCreneau($creneau);
            // Un créneau porte son activité : on la reprend si le corps n'en fournit pas d'autre.
            if ($data->getActivite() === null) {
                $data->setActivite($creneau->getActivite());
            }
        }

        if (($corps['activite'] ?? null) !== null && $corps['activite'] !== '') {
            $activite = $this->resoudre(Activite::class, $corps['activite'], 'activite');
            \assert($activite instanceof Activite);
            $this->memeEtablissement($activite->getEtablissement(), $etablissement, 'activite');
            $data->setActivite($activite);
        }

        $data->setEtablissement($etablissement);

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }

    private function memeEtablissement(?Etablissement $porte, Etablissement $actif, string $champ): void
    {
        if ($porte === null || !$porte->getId()->equals($actif->getId())) {
            throw new NotFoundHttpException(sprintf('%s introuvable dans l\'établissement actif.', $champ));
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function resoudre(string $classe, mixed $reference, string $champ): object
    {
        $segment = \is_string($reference) && str_contains($reference, '/') ? basename($reference) : $reference;
        if (!\is_string($segment) || $segment === '' || !Uuid::isValid($segment)) {
            throw new UnprocessableEntityHttpException(sprintf('Référence « %s » obligatoire (UUID ou IRI).', $champ));
        }
        $entite = $this->em->getRepository($classe)->find(Uuid::fromString($segment));
        if ($entite === null) {
            throw new NotFoundHttpException(sprintf('%s introuvable.', $champ));
        }

        return $entite;
    }
}
