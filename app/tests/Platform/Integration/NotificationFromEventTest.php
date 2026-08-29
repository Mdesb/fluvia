<?php

declare(strict_types=1);

namespace App\Tests\Platform\Integration;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Entity\Notification;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Platform\Notification\NotificationRule;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * LA CLOCHE SE REMPLIT-ELLE VRAIMENT — ET SEULEMENT POUR CEUX QUI PEUVENT AGIR ?
 *
 * ── CE QUE CE FICHIER EXISTE POUR EMPÊCHER ──────────────────────────────────────────────────────
 *
 * Une table de notifications que rien ne remplit. Ce dépôt en compte déjà trop du même genre :
 * vingt-trois commandes planifiées dont aucune ne se déclenche, des abonnés écrits que rien
 * n'appelle. Le mécanisme a l'air de fonctionner **parce qu'il existe** — et une pastille rouge le
 * rendrait plus crédible que les autres, pas plus vivant.
 *
 * ── LES TROIS PROPRIÉTÉS, DANS L'ORDRE DE CE QUE LEUR ÉCHEC COÛTERAIT ───────────────────────────
 *
 * 1. **Seuls ceux qui peuvent agir sont prévenus.** Une notification de recouvrement chez un
 *    caissier n'est pas seulement inutile : elle lui apprend à ignorer la cloche, et le jour où elle
 *    porte quelque chose pour lui, il ne la lira pas.
 * 2. **Un événement non admis ne crée rien.** Le critère est restrictif exprès ; une cloche qui
 *    sonne pour tout ne se lit plus.
 * 3. **Un événement admis crée quelque chose.** C'est le témoin, et sans lui les deux premières
 *    assertions seraient vraies d'un mécanisme entièrement mort.
 *
 * ⚠ LA TROISIÈME EST LA PLUS IMPORTANTE À ÉCRIRE, ET LA PLUS FACILE À OUBLIER. « Le caissier ne
 * reçoit rien » est vrai aussi quand PERSONNE ne reçoit rien.
 */
final class NotificationFromEventTest extends SocleApiTestCase
{
    public function testUnEvenementAdmisPrevientCeuxQuiPeuventAgir(): void
    {
        $etablissement = $this->etablissement(SocleFixtures::ETAB_A_NOM);
        $incident = (string) Uuid::v4();

        $this->publier('payment.failed', $etablissement, $incident);

        $notifications = $this->notificationsDe('payment.failed');

        // ── LE TÉMOIN ───────────────────────────────────────────────────────────────────────────
        self::assertNotEmpty(
            $notifications,
            'Témoin absent : si personne n’est prévenu, « le caissier n’est pas prévenu » ne prouve rien.',
        );

        $regle = NotificationRule::forEvent('payment.failed');
        self::assertNotNull($regle);

        foreach ($notifications as $notification) {
            // ── LA PROPRIÉTÉ QUI COMPTE ─────────────────────────────────────────────────────────
            // Chaque destinataire possède RÉELLEMENT le droit exigé par la règle. Un résolveur qui
            // oublierait de filtrer rendrait tout le monde, et cette boucle le dirait.
            $codes = $this->droits()->codesEffectifs($notification->getDestinataire(), $etablissement->getId());
            self::assertTrue(
                $this->droits()->autorise($codes, $regle->module, $regle->action),
                sprintf(
                    'Prévenu sans pouvoir agir : %s n’a pas %s.%s',
                    $notification->getDestinataire()->getEmail(),
                    $regle->module,
                    $regle->action,
                ),
            );

            // ⚠ On compare les VALEURS, pas les objets : après le `clear()` de l'unité de travail,
            // l'entité relue est une autre instance et `assertSame` échouerait sur une identité
            // d'objet — un test rouge pour une raison qui n'a rien à voir avec ce qu'il mesure.
            self::assertSame((string) $etablissement->getId(), (string) $notification->getEtablissement()->getId());
            self::assertSame($regle->screen, $notification->getEcran());
            self::assertSame([$regle->paramName => $incident], $notification->getParams());
            self::assertFalse($notification->isLue(), 'Une notification naît non lue.');
        }
    }

    public function testUnEvenementNonAdmisNeCreeRien(): void
    {
        $etablissement = $this->etablissement(SocleFixtures::ETAB_A_NOM);

        // ── LE TÉMOIN D'ABORD ───────────────────────────────────────────────────────────────────
        // On prouve que le mécanisme fonctionne AVANT d'affirmer qu'il s'est abstenu. Sans cela,
        // « rien n'a été créé » se lit aussi bien comme « l'abonné n'est pas branché ».
        $this->publier('payment.failed', $etablissement, (string) Uuid::v4());
        self::assertNotEmpty($this->notificationsDe('payment.failed'), 'Témoin absent : le mécanisme doit être vivant.');

        // `payment.succeeded` est émis par le même pont que `payment.failed` et n'est PAS admis :
        // un règlement qui aboutit n'appelle le geste de personne.
        $this->publier('payment.succeeded', $etablissement, (string) Uuid::v4());

        self::assertSame([], $this->notificationsDe('payment.succeeded'));
    }

    public function testUnCompteNonAffecteAuSiteNestJamaisPrevenu(): void
    {
        $etabA = $this->etablissement(SocleFixtures::ETAB_A_NOM);
        $etabB = $this->etablissement(SocleFixtures::ETAB_B_NOM);

        $this->publier('payment.failed', $etabB, (string) Uuid::v4());

        $notifications = $this->notificationsDe('payment.failed');
        self::assertNotEmpty($notifications, 'Témoin absent : sans destinataire sur B, ce test ne mesure rien.');

        foreach ($notifications as $notification) {
            self::assertSame(
                (string) $etabB->getId(),
                (string) $notification->getEtablissement()->getId(),
                'Un événement de B ne doit prévenir personne au titre de A.',
            );
        }

        // Et rien n'a été posé sur A, qui n'a connu aucun incident.
        $surA = array_filter(
            $notifications,
            static fn (Notification $n): bool => $n->getEtablissement()->getId()->equals($etabA->getId()),
        );
        self::assertSame([], $surA);
    }

    // ── Aides ───────────────────────────────────────────────────────────────────────────────────

    private function publier(string $nom, Etablissement $etablissement, string $sujet): void
    {
        $bus = static::getContainer()->get(EventBus::class);
        self::assertInstanceOf(EventBus::class, $bus);

        $bus->publish(new DomainEvent(
            $nom,
            new EventTenant($etablissement->getId()),
            new EventSubject('PaymentIncident', $sujet),
            ['amount_cents' => 4500],
        ));

        // L'abonné persiste sans écrire : c'est l'émetteur qui décide. Ici, c'est nous.
        $this->em()->flush();
        $this->em()->clear();
    }

    /** @return list<Notification> */
    private function notificationsDe(string $source): array
    {
        return array_values($this->em()->getRepository(Notification::class)->findBy(['source' => $source]));
    }

    private function droits(): CalculateurDroits
    {
        $droits = static::getContainer()->get(CalculateurDroits::class);
        self::assertInstanceOf(CalculateurDroits::class, $droits);

        return $droits;
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
