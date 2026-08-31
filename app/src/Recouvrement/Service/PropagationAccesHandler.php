<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\StatutProjectionDroit;
use App\Recouvrement\Entity\IncidentImpaye;
use App\Recouvrement\Event\AccesRedevableChangeEvent;
use App\Recouvrement\Port\BlockingExemptionLookup;
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
        private readonly BlockingExemptionLookup $exemptions,
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
        // ⚠ L'EXEMPTION SE CONSULTE AVANT LE COMPTE, SINON ELLE NE SERVIRAIT QU'AUX IMPAYES FUTURS.
        //
        // Le cas qui a motivé la fonctionnalité est une collectivité **déjà bloquée** au moment où
        // l'on décide de l'exempter. Si l'exemption n'était lue qu'à la fermeture, poser l'exemption
        // ne rouvrirait rien : il faudrait encore forcer chaque dossier à la main, c'est-à-dire
        // exactement ce qu'elle remplace.
        if ($this->exemptions->estExempte($typeRedevable, $referenceRedevable)) {
            $this->activer($typeRedevable, $referenceRedevable);

            return;
        }

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

        // ⚠ ON CONCLUT DANS LES DEUX SENS. La version d'origine sortait quand il restait des
        // bloquants : juste tant que la fonction n'etait appelee que depuis une porte DEJA fermee —
        // ce qui etait le cas de ses trois appelants, et que rien n'ecrivait nulle part. Au retrait
        // d'une exemption la porte est OUVERTE : ne rien faire y laisse entrer un client qui doit
        // encore de l'argent.
        $this->conclure($typeRedevable, $referenceRedevable, $bloquantsRestants === 0);
    }

    /**
     * Porte l'etat voulu, et n'ecrit que s'il differe de l'etat courant.
     *
     * `appliquer()` publie `AccesRedevableChangeEvent` a chaque appel. Un evenement qui annonce un
     * changement qui n'a pas eu lieu ne casse rien ici — le pont l'exclut des notifications et son
     * unique ecouteur est idempotent — mais c'est un mensonge bon marche, et ceux-la finissent par
     * etre crus.
     */
    private function conclure(string $typeRedevable, string $referenceRedevable, bool $ouvrir): void
    {
        $droit = $this->redevables->droitAcces($typeRedevable, $referenceRedevable);
        if ($droit instanceof DroitAcces) {
            $voulu = $ouvrir ? StatutProjectionDroit::Valide : StatutProjectionDroit::Devalide;
            if ($droit->getStatutProjection() === $voulu) {
                return;
            }
        }

        $this->appliquer($typeRedevable, $referenceRedevable, $ouvrir);
    }

    /**
     * Coupe l'accès (impayé non régularisé, selon la politique) — sauf redevable exempté.
     *
     * ⚠ LA GARDE EST ICI, PAS CHEZ LES DEUX APPELANTS.
     *
     * `MoteurRecouvrementHandler` coupe à deux endroits distincts (échéance dépassée, N
     * représentations échouées). Recopier la condition aux deux ferait diverger la règle au premier
     * correctif ; la poser au point unique où la porte se ferme la rend vraie pour tout appelant
     * futur, y compris celui qu'on n'a pas encore écrit.
     *
     * ⚠ ET L'EXEMPTION NE MASQUE PAS L'IMPAYE. Le drapeau `IncidentImpaye::accesBloque` continue
     * d'être posé par le moteur, le dossier reste dû, la relance continue. Ce qui est exempté est la
     * **conséquence sur la porte**, pas la dette : le tableau de bord doit toujours montrer que ce
     * client doit de l'argent.
     */
    public function desactiver(string $typeRedevable, string $referenceRedevable): void
    {
        if ($this->exemptions->estExempte($typeRedevable, $referenceRedevable)) {
            return;
        }

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
