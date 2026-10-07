<?php

declare(strict_types=1);

namespace App\Securite\Service;

use App\Securite\Entity\Affectation;
use App\Securite\Entity\DelegationDroit;
use App\Securite\Port\SupportAccessRightsInterface;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutDelegation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Calcule les droits effectifs d'un utilisateur (RG-SOCLE-04) : union des permissions des
 * affectations, ET des délégations actives non expirées (§2.5 plan-backoffice.md, US-L7-07), sur
 * l'établissement actif. Supporte les jokers (`*.lire`, `organisation.*`).
 *
 * NB « conflit → plus restrictive » : le modèle ne connaît que des permissions accordées
 * (pas de refus explicite), l'union est donc additive ; aucune permission ne peut en révoquer
 * une autre. La règle reste documentée pour un éventuel mécanisme de refus ultérieur.
 */
final class CalculateurDroits
{
    /**
     * LES TROIS DROITS QUE PORTE TOUT COMPTE RATTACHE A UN ETABLISSEMENT.
     *
     * Demander de l'aide n'est pas une fonctionnalite qu'on achete, ni un role qu'un exploitant
     * doit penser a distribuer. Le jour ou sa caisse ne s'ouvre pas, l'agent d'accueil doit
     * pouvoir le dire — et il ne peut pas, si le droit de le dire depend d'un role que personne
     * ne lui a donne. La porte de l'assistance etait donc fermee exactement pour les comptes qui
     * en ont le plus besoin : ceux qu'on n'a pas configures.
     *
     * On accorde donc trois droits, et strictement trois : lire la base de connaissances, ouvrir
     * un ticket, suivre LES SIENS. Rien de plus. Traiter la file, lire les tickets des autres,
     * ecrire la base restent des roles — l'assistance est ouverte a tous, elle n'est pas
     * administrable par tous.
     *
     * @var list<string>
     */
    private const ASSISTANCE_DE_SOCLE = [
        'support.lire',
        'support.ouvrir_ticket',
        'support.lire_ticket_soi',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SupportAccessRightsInterface $supportAccessRights,
        private readonly PlatformScope $platformScope,
    ) {
    }

    /**
     * Codes « module.action » effectifs de l'utilisateur, bornés à l'établissement actif si fourni.
     *
     * @return list<string>
     */
    public function codesEffectifs(Utilisateur $utilisateur, ?Uuid $etablissementActif): array
    {
        // ÉQUIPE PLATEFORME (mono-propriétaire) : tous les droits, partout. Voir `PlatformScope` pour
        // l'exception assumée à RG-ED-07. `*.*` est déjà interprété par `autorise()` (jokers) et par le
        // front (`couvre()`) : un membre plateforme a donc l'UI et l'API complètes sur le site actif.
        if ($this->platformScope->isGlobal($utilisateur)) {
            return ['*.*'];
        }

        $criteres = ['utilisateur' => $utilisateur];
        $etablissement = null;
        if ($etablissementActif !== null) {
            $etablissement = $this->em->getRepository(\App\Organisation\Entity\Etablissement::class)->find($etablissementActif);
            if ($etablissement === null) {
                return [];
            }
            $criteres['etablissement'] = $etablissement;
        }

        /** @var list<Affectation> $affectations */
        $affectations = $this->em->getRepository(Affectation::class)->findBy($criteres);

        $codes = [];
        // Rattachement, et non « a des droits » : un role vide est un role quand meme. C'est
        // l'appartenance a l'etablissement qui ouvre l'assistance, pas le contenu du role.
        $rattache = false;
        foreach ($affectations as $affectation) {
            $role = $affectation->getRole();
            if ($role === null) {
                continue;
            }
            $rattache = true;
            foreach ($role->getPermissions() as $permission) {
                $codes[$permission->getCode()] = true;
            }
        }

        // Délégations actives (§2.5) : même symétrie que les affectations — si aucun établissement
        // actif n'est fourni, les délégations actives de tous les établissements sont incluses.
        $criteresDelegation = ['beneficiaire' => $utilisateur, 'statut' => StatutDelegation::Active];
        if ($etablissement !== null) {
            $criteresDelegation['etablissement'] = $etablissement;
        }

        /** @var list<DelegationDroit> $delegations */
        $delegations = $this->em->getRepository(DelegationDroit::class)->findBy($criteresDelegation);

        $maintenant = new \DateTimeImmutable();
        foreach ($delegations as $delegation) {
            // Double garde défensive (statut déjà mis à jour par la commande planifiée en cas
            // normal) contre un retard d'exécution de `securite:delegations:expirer`.
            if (!$delegation->estActiveMaintenant($maintenant)) {
                continue;
            }
            $role = $delegation->getRole();
            if ($role === null) {
                continue;
            }
            $rattache = true;
            foreach ($role->getPermissions() as $permission) {
                $codes[$permission->getCode()] = true;
            }
        }

        // --- L'ASSISTANCE EST DU SOCLE, PAS UNE OPTION ---
        //
        // Place APRES les affectations et les delegations, et avant l'acces d'assistance de
        // l'editeur : ce bloc ne peut qu'AJOUTER, il ne retire ni ne remplace rien. Un compte qui
        // porte deja `support.administrer` par son role le garde ; il gagne ici, au pire, des
        // droits qu'il possedait.
        //
        // Garde par le rattachement : un compte sans aucune affectation sur l'etablissement actif
        // n'en obtient rien. Sans cette garde, `support.lire_ticket_soi` s'accorderait sur un
        // etablissement ou l'on n'est pas — le cloisonnement le rattraperait (les extensions
        // Doctrine filtrent), mais on aurait fait dependre l'etancheite d'un second rempart au
        // lieu du premier.
        if ($rattache) {
            foreach (self::ASSISTANCE_DE_SOCLE as $codeSocle) {
                $codes[$codeSocle] = true;
            }
        }

        // --- Accès d'assistance de l'éditeur (ED-4, RG-ED-07) ---
        //
        // **Ce n'est PAS une exception au cloisonnement, c'est le seul chemin légitime pour la
        // franchir.** Le cloisonnement ordinaire refuse déjà à un agent de l'éditeur l'établissement
        // d'un client : pas d'affectation, pas de droits, 404. Le risque n'était donc pas l'accès non
        // autorisé — c'était le **contournement**.
        //
        // Le jour où un client appelle parce que sa caisse ne s'ouvre pas, quelqu'un doit regarder ses
        // données. Sans chemin praticable, la seule façon est de donner à l'agent une **affectation
        // permanente** sur l'établissement du client : invisible, indistinguable d'une affectation
        // normale, que personne ne pensera à retirer. C'est exactement ce que RG-ED-07 interdit, obtenu
        // par la porte de service. **Une règle sans chemin praticable ne tient pas.**
        //
        // ⚠ **Placé APRÈS les affectations et les délégations, et non à leur place.** Un agent qui a
        // par ailleurs des droits légitimes les garde ; l'accès d'assistance n'ajoute que la lecture.
        // Le port rend un tableau vide dans tous les cas douteux, donc ce bloc ne peut qu'ajouter.
        //
        // ⚠ **L'appel vaut usage** : l'implémentation trace. Savoir qui *pouvait* regarder n'est pas
        // savoir qui a regardé. Le volume reste borné — un accès d'assistance est exceptionnel par
        // construction, et s'il produit beaucoup d'entrées, c'est une information et pas du bruit.
        //
        // Sans établissement actif, rien : un accès d'assistance est nominatif ET ciblé, et l'accorder
        // « partout » reviendrait à recréer le rôle qui voit tous les établissements.
        if ($etablissement !== null) {
            foreach ($this->supportAccessRights->grantedCodes($utilisateur, $etablissement, $maintenant) as $code) {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * Vrai si l'un des codes couvre « module.action », jokers inclus.
     *
     * @param list<string> $codes
     */
    public function autorise(array $codes, string $module, string $action): bool
    {
        foreach ($codes as $code) {
            [$m, $a] = array_pad(explode('.', $code, 2), 2, '');
            if (($m === $module || $m === '*') && ($a === $action || $a === '*')) {
                return true;
            }
        }

        return false;
    }
}
