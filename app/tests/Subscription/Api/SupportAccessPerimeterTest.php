<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Securite\Entity\Utilisateur;
use App\Subscription\Entity\SupportAccess;
use App\Subscription\Service\SupportAccessGuard;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * UN ACCÈS D'ASSISTANCE REND-IL L'ÉTABLISSEMENT ATTEIGNABLE ? — la moitié qui manquait.
 *
 * ── CE QUE CE FICHIER TESTE, ET QU'AUCUN AUTRE NE TESTAIT ─────────────────────────────────────
 *
 * `SupportAccessGuardTest` prouve que l'accès accorde des DROITS. Ce fichier prouve qu'il accorde
 * la VISIBILITÉ, et ce sont deux questions : un agent peut avoir tous les droits sur un
 * établissement qui n'apparaît dans aucune liste. Le sélecteur ne le propose pas, et la seule façon
 * d'y entrer est de connaître son identifiant et de forger l'en-tête `X-Etablissement` à la main.
 *
 * ⚠ ET LA CONSÉQUENCE N'EST PAS « UNE GÊNE » : sans chemin praticable, la seule manière de dépanner
 * un client redevient de poser une **affectation permanente** sur son établissement — invisible,
 * indistinguable d'une affectation normale, que personne ne pensera à retirer. C'est exactement ce
 * que RG-ED-07 interdit, obtenu par la porte de service. Une règle sans chemin praticable ne tient
 * pas.
 *
 * ── ⚠ CE QUE CE FICHIER ATTRAPE EN PARTICULIER : LA CLAUSE `IN` QUI NE MATCHE RIEN ────────────
 *
 * `org_etablissement.id` est un `BINARY(16)`. Une clause `IN` alimentée d'objets `Uuid` part en
 * chaînes RFC 4122 sous l'inférence de Doctrine et ne correspond à **rien** — sans erreur, sans
 * avertissement, sans requête invalide. La liste rendue serait exactement celle d'avant, l'accès
 * d'assistance n'ouvrirait rien, et le défaut se lirait comme un cloisonnement qui fonctionne.
 *
 * C'est la raison d'être de ce fichier plus que ses assertions : **le seul témoin d'une conversion
 * binaire est une ligne qui apparaît**. Aucune relecture ne distingue les deux cas.
 *
 * ── LES TÉMOINS NÉGATIFS SONT LA MOITIÉ QUI COMPTE ────────────────────────────────────────────
 *
 * Chaque test commence par constater l'absence. Sans cela, une extension qui aurait cessé de
 * cloisonner — la panne la plus grave possible à cet endroit — rendrait tous ces tests verts.
 *
 * ── ⚠ TOUT EST REPRIS PAR IDENTIFIANT, ET CE N'EST PAS UNE COQUETTERIE ────────────────────────
 *
 * Symfony réinitialise ses services entre deux requêtes du client de test, et cela vide
 * l'`EntityManager`. Toute entité tenue à travers un appel HTTP devient **détachée** — et une
 * entité détachée qu'on modifie puis `flush()` ne lève rien du tout : la requête part sans le
 * moindre UPDATE, et le test échoue sur une assertion qui accuse le code de production.
 *
 * La première écriture de ce fichier est tombée exactement là : `revoke()` s'appliquait à un objet
 * détaché, ne révoquait rien, et l'établissement restait visible. On aurait pu conclure « la
 * révocation ne ferme pas l'accès » — un défaut inventé, dans un code juste. **Chaque helper
 * reprend donc son entité fraîche par son identifiant, juste avant de s'en servir.**
 */
final class SupportAccessPerimeterTest extends SocleApiTestCase
{
    private const CUSTOMER_NAME = 'Camping du Gué';

    /** Le client voisin : créé, jamais accordé. C'est lui le témoin, voir le premier test. */
    private const OTHER_CUSTOMER_NAME = 'Camping des Peupliers';

    /**
     * L'accès ouvert fait apparaître l'établissement du client, et lui seul.
     *
     * ⚠ La dernière assertion est le garde-fou du garde-fou : l'établissement B, où l'agent n'a ni
     * affectation ni accès, doit rester invisible. Sans elle, on ne saurait pas si la deuxième
     * prouve l'accès ou l'effondrement du cloisonnement.
     */
    public function testAGrantedAccessMakesTheCustomerVisible(): void
    {
        $http = static::createClient();
        $token = $this->jeton($http, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);

        $customerId = $this->createCustomer();
        $this->createCustomer(self::OTHER_CUSTOMER_NAME);

        self::assertNotContains(
            self::CUSTOMER_NAME,
            $this->visibleNames($http, $token),
            'témoin : sans accès d’assistance, l’établissement du client ne doit pas être listé. '
            .'S’il l’est déjà, la suite ne prouve rien.',
        );

        $this->grant($customerId);

        $names = $this->visibleNames($http, $token);

        self::assertContains(
            self::CUSTOMER_NAME,
            $names,
            'Un accès d’assistance ouvert ne rend pas l’établissement atteignable : l’agent a les '
            .'droits sur un établissement qu’aucune liste ne nomme. Cause la plus probable — la '
            .'clause IN part en chaînes RFC 4122 contre une colonne BINARY(16) et ne matche rien, '
            .'sans la moindre erreur.',
        );
        self::assertContains(SocleFixtures::ETAB_A_NOM, $names, 'L’affectation ordinaire doit continuer de valoir.');

        // ⚠ LE GARDE-FOU DU GARDE-FOU, ET IL A FALLU DEUX ESSAIS POUR LE POSER AU BON ENDROIT.
        //
        // Il visait d'abord « Patinoire B », l'établissement B des fixtures. Les fixtures disent en
        // toutes lettres « admin sur A et B » : l'assertion accusait le cloisonnement de s'être
        // effondré là où elle constatait une affectation légitime.
        //
        // Prendre le lecteur, affecté à A seulement, l'aurait rendue verte — et elle n'aurait
        // toujours rien prouvé de ce qu'elle prétend. Le cas qui compte n'est pas un établissement
        // des fixtures : c'est un SECOND CLIENT, créé ici, dont on n'a PAS ouvert l'accès. Lui seul
        // distingue « la clause nomme l'établissement accordé » de « la clause ouvre tout ce qui
        // existe » — la panne la plus grave possible à cet endroit, et celle qui rendrait toutes
        // les assertions précédentes vertes.
        self::assertNotContains(
            self::OTHER_CUSTOMER_NAME,
            $names,
            'Un second client, pour lequel aucun accès n’a été ouvert, apparaît dans la liste : le '
            .'périmètre d’assistance ne nomme pas l’établissement accordé, il ouvre tout.',
        );
    }

    /** L'accès révoqué reprend la visibilité qu'il avait donnée — sinon la révocation ne révoque rien. */
    public function testARevokedAccessTakesTheCustomerBack(): void
    {
        $http = static::createClient();
        $token = $this->jeton($http, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);

        $customerId = $this->createCustomer();
        $accessId = $this->grant($customerId);

        self::assertContains(
            self::CUSTOMER_NAME,
            $this->visibleNames($http, $token),
            'témoin : l’accès doit d’abord valoir, sinon révoquer ne prouve rien.',
        );

        $this->revoke($accessId);

        self::assertNotContains(
            self::CUSTOMER_NAME,
            $this->visibleNames($http, $token),
            'L’établissement reste listé après révocation : l’accès survit à sa fermeture.',
        );
    }

    /**
     * Un accès dont la fenêtre est passée n'ouvre rien — et c'est le cas qui compte le plus.
     *
     * ⚠ La révocation est un geste : quelqu'un l'a fait, et s'en souvient. L'expiration ne l'est
     * pas — personne n'agit, et un accès qui survivrait à son terme resterait ouvert sans que le
     * moindre événement le signale. C'est la façon dont un accès temporaire devient permanent.
     */
    public function testAnExpiredAccessOpensNothing(): void
    {
        $http = static::createClient();
        $token = $this->jeton($http, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);

        $customerId = $this->createCustomer();

        $now = new \DateTimeImmutable();
        $this->guard()->grant(
            $this->agent(),
            $this->customer($customerId),
            'Ticket 4711 — terminé depuis hier',
            $now->modify('-2 days'),
            $now->modify('-1 day'),
            'chef@editeur.test',
        );

        $names = $this->visibleNames($http, $token);

        // ⚠ LE TÉMOIN DE NON-VACUITÉ, ET C'EST UN GARDE-FOU DU DÉPÔT QUI L'A VU, PAS MOI.
        //
        // Sans lui, une liste vide — pour n'importe quelle raison étrangère au sujet : jeton
        // refusé, requête en erreur, cloisonnement effondré — rendait ce test vert. Une assertion
        // de non-appartenance ne prouve quelque chose que si l'on sait que la liste existe.
        self::assertContains(
            SocleFixtures::ETAB_A_NOM,
            $names,
            'témoin : la liste doit contenir l’établissement de l’agent. Vide, elle rendrait '
            .'l’assertion suivante vraie sans rien prouver.',
        );
        self::assertNotContains(
            self::CUSTOMER_NAME,
            $names,
            'Un accès expiré rend encore l’établissement visible : le temps ne ferme rien, et un '
            .'accès temporaire devient permanent sans que personne ne s’en aperçoive.',
        );
    }

    // ── Outillage ────────────────────────────────────────────────────────────────────────────────

    /**
     * Les noms des établissements que l'agent voit réellement, tels que l'API les rend.
     *
     * ⚠ ON PASSE PAR HTTP, ET NON PAR LE `QueryBuilder`. Ce qu'on veut savoir est ce que le
     * SÉLECTEUR proposera ; interroger l'extension directement sauterait l'inférence de type de
     * Doctrine, c'est-à-dire précisément l'endroit où le défaut se produit.
     *
     * @return list<string>
     */
    private function visibleNames(Client $http, string $token): array
    {
        $response = $http->request('GET', '/api/etablissements', ['auth_bearer' => $token]);
        self::assertResponseIsSuccessful();

        $body = $response->toArray();
        /** @var list<array{nom: string}> $members */
        $members = $body['member'] ?? $body['hydra:member'];

        return array_map(static fn (array $one): string => $one['nom'], $members);
    }

    /** Un établissement client, dans son propre arbre — comme le provisionnement le crée. */
    private function createCustomer(string $name = self::CUSTOMER_NAME): Uuid
    {
        $groupe = (new Groupe())->setNom($name);
        $this->em()->persist($groupe);

        $region = (new Region())->setNom($name)->setGroupe($groupe);
        $this->em()->persist($region);

        $establishment = (new Etablissement())->setNom($name)->setRegion($region)->setActif(true);
        $this->em()->persist($establishment);
        $this->em()->flush();

        return $establishment->getId();
    }

    private function grant(Uuid $customerId): Uuid
    {
        $now = new \DateTimeImmutable();

        // ⚠ FENÊTRE À L'HEURE RÉELLE. L'extension de cloisonnement forge son propre instant à chaque
        // requête ; une fenêtre posée autour d'une date fixe serait dans le futur au moment où elle
        // interroge, et l'accès ne vaudrait rien.
        return $this->guard()->grant(
            $this->agent(),
            $this->customer($customerId),
            'Ticket 5102 — la caisse ne s’ouvre pas',
            $now->modify('-1 hour'),
            $now->modify('+1 hour'),
            'chef@editeur.test',
        )->getId();
    }

    private function revoke(Uuid $accessId): void
    {
        $access = $this->em()->getRepository(SupportAccess::class)->find($accessId);
        self::assertInstanceOf(SupportAccess::class, $access);

        $this->guard()->revoke($access, new \DateTimeImmutable('-1 minute'), 'chef@editeur.test');
    }

    /** L'établissement client, repris frais : voir l'avertissement de la classe. */
    private function customer(Uuid $id): Etablissement
    {
        $customer = $this->em()->getRepository(Etablissement::class)->find($id);
        self::assertInstanceOf(Etablissement::class, $customer);

        return $customer;
    }

    /**
     * L'agent est l'administrateur des fixtures.
     *
     * Ce test observe le PÉRIMÈTRE, pas la désignation de l'éditeur : l'extension ne demande jamais
     * qui est l'éditeur, seulement quels accès sont ouverts. Emprunter un compte qui s'authentifie
     * déjà évite d'introduire ici un montage dont ce fichier ne prouve rien.
     */
    private function agent(): Utilisateur
    {
        $agent = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        self::assertInstanceOf(Utilisateur::class, $agent);

        return $agent;
    }

    private function guard(): SupportAccessGuard
    {
        /** @var SupportAccessGuard $guard */
        $guard = static::getContainer()->get(SupportAccessGuard::class);

        return $guard;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
