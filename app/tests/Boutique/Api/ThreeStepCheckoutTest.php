<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Entity\CompteClient;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Entity\RetraitClickCollect;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Crm\Entity\Consentement;
use App\Crm\Enum\CanalConsentement;
use App\Crm\Enum\EtatConsentement;
use App\DataFixtures\SocleFixtures;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Securite\Entity\Utilisateur;
use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Vente\Entity\BilletSupport;
use App\Vente\Entity\Vente;

/**
 * Tunnel d'achat en 3 étapes (#101, spec-tunnel-3-etapes.md §5) — dans l'ORDRE RÉEL des appels de
 * l'écran 1 : `identifier` → `beneficiaires` → `consentement`, puis `payer` et le retour du
 * prestataire (bouchon).
 */
final class ThreeStepCheckoutTest extends BoutiqueApiTestCase
{
    private const MENTION = 'mention-2026-10-04';
    private const MARKETING = 'marketing-2026-10-04';

    /** CA-2 : sans compte, pas d'achat sans e-mail valide (422). Témoin : une adresse valide passe. */
    public function testGuestNeedsAValidEmail(): void
    {
        [$client, $panierId, $entete] = $this->panierAvecUnBillet(BoutiqueFixtures::PRODUIT_SIMPLE_CODE);

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', ['headers' => $entete, 'json' => ['mode' => 'invite']]);
        self::assertResponseStatusCodeSame(422, 'Sans e-mail : refusé.');
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', ['headers' => $entete, 'json' => ['mode' => 'invite', 'email' => 'pas-une-adresse']]);
        self::assertResponseStatusCodeSame(422, 'E-mail invalide : refusé.');
        // Un corps sans `mode` retombe sur l'invité : même exigence.
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', ['headers' => $entete, 'json' => []]);
        self::assertResponseStatusCodeSame(422, 'Mode par défaut (invité) sans e-mail : refusé.');

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', ['headers' => $entete, 'json' => ['mode' => 'invite', 'email' => 'acheteur@example.test']]);
        self::assertResponseIsSuccessful();
    }

    /**
     * CA-3, sens « non coché » : une commande payée ne crée AUCUN consentement. CA-5 : la mention et
     * sa version sont horodatées sur le panier.
     */
    public function testOrderWithoutMarketingCreatesNoConsent(): void
    {
        $avant = $this->nombreConsentements();

        [$client, $panierId, $entete] = $this->panierAvecUnBillet(BoutiqueFixtures::PRODUIT_SIMPLE_CODE);
        $this->ecranVosBillets($client, $panierId, $entete, ['dateNaissance' => '1990-01-01'], ['marketing' => false]);
        $venteId = $this->payer($client, $panierId, $entete);

        self::assertNotSame('', $venteId);
        self::assertSame($avant, $this->nombreConsentements(), 'Aucun Consentement ne doit naître d\'une commande sans la case marketing.');

        $panier = $this->em()->getRepository(PanierEnLigne::class)->find($panierId);
        self::assertInstanceOf(PanierEnLigne::class, $panier);
        self::assertSame(self::MENTION, $panier->getPrivacyNoticeVersion(), 'CA-5 : version de la mention gardée.');
        self::assertNotNull($panier->getPrivacyNoticeShownAt(), 'CA-5 : affichage de la mention horodaté.');
        self::assertNull($panier->getConsentementRgpdHorodatage(), 'La colonne historique n\'est plus écrite.');
    }

    /** CA-3, sens « coché » : un et un seul accord e-mail, source boutique, avec la version du texte. */
    public function testMarketingCheckedCreatesOneConsentWithItsTextVersion(): void
    {
        $avant = $this->nombreConsentements();

        [$client, $panierId, $entete] = $this->panierAvecUnBillet(BoutiqueFixtures::PRODUIT_SIMPLE_CODE);
        $this->ecranVosBillets($client, $panierId, $entete, ['dateNaissance' => '1990-01-01'], ['marketing' => true, 'marketingVersion' => self::MARKETING]);
        $this->payer($client, $panierId, $entete);

        self::assertSame($avant + 1, $this->nombreConsentements());
        $panier = $this->em()->getRepository(PanierEnLigne::class)->find($panierId);
        $consentements = $this->em()->getRepository(Consentement::class)->findBy(['source' => 'boutique']);
        $duClient = array_values(array_filter($consentements, static fn (Consentement $c): bool => $c->getClient()?->getId()->equals($panier->getClientResolu()) ?? false));
        self::assertCount(1, $duClient);
        self::assertSame(CanalConsentement::Email, $duClient[0]->getCanal());
        self::assertSame(EtatConsentement::Accorde, $duClient[0]->getEtat());
        self::assertSame(self::MARKETING, $duClient[0]->getTextVersion());
    }

    /** Une case marketing cochée sans la version de son texte ne s'enregistre pas ; la mention non plus. */
    public function testConsentCallRequiresTheTextVersions(): void
    {
        [$client, $panierId, $entete] = $this->panierAvecUnBillet(BoutiqueFixtures::PRODUIT_SIMPLE_CODE);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', ['headers' => $entete, 'json' => ['rgpd' => true]]);
        self::assertResponseStatusCodeSame(422, 'L\'ancien corps (case « rgpd ») ne vaut plus mention.');
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', ['headers' => $entete, 'json' => ['mentionVersion' => self::MENTION, 'marketing' => true]]);
        self::assertResponseStatusCodeSame(422, 'Marketing coché sans version du texte : refusé.');

        // Et le paiement reste fermé tant que la mention n'est pas enregistrée.
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', ['headers' => $entete, 'json' => ['mode' => 'invite', 'email' => 'mention@example.test']]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete]);
        self::assertResponseStatusCodeSame(422);
    }

    /** CA-4 : produit qui exige l'autorisation — mineur sans case → 422 ; renvoi avec la case → accepté. */
    public function testMinorOnAProductThatRequiresParentalConsent(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);
        $produit->setParentalConsentRequired(true);
        $this->em()->flush();

        [$client, $panierId, $entete, $ligneId] = $this->panierAvecUnBillet(BoutiqueFixtures::PRODUIT_SIMPLE_CODE);
        $mineur = ['dateNaissance' => (new \DateTimeImmutable('-10 years'))->format('Y-m-d')];

        $this->ecranVosBillets($client, $panierId, $entete, $mineur, ['marketing' => false]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete]);
        self::assertResponseStatusCodeSame(422, 'Mineur sans autorisation sur un produit qui l\'exige : refusé.');

        // L'écran renvoie l'ensemble (spec §4), cette fois avec la case cochée.
        $this->ecranVosBillets($client, $panierId, $entete, $mineur, ['marketing' => false, 'autorisationsParentales' => [$ligneId => true]]);
        self::assertNotSame('', $this->payer($client, $panierId, $entete));
    }

    /** CA-4 : produit qui ne l'exige pas — un mineur passe, sans case et sans autorisation. */
    public function testMinorOnAProductThatDoesNotRequireIt(): void
    {
        [$client, $panierId, $entete] = $this->panierAvecUnBillet(BoutiqueFixtures::PRODUIT_SIMPLE_CODE);
        $panier = $this->ecranVosBillets($client, $panierId, $entete, ['dateNaissance' => (new \DateTimeImmutable('-10 years'))->format('Y-m-d')], ['marketing' => false]);

        self::assertFalse($panier['lignes'][0]['autorisationParentaleRequise'] ?? null, 'Aucune case demandée.');
        self::assertNotSame('', $this->payer($client, $panierId, $entete));
    }

    /**
     * L'autorisation donnée pour un enfant ne reste pas acquise à la ligne quand on y met un autre
     * enfant : le renvoi des bénéficiaires la remet à zéro, et sans la case, le paiement est refusé.
     */
    public function testParentalConsentDoesNotSurviveAChangeOfBeneficiary(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);
        $produit->setParentalConsentRequired(true);
        $this->em()->flush();

        [$client, $panierId, $entete, $ligneId] = $this->panierAvecUnBillet(BoutiqueFixtures::PRODUIT_SIMPLE_CODE);
        $mineur = ['dateNaissance' => (new \DateTimeImmutable('-10 years'))->format('Y-m-d')];
        $this->ecranVosBillets($client, $panierId, $entete, $mineur, ['marketing' => false, 'autorisationsParentales' => [$ligneId => true]]);
        $this->ecranVosBillets($client, $panierId, $entete, $mineur + ['prenom' => 'Autre'], ['marketing' => false]);

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * CA-1 : un client connecté ne saisit ni e-mail ni mot de passe, et sa commande apparaît dans
     * « Mes billets ».
     */
    public function testConnectedCustomerBuysWithoutTypingAndFindsItInMyTickets(): void
    {
        [$client, $panierId, $entete] = $this->panierAvecUnBillet(BoutiqueFixtures::PRODUIT_SIMPLE_CODE);
        $jwt = $this->jeton($client, BoutiqueFixtures::CLIENT_EMAIL, BoutiqueFixtures::CLIENT_MDP);

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', ['auth_bearer' => $jwt, 'headers' => $entete, 'json' => ['mode' => 'session']]);
        self::assertResponseIsSuccessful();
        $this->ecranVosBillets($client, $panierId, $entete, ['dateNaissance' => '1990-01-01'], ['marketing' => false], identifier: false);
        $venteId = $this->payer($client, $panierId, $entete);

        $billets = $client->request('GET', '/api/boutique/comptes/me/billets', ['auth_bearer' => $jwt])->toArray()['billets'];
        self::assertResponseIsSuccessful();
        self::assertContains($venteId, array_column($billets, 'vente'), 'La commande du client connecté est dans « Mes billets ».');
    }

    /** CA-4b : un client connecté d'un AUTRE groupe ne peut pas rattacher le panier (404). */
    public function testConnectedCustomerFromAnotherGroupGets404(): void
    {
        $groupe = (new Groupe())->setNom('Groupe étranger');
        $region = (new Region())->setNom('Région étrangère')->setGroupe($groupe);
        $etabB = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);
        $etabB->setRegion($region);
        $utilisateur = $this->entite(Utilisateur::class, ['email' => BoutiqueFixtures::CLIENT_EMAIL]);
        $this->entite(CompteClient::class, ['utilisateur' => $utilisateur])->setEtablissement($etabB);
        $this->em()->persist($groupe);
        $this->em()->persist($region);
        $this->em()->flush();

        [$client, $panierId, $entete] = $this->panierAvecUnBillet(BoutiqueFixtures::PRODUIT_SIMPLE_CODE);
        $jwt = $this->jeton($client, BoutiqueFixtures::CLIENT_EMAIL, BoutiqueFixtures::CLIENT_MDP);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', ['auth_bearer' => $jwt, 'headers' => $entete, 'json' => ['mode' => 'session']]);
        self::assertResponseStatusCodeSame(404);

        $this->em()->clear();
        self::assertNull($this->em()->getRepository(PanierEnLigne::class)->find($panierId)?->getCompteClient(), 'Le panier n\'a pas été rattaché.');
    }

    /** Mode `session` sans être connecté : 401, rien n'est rattaché. */
    public function testSessionModeWithoutLoginIsRefused(): void
    {
        [$client, $panierId, $entete] = $this->panierAvecUnBillet(BoutiqueFixtures::PRODUIT_SIMPLE_CODE);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', ['headers' => $entete, 'json' => ['mode' => 'session']]);
        self::assertResponseStatusCodeSame(401);
    }

    /** CA-6 : le code de retrait click & collect est rendu avec les billets de la confirmation. */
    public function testClickAndCollectCodeIsOnTheConfirmation(): void
    {
        [$client, $panierId, $entete] = $this->panierAvecUnBillet(BoutiqueFixtures::PRODUIT_SUPPORT_PHYSIQUE_CODE);
        $this->ecranVosBillets($client, $panierId, $entete, [], ['marketing' => false]);
        $venteId = $this->payer($client, $panierId, $entete);

        $billets = $client->request('GET', '/api/boutique/paniers/' . $panierId . '/billets', ['headers' => $entete])->toArray()['billets'];
        self::assertResponseIsSuccessful();

        $support = $this->em()->getRepository(BilletSupport::class)->findOneBy(['vente' => $this->em()->getRepository(Vente::class)->find($venteId)]);
        $retrait = $this->em()->getRepository(RetraitClickCollect::class)->findOneBy(['billetSupport' => $support]);
        self::assertInstanceOf(RetraitClickCollect::class, $retrait);
        self::assertNotSame('', $retrait->getCodeRetrait());
        self::assertSame($retrait->getCodeRetrait(), $billets[0]['codeRetrait'] ?? null);
    }

    /** Sans politique publiée, la vitrine sert une politique type à son nom (décision CP-1). */
    public function testDefaultPrivacyPolicyWhenNonePublished(): void
    {
        $client = static::createClient();
        $documents = $client->request('GET', '/api/legal/publics/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM))->toArray()['documents'];
        self::assertResponseIsSuccessful();

        $politiques = array_values(array_filter($documents, static fn (array $d): bool => $d['slug'] === 'confidentialite'));
        self::assertCount(1, $politiques);
        self::assertTrue($politiques[0]['parDefaut']);
        self::assertStringContainsString(SocleFixtures::ETAB_A_NOM, $politiques[0]['contenu']);
    }

    /** @return array{0: Client, 1: string, 2: array<string, string>, 3: string} */
    private function panierAvecUnBillet(string $codeProduit): array
    {
        $produit = $this->entite(Produit::class, ['code' => $codeProduit]);
        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $panier = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => $entete,
            'json' => ['produit' => (string) $produit->getId(), 'quantite' => 1],
        ])->toArray();
        self::assertResponseIsSuccessful();

        return [$client, $panierId, $entete, (string) $panier['lignes'][0]['id']];
    }

    /**
     * L'écran « Vos billets » : les trois appels dans l'ordre de l'écran.
     *
     * @param array<string, string> $beneficiaire
     * @param array<string, mixed>  $consentement
     * @param array<string, string> $entete
     *
     * @return array<string, mixed> le panier rendu par le dernier appel
     */
    private function ecranVosBillets(Client $client, string $panierId, array $entete, array $beneficiaire, array $consentement, bool $identifier = true): array
    {
        if ($identifier) {
            $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', ['headers' => $entete, 'json' => ['mode' => 'invite', 'email' => 'trois.etapes@example.test']]);
            self::assertResponseIsSuccessful();
        }

        $panier = $client->request('GET', '/api/boutique/paniers/' . $panierId, ['headers' => $entete])->toArray();
        $ligneId = (string) $panier['lignes'][0]['id'];
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/beneficiaires', [
            'headers' => $entete,
            'json' => ['lignes' => [['ligneId' => $ligneId, 'beneficiaireSimple' => $beneficiaire + ['nom' => 'Martin', 'prenom' => 'Lou']]]],
        ]);
        self::assertResponseIsSuccessful();

        $reponse = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', [
            'headers' => $entete,
            'json' => ['mentionVersion' => self::MENTION] + $consentement,
        ]);
        self::assertResponseIsSuccessful();

        return $reponse->toArray();
    }

    /** @param array<string, string> $entete */
    private function payer(Client $client, string $panierId, array $entete): string
    {
        $paiement = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete])->toArray();
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => ['referenceTransaction' => $paiement['referenceTransaction'], 'recu' => $paiement['simulation']['accepte']],
        ]);
        self::assertResponseIsSuccessful();
        $retour = $client->getResponse()->toArray();
        self::assertSame('confirme', $retour['statut']);
        $this->em()->clear();

        return (string) $retour['vente'];
    }

    private function nombreConsentements(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM crm_consentement');
    }
}
