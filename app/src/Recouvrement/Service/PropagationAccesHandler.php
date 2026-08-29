<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\StatutProjectionDroit;
use App\Recouvrement\Entity\IncidentImpaye;
use App\Recouvrement\Event\AccesRedevableChangeEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Seul point d'écriture du moteur de recouvrement sur `DroitAcces.statutProjection` (extrait de
 * `App\Sport\Service\PropagationAccesFitnessHandler`, généralisé). **Aucun fichier `App\Acces\*` n'est
 * modifié** : le moteur de recouvrement devient un producteur légitime de cette transition, exactement
 * comme M2/Sport. Le hors-ligne/synchro est hérité intégralement du mécanisme générique L3 — aucun
 * développement supplémentaire ici. Résout le `DroitAcces` via `RedevableRegistry` (port fourni par la
 * verticale) puis dispatche `AccesRedevableChangeEvent` pour que la verticale tienne à jour sa propre
 * projection métier (motif d'inactivité, etc.).
 */
final class PropagationAccesHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RedevableRegistry $redevables,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * Ouvre la porte, sans rien vérifier.
     *
     * ⚠ @internal — DANS LE DOMAINE DU RECOUVREMENT, APPELEZ `reevaluer()` À LA PLACE.
     *
     * Cette méthode est une PRIMITIVE : elle exécute, elle ne décide pas. Or la porte se ferme par
     * REDEVABLE tandis que le drapeau `accesBloque` se pose par DOSSIER — un même client peut avoir
     * plusieurs impayés, et l'appeler après en avoir réglé un rouvrirait l'accès alors qu'un autre
     * reste dû. C'est le défaut corrigé le 30/08 : le tableau de bord comptait un « accès bloqué »
     * dont la porte était ouverte, et un client qui devait encore de l'argent entrait parce qu'il
     * avait réglé autre chose.
     *
     * `reevaluer()` porte cette décision. Elle est juste en dessous, et c'est elle qu'on veut
     * presque toujours — cet avertissement est ici parce que `activer()` est le nom le plus évident
     * des deux, donc celui qu'on trouve en premier sans lire l'autre.
     *
     * @see self::reevaluer()
     */
    public function activer(string $typeRedevable, string $referenceRedevable): void
    {
        $this->appliquer($typeRedevable, $referenceRedevable, true);
    }

    /**
     * ⚠ RÉÉVALUE AU LIEU D'OUVRIR : la porte se ferme par REDEVABLE, le drapeau se pose par DOSSIER.
     *
     * `activer()` remet le droit à « valide » sans rien regarder — c'est une primitive, et elle doit
     * le rester. Mais un même client peut porter plusieurs impayés : rien n'empêche deux incidents
     * simultanés, un abonnement mensuel rejeté deux mois de suite suffit. Régler celui de mars
     * appelait `activer()` et rouvrait la porte alors qu'avril restait dû.
     *
     * Deux conséquences, et la seconde est la pire : le tableau de bord comptait un « accès bloqué »
     * dont la porte était ouverte, et un client qui devait encore de l'argent retrouvait son accès
     * parce qu'il avait réglé AUTRE CHOSE.
     *
     * ⚠ L'ASYMÉTRIE EST VOULUE. Un seul impayé bloquant suffit à fermer ; il faut qu'ils soient TOUS
     * levés pour rouvrir. Fermer sur un doute est réparable d'un clic ; ouvrir à tort ne se rattrape
     * pas — la personne est déjà entrée.
     */
    public function reevaluer(string $typeRedevable, string $referenceRedevable): void
    {
        $bloquantsRestants = (int) $this->em->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(IncidentImpaye::class, 'i')
            ->andWhere('i.typeRedevable = :type')
            ->andWhere('i.referenceRedevable = :reference')
            ->andWhere('i.accesBloque = true')
            ->setParameter('type', $typeRedevable)
            ->setParameter('reference', $referenceRedevable)
            ->getQuery()
            ->getSingleScalarResult();

        if ($bloquantsRestants > 0) {
            return;
        }

        $this->activer($typeRedevable, $referenceRedevable);
    }

    /** Coupe l'accès (impayé non régularisé, selon la politique). */
    public function desactiver(string $typeRedevable, string $referenceRedevable): void
    {
        $this->appliquer($typeRedevable, $referenceRedevable, false);
    }

    private function appliquer(string $typeRedevable, string $referenceRedevable, bool $actif): void
    {
        $droit = $this->redevables->droitAcces($typeRedevable, $referenceRedevable);
        $etablissementId = null;
        if ($droit instanceof DroitAcces) {
            $droit->setStatutProjection($actif ? StatutProjectionDroit::Valide : StatutProjectionDroit::Devalide);
            $this->em->flush();
            // C12 (RG-PLAT-03) — l'établissement du droit résolu rend l'événement pontable (tenant D6).
            $etablissement = $droit->getEtablissement();
            $etablissementId = $etablissement !== null ? (string) $etablissement->getId() : null;
        }

        $this->dispatcher->dispatch(new AccesRedevableChangeEvent($typeRedevable, $referenceRedevable, $actif, $etablissementId));
    }
}
