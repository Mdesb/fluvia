<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Compta\DataFixtures\ComptaFixtures;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\ReferentielComptable;
use App\Compta\Enum\StatutPeriode;
use App\Compta\Enum\TypeExploitant;
use App\DataFixtures\SocleFixtures;
use App\Facturation\Entity\ParametreFacturationEtablissement;
use App\Facturation\Entity\SerieNumerotation;
use App\Facturation\Enum\PrefixeSerie;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Correctif revue de cohérence (défaut 3, MAJEUR) : `PerimetreFacturationExtension` ne filtrait que
 * `Facture` — `GET /parametres-facturation` et `/series-numerotation` renvoyaient toutes les lignes
 * tous établissements (fuite SIRET/TVA/numéros inter-tenants). Reproduit le scénario avec un second
 * `ProfilExploitant` rattaché à l'établissement B et vérifie que le lecteur (affecté à A **seulement**,
 * même patron que `CloisonnementFacturationTest`) ne voit jamais les lignes de B — l'administrateur
 * n'est pas exploitable ici : il est affecté aux deux établissements (fixtures socle), donc verrait
 * légitimement les deux quel que soit l'en-tête (le filtrage porte sur les affectations de
 * l'utilisateur, pas sur l'établissement actif — même sémantique que `Facture`).
 */
final class CloisonnementParametresEtSeriesTest extends FacturationApiTestCase
{
    public function testParametresFacturationCloisonnesParEtablissement(): void
    {
        $profilB = $this->creerProfilEtParametrePourB();

        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $enteteA = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $reponse = $client->request('GET', '/api/parametres-facturation', $enteteA);
        self::assertResponseIsSuccessful();
        $donnees = $reponse->toArray();
        // ⚠ LE TEMOIN EST L'AXE DE CLOISONNEMENT, PAS UN CHAMP DECORATIF.
        //
        // Ce test lisait `mentionsLegalesEmetteur.siret` pour distinguer A de B. Ce n'est pas ce
        // qu'il teste — il teste le CLOISONNEMENT — c'était un marqueur commode, et il est tombé le
        // jour où l'identité légale a déménagé vers `ProfilExploitant` (01/09).
        //
        // Le profil EST l'axe : un test qui se prouve sur son propre axe ne tombe pas parce qu'un
        // champ voisin a bougé.
        $profils = array_map(
            static fn (array $p): mixed => $p['profilExploitant'] ?? null,
            $donnees['member'] ?? $donnees,
        );

        // Témoin : la liste n'est pas vide. Sans lui, « ne contient pas B » serait vrai pour rien —
        // une collection vide ne contient rien, y compris ce qu'on cherche.
        self::assertNotEmpty($profils, 'Le lecteur affecté à A doit voir au moins son propre paramétrage.');

        self::assertNotContains(
            '/api/profil_exploitants/' . $profilB->getId(),
            $profils,
            'Le paramétrage de B ne doit pas fuiter vers le lecteur affecté à A seule.',
        );
    }

    public function testSeriesNumerotationCloisonneesParEtablissement(): void
    {
        // Génère une SerieNumerotation réelle pour A (émission d'une facture directe par l'admin).
        [$clientAdmin, $enteteAdminA] = $this->adminSurA();
        $brouillon = $clientAdmin->request('POST', '/api/factures', $enteteAdminA + [
            'json' => $this->corpsFactureDirecte(50.0),
        ])->toArray();
        $clientAdmin->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $enteteAdminA);

        $profilB = $this->creerProfilEtParametrePourB();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $periodeB = new PeriodeComptable();
        $periodeB->setProfilExploitant($profilB);
        $periodeB->setDateDebut(new \DateTimeImmutable('first day of this month'));
        $periodeB->setDateFin(new \DateTimeImmutable('last day of this month'));
        $periodeB->setStatut(StatutPeriode::Ouverte);
        $em->persist($periodeB);

        $serieB = new SerieNumerotation();
        $serieB->setProfilExploitant($profilB);
        $serieB->setPeriode($periodeB);
        $serieB->setPrefixe(PrefixeSerie::Facture);
        $serieB->setDernierNumero(7);
        $em->persist($serieB);
        $em->flush();

        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $enteteA = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $reponse = $client->request('GET', '/api/series-numerotation', $enteteA);
        self::assertResponseIsSuccessful();
        $donnees = $reponse->toArray();
        $derniers = array_map(static fn (array $s): int => (int) $s['dernierNumero'], $donnees['member'] ?? $donnees);

        self::assertContains(1, $derniers, 'La série de A (première facture émise) doit rester visible au lecteur affecté à A.');
        self::assertNotContains(7, $derniers, 'La série de B (dernierNumero=7) ne doit pas fuiter vers le lecteur affecté à A seule.');
    }

    private function creerProfilEtParametrePourB(): ProfilExploitant
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabB);

        $profilB = new ProfilExploitant();
        $profilB->setType(TypeExploitant::RegieDirecte);
        $profilB->setReferentielComptable(ReferentielComptable::M57);
        $profilB->setSiren('999999999');
        $profilB->setEtablissementPrincipal($etabB);
        $em->persist($profilB);

        $parametreB = new ParametreFacturationEtablissement();
        $parametreB->setProfilExploitant($profilB);
        $parametreB->setMentionsLegalesEmetteur([
            'denomination' => 'Patinoire B',
            'adresse' => ['rue' => '1 rue de B', 'cp' => '75000', 'ville' => 'Paris', 'pays' => 'FR'],
            'siret' => '99999999900099',
            'tvaIntra' => 'FR99999999999',
        ]);
        $parametreB->setConditionsReglementDefaut('Paiement à 30 jours date de facture.');
        $parametreB->setDelaiPaiementDefautJours(30);
        $em->persist($parametreB);

        $em->flush();

        return $profilB;
    }
}
