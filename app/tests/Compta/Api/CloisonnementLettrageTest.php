<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\LettrageEcriture;
use App\Compta\Entity\LigneEcriture;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LE LETTRAGE COMPTABLE D'UN AUTRE EXPLOITANT EST-IL LISIBLE ?
 *
 * `LettrageEcriture` expose une `GetCollection` gardee par `compta.lire`, et AUCUNE extension ne la
 * nomme. Son groupe `ecriture:lettrage` rend la ligne d'ecriture lettree, la date, l'auteur et le
 * code de rapprochement — c'est-a-dire quelles ecritures d'un exploitant ont ete rapprochees, quand,
 * et par qui.
 *
 * `AccountingScopeExtension` atteint l'etablissement par le profil exploitant. `LettrageEcriture`
 * n'en porte pas : son chemin est `ligne -> ecriture -> profilExploitant`, deux sauts la ou les
 * cartes existantes n'en prevoient qu'un.
 *
 * ⚠ LE COUT DE CETTE MESURE EST SON INTERET. Fabriquer un lettrage etranger demande huit entites :
 * profil, journal, periode, compte, taux de TVA, ecriture, ligne, lettrage. C'est precisement ce
 * qui fait qu'on ne mesure pas ce genre de ressource et qu'on se contente de la lire — et lire, ce
 * jour-la, s'est trompe quatre fois.
 */
final class CloisonnementLettrageTest extends ComptaApiTestCase
{
    public function testLeLettrageDunAutreExploitantNestPasListe(): void
    {
        [$client, $entete] = $this->adminSurA();

        $idSien = $this->lettragePour($this->profilExploitant(), 'MIEN');
        $idEtranger = $this->lettragePour($this->profilEtranger(), 'VOISIN');

        $client->request('GET', '/api/lettrage_ecritures', $entete + ['query' => ['itemsPerPage' => 100]]);
        self::assertResponseIsSuccessful('temoin : la route doit exister et repondre');

        $corps = $client->getResponse()->getContent(false);

        // ⚠ CONTROLE POSITIF : sans lui, une collection vide passerait l'assertion suivante en
        // prouvant le contraire de ce qu'on veut.
        self::assertStringContainsString(
            $idSien,
            $corps,
            'temoin : le lettrage de MON profil comptable doit etre lisible',
        );

        self::assertStringNotContainsString(
            $idEtranger,
            $corps,
            'le lettrage dit quelles ecritures ont ete rapprochees, quand et par qui : celui d\'un '
            . 'autre exploitant ne doit pas figurer dans la collection',
        );
    }

    /** Un second profil comptable, rattache a l'etablissement B. */
    private function profilEtranger(): ProfilExploitant
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabB, 'temoin : l\'etablissement B doit exister');

        $profil = new ProfilExploitant();
        $profil->setSiren('111222333')->setEtablissementPrincipal($etabB);
        $em->persist($profil);
        $em->flush();

        return $profil;
    }

    /** La chaine complete : journal, periode, compte, taux, ecriture, ligne, lettrage. */
    private function lettragePour(ProfilExploitant $profil, string $marque): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $admin = $em->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        self::assertInstanceOf(Utilisateur::class, $admin);

        $journal = (new Journal())->setCode('L' . substr($marque, 0, 2))->setLibelle('Journal ' . $marque);
        $journal->setProfilExploitant($profil);
        $em->persist($journal);

        $periode = new PeriodeComptable();
        $periode->setProfilExploitant($profil)
            ->setDateDebut(new \DateTimeImmutable('2026-01-01'))
            ->setDateFin(new \DateTimeImmutable('2026-12-31'));
        $em->persist($periode);

        $compte = (new CompteComptable())->setNumero('411' . substr($marque, 0, 3))->setLibelle('Client ' . $marque);
        $compte->setProfilExploitant($profil);
        $em->persist($compte);

        $taux = (new TauxTva())->setTaux('20.00')->setLibelle('TVA ' . $marque);
        $taux->setProfilExploitant($profil);
        $em->persist($taux);

        $ecriture = new EcritureComptable();
        $ecriture->setProfilExploitant($profil)
            ->setJournal($journal)
            ->setPeriode($periode)
            ->setDateEcriture(new \DateTimeImmutable('2026-06-15'))
            ->setLibelle('Ecriture ' . $marque);
        $em->persist($ecriture);

        $ligne = new LigneEcriture();
        $ligne->setEcriture($ecriture)
            ->setCompte($compte)
            ->setTauxTva($taux)
            ->setDebitCentimes(1000)
            ->setCreditCentimes(0)
            ->setLibelle('Ligne ' . $marque);
        $em->persist($ligne);

        $lettrage = new LettrageEcriture();
        $lettrage->setLigne($ligne)
            ->setDateLettrage(new \DateTimeImmutable('2026-06-20'))
            ->setAuteur($admin)
            ->setReconciliationCode('RC-' . $marque);
        $em->persist($lettrage);

        $em->flush();

        return (string) $lettrage->getId();
    }
}
