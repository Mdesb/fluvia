<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Entity\Vitrine;
use App\Crm\Entity\Client;
use App\Crm\Entity\Consentement;
use App\Crm\Enum\CanalConsentement;
use App\Crm\Enum\EtatConsentement;
use App\Crm\Enum\StatutClient;
use App\Crm\Enum\TypeClient;
use App\Crm\Notification\ConsentGatedNotifier;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Notification\ClientNotification;
use App\Platform\Notification\ClientNotifierInterface;
use App\Platform\Notification\NotificationBasis;
use App\Platform\Notification\NotificationChannel;
use App\Platform\Notification\NotificationOutcome;
use App\Tests\Boutique\BoutiqueApiTestCase;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261004001923;
use Psr\Log\NullLogger;

require_once \dirname(__DIR__, 3) . '/migrations/Version20261004001923.php';

/**
 * CA-4c (#101) : la reprise invalide EXACTEMENT les consentements nés de la case « gestion de
 * commande », et ne touche pas un accord marketing réel. On joue le SQL de la migration elle-même
 * (ses `INSERT`/`DELETE` ; le schéma du harnais porte déjà les colonnes, bâties depuis le mapping).
 *
 * Cinq clients, un seul visé :
 * - A : accord boutique + panier dont l'horodatage est, à la seconde, la date de recueil → invalidé ;
 * - B : accord boutique SANS tel panier (la case marketing d'aujourd'hui) → intact ;
 * - C : accord boutique + panier décalé d'une seconde → intact (le ciblage est exact) ;
 * - D : même panier que A, mais source `crm` → intact (la source compte) ;
 * - E : comme A, mais un accord plus récent a été donné ailleurs depuis → intact (on ne recouvre
 *   jamais une ligne plus récente : l'invalidation deviendrait l'état courant à sa place).
 */
final class ConsentCorrectionMigrationTest extends BoutiqueApiTestCase
{
    private const RECUEIL = '2026-09-20 19:25:22';

    public function testInvalidatesExactlyTheOrderCheckboxConsentsAndDownRestoresThem(): void
    {
        $em = $this->em();
        $a = $this->client('A');
        $b = $this->client('B');
        $c = $this->client('C');
        $d = $this->client('D');
        $e = $this->client('E');
        $recueil = new \DateTimeImmutable(self::RECUEIL);

        $this->accord($a, 'boutique', $recueil);
        $this->panier($a, $recueil);
        $this->accord($b, 'boutique', $recueil)->setTextVersion('marketing-2026-10-04');
        $this->accord($c, 'boutique', $recueil);
        $this->panier($c, $recueil->modify('+1 second'));
        $this->accord($d, 'crm', $recueil);
        $this->panier($d, $recueil);
        $this->accord($e, 'boutique', $recueil);
        $this->panier($e, $recueil);
        $this->accord($e, 'crm', $recueil->modify('+1 day'));
        $em->flush();

        // Témoin positif AVANT : les quatre sont joignables par campagne.
        foreach ([$a, $b, $c, $d, $e] as $client) {
            self::assertSame(NotificationOutcome::Journalisee, $this->campagne($client));
        }
        $total = $this->compter('SELECT COUNT(*) FROM crm_consentement');

        $this->jouer('up', 'INSERT');

        self::assertSame($total + 1, $this->compter('SELECT COUNT(*) FROM crm_consentement'), 'Une seule ligne ajoutée : la ligne d\'origine est conservée.');
        $em->clear();
        $invalide = $em->getRepository(Consentement::class)->findBy(['etat' => EtatConsentement::Invalide]);
        self::assertCount(1, $invalide);
        self::assertTrue($invalide[0]->getClient()?->getId()->equals($a->getId()));
        self::assertSame(Version20261004001923::REASON, $invalide[0]->getInvalidationReason());
        self::assertSame(Version20261004001923::BATCH, $invalide[0]->getInvalidationBatch());
        self::assertSame(CanalConsentement::Email, $invalide[0]->getCanal());

        // Le moteur de campagnes exclut `invalide` ; les trois autres restent joignables.
        self::assertSame(NotificationOutcome::Refusee, $this->campagne($a));
        self::assertSame(NotificationOutcome::Journalisee, $this->campagne($b));
        self::assertSame(NotificationOutcome::Journalisee, $this->campagne($c));
        self::assertSame(NotificationOutcome::Journalisee, $this->campagne($d));
        self::assertSame(NotificationOutcome::Journalisee, $this->campagne($e), 'Un accord donné plus tard ailleurs reste l\'état courant.');

        // Une case marketing cochée APRÈS la reprise rend le client joignable : la ligne la plus
        // récente l'emporte.
        $nouvelAccord = $this->accord($a, 'boutique', new \DateTimeImmutable('+1 minute'));
        $this->em()->flush();
        self::assertSame(NotificationOutcome::Journalisee, $this->campagne($a));
        // Retiré par SQL (append-only côté ORM) pour que down() se mesure sur le seul état de la reprise.
        $this->em()->getConnection()->executeStatement('DELETE FROM crm_consentement WHERE id = ?', [$nouvelAccord->getId()->toBinary()]);
        $this->em()->clear();

        $this->jouer('down', 'DELETE');

        self::assertSame($total, $this->compter('SELECT COUNT(*) FROM crm_consentement'));
        self::assertSame(0, $this->compter("SELECT COUNT(*) FROM crm_consentement WHERE etat = 'invalide'"));
        self::assertSame(NotificationOutcome::Journalisee, $this->campagne($a), 'down() rend l\'état « accordé » d\'origine.');
    }

    /** Les requêtes de la migration commençant par `$verbe`, exécutées telles quelles. */
    private function jouer(string $sens, string $verbe): void
    {
        $connexion = $this->em()->getConnection();
        $migration = new Version20261004001923($connexion, new NullLogger());
        $migration->{$sens}(new Schema());

        $jouees = 0;
        foreach ($migration->getSql() as $requete) {
            if (str_starts_with(ltrim($requete->getStatement()), $verbe)) {
                $connexion->executeStatement($requete->getStatement(), $requete->getParameters(), $requete->getTypes());
                ++$jouees;
            }
        }
        self::assertSame(1, $jouees, sprintf('Une requête %s attendue dans %s().', $verbe, $sens));
    }

    private function campagne(Client $client): NotificationOutcome
    {
        $decore = $this->createStub(ClientNotifierInterface::class);
        $decore->method('notify')->willReturn(NotificationOutcome::Journalisee);
        $this->em()->clear();

        return (new ConsentGatedNotifier($decore, $this->em()))->notify(new ClientNotification(
            clientId: $client->getId(),
            channel: NotificationChannel::Email,
            templateKey: 'marketing.campaign',
            variables: [],
            occurredAt: new \DateTimeImmutable(),
            source: 'test',
            basis: NotificationBasis::Consentement,
        ));
    }

    private function client(string $nom): Client
    {
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $client = (new Client())->setType(TypeClient::Physique)->setNom('Reprise ' . $nom)->setPrenom($nom)
            ->setEmail(strtolower($nom) . '.reprise@example.test')->setStatut(StatutClient::Actif)
            ->setEtablissementCreation($etabA)->setGroupe($etabA->getRegion()?->getGroupe());
        $this->em()->persist($client);

        return $client;
    }

    private function accord(Client $client, string $source, \DateTimeImmutable $recueil): Consentement
    {
        $consentement = (new Consentement(CanalConsentement::Email, EtatConsentement::Accorde))
            ->setClient($this->em()->getReference(Client::class, $client->getId()))->setSource($source)->setDateRecueil($recueil);
        $this->em()->persist($consentement);

        return $consentement;
    }

    private function panier(Client $client, \DateTimeImmutable $horodatage): void
    {
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $panier = (new PanierEnLigne())
            ->setVitrine($this->entite(Vitrine::class, ['etablissement' => $etabA]))
            ->setEtablissement($etabA)
            ->setClientResolu($client->getId())
            ->setConsentementRgpdHorodatage($horodatage);
        $this->em()->persist($panier);
    }

    private function compter(string $sql): int
    {
        return (int) $this->em()->getConnection()->fetchOne($sql);
    }
}
