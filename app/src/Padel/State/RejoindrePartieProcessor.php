<?php

declare(strict_types=1);

namespace App\Padel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Beneficiaire;
use App\Padel\Entity\NiveauJoueur;
use App\Padel\Entity\ReservationPadel;
use App\Padel\Enum\StatutPartieOuverte;
use App\Reservation\Entity\ParticipantReservation;
use App\Reservation\Enum\StatutPaiementParticipant;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Rejoint une partie ouverte (POST /padel/parties-ouvertes/{id}/rejoindre, RG-PADEL-03, CA-3/CA-5).
 * Filtre par niveau **validé** uniquement (un niveau `proposé` n'est pas éligible, CA-5) ; chaque
 * joueur paie sa part à l'inscription (§4.3, consomme le paiement partagé générique du socle). Passe
 * `statutPartie=complete` et journalise une notification quand la partie atteint 4/4 (CA-3).
 *
 * Corps : { "joueur": iri|uuid }
 *
 * @implements ProcessorInterface<mixed, ReservationPadel>
 */
final class RejoindrePartieProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ReservationPadel
    {
        \assert($data instanceof ReservationPadel);

        if (!$data->isOuverte() || $data->getStatutPartie() !== StatutPartieOuverte::Ouverte) {
            throw new ConflictHttpException('Cette partie n\'est plus ouverte à l\'inscription.');
        }

        $reservation = $data->getReservation();
        if ($reservation === null) {
            throw new ConflictHttpException('Partie sans réservation socle rattachée.');
        }
        if ($reservation->getParticipants()->count() >= 4) {
            throw new ConflictHttpException('Partie déjà complète (4/4).');
        }

        $corps = $this->lecteur->corps();
        $joueur = $this->resoudre(Beneficiaire::class, $corps['joueur'] ?? null, 'joueur');
        \assert($joueur instanceof Beneficiaire);

        foreach ($reservation->getParticipants() as $participant) {
            if ((string) $participant->getPersonne()?->getId() === (string) $joueur->getId()) {
                throw new ConflictHttpException('Ce joueur participe déjà à cette partie.');
            }
        }

        $etablissement = $reservation->getEtablissement();
        $niveau = $etablissement !== null
            ? $this->em->getRepository(NiveauJoueur::class)->findOneBy(['joueur' => $joueur, 'etablissement' => $etablissement])
            : null;

        if ($data->getNiveauViseMin() !== null || $data->getNiveauViseMax() !== null) {
            if ($niveau === null || !$niveau->estEligibleFiltrage()) {
                throw new UnprocessableEntityHttpException('Niveau non validé par le club : non éligible au filtrage de cette partie (CA-5).');
            }
            $valeur = $niveau->getNiveau();
            if (($data->getNiveauViseMin() !== null && $valeur < $data->getNiveauViseMin())
                || ($data->getNiveauViseMax() !== null && $valeur > $data->getNiveauViseMax())) {
                throw new UnprocessableEntityHttpException('Niveau du joueur hors de la fourchette visée par cette partie.');
            }
        }

        // ⚠ LA PART EST INDICATIVE, PAS UNE CRÉANCE. Maxime a tranché : l'organisateur reste
        // redevable du montant global, et le partage entre quatre est ce que les joueurs se
        // racontent entre eux — pas ce que l'établissement réclame.
        $partBase = number_format((float) $reservation->getMontantDu() / 4, 2, '.', '');
        $participant = new ParticipantReservation();
        $participant->setPersonne($joueur)
            ->setEstOrganisateur(false)
            ->setPartMontant($partBase)
            // ⚠ CETTE LIGNE POSAIT `Paye`, AVEC LE COMMENTAIRE « paiement à l'inscription (§4.3) ».
            // Il n'y avait aucun paiement : ni `Vente`, ni écriture, ni encaissement. Un joueur
            // apparaissait réglé sans qu'un centime soit entré, et la comptabilité ne voyait
            // jamais cette recette.
            //
            // `ImputeOrganisateur` dit ce qui est vrai : ce joueur ne doit rien, l'organisateur si.
            ->setStatutPaiement(StatutPaiementParticipant::ImputeOrganisateur);
        $reservation->addParticipant($participant);
        $this->em->persist($participant);

        if ($reservation->getParticipants()->count() >= 4) {
            $data->setStatutPartie(StatutPartieOuverte::Complete);
            $this->logger->info('padel.partie_ouverte.complete', ['reservation' => (string) $reservation->getId()]);
        }

        $this->em->flush();

        return $data;
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
