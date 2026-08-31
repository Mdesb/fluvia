<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Reservation;
use App\Reservation\Entity\Ressource;
use App\Reservation\Service\ChevauchementCreneauGuard;
use App\Reservation\Port\NotificationReservationInterface;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Validation manuelle d'un conflit de récurrence (RG-M5-11, CA-7) : un rôle habilité
 * (`reservation.arbitrer_recurrence`) tranche — soit en réaffectant une ressource (même si moins
 * équivalente), soit en confirmant le créneau tel quel — et le(s) bénéficiaire(s) sont notifiés.
 *
 * @implements ProcessorInterface<mixed, Creneau>
 */
final class ArbitrerConflitRecurrenceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ChevauchementCreneauGuard $chevauchement,
        private readonly NotificationReservationInterface $notification,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Creneau
    {
        \assert($data instanceof Creneau);

        $corps = $this->lecteur->corps();
        if (isset($corps['ressource']) && \is_string($corps['ressource'])) {
            $segment = str_contains($corps['ressource'], '/') ? basename($corps['ressource']) : $corps['ressource'];
            if (Uuid::isValid($segment)) {
                $ressource = $this->em->getRepository(Ressource::class)->find(Uuid::fromString($segment));
                // D3/D8 — la ressource vient du CORPS de la requete ; le perimetre vient du creneau,
                // lui-meme filtre par `PerimetreReservationExtension`. Sans cette confrontation, un
                // exploitant d'un etablissement pouvait deplacer son creneau sur la ressource d'un
                // autre : la ressource n'etait resolue que par son identifiant.
                //
                // Echec ferme, et 404 plutot que 403 : repondre « interdit » confirmerait que cet
                // identifiant existe ailleurs. L'ignorer silencieusement aurait ete pire encore —
                // l'arbitrage aurait rendu 200 sans avoir rien change.
                if (!$ressource instanceof Ressource
                    || (string) $ressource->getEtablissement()?->getId() !== (string) $data->getEtablissement()?->getId()) {
                    throw new NotFoundHttpException('Ressource introuvable.');
                }

                // ⚠ ET ELLE DOIT ETRE LIBRE. Sans ce controle, arbitrer DEPLACE le conflit au
                // lieu de le resoudre : le creneau quitte un chevauchement pour un autre, le
                // drapeau tombe, et l'ecran annonce « arbitre ». Deux personnes recevraient la meme
                // ressource a la meme heure — ce que RG-M5-03 interdit a la creation, obtenu par la
                // porte de derriere.
                //
                // Le trou etait inatteignable tant que rien ne produisait d'etat « en attente
                // d'arbitrage ». Il devient atteignable dans le meme lot que l'ecran qui arbitre :
                // on le ferme donc avant d'ouvrir la porte.
                //
                // Meme garde que la creation, deliberement : une seconde regle ecrite ici finirait
                // par diverger de celle qui fait foi. Le creneau s'exclut lui-meme, sinon il se
                // verrait comme son propre conflit.
                if ($this->chevauchement->enConflit($ressource, $data->getDebut(), $data->getFin(), $data->getId())) {
                    throw new ConflictHttpException(sprintf(
                        'La ressource « %s » est déjà occupée sur cette fenêtre : l\'arbitrage '
                        .'déplacerait le conflit au lieu de le résoudre (RG-M5-03).',
                        $ressource->getLibelle(),
                    ));
                }

                $data->setRessource($ressource);
                $data->setOccurrenceModifiee(true);
            }
        }

        $data->setEnAttenteArbitrage(false);
        $this->em->flush();

        /** @var list<Reservation> $reservations */
        $reservations = $this->em->getRepository(Reservation::class)->findBy(['creneau' => $data]);
        foreach ($reservations as $reservation) {
            $this->notification->notifierArbitrageRecurrence($reservation, 'Conflit de récurrence arbitré manuellement (RG-M5-11).');
        }

        return $data;
    }
}
