<?php

declare(strict_types=1);

namespace App\Compta\DataFixtures;

use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\MappingComptable;
use App\Compta\Entity\MoyenPaiement;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\RegieRecettes;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\ReferentielComptable;
use App\Compta\Enum\SensCompte;
use App\Compta\Enum\TypeExploitant;
use App\Compta\ValueObject\ParametresRegime;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Categorie;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données M6 (L4) : un profil exploitant en régie directe M57 sur l'établissement A du socle,
 * plan de comptes de base, mapping vers la catégorie comptable de M1, taux de TVA 20 %/10 %,
 * référentiel des moyens de paiement (identique au stub L2, non-régression), régie de recettes, et
 * permissions `compta.*`/`caisse.versement` accordées à l'administrateur.
 */
final class ComptaFixtures extends Fixture implements DependentFixtureInterface
{
    public const PROFIL_SIREN = '130025265';
    public const REGIE_LIBELLE = 'Régie piscine A';

    public function getDependencies(): array
    {
        return [SocleFixtures::class, OffreFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        // --- Permissions compta.* + caisse.versement + octroi à l'administrateur ---
        $permComptaTout = (new Permission())->setModule('compta')->setAction('*');
        $manager->persist($permComptaTout);
        // `record_manual_entry` (FIN-1, US-L4-11) : nouvelle action, couverte par le joker `compta.*`
        // déjà accordé à l'Administrateur groupe ; créée explicitement ici comme toute autre action du
        // référentiel (§3 spec-comptabilite-generale.md), pour un octroi fin à un rôle non-wildcard.
        foreach (['lire', 'lettrer', 'valider', 'exporter', 'cloturer', 'gerer', 'lire_rad', 'lire_consolide', 'record_manual_entry'] as $action) {
            $manager->persist((new Permission())->setModule('compta')->setAction($action));
        }
        $permVersement = (new Permission())->setModule('caisse')->setAction('versement');
        $manager->persist($permVersement);

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            $roleAdmin->addPermission($permComptaTout);
            $roleAdmin->addPermission($permVersement);
        }

        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);

        // --- Profil exploitant : régie directe / M57 ---
        $profil = new ProfilExploitant();
        $profil->setType(TypeExploitant::RegieDirecte);
        $profil->setReferentielComptable(ReferentielComptable::M57);
        $profil->setSiren(self::PROFIL_SIREN);
        if ($etabA instanceof Etablissement) {
            $profil->setEtablissementPrincipal($etabA);
            $profil->addEtablissementRattache($etabA);
        }
        // PCA actif pour illustrer le mécanisme en démo (point EXPERT #3 — activable, défaut prudent
        // conservé à false dans `ParametresRegime::defautPour`, ici activé explicitement pour la
        // fixture de démonstration).
        $profil->setParametresRegime(new ParametresRegime(pcaActif: true));
        $manager->persist($profil);

        // --- Journaux ---
        $journaux = [
            'VTE' => 'Journal des ventes',
            'ENC' => 'Journal des encaissements',
            'REG' => 'Journal de la régie',
            'PCA' => 'Opérations diverses — PCA',
            'EXT' => 'Journal des extournes',
            // Journal dédié « opérations diverses » (FIN-1, RG-M6-11, §7 point 5 du plan) : recommandé
            // pour la saisie manuelle libre — aucune contrainte technique ne l'impose (n'importe quel
            // journal du profil peut être référencé par `POST /compta/journal-entries/manual`).
            'OD' => 'Journal des opérations diverses',
        ];
        foreach ($journaux as $code => $libelle) {
            $manager->persist((new Journal())->setProfilExploitant($profil)->setCode($code)->setLibelle($libelle));
        }

        // --- Plan de comptes de base (M57, jeu minimal de démarrage) ---
        $comptes = [
            '401000' => ['Fournisseurs', SensCompte::Credit],
            '411000' => ['Redevables', SensCompte::Debit],
            '4457100' => ['TVA collectée', SensCompte::Credit],
            '487000' => ['Produits constatés d\'avance', SensCompte::Credit],
            '511000' => ['Recettes à classer / encaissements régie', SensCompte::Debit],
            '512000' => ['Banque', SensCompte::Debit],
            // Compte non commercial (FIN-1, §4.1 spec) : illustre une ligne hors champ (taux TVA
            // « Hors champ » déjà seedé, réutilisé tel quel, pas de nouveau taux « néant » créé).
            '627000' => ['Frais bancaires', SensCompte::Debit],
            '706100' => ['Redevances billetterie piscine', SensCompte::Credit],
            '706200' => ['Redevances activités culturelles', SensCompte::Credit],
        ];
        $comptesEntites = [];
        foreach ($comptes as $numero => [$libelle, $sens]) {
            // PHP transforme les clés de tableau numériques ("411000") en entiers : recast explicite.
            $numero = (string) $numero;
            $compte = (new CompteComptable())->setProfilExploitant($profil)->setNumero($numero)->setLibelle($libelle)->setSens($sens);
            $manager->persist($compte);
            $comptesEntites[$numero] = $compte;
        }

        // --- Taux de TVA (RG-TVA-06) ---
        $taux20 = (new TauxTva())->setProfilExploitant($profil)->setTaux('20.00')->setLibelle('Taux normal 20 %')->setActif(true);
        $taux10 = (new TauxTva())->setProfilExploitant($profil)->setTaux('10.00')->setLibelle('Taux intermédiaire 10 %')->setActif(true);
        $taux55 = (new TauxTva())->setProfilExploitant($profil)->setTaux('5.50')->setLibelle('Taux réduit 5,5 %')->setActif(true);
        // Point EXPERT #2 : taux réduit 2025 créé mais INACTIF par défaut (à activer après avis fiscaliste).
        $tauxReduit2025 = (new TauxTva())->setProfilExploitant($profil)->setTaux('5.50')->setLibelle('Taux réduit 2025 (cours/accès sportifs, ⚠ à valider fiscaliste)')->setActif(false);
        $tauxHorsChamp = (new TauxTva())->setProfilExploitant($profil)->setTaux('0.00')->setLibelle(TauxTva::LIBELLE_HORS_CHAMP)->setActif(true);
        foreach ([$taux20, $taux10, $taux55, $tauxReduit2025, $tauxHorsChamp] as $taux) {
            $manager->persist($taux);
        }

        // --- Mapping comptable : catégorie comptable M1 (billetterie) -> compte 7061 + TVA 20 % ---
        $catCompta = $manager->getRepository(Categorie::class)->findOneBy(['libelle' => OffreFixtures::CAT_COMPTABLE]);
        if ($catCompta instanceof Categorie) {
            $mapping = (new MappingComptable())
                ->setProfilExploitant($profil)
                ->setCategorie($catCompta->getId())
                ->setCompteProduit($comptesEntites['706100'])
                ->setTauxTva($taux20);
            $manager->persist($mapping);
        }

        // --- Référentiel des moyens de paiement (identique au stub L2, non-régression, §3 du plan) ---
        $moyens = [
            ['especes', 'Espèces', true, false, false],
            ['cb', 'Carte bancaire (TPE)', false, true, false],
            ['cheque', 'Chèque', false, false, false],
            ['virement', 'Virement', false, false, false],
            ['cheque_vacances', 'Chèques Vacances', false, false, false],
            ['cheque_culture', 'Chèque Culture', false, false, false],
            ['cheque_loisirs', 'Chèque Loisirs', false, false, false],
            ['pmv', 'Passe / Monnaie de ville (PMV)', false, false, false],
            ['avoir', 'Avoir', false, false, false],
            ['differe', 'Paiement différé', false, false, true],
            ['payfip', 'PayFiP (DGFiP)', false, true, false],
        ];
        foreach ($moyens as [$code, $libelle, $rendu, $reference, $differe]) {
            $manager->persist((new MoyenPaiement())
                ->setCode($code)->setLibelle($libelle)
                ->setAutoriseRendu($rendu)->setExigeReference($reference)->setAutoriseDiffere($differe));
        }

        // --- Régie de recettes (US-L4-02) ---
        $regie = (new RegieRecettes())
            ->setProfilExploitant($profil)
            ->setLibelle(self::REGIE_LIBELLE)
            ->setActeNomination('Arrêté n°2026-01 portant création de régie')
            ->setModesAutorises(['especes', 'cb', 'payfip'])
            ->setPlafondEncaisseCentimes(500000)
            ->setPeriodiciteVersement('quotidien');
        $manager->persist($regie);

        $manager->flush();
    }
}
