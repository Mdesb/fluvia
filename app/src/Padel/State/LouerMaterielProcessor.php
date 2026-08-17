<?php

declare(strict_types=1);

namespace App\Padel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caution\Entity\Caution;
use App\Caution\Service\GestionCaution;
use App\Padel\Entity\CautionMateriel;
use App\Padel\Entity\LocationMateriel;
use App\Padel\Enum\StatutCautionMateriel;
use App\Reservation\Entity\Reservation;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Loue du matériel (raquette/balles) rattaché à une réservation (POST /padel/locations, US-PADEL-08,
 * CA-9). Corps : { "reservation": iri|uuid, "article": uuid, "quantite": int, "caution"?: decimal }.
 * Route « flat » plutôt que nested `/padel/reservations/{id}/materiel` (`Reservation` appartient à
 * `App\Reservation`, non modifiable — divergence documentée vs. plan §3). La consignation de la
 * caution est déléguée à `App\Caution\Service\GestionCaution` (refactor caution générique) ;
 * `CautionMateriel` reste l'entité locale exposée par `/api/padel_caution_materiels` (contrat
 * inchangé), miroir de la caution générique `App\Caution\Entity\Caution` (cible `padel.materiel`).
 *
 * @implements ProcessorInterface<mixed, LocationMateriel>
 */
final class LouerMaterielProcessor implements ProcessorInterface
{
    public const TYPE_CIBLE = 'padel.materiel';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
        private readonly GestionCaution $gestionCaution,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LocationMateriel
    {
        $corps = $this->lecteur->corps();

        $reservation = $this->resoudre(Reservation::class, $corps['reservation'] ?? null, 'reservation');
        \assert($reservation instanceof Reservation);

        if (!$this->security->isGranted('PERM', 'padel.materiel_gerer')) {
            $utilisateur = $this->security->getUser();
            $clientLie = $utilisateur instanceof Utilisateur ? $utilisateur->getClientLie() : null;
            $organisateurClient = $reservation->getOrganisateur()?->getClient();
            if ($clientLie === null || $organisateurClient === null || (string) $clientLie !== (string) $organisateurClient->getId()) {
                throw new UnprocessableEntityHttpException('Seul l\'organisateur de la réservation (ou un agent) peut louer du matériel pour celle-ci.');
            }
        }

        $article = $this->uuid($corps['article'] ?? null);
        if ($article === null) {
            throw new UnprocessableEntityHttpException('Champ « article » obligatoire (référence catalogue M1).');
        }
        $quantite = isset($corps['quantite']) ? (int) $corps['quantite'] : 1;
        if ($quantite < 1) {
            throw new UnprocessableEntityHttpException('« quantite » doit être ≥ 1.');
        }

        $location = new LocationMateriel();
        $location->setReservation($reservation)
            ->setArticle($article)
            ->setQuantite($quantite);
        $this->em->persist($location);
        $this->em->flush();

        if (isset($corps['caution']) && (float) $corps['caution'] > 0.0) {
            $montantDecimal = number_format((float) $corps['caution'], 2, '.', '');

            $etablissement = $reservation->getEtablissement();
            if ($etablissement !== null) {
                $this->gestionCaution->consigner($etablissement, self::TYPE_CIBLE, $location->getId(), Caution::decimalVersCentimes($montantDecimal));
            }

            $caution = new CautionMateriel();
            $caution->setLocation($location)
                ->setMontant($montantDecimal)
                ->setStatut(StatutCautionMateriel::Encaissee)
                ->setDateEncaissement(new \DateTimeImmutable());
            $this->em->persist($caution);
            $this->em->flush();
        }

        return $location;
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
        $uuid = $this->uuid($reference);
        if ($uuid === null) {
            throw new UnprocessableEntityHttpException(sprintf('Référence « %s » obligatoire (UUID ou IRI).', $champ));
        }
        $entite = $this->em->getRepository($classe)->find($uuid);
        if ($entite === null) {
            throw new UnprocessableEntityHttpException(sprintf('%s introuvable.', $champ));
        }

        return $entite;
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
