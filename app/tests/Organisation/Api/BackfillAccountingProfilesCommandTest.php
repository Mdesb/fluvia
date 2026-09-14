<?php

declare(strict_types=1);

namespace App\Tests\Organisation\Api;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Facturation\Entity\ParametreFacturationEtablissement;
use App\Legal\Entity\LegalIdentity;
use App\Organisation\Command\BackfillAccountingProfilesCommand;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Tests\Compta\LegalVatRateFixtureTrait;
use App\Tests\Securite\SecuriteApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * LA REPRISE DES STRUCTURES OUVERTES AVANT QUE L'OUVERTURE NE SACHE LES EQUIPER.
 *
 * ⚠ CETTE COMMANDE N'AVAIT AUCUN TEST, ET ELLE ECRIT DANS LA COMPTABILITE D'UN PARC ENTIER.
 * Elle portait une SECONDE copie de `TAUX_TVA_FRANCE` : corriger l'ouverture sans la corriger
 * aurait laisse la reprise semer des taux francais sur les structures deja ouvertes — celles-la
 * memes qu'elle est censee reparer, et sans que personne ne relance l'ouverture pour s'en rendre
 * compte.
 *
 * ── CE QUE CE TEST EPROUVE EN PRIORITE ──────────────────────────────────────────────────────────
 *
 * Que le CONSTAT et l'ECRITURE disent la meme chose. C'est la propriete que la commande revendique
 * dans son propre docbloc — « meme source, donc le mode constat ne peut pas annoncer autre chose
 * que ce que le mode ecriture ferait » — et c'est exactement celle que le `--dry-run` de
 * `sepa:echeances:facturer` n'avait PAS : il annoncait « 5 a facturer, 0 refus » la ou le passage
 * reel en refusait cinq. Une revendication de ce genre, non testee, est une phrase.
 */
final class BackfillAccountingProfilesCommandTest extends SecuriteApiTestCase
{
    use LegalVatRateFixtureTrait;

    /**
     * LE CONSTAT N'ECRIT RIEN, ET ANNONCE CE QUE L'ECRITURE FERA.
     */
    public function testLeConstatNecritRienEtAnnonceCeQueLEcritureFera(): void
    {
        $em = $this->em();
        $this->seedFranceMetropolitanVatRates($em);
        $etablissement = $this->structureSansProfil('Reprise — constat', 'FR', '');

        $testeur = new CommandTester($this->commande());
        $testeur->execute([]);

        $em->clear();
        self::assertNull(
            $em->getRepository(ProfilExploitant::class)->findOneBy(['etablissementPrincipal' => $etablissement->getId()]),
            'sans --ecrire, une commande de reprise ne doit RIEN laisser derriere elle',
        );

        // Cinq : les quatre taux legaux francais, plus le hors-champ qui ne vient d'aucun
        // referentiel. C'est le chiffre que l'ecriture doit poser — verifie par le test suivant.
        self::assertStringContainsString('5 taux à poser', $testeur->getDisplay());
    }

    /**
     * L'ECRITURE POSE LES TAUX DU PAYS, ET LE PARAMETRAGE QUI PERMET DE FACTURER.
     */
    public function testLEcriturePoseLesTauxDuPaysEtLeParametrage(): void
    {
        $em = $this->em();
        $this->seedFranceMetropolitanVatRates($em);
        $etablissement = $this->structureSansProfil('Reprise — ecriture', 'FR', '');

        $testeur = new CommandTester($this->commande());
        $testeur->execute(['--ecrire' => true]);

        $em->clear();
        $profil = $em->getRepository(ProfilExploitant::class)
            ->findOneBy(['etablissementPrincipal' => $etablissement->getId()]);

        self::assertInstanceOf(ProfilExploitant::class, $profil, 'la reprise cree le profil manquant');

        $valeurs = array_map(
            static fn (TauxTva $t): string => $t->getTaux(),
            $em->getRepository(TauxTva::class)->findBy(['profilExploitant' => $profil]),
        );
        sort($valeurs);

        self::assertSame(
            ['0.00', '2.10', '5.50', '10.00', '20.00'],
            $valeurs,
            'les taux viennent du referentiel legal francais, plus le hors-champ',
        );

        $parametre = $em->getRepository(ParametreFacturationEtablissement::class)
            ->findOneBy(['profilExploitant' => $profil]);

        self::assertInstanceOf(
            ParametreFacturationEtablissement::class,
            $parametre,
            'une structure reprise sans parametrage de facturation refuserait toujours de facturer',
        );
        self::assertSame('20.00', $parametre->getTauxTvaDefaut()?->getTaux());
        self::assertSame('706000', $parametre->getCompteProduitDefaut()?->getNumero());
    }

    /**
     * ⚠ LE TEMOIN QUI DEMASQUE LA CONSTANTE FRANCAISE : une structure ultramarine reprise.
     *
     * Avec l'ancienne copie de `TAUX_TVA_FRANCE`, cette reprise posait 20 / 10 / 5,5 / 2,1 sur un
     * etablissement guadeloupeen, et son defaut de facturation aurait ete 20 % — un taux qui
     * n'existe pas la-bas, dans des factures scellees.
     */
    public function testUneStructureUltramarineRepriseNeRecoitPasLeBaremeMetropolitain(): void
    {
        $em = $this->em();
        $this->seedFranceMetropolitanVatRates($em);
        $this->seedFranceOverseasVatRates($em);
        $etablissement = $this->structureSansProfil('Reprise — Guadeloupe', 'FR', 'DOM');

        $testeur = new CommandTester($this->commande());
        $testeur->execute(['--ecrire' => true]);

        $em->clear();
        $profil = $em->getRepository(ProfilExploitant::class)
            ->findOneBy(['etablissementPrincipal' => $etablissement->getId()]);
        self::assertInstanceOf(ProfilExploitant::class, $profil);

        $valeurs = array_map(
            static fn (TauxTva $t): string => $t->getTaux(),
            $em->getRepository(TauxTva::class)->findBy(['profilExploitant' => $profil]),
        );
        sort($valeurs);

        self::assertSame(['0.00', '1.05', '2.10', '8.50'], $valeurs, 'le bareme des DOM, et lui seul');

        $parametre = $em->getRepository(ParametreFacturationEtablissement::class)
            ->findOneBy(['profilExploitant' => $profil]);
        self::assertSame(
            '8.50',
            $parametre?->getTauxTvaDefaut()?->getTaux(),
            'le defaut ultramarin est 8,5 % — pas le 20 % metropolitain',
        );
    }

    /**
     * La chaine minimale qu'une structure « deja ouverte » presente a la reprise.
     *
     * Le SIRET passe par `LegalIdentity` : c'est la que la commande va chercher le SIREN, et sans
     * lui elle refuse de fabriquer un numero d'entreprise — elle signale et passe au suivant.
     */
    private function structureSansProfil(string $nom, string $pays, string $territoire): Etablissement
    {
        $em = $this->em();

        $groupe = (new Groupe())->setNom($nom);
        $em->persist($groupe);

        $region = (new Region())->setNom($nom)->setGroupe($groupe);
        $em->persist($region);

        $etablissement = (new Etablissement())
            ->setNom($nom)
            ->setRegion($region)
            ->setPays($pays)
            ->setFiscalTerritory($territoire);
        $em->persist($etablissement);

        $em->persist(
            (new LegalIdentity())
                ->setEstablishment($etablissement)
                ->setLegalName(strtoupper($nom))
                ->setSiret('81240390500019')
        );

        $em->flush();

        return $etablissement;
    }

    private function commande(): BackfillAccountingProfilesCommand
    {
        /** @var BackfillAccountingProfilesCommand $commande */
        $commande = static::getContainer()->get(BackfillAccountingProfilesCommand::class);
        $commande->setName('organisation:reprendre-profils-compta');

        return $commande;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
