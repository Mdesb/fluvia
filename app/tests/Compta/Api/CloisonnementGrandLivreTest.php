<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\ReferentielComptable;
use App\Compta\Enum\StatutEcriture;
use App\Compta\Enum\TypeExploitant;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le grand livre est cloisonné (D3, `AccountingScopeExtension`, 23/08).
 *
 * **Ce que ce test prouve, et pourquoi il n'existait pas avant.** Le module `Compta` était le seul des
 * vingt-six modules exposant des ressources API à n'avoir **aucune** extension de périmètre :
 * `GET /ecritures-comptables`, protégé par la seule permission `compta.lire`, renvoyait le grand livre
 * de **tous** les établissements. Ce n'est pas un IDOR — il n'y avait aucun identifiant à deviner, il
 * suffisait d'appeler la route.
 *
 * **Le montage utilise le `lecteur`, et c'est le point clé.** L'administrateur socle est affecté sur A
 * **et** sur B : il verrait légitimement les deux, et un test bâti sur lui serait vert avec ou sans
 * cloisonnement — donc sans valeur. Le `lecteur` porte `*.lire`, donc `compta.lire`, et n'est affecté
 * qu'à **A**. C'est le seul montage qui distingue réellement les deux situations.
 *
 * **Deux assertions.** L'écriture de B est absente — et celles de A sont présentes. Sans ce second
 * contrôle, une extension trop stricte qui masquerait *tout* rendrait le test vert pour la mauvaise
 * raison, et personne ne s'en apercevrait avant la production.
 */
final class CloisonnementGrandLivreTest extends ComptaApiTestCase
{
    public function testLecteurDeANeVoitPasLesEcrituresDeB(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertNotNull($etabB);

        // Un exploitant comptable propre à B, avec son journal, sa période et une écriture.
        $profilB = (new ProfilExploitant())
            ->setEtablissementPrincipal($etabB)
            ->setType(TypeExploitant::RegieDirecte)
            ->setReferentielComptable(ReferentielComptable::M57)
            ->setSiren('552081317');
        $em->persist($profilB);

        $journalB = (new Journal())->setProfilExploitant($profilB)->setCode('VTB')->setLibelle('Ventes B');
        $em->persist($journalB);

        $periodeB = (new PeriodeComptable())
            ->setProfilExploitant($profilB)
            ->setDateDebut(new \DateTimeImmutable('2026-01-01'))
            ->setDateFin(new \DateTimeImmutable('2026-12-31'));
        $em->persist($periodeB);

        $ecritureB = (new EcritureComptable())
            ->setProfilExploitant($profilB)
            ->setJournal($journalB)
            ->setPeriode($periodeB)
            ->setDateEcriture(new \DateTimeImmutable('2026-06-15'))
            ->setLibelle('ECRITURE-SECRETE-DE-B')
            ->setStatut(StatutEcriture::Controlee);
        $em->persist($ecritureB);

        // Une ecriture sur A, sans quoi le controle positif ne prouve rien : les fixtures du projet
        // n'en creent aucune, et une liste vide serait alors verte pour la mauvaise raison.
        $profilA = $this->profilExploitant();

        // Le profil A a des journaux en fixture, mais **aucune période** : les fixtures du projet n'en
        // créent pour personne. On la crée, comme pour B.
        $periodeA = (new PeriodeComptable())
            ->setProfilExploitant($profilA)
            ->setDateDebut(new \DateTimeImmutable('2026-01-01'))
            ->setDateFin(new \DateTimeImmutable('2026-12-31'));
        $em->persist($periodeA);

        $journalA = $em->getRepository(Journal::class)->findOneBy(['profilExploitant' => $profilA]);
        self::assertNotNull($journalA, 'Le profil A doit avoir au moins un journal en fixture.');

        $ecritureA = (new EcritureComptable())
            ->setProfilExploitant($profilA)
            ->setJournal($journalA)
            ->setPeriode($periodeA)
            ->setDateEcriture(new \DateTimeImmutable('2026-06-15'))
            ->setLibelle('ECRITURE-VISIBLE-DE-A')
            ->setStatut(StatutEcriture::Controlee);
        $em->persist($ecritureA);

        $em->flush();

        // Le lecteur : `*.lire` donc `compta.lire`, affecté a A seulement.
        $client = static::createClient();
        $jeton = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $entete = [
            'auth_bearer' => $jeton,
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)],
        ];

        $reponse = $client->request('GET', '/api/ecriture_comptables', $entete);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));

        $corps = $reponse->toArray();
        $membres = $corps['member'] ?? $corps['hydra:member'] ?? [];

        $libelles = array_map(static fn (array $e): string => (string) ($e['libelle'] ?? ''), $membres);

        self::assertNotContains(
            'ECRITURE-SECRETE-DE-B',
            $libelles,
            'Le grand livre d\'un autre établissement ne doit jamais apparaître (D3).',
        );

        self::assertContains(
            'ECRITURE-VISIBLE-DE-A',
            $libelles,
            'Contrôle positif : le lecteur doit continuer de voir les écritures de son propre établissement — '
            . 'une extension qui masque tout serait verte pour la mauvaise raison.',
        );
    }
}
