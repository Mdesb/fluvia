<?php

declare(strict_types=1);

namespace App\Platform\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Purger\PurgerFactory;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\Common\DataFixtures\Purger\PurgerInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le purgeur de `doctrine:fixtures:load` refuse de vider une base hors `dev` et `test`.
 *
 * **Pourquoi ce garde existe (T6).** Charger les données de démonstration en préproduction demande que
 * `DoctrineFixturesBundle` y soit actif. Or sa commande **purge la base par défaut** : elle vide avant
 * d'écrire. C'est exactement ce qui s'est produit le 24/08 — le chargement a tronqué la table des
 * rattachements droits-rôles puis a échoué en cours de route, laissant les trente-quatre rôles de la
 * préproduction à zéro droit. Rendre la commande disponible sans neutraliser sa purge reviendrait à
 * réarmer l'incident, avec la même détente.
 *
 * **Le refus est posé sur la purge, pas sur la construction, et cette nuance est tout le garde.** La
 * commande construit son purgeur **même en `--append`** (`LoadDataFixturesDoctrineCommand`, ligne 120)
 * : refuser dès la fabrique casserait le rechargement additif, qui est précisément le mode dont la
 * préproduction a besoin. On rend donc un purgeur qui laisse passer tant qu'on ne lui demande pas de
 * vider, et qui refuse bruyamment si on le lui demande.
 *
 * **En `dev` et `test`, rien ne change** : le purgeur d'origine est rendu tel quel. Le harnais de test
 * recrée le schéma à chaque classe et dépend de ce comportement.
 */
final class PurgeurInterditHorsDeveloppement implements PurgerFactory
{
    public function __construct(private readonly string $environnement)
    {
    }

    public function createForEntityManager(
        ?string $emName,
        EntityManagerInterface $em,
        array $excluded = [],
        bool $purgeWithTruncate = false,
    ): PurgerInterface {
        $purgeur = new ORMPurger($em, $excluded);
        $purgeur->setPurgeMode($purgeWithTruncate ? ORMPurger::PURGE_MODE_TRUNCATE : ORMPurger::PURGE_MODE_DELETE);

        if (\in_array($this->environnement, ['dev', 'test'], true)) {
            return $purgeur;
        }

        return new PurgeurRefusant($this->environnement);
    }
}
