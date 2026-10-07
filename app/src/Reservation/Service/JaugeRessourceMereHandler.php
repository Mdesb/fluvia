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
 * **ACT-1 / D16 point 1** — le compteur bouge de la **quantité** de la réservation, pas de 1.
 *
 * ── ⚠ LES DEUX SENS S'ÉCRIVENT EN RELATIF, JAMAIS EN VALEUR ABSOLUE ───────────────────────────────
 *
 * Les deux méthodes lisaient la valeur EN MÉMOIRE, la modifiaient, et laissaient le `flush()` écrire
 * la valeur ABSOLUE. Une annulation chargeait « 5 », une réservation concurrente validait « 6 », puis
 * l'annulation écrivait « 4 » : l'unité de la réservation disparaissait du compteur, sans erreur, et
 * la jauge globale acceptait une réservation de trop. La base calcule donc les deux sens
 * (`occupation_courante ± quantité`), et la valeur en mémoire est marquée comme DÉJÀ ÉCRITE
 * (`UnitOfWork::setOriginalEntityProperty`) pour que le `flush()` ne la réécrive pas par-dessus.
 *
 * ── ⚠ LES DEUX SENS NE S'APPLIQUENT PAS AU MÊME MOMENT, ET C'EST LE POINT DÉLICAT ────────────────
 *
 * - **Incrémenter est IMMÉDIAT.** Le compteur doit porter la place dès qu'elle est prise : une
 *   réservation concurrente qui relit la porteuse sous verrou doit la voir. Différée, l'écriture
 *   laisserait une fenêtre où deux réservations lisent un compteur qui ignore l'autre — la
 *   surréservation que ce compteur existe pour empêcher. Si l'écriture de la réservation échoue
 *   ensuite, le compteur reste trop HAUT : une réservation refusée à tort, jamais une de trop.
 * - **Décrémenter attend le `postFlush`**, donc le `flush()` qui écrit le statut annulé. Appliquée
 *   avant, une place serait rendue par un `flush()` qui échoue ensuite : le compteur passerait sous
 *   la réalité, et la jauge accepterait une réservation de trop.
 *
 * Dans les deux cas, l'échec laisse le compteur trop haut plutôt que trop bas. `onClear` abandonne
 * les écritures en attente.
 *
 * ── ⚠ TOUT CHEMIN QUI CRÉE UNE RÉSERVATION DOIT INCRÉMENTER ──────────────────────────────────────
 *
 * Mesuré le 15/09/2026 : deux chemins sur sept incrémentaient (`ReserverProcessor`, promotion de
 * liste d'attente), alors que **toutes** les annulations décrémentent. Annuler une réservation créée
 * par un autre chemin — OTA musée, OTA boutique, commande boutique, confirmation de groupe, padel —
 * retirait donc une unité que personne n'avait posée. Le compteur dérivait vers le BAS, c'est-à-dire
 * vers la surréservation, et `GREATEST(…, 0)` le masquait à zéro. Les sept chemins incrémentent
 * désormais ; `BackfillResourceOccupancyCommand` recalcule un compteur déjà dérivé.
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

    public function incrementer(Ressource $ressource, int $quantite = 1): void
    {
        $porteuse = $ressource->ressourcePorteuseJauge();
        $nouvelle = $porteuse->getOccupationCourante() + $quantite;
        $porteuse->setOccupationCourante($nouvelle);

        if (!$this->suivieEnBase($porteuse, $nouvelle)) {
            return;
        }

        $this->em->getConnection()->executeStatement(
            'UPDATE reservation_ressource SET occupation_courante = occupation_courante + ? WHERE id = UNHEX(?)',
            [$quantite, $this->cle($porteuse)],
        );
    }

    public function decrementer(Ressource $ressource, int $quantite = 1): void
    {
        $porteuse = $ressource->ressourcePorteuseJauge();
        $nouvelle = max(0, $porteuse->getOccupationCourante() - $quantite);
        $porteuse->setOccupationCourante($nouvelle);

        if (!$this->suivieEnBase($porteuse, $nouvelle)) {
            return;
        }

        $cle = $this->cle($porteuse);
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

    /**
     * La ressource est-elle une ligne existante, dont le compteur se corrige en relatif ?
     *
     * Une ressource pas encore en base s'écrit entière à l'insertion : rien à corriger. Sinon, la
     * valeur en mémoire est marquée comme déjà écrite, pour que le `flush()` ne la réécrive pas.
     */
    private function suivieEnBase(Ressource $porteuse, int $valeur): bool
    {
        $uow = $this->em->getUnitOfWork();
        if ($uow->getEntityState($porteuse) !== UnitOfWork::STATE_MANAGED || $uow->isScheduledForInsert($porteuse)) {
            return false;
        }
        $uow->setOriginalEntityProperty(spl_object_id($porteuse), 'occupationCourante', $valeur);

        return true;
    }

    private function cle(Ressource $porteuse): string
    {
        return str_replace('-', '', (string) $porteuse->getId());
    }
}
