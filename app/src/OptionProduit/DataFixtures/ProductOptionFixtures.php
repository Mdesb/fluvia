<?php

declare(strict_types=1);

namespace App\OptionProduit\DataFixtures;

use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\OptionProduit\Entity\GroupeOption;
use App\OptionProduit\Entity\OptionProduit;
use App\OptionProduit\Entity\ValeurOption;
use App\OptionProduit\Enum\ImpactOptionType;
use App\OptionProduit\Enum\ModeSelectionOption;
use App\Platform\DataFixtures\FixturesIdempotentes;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * De quoi *vendre* une option, et pas seulement en configurer une.
 *
 * **Pourquoi ces données manquaient.** Le module était complet côté serveur — groupes, valeurs, impact
 * tarifaire, gel de la sélection sur la ligne de vente — et **aucun jeu de démonstration n'en créait**.
 * Conséquence : on pouvait brancher l'écran de vente et le trouver vide, donc conclure qu'il ne marche
 * pas. C'est exactement le motif qui a fait dire à Maxime « je ne comprends rien aux options produit » :
 * il cherchait quelque chose qui n'existait nulle part.
 *
 * **Deux groupes, choisis pour montrer les deux formes qui se comportent différemment :**
 *
 * - **Affûtage** (choix unique, montant fixe) — le cas de la patinoire que Maxime a cité. Une des trois
 *   valeurs est à 0,00 € : une option peut être **incluse** sans être gratuite au sens marketing, et
 *   l'écran doit l'afficher « inclus » plutôt que « +0,00 € ».
 * - **Assurance** (choix multiple, pourcentage) — parce qu'un pourcentage se calcule sur le prix de
 *   base résolu, donc **l'écran ne peut pas le connaître avant de demander au serveur**. C'est le cas
 *   qui rend la règle visible.
 *
 * Le second groupe est **obligatoire** : il vérifie que l'écran refuse d'ajouter au panier tant que rien
 * n'est choisi, et qu'il **dit lequel manque** au lieu de griser un bouton sans raison.
 */
final class ProductOptionFixtures extends Fixture implements DependentFixtureInterface
{
    use FixturesIdempotentes;

    public const GROUPE_AFFUTAGE = 'Affûtage des patins';
    public const GROUPE_ASSURANCE = 'Assurance annulation';

    /** @return list<class-string> */
    public function getDependencies(): array
    {
        return [SocleFixtures::class, OffreFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $produit = $manager->getRepository(Produit::class)
            ->findOneBy(['code' => 'PRD-ENTREE01']);

        if (!$produit instanceof Produit) {
            // Sans produit de démonstration, il n'y a rien à quoi rattacher : on ne fabrique pas un
            // produit ici pour sauver la fixture, ce serait cacher que `OffreFixtures` n'a pas tourné.
            return;
        }

        $affutage = $this->groupeNomme($manager, self::GROUPE_AFFUTAGE, ModeSelectionOption::Unique);
        $this->valeur($manager, $affutage, 'Sans affûtage', ImpactOptionType::Montant, '0.00', 0);
        $this->valeur($manager, $affutage, 'Affûtage simple', ImpactOptionType::Montant, '5.00', 1);
        $this->valeur($manager, $affutage, 'Affûtage compétition', ImpactOptionType::Montant, '9.50', 2);

        $assurance = $this->groupeNomme($manager, self::GROUPE_ASSURANCE, ModeSelectionOption::Multiple);
        $this->valeur($manager, $assurance, 'Annulation jusqu\'à J-1', ImpactOptionType::Pourcentage, '8.00', 0);
        $this->valeur($manager, $assurance, 'Casse de matériel', ImpactOptionType::Montant, '2.50', 1);

        $this->rattacher($manager, $produit, $affutage, obligatoire: false, ordre: 0);
        $this->rattacher($manager, $produit, $assurance, obligatoire: true, ordre: 1);

        $manager->flush();
    }

    private function groupeNomme(ObjectManager $manager, string $libelle, ModeSelectionOption $mode): GroupeOption
    {
        $existant = $manager->getRepository(GroupeOption::class)->findOneBy(['libelle' => $libelle]);

        if ($existant instanceof GroupeOption) {
            return $existant;
        }

        $groupe = (new GroupeOption())->setLibelle($libelle)->setModeSelection($mode)->setActif(true);
        $manager->persist($groupe);

        return $groupe;
    }

    private function valeur(
        ObjectManager $manager,
        GroupeOption $groupe,
        string $libelle,
        ImpactOptionType $type,
        string $valeur,
        int $ordre,
    ): void {
        $existante = $manager->getRepository(ValeurOption::class)
            ->findOneBy(['groupeOption' => $groupe, 'libelle' => $libelle]);

        if ($existante instanceof ValeurOption) {
            return;
        }

        $manager->persist(
            (new ValeurOption())
                ->setGroupeOption($groupe)
                ->setLibelle($libelle)
                ->setImpactType($type)
                ->setImpactValeur($valeur)
                ->setOrdreAffichage($ordre)
                ->setActif(true)
        );
    }

    private function rattacher(
        ObjectManager $manager,
        Produit $produit,
        GroupeOption $groupe,
        bool $obligatoire,
        int $ordre,
    ): void {
        $existante = $manager->getRepository(OptionProduit::class)
            ->findOneBy(['produit' => $produit, 'groupeOption' => $groupe]);

        if ($existante instanceof OptionProduit) {
            return;
        }

        $manager->persist(
            (new OptionProduit())
                ->setProduit($produit)
                ->setGroupeOption($groupe)
                ->setObligatoire($obligatoire)
                ->setOrdreAffichage($ordre)
                ->setActif(true)
        );
    }
}
