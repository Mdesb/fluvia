<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\ProductAccessZone;
use App\Acces\Entity\Support;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Tests\Acces\AccesApiTestCase;
use App\Tests\Acces\SnapshotDeltaTrait;
use App\Vente\Entity\BilletSupport;
use Symfony\Component\Uid\Uuid;

/**
 * Une entrée unitaire vaut UNE entrée, pendant la durée du produit (décision de Maxime du 08/10).
 *
 * Seule la carte était décomptée. Le droit d'un billet n'avait ni décompte ni fenêtre : passé le
 * délai anti-retour, il rouvrait, ce jour-là et tous les suivants. La durée de validité du produit
 * n'était lue que par la comptabilité.
 *
 * Par défaut, le billet vaut le jour de la vente, au jour civil de l'établissement. Un produit peut
 * régler plusieurs entrées, ou « illimité dans la durée » (`entryCount: null`), et une durée en jours.
 *
 * Les passages sont horodatés à l'heure murale de l'établissement, aujourd'hui à 00:10 et 00:20 :
 * avant l'heure de la vente, mais dans son jour, et à l'abri d'un test lancé près de minuit.
 */
final class SingleEntryTicketTest extends AccesApiTestCase
{
    use SnapshotDeltaTrait;

    public function testSoldEntryPassesOnceThenIsRefusedAfterTheAntiPassbackDelay(): void
    {
        [$ticket] = $this->sellEntries();

        self::assertSame('valide', $this->pass($ticket, $this->wallClock(0, '00:10'))['resultat']);
        $second = $this->pass($ticket, $this->wallClock(0, '00:20'));
        self::assertSame('refuse', $second['resultat'], 'Dix minutes plus tard, au-delà du délai anti-retour, l\'entrée a déjà servi.');
        self::assertSame('deja_consomme', $second['codeMotif']);
    }

    public function testSoldEntryIsRefusedTheNextDay(): void
    {
        [$ticket] = $this->sellEntries();

        $lendemain = $this->pass($ticket, $this->wallClock(1, '10:00'));
        self::assertSame('refuse', $lendemain['resultat'], 'Une entrée sans durée vaut le jour de la vente.');
        self::assertSame('hors_marge', $lendemain['codeMotif']);
    }

    public function testUnlimitedEntryPassesTwiceWithinItsDayOnly(): void
    {
        $this->patchEntryProduct(['entryCount' => null]);
        [$ticket] = $this->sellEntries();

        self::assertSame('valide', $this->pass($ticket, $this->wallClock(0, '00:10'))['resultat']);
        self::assertSame('valide', $this->pass($ticket, $this->wallClock(0, '00:20'))['resultat'], 'Illimité dans la durée : l\'aller-retour passe.');
        self::assertSame('refuse', $this->pass($ticket, $this->wallClock(1, '10:00'))['resultat'], 'Illimité dans la durée, pas au-delà.');
    }

    /** Zéro entrée ferait un billet qui n'ouvre jamais : refusé à l'écriture. */
    public function testAProductCannotSellZeroEntry(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->request('PATCH', '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE), [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['entryCount' => 0],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /** Deux jours : le jour de la vente et le suivant, jusqu'à minuit à l'établissement. */
    public function testValidityInDaysCoversTheFollowingDayAndNoMore(): void
    {
        $this->patchEntryProduct(['dureeValidite' => 'P2D']);
        [$first, $second] = $this->sellEntries(2);

        self::assertSame('valide', $this->pass($first, $this->wallClock(1, '23:50'))['resultat']);
        self::assertSame('refuse', $this->pass($second, $this->wallClock(2, '00:10'))['resultat']);
    }

    /** La borne hors ligne reçoit l'entrée restante et la fin du jour, puis le décompte en delta (#266). */
    public function testOfflineTerminalSeesTheRemainingEntryAndTheEndOfDay(): void
    {
        [$ticket] = $this->sellEntries();

        $entree = $this->fullSnapshotEntry($ticket);
        self::assertSame(1, $entree['compostagesRestants'] ?? null);
        self::assertSame($this->wallClock(0, '23:59:59'), $entree['validiteFin'] ?? null);

        $curseur = $this->snapshotCursor();
        self::assertSame('valide', $this->pass($ticket, $this->wallClock(0, '00:10'))['resultat']);
        self::assertSame(0, $this->assertInDelta($curseur, $ticket, 'ValidationPassageHandler (entrée unitaire)')['compostagesRestants']);
    }

    /** Déclarer le billet perdu puis le ré-appairer ne lui rend pas son entrée. */
    public function testRePairingAUsedEntryDoesNotGiveItBack(): void
    {
        [$ticket] = $this->sellEntries();
        self::assertSame('valide', $this->pass($ticket, $this->wallClock(0, '00:10'))['resultat']);

        [$client, $entete] = $this->adminSurA();
        $support = $this->entite(Support::class, ['identifiant' => $ticket]);
        $appairage = $this->entite(Appairage::class, ['support' => $support, 'actif' => true]);
        $client->request('POST', '/api/acces/appairages/' . $appairage->getId() . '/revoquer', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/acces/appairages', $entete + ['json' => [
            'identifiantSupport' => $ticket, 'typeSupport' => 'QR', 'mode' => 'caisse',
            'billetSupportRef' => (string) $this->entite(BilletSupport::class, ['identifiantSupport' => $ticket])->getId(),
        ]]);
        self::assertResponseIsSuccessful();

        self::assertSame('refuse', $this->pass($ticket, $this->wallClock(0, '00:30'))['resultat']);
    }

    // ── Outils ───────────────────────────────────────────────────────────────────────────────────

    /** L'instant UTC d'une heure murale de l'établissement A, `$days` jours après aujourd'hui. */
    private function wallClock(int $days, string $time): string
    {
        $site = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $day = Etablissement::jourCivil($site)->modify(sprintf('+%d days', $days))->format('Y-m-d');

        return Etablissement::instantLocal($site, $day . ' ' . $time)->format(DATE_ATOM);
    }

    /** @return array<string, mixed> */
    private function pass(string $ticket, string $at): array
    {
        $reponse = static::createClient()->request('POST', '/api/terminal/passages', $this->terminalEntete() + ['json' => [
            'equipementId' => $this->idEquipement(),
            'identifiantSupport' => $ticket,
            'cleIdempotence' => (string) Uuid::v4(),
            'horodatageBorne' => $at,
        ]]);
        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent(false));

        return $reponse->toArray();
    }

    /** @param array<string, mixed> $fields */
    private function patchEntryProduct(array $fields): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->request('PATCH', '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE), [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => $fields,
        ]);
        self::assertResponseIsSuccessful();
    }

    /**
     * Vend `$quantity` entrées au guichet, pour un produit qui ouvre l'espace de la borne.
     *
     * @return list<string> les codes des billets
     */
    private function sellEntries(int $quantity = 1): array
    {
        $em = $this->snapshotEm();
        $produit = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_ENTREE]);
        $em->persist((new ProductAccessZone($produit->getId(), $this->entite(EspaceAcces::class, ['libelle' => AccesFixtures::ESPACE_LIBELLE])))
            ->setEstablishment($this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM])));
        $em->flush();

        [$client, $entete] = $this->adminSurA();
        $vente = $this->creerVente($client, $entete, $this->ouvrirSession($client, $entete)['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + ['json' => [
            'produit' => '/api/produits/' . $produit->getId(),
            'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
            'quantite' => $quantity,
        ]]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'cb']]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $em->clear();
        $codes = array_map(
            static fn (BilletSupport $s): string => (string) $s->getIdentifiantSupport(),
            $em->getRepository(BilletSupport::class)->findBy(['vente' => $vente['id']]),
        );
        self::assertCount($quantity, $codes);

        return $codes;
    }
}
