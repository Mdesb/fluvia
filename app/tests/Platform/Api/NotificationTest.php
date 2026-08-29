<?php

declare(strict_types=1);

namespace App\Tests\Platform\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Entity\Notification;
use App\Platform\Enum\NotificationSeverity;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LE CENTRE DE NOTIFICATION — CE QUE LA CLOCHE A LE DROIT DE MONTRER.
 *
 * Demandé par Maxime ; la ressource est ici, l'écran chez allaccess-8e.
 *
 * ── CE QUE CE FICHIER TIENT, ET DANS QUEL ORDRE D'IMPORTANCE ────────────────────────────────────
 *
 * 1. **La confidentialité.** Les notifications d'un collègue ne me regardent pas, même sur un site
 *    où j'ai tous les droits. C'est la seule propriété dont l'échec serait une fuite.
 * 2. **L'établissement actif.** Une notification d'un site que je ne consulte pas ne doit pas sonner.
 * 3. **Le tri.** Sans lui, la cloche montrerait les plus ANCIENNES — des lignes vraies, dans un
 *    ordre qui les rend inutiles, et rien ne le signale.
 * 4. **L'écriture en masse.** « Tout marquer comme lu » est une requête écrite à la main : aucune
 *    extension Doctrine ne la traverse. Une restriction oubliée là ne montrerait pas des lignes en
 *    trop, elle en MODIFIERAIT.
 *
 * ⚠ CHAQUE CAS POSE UN TÉMOIN AVANT D'AFFIRMER UNE ABSENCE. « Je ne vois pas la notification du
 * collègue » est vrai d'une liste vide, et une liste vide est exactement ce que rendrait un
 * cloisonnement trop large ou un filtre cassé. On exige donc toujours de voir la sienne dans le même
 * appel.
 */
final class NotificationTest extends SocleApiTestCase
{
    public function testJeNeVoisQueMesNotifications(): void
    {
        $client = static::createClient();
        $moi = $this->utilisateur(SocleFixtures::ADMIN_EMAIL);
        $collegue = $this->utilisateur(SocleFixtures::LECTEUR_EMAIL);
        $etabA = $this->etablissement(SocleFixtures::ETAB_A_NOM);

        $mienne = $this->poser($moi, $etabA, 'La facture 2026-014 est en retard');
        $sienne = $this->poser($collegue, $etabA, 'Un devis a expiré');

        $ids = $this->lister($client, SocleFixtures::ADMIN_EMAIL, $etabA);

        // ── LE TÉMOIN ───────────────────────────────────────────────────────────────────────────
        // Sans lui, « je ne vois pas celle du collègue » serait aussi vrai d'une liste vide — c'est-
        // à-dire du cas où le cloisonnement est cassé dans l'autre sens.
        self::assertContains($mienne, $ids, 'Témoin absent : je dois voir la mienne pour que le reste veuille dire quelque chose.');
        self::assertNotContains($sienne, $ids, 'Les notifications d’un collègue ne se voient pas, même avec tous les droits sur le site.');
    }

    public function testUneNotificationDUnAutreSiteNeSonnePas(): void
    {
        $client = static::createClient();
        $moi = $this->utilisateur(SocleFixtures::ADMIN_EMAIL);
        $etabA = $this->etablissement(SocleFixtures::ETAB_A_NOM);
        $etabB = $this->etablissement(SocleFixtures::ETAB_B_NOM);

        $surA = $this->poser($moi, $etabA, 'Impayé sur la piscine');
        $surB = $this->poser($moi, $etabB, 'Impayé sur la patinoire');

        $ids = $this->lister($client, SocleFixtures::ADMIN_EMAIL, $etabA);

        self::assertContains($surA, $ids);
        self::assertNotContains($surB, $ids, 'Le périmètre dit ce que j’ai le droit de voir ; l’actif dit ce que je regarde.');
    }

    public function testLaClocheMontreLesPlusRecentesDAbord(): void
    {
        $client = static::createClient();
        $moi = $this->utilisateur(SocleFixtures::ADMIN_EMAIL);
        $etabA = $this->etablissement(SocleFixtures::ETAB_A_NOM);

        $vieille = $this->poser($moi, $etabA, 'La plus ancienne', new \DateTimeImmutable('2026-01-01 08:00:00'));
        $recente = $this->poser($moi, $etabA, 'La plus récente', new \DateTimeImmutable('2026-08-30 08:00:00'));

        $ids = $this->lister($client, SocleFixtures::ADMIN_EMAIL, $etabA, ['order' => ['horodatage' => 'desc']]);

        $positionRecente = array_search($recente, $ids, true);
        $positionVieille = array_search($vieille, $ids, true);

        self::assertIsInt($positionRecente);
        self::assertIsInt($positionVieille);
        self::assertLessThan(
            $positionVieille,
            $positionRecente,
            'Sans tri déclaré, la cloche montrerait les plus anciennes : des lignes vraies dans un ordre qui les rend inutiles.',
        );
    }

    public function testMarquerLueEstIdempotent(): void
    {
        $client = static::createClient();
        $moi = $this->utilisateur(SocleFixtures::ADMIN_EMAIL);
        $etabA = $this->etablissement(SocleFixtures::ETAB_A_NOM);
        $id = $this->poser($moi, $etabA, 'À lire une fois');

        $entete = $this->entete($client, SocleFixtures::ADMIN_EMAIL, $etabA);

        $premier = $client->request('POST', '/api/notifications/'.$id.'/lue', $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertTrue($premier['lue']);
        $luLe = $premier['luLe'];
        self::assertNotNull($luLe, 'Une notification lue porte la date de sa lecture.');

        // ⚠ La cloche renvoie parfois deux fois le même clic. La date de PREMIÈRE lecture ne doit pas
        // bouger : « lu à 14h02 » puis « lu à 14h02 et 3 secondes » n'apprend rien et fait écrire la
        // base pour rien.
        $second = $client->request('POST', '/api/notifications/'.$id.'/lue', $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame($luLe, $second['luLe'], 'La date de première lecture ne se réécrit pas.');
    }

    public function testLaNotificationDUnAutreRendQuatreCentQuatre(): void
    {
        $client = static::createClient();
        $collegue = $this->utilisateur(SocleFixtures::LECTEUR_EMAIL);
        $etabA = $this->etablissement(SocleFixtures::ETAB_A_NOM);
        $sienne = $this->poser($collegue, $etabA, 'Pas pour moi');

        $entete = $this->entete($client, SocleFixtures::ADMIN_EMAIL, $etabA);

        // 404 et non 403 : répondre « interdit » confirmerait qu'elle existe. Pour cet appelant, elle
        // n'existe pas.
        $client->request('POST', '/api/notifications/'.$sienne.'/lue', $entete);
        self::assertResponseStatusCodeSame(404);
    }

    public function testToutMarquerCommeLuNeTouchePasAuxAutres(): void
    {
        $client = static::createClient();
        $moi = $this->utilisateur(SocleFixtures::ADMIN_EMAIL);
        $collegue = $this->utilisateur(SocleFixtures::LECTEUR_EMAIL);
        $etabA = $this->etablissement(SocleFixtures::ETAB_A_NOM);
        $etabB = $this->etablissement(SocleFixtures::ETAB_B_NOM);

        $mienneSurA = $this->poser($moi, $etabA, 'La mienne, ici');
        $mienneSurB = $this->poser($moi, $etabB, 'La mienne, ailleurs');
        $sienneSurA = $this->poser($collegue, $etabA, 'Celle du collègue');

        $entete = $this->entete($client, SocleFixtures::ADMIN_EMAIL, $etabA);
        $client->request('POST', '/api/notifications/tout-lu', $entete);
        self::assertResponseIsSuccessful();

        // ⚠ CETTE REQUÊTE EST ÉCRITE À LA MAIN : aucune extension Doctrine ne la traverse. On relit
        // donc en base, pas par l'API — l'API rejouerait le cloisonnement et masquerait précisément
        // le débordement qu'on cherche.
        self::assertTrue($this->estLue($mienneSurA), 'La mienne sur le site actif doit être marquée.');
        self::assertFalse($this->estLue($mienneSurB), 'Ce geste vide ce que la cloche MONTRE, pas les autres sites.');
        self::assertFalse($this->estLue($sienneSurA), 'Marquer mes notifications ne touche jamais celles d’un autre.');
    }

    // ── Aides ───────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function entete(object $client, string $email, Etablissement $etablissement): array
    {
        return [
            'auth_bearer' => $this->jeton($client, $email, SocleFixtures::ADMIN_MDP),
            'headers' => [ContexteEtablissement::HEADER => (string) $etablissement->getId()],
        ];
    }

    /**
     * @param array<string, mixed> $requete
     *
     * @return list<string>
     */
    private function lister(object $client, string $email, Etablissement $etablissement, array $requete = []): array
    {
        $reponse = $client->request(
            'GET',
            '/api/notifications',
            $this->entete($client, $email, $etablissement) + ['query' => $requete + ['itemsPerPage' => 100]],
        )->toArray();

        $membres = $reponse['member'] ?? $reponse['hydra:member'] ?? [];

        return array_map(static fn (array $n): string => $n['id'], $membres);
    }

    private function poser(
        Utilisateur $destinataire,
        Etablissement $etablissement,
        string $titre,
        ?\DateTimeImmutable $quand = null,
    ): string {
        $em = $this->em();

        $notification = (new Notification())
            ->setDestinataire($destinataire)
            ->setEtablissement($etablissement)
            ->setTitre($titre)
            ->setTexte($titre.' — ouvrez l’écran pour agir.')
            ->setEcran('recouvrement')
            ->setParams(['impaye' => '00000000-0000-4000-8000-000000000001'])
            ->setSource('invoice.overdue')
            ->setGravite(NotificationSeverity::Warning);

        if ($quand !== null) {
            $notification->setHorodatage($quand);
        }

        $em->persist($notification);
        $em->flush();

        return (string) $notification->getId();
    }

    private function estLue(string $id): bool
    {
        $em = $this->em();
        $em->clear();

        $notification = $em->getRepository(Notification::class)->find($id);
        self::assertInstanceOf(Notification::class, $notification);

        return $notification->isLue();
    }

    private function utilisateur(string $email): Utilisateur
    {
        $utilisateur = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(Utilisateur::class, $utilisateur, 'compte de fixture introuvable : '.$email);

        return $utilisateur;
    }

    private function etablissement(string $nom): Etablissement
    {
        $etablissement = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => $nom]);
        self::assertInstanceOf(Etablissement::class, $etablissement, 'établissement de fixture introuvable : '.$nom);

        return $etablissement;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
