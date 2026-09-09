<?php

declare(strict_types=1);

namespace App\Compta\DataFixtures;

use App\Platform\DataFixtures\FixturesIdempotentes;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\MappingComptable;
use App\Compta\Entity\MoyenPaiement;
use App\Compta\Entity\PaymentMethodTreasuryAccount;
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
    use FixturesIdempotentes;

    public const PROFIL_SIREN = '130025265';
    public const REGIE_LIBELLE = 'Régie piscine A';

    public function getDependencies(): array
    {
        return [SocleFixtures::class, OffreFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        // --- Permissions compta.* + caisse.versement + octroi à l'administrateur ---
        $permComptaTout = $this->permissionNommee($manager, 'compta', '*');
        $manager->persist($permComptaTout);
        // `record_manual_entry` (FIN-1, US-L4-11) : nouvelle action, couverte par le joker `compta.*`
        // déjà accordé à l'Administrateur groupe ; créée explicitement ici comme toute autre action du
        // référentiel (§3 spec-comptabilite-generale.md), pour un octroi fin à un rôle non-wildcard.
        foreach (['lire', 'lettrer', 'valider', 'exporter', 'cloturer', 'gerer', 'lire_rad', 'lire_consolide', 'record_manual_entry'] as $action) {
            $manager->persist($this->permissionNommee($manager, 'compta', $action));
        }
        $permVersement = $this->permissionNommee($manager, 'caisse', 'versement');
        $manager->persist($permVersement);

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            $roleAdmin->addPermission($permComptaTout);
            $roleAdmin->addPermission($permVersement);
        }

        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);

        // ── LE BLOC DE DÉMONSTRATION NE SE POSE QU'UNE FOIS ──────────────────────────────────
        //
        // Tout ce qui suit est un jeu de données cohérent, pas un référentiel : le reposer sur une
        // base qui l'a déjà écraserait ce qui a été corrigé à la main depuis, ou le dupliquerait
        // pour les entités sans contrainte d'unicité — silencieusement.
        //
        // Les permissions et les rôles restent AU-DESSUS de cette garde : ils doivent être rejoués à
        // chaque chargement, sans quoi un droit ajouté au code n'atteindrait jamais une base
        // existante.
        if ($manager->getRepository(\App\Compta\Entity\ProfilExploitant::class)
            ->findOneBy(['siren' => self::PROFIL_SIREN]) !== null
        ) {
            $manager->flush();

            return;
        }

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
        // ⚠ Chercher avant de créer, et pas seulement parce qu'un rechargement doublonnerait : la
        // migration `Version20260814231600` **insère déjà ces onze lignes** (`INSERT IGNORE`). Sur toute
        // base construite par les migrations — donc toute préproduction — elles sont présentes avant
        // qu'aucune fixture n'ait tourné, et `uniq_moyen_code` faisait échouer le chargement dès le
        // premier code. La garde d'entrée de cette classe ne protégeait pas ce cas : elle teste le
        // profil exploitant, que la migration ne pose pas — un témoin présent ne garantit pas que tout
        // le bloc qu'il est censé représenter le soit.
        $depot = $manager->getRepository(MoyenPaiement::class);
        foreach ($moyens as [$code, $libelle, $rendu, $reference, $differe]) {
            if ($depot->findOneBy(['code' => $code]) instanceof MoyenPaiement) {
                continue;
            }

            $manager->persist((new MoyenPaiement())
                ->setCode($code)->setLibelle($libelle)
                ->setAutoriseRendu($rendu)->setExigeReference($reference)->setAutoriseDiffere($differe));
        }
        $manager->flush();

        // --- Compte de trésorerie par (exploitant, moyen) ---
        //
        // ⚠ INDISPENSABLE, PAS DÉCORATIF. Sans ces rattachements, `PaymentLedgerPoster` refuse tout
        //    encaissement et TOUTE la facturation tombe : le règlement d'une facture écrit désormais
        //    son écriture au journal `ENC`, et il ne peut pas savoir quel compte débiter.
        //
        // ⚠ ET LES FIXTURES NE PEUVENT PAS S'APPUYER SUR LA MIGRATION QUI POSE LES MÊMES DÉFAUTS.
        //    Le harnais de test construit le schéma depuis le MAPPING, il ne joue jamais les
        //    migrations : ce que `Version20260908091500` insère n'existe pas ici. Les deux chemins
        //    doivent donc poser la même chose, chacun de son côté — et c'est exactement le genre de
        //    duplication qui diverge en silence. Si tu touches l'un, touche l'autre.
        //
        // La règle est la même qu'en migration : le numéro EXACT d'abord, le préfixe en repli. Le
        // plan de comptes de démonstration ci-dessus n'a ni 531x ni 511200 — espèces et chèques
        // retombent donc sur 511000, et c'est voulu : un semis de démo n'invente pas des comptes
        // qu'il n'a pas.
        $correspondances = [
            ['exact' => '531000', 'prefixe' => '531', 'codes' => ['especes']],
            ['exact' => '512000', 'prefixe' => '512', 'codes' => ['cb', 'virement', 'payfip']],
            ['exact' => '511200', 'prefixe' => '511', 'codes' => ['cheque', 'cheque_vacances', 'cheque_culture', 'cheque_loisirs']],
        ];
        foreach ($correspondances as $correspondance) {
            $compte = $comptesEntites[$correspondance['exact']] ?? null;
            if (!$compte instanceof CompteComptable) {
                foreach ($comptesEntites as $numero => $candidat) {
                    if (str_starts_with((string) $numero, $correspondance['prefixe'])) {
                        $compte = $candidat;
                        break;
                    }
                }
            }
            if (!$compte instanceof CompteComptable) {
                continue;
            }

            foreach ($correspondance['codes'] as $code) {
                $moyen = $depot->findOneBy(['code' => $code]);
                if (!$moyen instanceof MoyenPaiement) {
                    continue;
                }
                $manager->persist((new PaymentMethodTreasuryAccount())
                    ->setBusinessProfile($profil)
                    ->setPaymentMethod($moyen)
                    ->setTreasuryAccount($compte));
            }
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
