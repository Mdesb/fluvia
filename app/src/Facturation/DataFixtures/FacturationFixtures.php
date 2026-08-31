<?php

declare(strict_types=1);

namespace App\Facturation\DataFixtures;

use App\Platform\DataFixtures\FixturesIdempotentes;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Enum\StatutPeriode;
use App\DataFixtures\SocleFixtures;
use App\Facturation\Entity\ParametreFacturationEtablissement;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données Facturation : permissions `facturation.*` accordées à l'administrateur (même patron
 * que `ComptaFixtures`), période comptable ouverte couvrant aujourd'hui (support de la numérotation),
 * paramétrage de facturation de démonstration (mentions légales émetteur, compte produit de repli).
 */
final class FacturationFixtures extends Fixture implements DependentFixtureInterface
{
    use FixturesIdempotentes;

    public function getDependencies(): array
    {
        return [SocleFixtures::class, ComptaFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $permFacturationTout = $this->permissionNommee($manager, 'facturation', '*');
        $manager->persist($permFacturationTout);
        foreach (['lire', 'lire_soi', 'emettre_justificative', 'emettre_directe', 'avoir', 'lettrer', 'deposer_chorus', 'gerer'] as $action) {
            $manager->persist($this->permissionNommee($manager, 'facturation', $action));
        }

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            $roleAdmin->addPermission($permFacturationTout);
        }

        $profil = $manager->getRepository(\App\Compta\Entity\ProfilExploitant::class)->findOneBy(['siren' => ComptaFixtures::PROFIL_SIREN]);
        if ($profil === null) {
            $manager->flush();

            return;
        }

        // ── LE BLOC DE DEMONSTRATION NE SE POSE QU'UNE FOIS ──────────────────────────────────
        //
        // Tout ce qui suit est un jeu de donnees coherent, pas un referentiel : le reposer sur une
        // base qui l'a deja ecraserait ce qui a ete corrige a la main depuis, ou le dupliquerait
        // pour les entites sans contrainte d'unicite -- silencieusement.
        //
        // Les permissions, les roles et les affectations restent AU-DESSUS : ils doivent etre
        // rejoues a chaque chargement, sans quoi un droit ajoute au code n'atteindrait jamais une
        // base existante.
        if ($manager->getRepository(\App\Compta\Entity\PeriodeComptable::class)->findOneBy([]) !== null) {
            $manager->flush();

            return;
        }
        $periode = new PeriodeComptable();
        $periode->setProfilExploitant($profil);
        $periode->setDateDebut(new \DateTimeImmutable('first day of this month'));
        $periode->setDateFin(new \DateTimeImmutable('last day of this month'));
        $periode->setStatut(StatutPeriode::Ouverte);
        $manager->persist($periode);

        $compteProduit = $manager->getRepository(CompteComptable::class)->findOneBy(['profilExploitant' => $profil, 'numero' => '706100']);

        // ⚠ L'IDENTITE LEGALE VIT SUR LE PROFIL, PAS DANS LE PARAMETRAGE.
        //
        // Elle etait posee dans `ParametreFacturationEtablissement::mentionsLegalesEmetteur`, un
        // tableau JSON libre. Depuis le 01/09 c'est le profil qui fait foi -- champs structures,
        // parce qu'EN 16931 exige des termes distincts (BT-27, BT-31, BT-35/37/38/40) qu'une
        // plateforme controle un par un.
        //
        // Ecrire les deux ferait exactement ce qu'on vient de refermer : deux sources pour le meme
        // fait sur un document opposable.
        $profil->setRaisonSociale('Régie piscine A');
        $profil->setTvaIntracommunautaire('FR00' . $profil->getSiren());
        $profil->setAdresse([
            'rue' => '1 rue de la Piscine',
            'cp' => '75000',
            'ville' => 'Paris',
            'pays' => 'FR',
        ]);
        $manager->persist($profil);

        $parametre = new ParametreFacturationEtablissement();
        $parametre->setProfilExploitant($profil);
        $parametre->setConditionsReglementDefaut('Paiement à 30 jours date de facture.');
        $parametre->setDelaiPaiementDefautJours(30);
        $parametre->setCompteProduitDefaut($compteProduit);
        $manager->persist($parametre);

        $manager->flush();
    }
}
