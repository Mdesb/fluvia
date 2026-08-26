<?php

declare(strict_types=1);

namespace App\Platform\DataFixtures;

use App\Organisation\Entity\Affectation;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use Doctrine\Persistence\ObjectManager;

/**
 * Chercher avant de créer — le geste que quinze fixtures ont dû réapprendre séparément.
 *
 * **Pourquoi ce trait existe.** Le 24/08, régénérer les données de démonstration de la préproduction a
 * échoué en cours de route, **après avoir tronqué la table des rattachements droits-rôles** : les
 * trente-quatre rôles de la préprod se sont retrouvés à zéro droit, et Maxime n'a plus pu tester qu'avec
 * son propre compte — donc plus aucun écran du point de vue d'un caissier ou d'un responsable.
 *
 * La cause n'était pas l'incident : **un chargement complet n'avait jamais fonctionné**. Quatorze
 * fixtures créaient aveuglément des objets à contrainte d'unicité.
 *
 * **Pourquoi rien ne l'avait vu.** Le harnais recrée le schéma depuis les entités à chaque classe de
 * test : les fixtures partent donc toujours d'une base vide, et elles sont chargées sélectivement. Le
 * seul geste qui révèle le défaut — charger deux fois — n'était fait nulle part.
 *
 * **⚠ ET LA MOITIÉ DES CAS NE LÈVE AUCUNE ERREUR.** C'est la leçon la plus chère de la journée (D52) :
 * une entité dont la seule unicité porte sur son **identifiant technique**, régénéré à chaque
 * construction, ne produit aucun « Duplicate entry » — elle se **duplique en silence**. `Etablissement`
 * en fait partie, et c'est la frontière sur laquelle repose tout le cloisonnement : deux établissements
 * du même nom, et la question « cet utilisateur a-t-il le droit ? » a deux réponses selon la ligne
 * qu'on lit.
 *
 * **Donc : inventorie ce que ta fixture construit, ne suis pas ce qui casse.** Six fois dans la journée,
 * corriger la seule famille qui criait a fait avancer le curseur d'un cran sans rendre la fixture
 * idempotente.
 *
 *     grep -oE 'new [A-Z][A-Za-z]+\(\)' ta-fixture.php | sort | uniq -c
 *
 * **Comment vérifier — et comment ne pas se tromper de vérification.** Relancer ta suite ne prouve
 * rien : elle passait déjà avant. Le seul contrôle qui compte est :
 *
 *     ./infra/test-stack.sh run <ton-token> tests/FixturesIdempotentesTest.php
 *
 * Il charge tout deux fois **et compte les lignes** de chaque table avant et après — c'est ce qui
 * attrape les duplications silencieuses. « Le nombre de lignes ne change pas » est la définition de
 * l'idempotence ; « ça ne plante pas » n'en est que le symptôme le plus bruyant.
 */
trait FixturesIdempotentes
{
    /**
     * Une entité identifiée par son nom : `Groupe`, `Region`, `Etablissement`, et tout référentiel
     * nommé. **Aucune de ces trois-là ne lève au rechargement** — elles se dupliquent.
     *
     * @template T of object
     * @param class-string<T> $classe
     * @return T
     */
    private function parNom(ObjectManager $manager, string $classe, string $nom): object
    {
        $existant = $manager->getRepository($classe)->findOneBy(['nom' => $nom]);

        if ($existant !== null) {
            return $existant;
        }

        $entite = new $classe();
        $entite->setNom($nom);
        $manager->persist($entite);

        return $entite;
    }

    /**
     * Une entité identifiée par un code métier. Même remarque : la plupart ne portent pas d'unicité
     * en base, donc elles ne crient pas.
     *
     * @template T of object
     * @param class-string<T> $classe
     * @return T
     */
    private function parCode(ObjectManager $manager, string $classe, string $code): object
    {
        $existant = $manager->getRepository($classe)->findOneBy(['code' => $code]);

        if ($existant !== null) {
            return $existant;
        }

        $entite = new $classe();
        $entite->setCode($code);
        $manager->persist($entite);

        return $entite;
    }

    /** `Role.nom` porte une unicité **globale** : deux modules qui créent le même nom se heurtent. */
    private function roleNomme(ObjectManager $manager, string $nom): Role
    {
        $existant = $manager->getRepository(Role::class)->findOneBy(['nom' => $nom]);

        if ($existant instanceof Role) {
            return $existant;
        }

        $role = (new Role())->setNom($nom);
        $manager->persist($role);

        return $role;
    }

    /** Le couple `(module, action)` porte une unicité globale. */
    private function permissionNommee(ObjectManager $manager, string $module, string $action): Permission
    {
        $existante = $manager->getRepository(Permission::class)
            ->findOneBy(['module' => $module, 'action' => $action]);

        if ($existante instanceof Permission) {
            return $existante;
        }

        $permission = (new Permission())->setModule($module)->setAction($action);
        $manager->persist($permission);

        return $permission;
    }

    /**
     * Le mot de passe n'est posé **qu'à la création** : le rejouer écraserait un mot de passe changé
     * depuis, et réécrirait un hachage pour rien à chaque chargement.
     *
     * @param callable(Utilisateur): string $hacher rend le mot de passe haché pour cet utilisateur
     */
    private function utilisateurParEmail(
        ObjectManager $manager,
        string $email,
        string $nom,
        callable $hacher,
    ): Utilisateur {
        $existant = $manager->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);

        if ($existant instanceof Utilisateur) {
            return $existant->setNom($nom)->setActif(true);
        }

        $utilisateur = (new Utilisateur())->setEmail($email)->setNom($nom)->setActif(true);
        $utilisateur->setMotDePasse($hacher($utilisateur));
        $manager->persist($utilisateur);

        return $utilisateur;
    }

    /**
     * **Le cas le plus vicieux, et le seul qui ne casse jamais.**
     *
     * `Affectation` ne porte **aucune** contrainte d'unicité en base. Un second chargement n'échoue
     * donc pas : il **empile des doublons**, silencieusement. Et les droits effectifs d'un utilisateur
     * se calculent en parcourant ses affectations — un doublon n'est pas cosmétique, c'est un calcul de
     * droits qui repose sur des données fausses.
     *
     * Trouvé par `claude-G` en cherchant tout autre chose.
     */
    private function affectationUnique(
        ObjectManager $manager,
        Utilisateur $utilisateur,
        Role $role,
        Etablissement $etablissement,
    ): void {
        $existante = $manager->getRepository(Affectation::class)->findOneBy([
            'utilisateur' => $utilisateur,
            'role' => $role,
            'etablissement' => $etablissement,
        ]);

        if ($existante instanceof Affectation) {
            return;
        }

        $manager->persist(
            (new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etablissement)
        );
    }
}
