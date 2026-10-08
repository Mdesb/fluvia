<?php

declare(strict_types=1);

namespace App\Securite\Controller;

use App\Securite\Service\EstablishmentReachability;
use App\Fonctionnalite\Service\Fonctionnalites;
use App\Securite\Entity\Utilisateur;
use App\Organisation\Service\EditorTenantResolver;
use App\Securite\Service\CalculateurDroits;
use App\Platform\Notification\ExpediteurCourriel;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Profil de l'utilisateur courant + droits effectifs sur l'établissement actif (US-L0-05).
 * `capacitesActives` (module `App\Fonctionnalite`) permet à l'UI de n'afficher que les fonctionnalités
 * pertinentes pour l'établissement actif (règle d'or §2 constitution.md) — champ additif, ne modifie
 * aucun champ existant du contrat `/me`.
 */
#[AsController]
final class MeController
{
    /** Nom de route lu par `EstablishmentHeaderListener` pour exempter cette route — et elle seule. */
    public const ROUTE = 'securite_me';

    public function __construct(
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
        private readonly EstablishmentReachability $reachability,
        private readonly CalculateurDroits $calculateur,
        private readonly Fonctionnalites $fonctionnalites,
        private readonly EditorTenantResolver $editeur,
        private readonly ExpediteurCourriel $courriel,
    ) {
    }

    #[Route('/me', name: self::ROUTE, methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return new JsonResponse(['message' => 'Non authentifié.'], 401);
        }

        $etablissementActif = $this->contexte->idActif();

        // ⚠ /me EST LA SEULE ROUTE OÙ UN EN-TÊTE HORS PÉRIMÈTRE NE VAUT PAS 404 — parce que c'est par
        //   elle que l'écran se remet d'un établissement mémorisé qui ne lui appartient plus (accès
        //   retiré, site supprimé) : `App.jsx` relit la liste et corrige le stockage local. Un 404 ici
        //   laisserait l'écran sans issue. Mais on ne RÉPOND PAS POUR AUTANT SUR CE SITE : il est traité
        //   comme absent — aucun droit qui lui soit propre, aucune capacité, aucun nom. Partout ailleurs,
        //   `EstablishmentHeaderListener` ferme (audit du 06/09, constat 3).
        if ($etablissementActif !== null
            && !$this->reachability->canReach($utilisateur, $etablissementActif, new \DateTimeImmutable())) {
            $etablissementActif = null;
        }
        $etablissementActifEntite = $etablissementActif !== null ? $this->contexte->etablissementActif() : null;

        return new JsonResponse([
            'id' => (string) $utilisateur->getId(),
            'email' => $utilisateur->getEmail(),
            'nom' => $utilisateur->getNom(),
            'actif' => $utilisateur->isActif(),
            // La langue préférée de la personne, `null` pour suivre celle de l'établissement : le
            // frontal en tire la langue d'affichage (`applyContextLanguage`). Champ additif.
            'locale' => $utilisateur->getLocale(),
            'etablissementActif' => $etablissementActif !== null ? (string) $etablissementActif : null,
            'droits' => $this->calculateur->codesEffectifs($utilisateur, $etablissementActif),
            // ⚠ « SUIS-JE CHEZ MOI OU CHEZ UN CLIENT ? » — la question que se pose un employé de
            // l'éditeur entré sur le site d'un client par un accès d'assistance. Sans réponse à
            // l'écran, il écrira une note au mauvais endroit, ou lira des chiffres en croyant que
            // ce sont ceux de l'éditeur.
            //
            // `isEditor()` ne lève jamais et rend `false` quand la désignation manque : fermé par
            // défaut. Un déploiement sans `EDITOR_TENANT_ID` n'a donc pas d'éditeur — ce qui est
            // exact, et non « tout le monde l'est ».
            'estEditeur' => $this->editeur->isEditor($etablissementActifEntite),
            'capacitesActives' => $etablissementActifEntite !== null ? $this->fonctionnalites->actives($etablissementActifEntite) : [],
            // ⚠ « MON COURRIEL PARTIRA-T-IL ? » — un fait d'exécution, pas une constante.
            //
            // Six services de ce dépôt composent un courriel et n'envoient rien : le transport
            // nul avale tout en silence. Les écrans doivent cesser de promettre ces envois — et
            // surtout cesser de le promettre le jour où ils marcheront. Une phrase écrite en dur
            // serait vraie aujourd'hui et fausse au premier expéditeur branché, en six
            // exemplaires, sans que rien ne relie la phrase à ce qui l'a rendue fausse.
            //
            // Publié ici parce qu'aucun écran ne se rend avant d'avoir reçu `/me` — c'est un
            // garde-fou du dépôt, donc l'information est disponible partout, gratuitement.
            'envoiCourrielBranche' => $this->courriel->estBranche(),
        ]);
    }
}
