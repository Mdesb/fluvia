<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Reservation\Entity\Ressource;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

/**
 * Maintient `Ressource.occupationCourante` sur la ressource **porteuse** de la jauge (RG-M5-08) :
 * elle-même si non partageable / sans mère, sinon sa `ressourceMere`. Garantit que toute réservation
 * qui dépasserait la jauge globale est refusée même si un Créneau individuel a encore de la place
 * (CA-14).
 *
 * **ACT-1 / D16 point 1** — le compteur bouge de la **quantité** de la réservation, pas de 1. Le
 * défaut `$quantite = 1` n'est pas un confort d'appel : il rend la bascule exactement neutre pour
 * tout appelant antérieur à ce lot, et `jaugeDepassee()` sans argument garde son sens d'origine
 * (`occupation >= capacité` ⟺ `occupation + 1 > capacité`).
 *
 * ── ⚠ LE COMPTEUR PERDAIT DES UNITÉS, ET CE N'ÉTAIT PAS UN ARRONDI ────────────────────────────────
 *
 * Les deux méthodes lisaient la valeur EN MÉMOIRE, la modifiaient, et laissaient le `flush()` écrire
 * la valeur ABSOLUE. Une annulation chargeait « 5 », une réservation concurrente validait « 6 », puis
 * l'annulation écrivait « 4 » : l'unité de la réservation disparaissait du compteur, sans erreur, et
 * la jauge globale acceptait une réservation de trop. Deux annulations simultanées rendaient une place
 * au lieu de deux, dans l'autre sens.
 *
 * - **Incrémenter** reste une écriture en mémoire : ses deux appelants (`ReserverProcessor`,
 *   `PromotionListeAttenteHandler`) le font SOUS le verrou posé par `JaugeCreneauGuard::verrouiller()`,
 *   qui a relu la porteuse après l'attente. La valeur écrite part donc d'une lecture à jour. Un
 *   nouvel appelant qui incrémente hors de ce verrou réintroduirait le défaut.
 * - **Décrémenter** devient une écriture RELATIVE, `occupation_courante - quantité`, appliquée par la
 *   base. Elle n'a pas de verrou à prendre : si une réservation tient la ligne, elle attend sa
 *   validation puis retire de la valeur validée.
 *
 * La décrémentation est appliquée APRÈS le `flush()` de l'appelant (`postFlush`), jamais avant : les
 * annulations écrivent leur statut par ce `flush()`. Appliquée avant, un `flush()` qui échoue aurait
 * rendu une place sans annuler la réservation, et le compteur descendrait sous la réalité. Si c'est
 * la décrémentation qui échoue après coup, le compteur reste trop HAUT : une réservation refusée à
 * tort, jamais une surréservation.
 */
#[AsDoctrineListener(event: Events::postFlush)]
#[AsDoctrineListener(event: Events::onClear)]
final class JaugeRessourceMereHandler
{
    /** @var array<string, int> identifiant hexadécimal de la ressource porteuse => unités à rendre */
    private array $decrementsEnAttente = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** À n'appeler que sous `JaugeCreneauGuard::verrouiller()` — voir le docblock de la classe. */
    public function incrementer(Ressource $ressource, int $quantite = 1): void
    {
        $porteuse = $ressource->ressourcePorteuseJauge();
        $porteuse->setOccupationCourante($porteuse->getOccupationCourante() + $quantite);
    }

    public function decrementer(Ressource $ressource, int $quantite = 1): void
    {
        $porteuse = $ressource->ressourcePorteuseJauge();
        $nouvelle = max(0, $porteuse->getOccupationCourante() - $quantite);
        $porteuse->setOccupationCourante($nouvelle);

        $uow = $this->em->getUnitOfWork();
        if ($uow->getEntityState($porteuse) !== UnitOfWork::STATE_MANAGED || $uow->isScheduledForInsert($porteuse)) {
            // Une ressource pas encore en base s'écrit entière à l'insertion : rien à rendre en relatif.
            return;
        }

        // La valeur en mémoire reste lisible par la suite de la requête, mais le `flush()` doit la
        // croire DÉJÀ ÉCRITE : sinon il réécrirait une valeur absolue calculée sur une lecture
        // périmée — exactement le défaut corrigé ici.
        $uow->setOriginalEntityProperty(spl_object_id($porteuse), 'occupationCourante', $nouvelle);

        $cle = str_replace('-', '', (string) $porteuse->getId());
        $this->decrementsEnAttente[$cle] = ($this->decrementsEnAttente[$cle] ?? 0) + $quantite;
    }

    /**
     * `$quantiteDemandee` est ce qu'on s'apprête à poser sur la jauge, pas ce qui s'y trouve déjà :
     * la question est « est-ce que cette demande **ferait** déborder », et une école à deux places
     * libres doit refuser un groupe de cinq sans être complète.
     */
    public function jaugeDepassee(Ressource $ressource, int $quantiteDemandee = 1): bool
    {
        $porteuse = $ressource->ressourcePorteuseJauge();

        return $porteuse->getOccupationCourante() + $quantiteDemandee > $porteuse->getCapacitePropre();
    }

    /** Applique, en relatif, les unités rendues depuis le dernier `flush()` réussi. */
    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->decrementsEnAttente === []) {
            return;
        }

        $enAttente = $this->decrementsEnAttente;
        $this->decrementsEnAttente = [];

        $connexion = $this->em->getConnection();
        foreach ($enAttente as $cle => $quantite) {
            $connexion->executeStatement(
                'UPDATE reservation_ressource SET occupation_courante = GREATEST(occupation_courante - ?, 0) WHERE id = UNHEX(?)',
                [$quantite, $cle],
            );
        }
    }

    /** Un `clear()` abandonne les écritures en attente : les unités rendues avec elles aussi. */
    public function onClear(OnClearEventArgs $args): void
    {
        $this->decrementsEnAttente = [];
    }
}
