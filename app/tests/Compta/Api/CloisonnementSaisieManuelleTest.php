<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\EcritureComptable;
use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * §0.5 du plan : cloisonnement explicite de `POST /compta/journal-entries/manual` — le
 * `businessProfile` du corps est **revérifié** couvert par l'établissement actif (jamais l'inverse).
 * Reproduit l'IDOR décrit §0.5 (constat sur `GenererEcrituresProcessor`, corrigé dans ce même lot) puis
 * prouve le refus sur le nouvel endpoint.
 */
final class CloisonnementSaisieManuelleTest extends ComptaApiTestCase
{
    public function testProfilHorsPerimetreRefuse404(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->ouvrirPeriode($client, $entete, '2026-08-01', '2026-08-31');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $avant = (int) $em->getRepository(EcritureComptable::class)->createQueryBuilder('e')->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();

        // Le profil exploitant n'existe que sur l'établissement A (ComptaFixtures). Un appel avec
        // l'établissement B actif (même utilisateur admin, affecté sur A ET B, RG-SOCLE-05) doit être
        // refusé : le profil référencé dans le corps n'est PAS couvert par l'établissement actif.
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $token = $entete['auth_bearer'];
        $enteteB = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idB]];

        $client->request('POST', '/api/compta/journal-entries/manual', $enteteB + [
            'json' => [
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'journal' => '/api/journals/' . $this->idJournal('OD'),
                'date' => '2026-08-19',
                'label' => 'Tentative IDOR',
                'lines' => [
                    ['account' => '/api/compte_comptables/' . $this->idCompte('512000'), 'debit' => '100.00', 'vatRate' => '/api/taux_tvas/' . $this->idTauxHorsChamp()],
                    ['account' => '/api/compte_comptables/' . $this->idCompte('401000'), 'credit' => '100.00', 'vatRate' => '/api/taux_tvas/' . $this->idTauxHorsChamp()],
                ],
            ],
        ]);

        self::assertResponseStatusCodeSame(404);

        /** @var EntityManagerInterface $emApres */
        $emApres = static::getContainer()->get('doctrine')->getManager();
        $apres = (int) $emApres->getRepository(EcritureComptable::class)->createQueryBuilder('e')->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
        self::assertSame($avant, $apres, 'Aucune écriture ne doit être créée hors périmètre.');
    }

    public function testCompteAutreProfilRefuse422(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->ouvrirPeriode($client, $entete, '2026-08-01', '2026-08-31');

        // Second profil exploitant, sur un établissement DISTINCT (contrainte unique
        // `etablissement_principal_id` sur `ProfilExploitant`, 1 profil par établissement), pour
        // injecter un compte d'un AUTRE profil que celui résolu au point 1 (§0.5 point 4).
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $regionA = $em->getRepository(\App\Organisation\Entity\Etablissement::class)->find($idA)?->getRegion();

        $etablissementTiers = new \App\Organisation\Entity\Etablissement();
        $etablissementTiers->setNom('Établissement tiers (test IDOR inter-profils)');
        $etablissementTiers->setRegion($regionA);
        $em->persist($etablissementTiers);

        $autreProfil = new \App\Compta\Entity\ProfilExploitant();
        $autreProfil->setSiren('987654321');
        $autreProfil->setEtablissementPrincipal($etablissementTiers);
        $em->persist($autreProfil);
        $autreCompte = (new \App\Compta\Entity\CompteComptable())
            ->setProfilExploitant($autreProfil)->setNumero('999999')->setLibelle('Compte étranger au profil courant')
            ->setSens(\App\Compta\Enum\SensCompte::Debit);
        $em->persist($autreCompte);
        $em->flush();

        $client->request('POST', '/api/compta/journal-entries/manual', $entete + [
            'json' => [
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'journal' => '/api/journals/' . $this->idJournal('OD'),
                'date' => '2026-08-19',
                'label' => 'Compte hors profil',
                'lines' => [
                    ['account' => '/api/compte_comptables/' . $autreCompte->getId(), 'debit' => '100.00', 'vatRate' => '/api/taux_tvas/' . $this->idTauxHorsChamp()],
                    ['account' => '/api/compte_comptables/' . $this->idCompte('401000'), 'credit' => '100.00', 'vatRate' => '/api/taux_tvas/' . $this->idTauxHorsChamp()],
                ],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }
}
