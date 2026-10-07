<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\StatutProjectionDroit;
use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\Caisse\Entity\SessionCaisse;
use App\Caisse\Enum\EtatCaisse;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Offre\Enum\RechargeValidityMode;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Acces\AccesApiTestCase;
use App\Tests\Acces\Support\CardRechargedEventCollector;
use App\Vente\Entity\BilletSupport;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use App\Vente\Service\ValiderVenteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * CQ-1 — recharge d'une carte multi-entrées (`RG-CQ1-01..09`, spec `spec-cq1-recharge-carte.md`).
 *
 * Scénario systématiquement composé (§11 spec, précondition sine qua non) : 1) vente initiale d'un
 * produit-carte, payée, validée → émission du `BilletSupport` ; 2) `POST /acces/appairages` avec
 * l'identifiant émis (`billetSupportRef`, cf. §3 pt.3 de la spec — l'appairage explicite réel, pas le
 * stub M2) ; 3) seconde vente du même produit-carte, `supportsOverride.identifiant` = l'identifiant de
 * l'étape 1, payée, validée → déclenche la recharge.
 */
final class CardRechargeTest extends AccesApiTestCase
{
    /** CA-1 (RG-CQ1-01/02) — même DroitAcces incrémenté, aucun doublon Droit/Appairage/Support. */
    public function testCa1MemeDroitIncrementeAucunDoublon(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$identifiant, $idDroit] = $this->emettreEtAppairerCarte($client, $entete, $session['id']);

        $em = $this->em();
        $countDroitAvant = (int) $em->getRepository(DroitAcces::class)->count([]);
        $countSupportAvant = (int) $em->getRepository(Support::class)->count([]);
        $countAppairageAvant = (int) $em->getRepository(Appairage::class)->count([]);

        $reponse = $this->rechargerCarte($client, $entete, $session['id'], $identifiant);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));
        $valide = $reponse->toArray();
        self::assertSame([], $valide['supports'] ?? [], 'RG-CQ1-05 : aucun nouveau BilletSupport pour une recharge.');

        // D7-bis — chemin heureux : l'événement est bien publié une fois la vente validée (commit réel).
        // Le collecteur est récupéré APRÈS la requête (pas avant) : `KernelBrowser` réamorce le noyau à
        // chaque requête par défaut, donc le conteneur courant — et l'instance singleton qu'il détient —
        // n'est stable qu'après la dernière requête effectuée (même prudence que partout ailleurs dans
        // ces tests, où `$em`/`entite()` sont systématiquement relus après coup, jamais mis en cache).
        /** @var CardRechargedEventCollector $collecteur */
        $collecteur = static::getContainer()->get(CardRechargedEventCollector::class);
        $evenements = $collecteur->evenements();
        self::assertCount(1, $evenements, 'access.card_recharged doit être publié après une recharge réussie.');
        self::assertSame('access.card_recharged', $evenements[0]->name->value);
        self::assertSame($idDroit, $evenements[0]->payload['droitId']);
        self::assertSame(24, $evenements[0]->payload['creditBalanceAfter']);

        $em->clear();
        $droit = $em->getRepository(DroitAcces::class)->find(Uuid::fromString($idDroit));
        self::assertInstanceOf(DroitAcces::class, $droit);
        self::assertSame(24, $droit->getCreditRestant(), '12 (émission) + 12 (recharge, même stock initial) = 24.');

        self::assertSame($countDroitAvant, (int) $em->getRepository(DroitAcces::class)->count([]), 'Aucun second DroitAcces.');
        self::assertSame($countSupportAvant, (int) $em->getRepository(Support::class)->count([]), 'Aucun second Support.');
        self::assertSame($countAppairageAvant, (int) $em->getRepository(Appairage::class)->count([]), 'Aucun second Appairage.');
    }

    /** CA-2 (RG-CQ1-03) — Support.versionMaj bascule ; le snapshot terminal reflète le nouveau solde. */
    public function testCa2VersionMajBasculeEtSnapshotReflete(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$identifiant, , $idSupportAcces] = $this->emettreEtAppairerCarte($client, $entete, $session['id']);

        $em = $this->em();
        $support = $em->getRepository(Support::class)->find(Uuid::fromString($idSupportAcces));
        self::assertInstanceOf(Support::class, $support);
        $versionAvant = $support->getVersionMaj();

        $terminalEntete = $this->terminalEntete();
        $terminalClient = static::createClient();
        $curseur = $terminalClient->request('GET', '/api/terminal/snapshot', $terminalEntete)->toArray()['versionCourante'];

        $reponse = $this->rechargerCarte($client, $entete, $session['id'], $identifiant);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));

        $em->clear();
        $supportApres = $em->getRepository(Support::class)->find(Uuid::fromString($idSupportAcces));
        self::assertInstanceOf(Support::class, $supportApres);
        self::assertGreaterThan($versionAvant, $supportApres->getVersionMaj());

        $delta = $terminalClient->request('GET', '/api/terminal/snapshot', $terminalEntete + ['query' => ['depuis' => $curseur]])->toArray();
        $entree = current(array_filter($delta['entrees'], static fn (array $e): bool => $e['identifiant'] === $identifiant));
        self::assertNotFalse($entree, 'Le support rechargé doit apparaître au delta.');
        self::assertSame(24, $entree['compostagesRestants']);
    }

    /** CA-3 (RG-CQ1-04, D26) — nouvelle échéance = J + 1 période complète, jamais « ancienne + période ». */
    public function testCa3EcheanceRepartPourUnePeriodeCompleteDepuisMaintenant(): void
    {
        [$client, $entete] = $this->adminSurA();
        $em = $this->em();
        $this->parametrerCarteDemo($em, new \DateInterval('P1Y'), null);

        $session = $this->ouvrirSession($client, $entete);
        [$identifiant, $idDroit] = $this->emettreEtAppairerCarte($client, $entete, $session['id']);

        $droit = $em->getRepository(DroitAcces::class)->find(Uuid::fromString($idDroit));
        self::assertInstanceOf(DroitAcces::class, $droit);
        $droit->setFenetreFin(new \DateTimeImmutable('+3 days')); // échéance actuelle : J + 3 jours.
        $em->flush();

        $reponse = $this->rechargerCarte($client, $entete, $session['id'], $identifiant);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));

        $em->clear();
        $droitApres = $em->getRepository(DroitAcces::class)->find(Uuid::fromString($idDroit));
        self::assertInstanceOf(DroitAcces::class, $droitApres);
        $attendu = (new \DateTimeImmutable('+1 year'))->format('Y-m-d');
        self::assertSame($attendu, $droitApres->getFenetreFin()?->format('Y-m-d'), 'J + 1 an, pas « J+3j + 1 an ».');
    }

    /** CA-4 (RG-CQ1-04, plafond) — l'échéance est plafonnée par dateButoir, pas repoussée à J + durée. */
    public function testCa4EcheancePlafonneeParDateButoir(): void
    {
        [$client, $entete] = $this->adminSurA();
        $em = $this->em();
        $butoir = new \DateTimeImmutable('+2 months');
        $this->parametrerCarteDemo($em, new \DateInterval('P1Y'), $butoir);

        $session = $this->ouvrirSession($client, $entete);
        [$identifiant, $idDroit] = $this->emettreEtAppairerCarte($client, $entete, $session['id']);

        $reponse = $this->rechargerCarte($client, $entete, $session['id'], $identifiant);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));

        $em->clear();
        $droitApres = $em->getRepository(DroitAcces::class)->find(Uuid::fromString($idDroit));
        self::assertInstanceOf(DroitAcces::class, $droitApres);
        self::assertSame($butoir->format('Y-m-d'), $droitApres->getFenetreFin()?->format('Y-m-d'), 'Plafonné à dateButoir.');
    }

    /**
     * CQ-7 (RG-CQ7-03) — mode `Keep` : la recharge ajoute des crédits mais **conserve** l'échéance
     * existante, jamais repoussée (contraste avec CA-3 en mode `Extend` par défaut).
     */
    public function testCq7ModeKeepConserveEcheanceALaRecharge(): void
    {
        [$client, $entete] = $this->adminSurA();
        $em = $this->em();

        // Carte avec une durée de validité ET le mode Keep.
        $produit = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_CARTE]);
        $carte = $produit->getCarte();
        self::assertNotNull($carte);
        $carte->setValiditeDuree(new \DateInterval('P1Y'))->setRechargeValidityMode(RechargeValidityMode::Keep);
        $em->flush();

        $session = $this->ouvrirSession($client, $entete);
        [$identifiant, $idDroit] = $this->emettreEtAppairerCarte($client, $entete, $session['id']);

        // Fixe une échéance connue et arbitraire, distincte de « maintenant + 1 an ».
        $droit = $em->getRepository(DroitAcces::class)->find(Uuid::fromString($idDroit));
        self::assertInstanceOf(DroitAcces::class, $droit);
        $echeanceFigee = new \DateTimeImmutable('2027-03-15');
        $droit->setFenetreFin($echeanceFigee);
        $em->flush();

        $reponse = $this->rechargerCarte($client, $entete, $session['id'], $identifiant);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));

        $em->clear();
        $droitApres = $em->getRepository(DroitAcces::class)->find(Uuid::fromString($idDroit));
        self::assertInstanceOf(DroitAcces::class, $droitApres);
        self::assertSame(24, $droitApres->getCreditRestant(), 'La recharge a bien crédité (12 + 12).');
        self::assertSame(
            $echeanceFigee->format('Y-m-d'),
            $droitApres->getFenetreFin()?->format('Y-m-d'),
            'RG-CQ7-03 : mode Keep -> échéance existante conservée, jamais repoussée à J + 1 an.',
        );
    }

    /** CA-5 (RG-CQ1-05) — la recharge est une vente standard, scellée NF525, chaînée. */
    public function testCa5VenteDeRechargeScelleeNf525EtComptabiliseeCa(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$identifiant] = $this->emettreEtAppairerCarte($client, $entete, $session['id']);

        $avant = $client->request('GET', '/api/operation_scellees', $entete)->toArray();
        $nbAvant = \count($avant['member'] ?? $avant['hydra:member'] ?? []);

        $reponse = $this->rechargerCarte($client, $entete, $session['id'], $identifiant);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));
        $valide = $reponse->toArray();

        self::assertNotEmpty($valide['numero']);
        self::assertSame('45.00', $valide['total']);
        self::assertNotEmpty($valide['paiements']);
        self::assertSame('validee', $valide['statut']);

        $apres = $client->request('GET', '/api/operation_scellees', $entete)->toArray();
        $nbApres = \count($apres['member'] ?? $apres['hydra:member'] ?? []);
        self::assertGreaterThan($nbAvant, $nbApres, 'La recharge produit un maillon NF525 de plus.');

        $rapport = $client->request('POST', '/api/nf525/verifier-chaine', $entete + [
            'json' => ['pointDeVente' => '/api/point_de_ventes/' . $this->idPointDeVente()],
        ])->toArray();
        self::assertTrue($rapport['intacte']);
    }

    /** CA-6 (RG-CQ1-06) — cloisonnement : recharge cross-tenant échoue en 404, rien n'est modifié sur A. */
    public function testCa6CloisonnementRechargeCrossTenantEchoue404(): void
    {
        [$clientA, $enteteA] = $this->adminSurA();
        $sessionA = $this->ouvrirSession($clientA, $enteteA);
        [$identifiant, $idDroit] = $this->emettreEtAppairerCarte($clientA, $enteteA, $sessionA['id']);

        $em = $this->em();
        $droitAvant = $em->getRepository(DroitAcces::class)->find(Uuid::fromString($idDroit));
        self::assertInstanceOf(DroitAcces::class, $droitAvant);
        $creditAvant = $droitAvant->getCreditRestant();
        $fenetreFinAvant = $droitAvant->getFenetreFin();

        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $enteteB = ['auth_bearer' => $enteteA['auth_bearer'], 'headers' => [ContexteEtablissement::HEADER => $idB]];
        $sessionIdB = $this->creerSessionCaissePourEtablissement($idB);

        // La carte des fixtures n'est vendue QUE sur le site A. Ce test la vendait pourtant à la caisse
        // de B — il s'appuyait sans le savoir sur le défaut corrigé le 06/10/2026 (la caisse vendait un
        // produit d'un autre site). La carte est donc rendue vendable sur B aussi : la vente à B est
        // légitime, et ce qui est éprouvé reste l'intrusion — rattacher à cette vente le SUPPORT de A.
        $carte = $em->getRepository(Produit::class)->findOneBy(['libelleRecherche' => OffreFixtures::PRODUIT_CARTE]);
        $etabB = $em->getRepository(Etablissement::class)->find(Uuid::fromString($idB));
        self::assertInstanceOf(Produit::class, $carte);
        self::assertInstanceOf(Etablissement::class, $etabB);
        $carte->addEtablissement($etabB);
        $em->flush();

        $clientB = static::createClient();
        $venteB = $this->creerVente($clientB, $enteteB, $sessionIdB);
        $ligneB = $clientB->request('POST', '/api/ventes/' . $venteB['id'] . '/lignes', $enteteB + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ])->toArray();
        $ligneIdB = $ligneB['lignes'][0]['id'];
        $clientB->request('POST', '/api/ventes/' . $venteB['id'] . '/paiements', $enteteB + ['json' => ['moyen' => 'especes', 'montant' => '45.00']]);

        $intrusion = $clientB->request('POST', '/api/ventes/' . $venteB['id'] . '/valider', $enteteB + [
            'json' => ['supports' => [['ligne' => $ligneIdB, 'identifiant' => $identifiant]]],
        ]);

        self::assertSame(404, $intrusion->getStatusCode(), (string) $intrusion->getContent(false));

        $em->clear();
        $droitApres = $em->getRepository(DroitAcces::class)->find(Uuid::fromString($idDroit));
        self::assertInstanceOf(DroitAcces::class, $droitApres);
        self::assertSame($creditAvant, $droitApres->getCreditRestant(), 'Aucun champ du droit de A modifié.');
        self::assertEquals($fenetreFinAvant, $droitApres->getFenetreFin());
    }

    /**
     * CA-7 (RG-CQ1-08) — preuve de l'incrément atomique : une écriture SQL brute directe (simulant une
     * seconde recharge concurrente déjà committée, +5) est injectée sur le même droit PENDANT que la
     * copie en mémoire (map d'identité) reste délibérément périmée, puis une recharge applicative (+12)
     * est déclenchée via l'API. Le solde final doit refléter les DEUX incréments (RG-CQ1-08) — jamais
     * un UPDATE absolu qui écraserait l'un des deux.
     */
    public function testCa7IncrementAtomiqueSansPerteSousEcritureConcurrenteNonSerialisee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$identifiant, $idDroit] = $this->emettreEtAppairerCarte($client, $entete, $session['id']);

        $em = $this->em();
        // Charge délibérément $droit dans la map d'identité AVANT l'écriture concurrente, pour que la
        // valeur PHP en mémoire soit périmée au moment de la recharge applicative ci-dessous — sans
        // quoi le test ne prouverait rien (cf. plan §8 risque n°10).
        $droit = $em->getRepository(DroitAcces::class)->find(Uuid::fromString($idDroit));
        self::assertInstanceOf(DroitAcces::class, $droit);
        $creditInitial = $droit->getCreditRestant();
        self::assertSame(12, $creditInitial, 'Précondition : crédit initial connu (émission, stock 12).');

        // Écriture SQL brute, hors ORM, simulant une seconde recharge déjà committée (+5).
        $em->getConnection()->executeStatement(
            'UPDATE acces_droit_acces SET credit_restant = credit_restant + 5 WHERE id = UNHEX(:hex)',
            ['hex' => bin2hex($droit->getId()->toBinary())],
        );

        // Recharge applicative (+12) déclenchée via l'API : $droit ci-dessus reste périmé en mémoire.
        $reponse = $this->rechargerCarte($client, $entete, $session['id'], $identifiant);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));

        $em->clear();
        $droitFinal = $em->getRepository(DroitAcces::class)->find(Uuid::fromString($idDroit));
        self::assertInstanceOf(DroitAcces::class, $droitFinal);
        self::assertSame(
            $creditInitial + 5 + 12,
            $droitFinal->getCreditRestant(),
            'Les deux incréments doivent être conservés — jamais initial + 12 seul (RG-CQ1-08).',
        );
    }

    /** CA-8 (RG-CQ1-07) — support jamais appairé côté Accès → refus explicite, aucun droit créé en repli. */
    public function testCa8RefusSupportJamaisAppaireAucunDroitCreeEnRepli(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        // Émission SANS appairage explicite (POST /acces/appairages jamais appelé).
        [$venteId] = $this->venteCarteAvecLigne($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '45.00']]);
        $valide = $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete + ['json' => []])->toArray();
        $identifiant = $valide['supports'][0]['identifiantSupport'];

        $em = $this->em();
        $countDroitAvant = (int) $em->getRepository(DroitAcces::class)->count([]);

        $reponse = $this->rechargerCarte($client, $entete, $session['id'], $identifiant);
        self::assertSame(422, $reponse->getStatusCode(), (string) $reponse->getContent(false));

        self::assertSame($countDroitAvant, (int) $em->getRepository(DroitAcces::class)->count([]), 'Aucun DroitAcces créé en repli.');
    }

    /** CA-9a (RG-CQ1-07) — support bloqué (perte/vol) → refus, solde inchangé. */
    public function testCa9aRefusSupportBloque(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$identifiant, $idDroit, $idSupportAcces] = $this->emettreEtAppairerCarte($client, $entete, $session['id']);

        $client->request('POST', '/api/acces/supports/' . $idSupportAcces . '/bloquer', $entete + [
            'json' => ['motif' => 'Test CQ-1 CA-9a'],
        ]);
        self::assertResponseIsSuccessful();

        $reponse = $this->rechargerCarte($client, $entete, $session['id'], $identifiant);
        self::assertSame(409, $reponse->getStatusCode(), (string) $reponse->getContent(false));

        $em = $this->em();
        $em->clear();
        $droit = $em->getRepository(DroitAcces::class)->find(Uuid::fromString($idDroit));
        self::assertSame(12, $droit?->getCreditRestant(), 'Solde inchangé.');
    }

    /** CA-9b (RG-CQ1-07) — droit dévalidé → refus, solde inchangé. */
    public function testCa9bRefusDroitDevalide(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$identifiant, $idDroit] = $this->emettreEtAppairerCarte($client, $entete, $session['id']);

        $em = $this->em();
        $droit = $em->getRepository(DroitAcces::class)->find(Uuid::fromString($idDroit));
        self::assertInstanceOf(DroitAcces::class, $droit);
        $droit->setStatutProjection(StatutProjectionDroit::Devalide);
        $em->flush();

        $reponse = $this->rechargerCarte($client, $entete, $session['id'], $identifiant);
        self::assertSame(409, $reponse->getStatusCode(), (string) $reponse->getContent(false));

        $em->clear();
        $droitApres = $em->getRepository(DroitAcces::class)->find(Uuid::fromString($idDroit));
        self::assertSame(12, $droitApres?->getCreditRestant(), 'Solde inchangé.');
    }

    /**
     * RG-CQ1-07 (cas limite §10 spec, arbitrage claude-A) — une ligne de recharge à `quantite > 1`
     * facturerait plusieurs fois (`PanierCalculateur`) pour un seul crédit (la recharge ne crédite le
     * droit qu'une fois, indépendamment de la quantité) : refusée explicitement (422), AVANT tout
     * `UPDATE` de crédit — jamais une facturation multiple silencieuse. Crédit inchangé, aucun
     * événement publié.
     */
    public function testRefusRechargeQuantiteSuperieureAUn(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$identifiant, $idDroit] = $this->emettreEtAppairerCarte($client, $entete, $session['id']);

        $em = $this->em();
        $droitAvant = $em->getRepository(DroitAcces::class)->find(Uuid::fromString($idDroit));
        self::assertInstanceOf(DroitAcces::class, $droitAvant);
        $creditAvant = $droitAvant->getCreditRestant();
        self::assertSame(12, $creditAvant, 'Précondition : crédit initial connu (émission, stock 12).');

        // Vente avec une SEULE ligne de recharge, mais quantite = 2.
        $vente = $this->creerVente($client, $entete, $session['id']);
        $apres = $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 2,
            ],
        ])->toArray();
        $ligneId = $apres['lignes'][0]['id'];

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '90.00']]);

        $reponse = $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + [
            'json' => ['supports' => [['ligne' => $ligneId, 'identifiant' => $identifiant]]],
        ]);
        self::assertSame(422, $reponse->getStatusCode(), (string) $reponse->getContent(false));

        $em->clear();
        $droitApres = $em->getRepository(DroitAcces::class)->find(Uuid::fromString($idDroit));
        self::assertInstanceOf(DroitAcces::class, $droitApres);
        self::assertSame($creditAvant, $droitApres->getCreditRestant(), 'Refus AVANT tout UPDATE : crédit inchangé.');

        /** @var CardRechargedEventCollector $collecteur */
        $collecteur = static::getContainer()->get(CardRechargedEventCollector::class);
        self::assertSame([], $collecteur->evenements(), 'Aucun access.card_recharged ne doit être publié si la vente est refusée.');
    }

    /** RG-CQ1-07 (bullet 2) — identifiant existant mais type ≠ Carte → conflit explicite, pas un crash. */
    public function testRefusIdentifiantExistantTypeNonCarte(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        // Émission d'un billet simple (pas une carte) avec identifiant auto-généré.
        $venteBillet = $this->creerVente($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $venteBillet['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        $client->request('POST', '/api/ventes/' . $venteBillet['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '5.50']]);
        $valideBillet = $client->request('POST', '/api/ventes/' . $venteBillet['id'] . '/valider', $entete + ['json' => []])->toArray();
        $identifiantBillet = $valideBillet['supports'][0]['identifiantSupport'];

        // Tentative de « recharge » sur une ligne produit-carte avec cet identifiant de billet.
        [$venteId, $ligneId] = $this->venteCarteAvecLigne($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '45.00']]);
        $reponse = $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete + [
            'json' => ['supports' => [['ligne' => $ligneId, 'identifiant' => $identifiantBillet]]],
        ]);

        self::assertSame(409, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    /**
     * Correctif revue de cohérence (risque n°1 du lot, atomicité recharge ⇄ vente) — une vente à DEUX
     * lignes : ligne 1 = recharge légitime d'une carte déjà appairée (déclenche l'UPDATE brut
     * RG-CQ1-08 et collecte l'événement `access.card_recharged`, RG-CQ1-*, D7-bis) ; ligne 2 = refus
     * explicite (RG-CQ1-07 bullet 2, identifiant d'un billet non-carte) qui fait échouer `valider()`
     * en 409 APRÈS que la ligne 1 ait déjà été traitée. Preuve automatisée que :
     *   1) le rollback de la transaction externe (`ValiderVenteService::valider()`, RG-CQ1-08) annule
     *      bien l'`UPDATE` brut déjà exécuté par la ligne 1 (`credit_restant`/`version_maj` inchangés,
     *      relecture DB après `em->clear()`) ;
     *   2) aucun événement n'est publié quand la transaction échoue (D7-bis, correctif revue de
     *      cohérence) — le bus étant synchrone, un événement publié avant le rollback aurait été vu
     *      immédiatement par le collecteur ci-dessous.
     */
    public function testAtomiciteRechargeRollbackSiEchecUneLigneSuivante(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        [$identifiantCarte, $idDroit, $idSupportAcces] = $this->emettreEtAppairerCarte($client, $entete, $session['id']);

        // Un billet simple existant (type != Carte), pour forcer le refus RG-CQ1-07 (bullet 2) sur la
        // DEUXIÈME ligne de la vente testée plus bas.
        $venteBillet = $this->creerVente($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $venteBillet['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        $client->request('POST', '/api/ventes/' . $venteBillet['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '5.50']]);
        $valideBillet = $client->request('POST', '/api/ventes/' . $venteBillet['id'] . '/valider', $entete + ['json' => []])->toArray();
        $identifiantBillet = $valideBillet['supports'][0]['identifiantSupport'];

        $em = $this->em();
        $droitAvant = $em->getRepository(DroitAcces::class)->find(Uuid::fromString($idDroit));
        self::assertInstanceOf(DroitAcces::class, $droitAvant);
        $creditAvant = $droitAvant->getCreditRestant();
        self::assertSame(12, $creditAvant, 'Précondition : crédit initial connu (émission, stock 12).');
        $supportAcces = $em->getRepository(Support::class)->find(Uuid::fromString($idSupportAcces));
        self::assertInstanceOf(Support::class, $supportAcces);
        $versionAvant = $supportAcces->getVersionMaj();

        // Vente à 2 lignes du même produit-carte : ligne 1 recharge (identifiant connu, légitime),
        // ligne 2 refusée (identifiant d'un billet non-carte).
        $vente = $this->creerVente($client, $entete, $session['id']);
        $apres1 = $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ])->toArray();
        $ligneId1 = $apres1['lignes'][0]['id'];

        $apres2 = $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ])->toArray();
        self::assertCount(2, $apres2['lignes']);
        $ligneId2 = $apres2['lignes'][1]['id'];

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '90.00']]);

        $reponse = $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + [
            'json' => ['supports' => [
                ['ligne' => $ligneId1, 'identifiant' => $identifiantCarte],
                ['ligne' => $ligneId2, 'identifiant' => $identifiantBillet],
            ]],
        ]);
        self::assertSame(409, $reponse->getStatusCode(), (string) $reponse->getContent(false));

        // 1) Rollback effectif : relecture DB (après em->clear()) — credit_restant ET version_maj de la
        // ligne 1 (déjà traitée avant le refus de la ligne 2) sont inchangés.
        $em->clear();
        $droitApres = $em->getRepository(DroitAcces::class)->find(Uuid::fromString($idDroit));
        self::assertInstanceOf(DroitAcces::class, $droitApres);
        self::assertSame(
            $creditAvant,
            $droitApres->getCreditRestant(),
            'RG-CQ1-08 : le rollback doit annuler l\'UPDATE brut déjà exécuté sur credit_restant par la ligne 1.',
        );
        $supportApres = $em->getRepository(Support::class)->find(Uuid::fromString($idSupportAcces));
        self::assertInstanceOf(Support::class, $supportApres);
        self::assertSame(
            $versionAvant,
            $supportApres->getVersionMaj(),
            'Le rollback doit annuler l\'UPDATE brut déjà exécuté sur version_maj par la ligne 1.',
        );

        // 2) Aucun événement publié (D7-bis). Récupéré APRÈS la requête de validation (`KernelBrowser`
        // réamorce le noyau à chaque requête par défaut) : c'est l'instance du conteneur de CETTE
        // requête, celle où la transaction a réellement échoué, qui doit être interrogée.
        /** @var CardRechargedEventCollector $collecteur */
        $collecteur = static::getContainer()->get(CardRechargedEventCollector::class);
        self::assertSame([], $collecteur->evenements(), 'Aucun access.card_recharged ne doit être publié si la transaction échoue.');
    }

    /**
     * Correctif revue de cohérence (piège latent `ConfirmerCommandeHandler`) — quand `valider()` échoue
     * APRÈS avoir déjà créé+persisté un `BilletSupport` (ligne 1 émise), le ROLLBACK SQL de
     * `Connection::transactional()` ne vide PAS l'UnitOfWork. Un appelant qui catche l'exception pour
     * continuer d'utiliser `$em` puis refait un `flush()` — patron exact de
     * `App\Boutique\Service\ConfirmerCommandeHandler::confirmerApresPaiementReussi()` — matérialiserait
     * ce support orphelin (une vente jamais scellée). On reproduit ce patron (valider direct + catch +
     * flush) et on prouve qu'aucun `BilletSupport` orphelin ne subsiste, et que la vente reste `EnCours`
     * (statut mémoire restauré à l'identique du rollback SQL).
     */
    public function testValiderNettoieSupportsOrphelinsQuandAppelantCatcheEtReflush(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        // Un billet simple existant (type != Carte) pour forcer le refus RG-CQ1-07 (bullet 2) sur la
        // ligne 2 de la vente testée.
        $venteBillet = $this->creerVente($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $venteBillet['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        $client->request('POST', '/api/ventes/' . $venteBillet['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '5.50']]);
        $valideBillet = $client->request('POST', '/api/ventes/' . $venteBillet['id'] . '/valider', $entete + ['json' => []])->toArray();
        $identifiantBillet = $valideBillet['supports'][0]['identifiantSupport'];

        // Vente à 2 lignes : ligne 1 = émission normale (PRODUIT_ENTREE, crée+persiste un support) ;
        // ligne 2 = produit-carte portant l'identifiant du billet existant -> ConflictHttpException
        // levée APRÈS la création du support de la ligne 1 (ordre d'insertion préservé).
        $vente = $this->creerVente($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        $apres2 = $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ])->toArray();
        self::assertCount(2, $apres2['lignes']);
        $ligneId2 = $apres2['lignes'][1]['id'];
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '50.50']]);

        // Reproduit le patron ConfirmerCommandeHandler : valider() direct, catch du refus métier, puis
        // flush() qui — sans le correctif — aurait inséré le support orphelin de la ligne 1.
        $em = $this->em();
        $venteEntite = $em->getRepository(Vente::class)->find(Uuid::fromString($vente['id']));
        self::assertInstanceOf(Vente::class, $venteEntite);
        /** @var ValiderVenteService $service */
        $service = static::getContainer()->get(ValiderVenteService::class);

        $countSupportAvant = (int) $em->getRepository(BilletSupport::class)->count([]);

        $refuse = false;
        try {
            $service->valider($venteEntite, [$ligneId2 => ['identifiant' => $identifiantBillet]]);
        } catch (ConflictHttpException) {
            $refuse = true;
        }
        self::assertTrue($refuse, 'La ligne 2 (identifiant billet non-carte sur produit-carte) doit lever un conflit.');

        // L'appelant continue d'utiliser $em (comme ConfirmerCommandeHandler) : ce flush ne doit PAS
        // matérialiser le support orphelin de la ligne 1.
        $em->flush();
        $em->clear();

        self::assertSame(
            $countSupportAvant,
            (int) $em->getRepository(BilletSupport::class)->count([]),
            'Aucun BilletSupport orphelin ne doit subsister après un valider() échoué suivi d\'un flush() appelant.',
        );

        $venteApres = $em->getRepository(Vente::class)->find(Uuid::fromString($vente['id']));
        self::assertInstanceOf(Vente::class, $venteApres);
        self::assertSame(StatutVente::EnCours, $venteApres->getStatut(), 'La vente doit rester EnCours après l\'échec (statut mémoire restauré).');
    }

    /** CA-10 (non-régression) — sans override, ou identifiant inédit : émission normale inchangée. */
    public function testCa10NonRegressionEmissionSansOverrideOuIdentifiantInedit(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        // Sans override.
        [$venteId] = $this->venteCarteAvecLigne($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '45.00']]);
        $valide = $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete + ['json' => []])->toArray();
        self::assertResponseIsSuccessful();
        self::assertNotEmpty($valide['supports']);
        self::assertNotEmpty($valide['supports'][0]['identifiantSupport']);
        self::assertSame(12, $valide['supports'][0]['nbCompostages']);

        // Identifiant inédit fourni explicitement.
        [$venteId2, $ligneId2] = $this->venteCarteAvecLigne($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $venteId2 . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '45.00']]);
        $valide2 = $client->request('POST', '/api/ventes/' . $venteId2 . '/valider', $entete + [
            'json' => ['supports' => [['ligne' => $ligneId2, 'identifiant' => 'CAR-INEDIT-CQ1-0001']]],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertNotEmpty($valide2['supports']);
        self::assertSame('CAR-INEDIT-CQ1-0001', $valide2['supports'][0]['identifiantSupport']);
        self::assertSame(12, $valide2['supports'][0]['nbCompostages']);
    }

    // ------------------------------------------------------------------------------------- fixtures

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array{0: string, 1: string} id vente, id ligne
     */
    private function venteCarteAvecLigne(Client $client, array $entete, string $sessionId): array
    {
        $vente = $this->creerVente($client, $entete, $sessionId);
        $apres = $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ])->toArray();

        return [$vente['id'], $apres['lignes'][0]['id']];
    }

    /**
     * Vend une carte, la paie, la valide (émission), puis l'appaire réellement côté Accès
     * (`POST /acces/appairages`, §3 pt.3 de la spec — précondition sine qua non d'une recharge).
     *
     * @param array<string, mixed> $entete
     *
     * @return array{0: string, 1: string, 2: string} identifiant, id DroitAcces, id Support Accès
     */
    private function emettreEtAppairerCarte(Client $client, array $entete, string $sessionId): array
    {
        [$venteId] = $this->venteCarteAvecLigne($client, $entete, $sessionId);
        $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '45.00']]);
        $valide = $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete + ['json' => []])->toArray();
        self::assertResponseIsSuccessful();
        self::assertNotEmpty($valide['supports']);
        $identifiant = $valide['supports'][0]['identifiantSupport'];

        $billetSupport = $this->entite(BilletSupport::class, ['identifiantSupport' => $identifiant]);

        $reponseAppairage = $client->request('POST', '/api/acces/appairages', $entete + [
            'json' => [
                'identifiantSupport' => $identifiant,
                'typeSupport' => 'QR',
                'billetSupportRef' => (string) $billetSupport->getId(),
                'mode' => 'caisse',
            ],
        ]);
        self::assertResponseIsSuccessful((string) $reponseAppairage->getContent(false));
        $appairage = $reponseAppairage->toArray();
        // `droit`/`support` sont sérialisés en objets embarqués (pas de simples IRI) : `id` est déjà
        // l'UUID brut.
        $idDroit = (string) $appairage['droit']['id'];
        $idSupportAcces = (string) $appairage['support']['id'];

        return [$identifiant, $idDroit, $idSupportAcces];
    }

    /**
     * Seconde vente du même produit-carte, avec l'identifiant déjà connu en override : déclenche la
     * recharge (RG-CQ1-01).
     *
     * @param array<string, mixed> $entete
     */
    private function rechargerCarte(Client $client, array $entete, string $sessionId, string $identifiant): object
    {
        [$venteId, $ligneId] = $this->venteCarteAvecLigne($client, $entete, $sessionId);
        $client->request('POST', '/api/ventes/' . $venteId . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '45.00']]);

        return $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete + [
            'json' => ['supports' => [['ligne' => $ligneId, 'identifiant' => $identifiant]]],
        ]);
    }

    /** Mute la CarteMultiEntrees du produit-carte de démonstration (CA-3/CA-4, produits ad-hoc évités). */
    private function parametrerCarteDemo(EntityManagerInterface $em, ?\DateInterval $duree, ?\DateTimeImmutable $butoir): void
    {
        $produit = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_CARTE]);
        $carte = $produit->getCarte();
        self::assertNotNull($carte);
        $carte->setValiditeDuree($duree)->setDateButoir($butoir);
        $em->flush();
    }

    /** Session de caisse ad-hoc sur un établissement sans fixture PDV/Caisse dédiée (CA-6). */
    private function creerSessionCaissePourEtablissement(string $idEtablissement): string
    {
        $em = $this->em();
        $etab = $em->getRepository(Etablissement::class)->find(Uuid::fromString($idEtablissement));
        self::assertInstanceOf(Etablissement::class, $etab);
        $admin = $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL]);

        $pdv = (new PointDeVente())->setLibelle('PDV test CQ1 cloisonnement')->setEtablissement($etab);
        $em->persist($pdv);
        $caisse = (new Caisse())->setLibelle('Caisse test CQ1 cloisonnement')->setPointDeVente($pdv)->setEtat(EtatCaisse::Securisee);
        $em->persist($caisse);
        $session = (new SessionCaisse())
            ->setNumero('S-CQ1B-' . substr(uniqid(), -8))
            ->setPointDeVente($pdv)
            ->setCaisse($caisse)
            ->setRegisseur($admin)
            ->setOperateur($admin)
            ->setFondDeCaisse('0.00')
            ->setEtablissement($etab);
        $em->persist($session);
        $em->flush();

        return (string) $session->getId();
    }
}
