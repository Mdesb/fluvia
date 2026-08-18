<?php

declare(strict_types=1);

namespace App\Facturation\DataFixtures;

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
    public function getDependencies(): array
    {
        return [SocleFixtures::class, ComptaFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $permFacturationTout = (new Permission())->setModule('facturation')->setAction('*');
        $manager->persist($permFacturationTout);
        foreach (['lire', 'lire_soi', 'emettre_justificative', 'emettre_directe', 'avoir', 'lettrer', 'deposer_chorus', 'gerer'] as $action) {
            $manager->persist((new Permission())->setModule('facturation')->setAction($action));
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

        $periode = new PeriodeComptable();
        $periode->setProfilExploitant($profil);
        $periode->setDateDebut(new \DateTimeImmutable('first day of this month'));
        $periode->setDateFin(new \DateTimeImmutable('last day of this month'));
        $periode->setStatut(StatutPeriode::Ouverte);
        $manager->persist($periode);

        $compteProduit = $manager->getRepository(CompteComptable::class)->findOneBy(['profilExploitant' => $profil, 'numero' => '706100']);

        $parametre = new ParametreFacturationEtablissement();
        $parametre->setProfilExploitant($profil);
        $parametre->setMentionsLegalesEmetteur([
            'denomination' => 'Régie piscine A',
            'adresse' => ['rue' => '1 rue de la Piscine', 'cp' => '75000', 'ville' => 'Paris', 'pays' => 'FR'],
            'siret' => $profil->getSiren() . '00012',
            'tvaIntra' => 'FR00' . $profil->getSiren(),
        ]);
        $parametre->setConditionsReglementDefaut('Paiement à 30 jours date de facture.');
        $parametre->setDelaiPaiementDefautJours(30);
        $parametre->setCompteProduitDefaut($compteProduit);
        $manager->persist($parametre);

        $manager->flush();
    }
}
