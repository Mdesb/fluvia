<?php

declare(strict_types=1);

namespace App\Tests\Sepa;

use App\Platform\Notification\ClientNotification;
use App\Platform\Notification\ClientNotifierInterface;
use App\Platform\Notification\NotificationBasis;
use App\Platform\Notification\NotificationOutcome;
use App\Sepa\Entity\ConfigCreancierSepa;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\PreNotificationReason;
use App\Sepa\Exception\PreNotificationRefusedException;
use App\Sepa\Service\DebitPreNotifier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * PAY-2 — le préavis de prélèvement.
 *
 * **Ce que ces tests protègent.** Un créancier SEPA doit prévenir le débiteur du montant et de la date
 * avant chaque prélèvement. Le module n'émettait rien : ni notification, ni événement. Ce lot ajoute
 * l'annonce **et** le contrôle qui la rend contraignante — un préavis qui n'empêche rien n'est pas un
 * préavis, c'est une ligne de plus dans une table.
 */
final class DebitPreNotifierTest extends SepaApiTestCase
{
    public function testAnnoncerEnvoieLePreavisEtLeConsigne(): void
    {
        [$notifier, $espion] = $this->service(NotificationOutcome::Envoyee);
        $mandat = $this->mandat();

        $preavis = $notifier->announce(
            $mandat,
            'echeance-1',
            4990,
            new \DateTimeImmutable('2026-09-15'),
            PreNotificationReason::CardFallback,
            new \DateTimeImmutable('2026-08-26 10:00:00'),
        );

        self::assertSame(4990, $preavis->getAmountCents());
        self::assertTrue($preavis->wasDelivered());

        $envoi = $espion->dernier();
        self::assertNotNull($envoi);
        self::assertSame('sepa.prenotification.card_fallback', $envoi->templateKey);
        self::assertSame('2026-09-15', $envoi->variables['dueDate']);
        self::assertSame(4990, $envoi->variables['amountCents']);

        // Le fondement contractuel n'est pas un confort : sur la base du consentement, un client ayant
        // refusé la prospection ne recevrait jamais son préavis, et le prélèvement serait irrégulier.
        self::assertSame(NotificationBasis::Contractuelle, $envoi->basis);
    }

    /**
     * **Le test central : un préavis seulement journalisé n'autorise aucun prélèvement.**
     *
     * Aucun prestataire d'envoi n'est branché dans ce dépôt, donc c'est le cas RÉEL aujourd'hui.
     * Sans cette règle, la table dirait « prévenu » pour des clients qui n'ont rien reçu, et chaque
     * remise partirait sur la foi d'une ligne de journal.
     */
    public function testUnPreavisSeulementJournaliseNAutorisePas(): void
    {
        [$notifier] = $this->service(NotificationOutcome::Journalisee);
        $mandat = $this->mandat();

        $preavis = $notifier->announce(
            $mandat,
            'echeance-1',
            4990,
            new \DateTimeImmutable('2026-09-15'),
            PreNotificationReason::Schedule,
            new \DateTimeImmutable('2026-08-26'),
        );

        self::assertFalse($preavis->wasDelivered(), 'journalise n est pas envoye');
        self::assertFalse(
            $notifier->covers($mandat, 'echeance-1', 4990, new \DateTimeImmutable('2026-09-15')),
            'un preavis journalise ne couvre aucun prelevement',
        );
    }

    /** Un envoi refusé ou en échec ne laisse aucune trace : rien n'est consigné, donc rien ne passe. */
    public function testUnEnvoiRefuseNeConsigneRien(): void
    {
        [$notifier] = $this->service(NotificationOutcome::Refusee);
        $mandat = $this->mandat();

        $this->expectException(PreNotificationRefusedException::class);

        $notifier->announce(
            $mandat,
            'echeance-1',
            4990,
            new \DateTimeImmutable('2026-09-15'),
            PreNotificationReason::Schedule,
            new \DateTimeImmutable('2026-08-26'),
        );
    }

    /** Prévenu la veille, ce n'est pas prévenu : le délai court avant la date d'exécution. */
    public function testUnPreavisTropTardifNeCouvrePas(): void
    {
        [$notifier] = $this->service(NotificationOutcome::Envoyee);
        $mandat = $this->mandat();

        $notifier->announce($mandat, 'echeance-1', 4990, new \DateTimeImmutable('2026-09-15'), PreNotificationReason::Schedule, new \DateTimeImmutable('2026-09-14'));

        self::assertFalse($notifier->covers($mandat, 'echeance-1', 4990, new \DateTimeImmutable('2026-09-15')));
        self::assertTrue($notifier->covers($mandat, 'echeance-1', 4990, new \DateTimeImmutable('2026-09-28')));
    }

    /**
     * **Annoncer trente euros puis en prélever trois cents n'est pas un préavis.**
     *
     * C'est le cas qui distingue un contrôle réel d'un contrôle de présence : sans la comparaison du
     * montant, il aurait suffi d'avoir prévenu une fois pour prélever n'importe quoi ensuite.
     */
    public function testUnPreavisPourUnAutreMontantNeCouvrePas(): void
    {
        [$notifier] = $this->service(NotificationOutcome::Envoyee);
        $mandat = $this->mandat();

        $notifier->announce($mandat, 'echeance-1', 3000, new \DateTimeImmutable('2026-09-15'), PreNotificationReason::Schedule, new \DateTimeImmutable('2026-08-01'));

        self::assertTrue($notifier->covers($mandat, 'echeance-1', 3000, new \DateTimeImmutable('2026-09-15')));
        self::assertFalse($notifier->covers($mandat, 'echeance-1', 30000, new \DateTimeImmutable('2026-09-15')));
    }

    /** Changer le montant rend au client la totalité du délai, et n'empile pas un second préavis. */
    public function testChangerLeMontantRelanceLeDelaiSansEmpiler(): void
    {
        [$notifier] = $this->service(NotificationOutcome::Envoyee);
        $mandat = $this->mandat();

        $notifier->announce($mandat, 'echeance-1', 3000, new \DateTimeImmutable('2026-09-15'), PreNotificationReason::Schedule, new \DateTimeImmutable('2026-08-01'));
        $second = $notifier->announce($mandat, 'echeance-1', 5000, new \DateTimeImmutable('2026-09-15'), PreNotificationReason::Schedule, new \DateTimeImmutable('2026-09-10'));

        // Sur CE mandat : la fixture de demonstration en pose un autre, sur un mandat different.
        self::assertCount(
            1,
            $this->em()->getRepository(\App\Sepa\Entity\DebitPreNotification::class)->findBy(['mandate' => $mandat]),
        );
        self::assertSame(5000, $second->getAmountCents());
        self::assertFalse(
            $notifier->covers($mandat, 'echeance-1', 5000, new \DateTimeImmutable('2026-09-15')),
            'le nouveau montant repart pour quatorze jours',
        );
    }

    /** Un prélèvement plus tôt qu'annoncé ne passe pas ; plus tard, oui. */
    public function testPrelevePlusTotQuAnnonceNePassePas(): void
    {
        [$notifier] = $this->service(NotificationOutcome::Envoyee);
        $mandat = $this->mandat();

        $notifier->announce($mandat, 'echeance-1', 3000, new \DateTimeImmutable('2026-09-20'), PreNotificationReason::Schedule, new \DateTimeImmutable('2026-08-01'));

        self::assertFalse($notifier->covers($mandat, 'echeance-1', 3000, new \DateTimeImmutable('2026-09-18')));
        self::assertTrue($notifier->covers($mandat, 'echeance-1', 3000, new \DateTimeImmutable('2026-09-22')));
    }

    /** Le délai du créancier prime le défaut légal quand il a été convenu plus long. */
    public function testLeDelaiDuCreancierPrimeLeDefaut(): void
    {
        [$notifier] = $this->service(NotificationOutcome::Envoyee);
        $mandat = $this->mandat(30);

        self::assertSame(30, $notifier->delayFor($mandat));

        $notifier->announce($mandat, 'echeance-1', 3000, new \DateTimeImmutable('2026-09-15'), PreNotificationReason::Schedule, new \DateTimeImmutable('2026-09-01'));

        // Quatorze jours auraient suffi ; trente non.
        self::assertFalse($notifier->covers($mandat, 'echeance-1', 3000, new \DateTimeImmutable('2026-09-15')));
    }

    /**
     * Un mandat sans client ne mène à personne : on refuse plutôt que de prélever en silence.
     *
     * Le mandat n'est pas persisté ici, et pas par commodité : `sepa_mandat.client_id` est NOT NULL,
     * donc ce cas **ne peut pas exister en base**. La garde reste néanmoins juste — elle protège le
     * chemin où un mandat est composé en mémoire avant d'être écrit —, et la seule façon honnête de
     * l'éprouver est de ne pas écrire.
     */
    public function testUnMandatSansClientEstRefuse(): void
    {
        [$notifier] = $this->service(NotificationOutcome::Envoyee);
        $mandat = $this->mandat(null, avecClient: false, persiste: false);

        $this->expectException(PreNotificationRefusedException::class);

        $notifier->announce($mandat, 'echeance-1', 3000, new \DateTimeImmutable('2026-09-15'), PreNotificationReason::Schedule, new \DateTimeImmutable('2026-08-01'));
    }

    // ---------------------------------------------------------------- montage

    /** @return array{0: DebitPreNotifier, 1: NotifierEspion} */
    private function service(NotificationOutcome $issue): array
    {
        $espion = new NotifierEspion($issue);

        return [new DebitPreNotifier($this->em(), $espion), $espion];
    }

    private function mandat(?int $delaiCreancier = null, bool $avecClient = true, bool $persiste = true): MandatSepa
    {
        $em = $this->em();
        // L'etablissement est celui qui porte une configuration creancier, et non le premier venu :
        // un mandat appartient a un etablissement capable de prelever. Prendre `findOneBy([])` rendait
        // parfois la Patinoire B, sans config — et `delayFor()` retombait sur le defaut legal sans que
        // le test s'en apercoive. Il passait au vert en ne mesurant pas ce qu'il annoncait.
        $config = $em->getRepository(ConfigCreancierSepa::class)->findOneBy([]);
        self::assertNotNull($config, 'une configuration creancier est requise');
        $etablissement = $config->getEtablissement();
        self::assertNotNull($etablissement);

        if (null !== $delaiCreancier) {
            $config->setPreNotificationDelayDays($delaiCreancier);
            $em->flush();
        }

        $mandat = new MandatSepa();
        $mandat->setRum('RUM-'.bin2hex(random_bytes(6)))
            ->setEtablissement($etablissement)
            ->setDebiteurNom('Debiteur Test')
            ->setDateSignature(new \DateTimeImmutable('2026-01-01'));

        if ($avecClient) {
            $client = $em->getRepository(\App\Crm\Entity\Client::class)->findOneBy([]);
            self::assertNotNull($client, 'un client est requis');
            $mandat->setClient($client);
        }

        if ($persiste) {
            $em->persist($mandat);
            $em->flush();
        }

        return $mandat;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}

/**
 * Un notifieur qui retient ce qu'on lui a demandé d'envoyer.
 *
 * Un bouchon plutôt qu'un mock : ce qu'on veut vérifier, c'est le **contenu** du préavis — montant,
 * date, fondement —, pas le fait qu'une méthode ait été appelée.
 */
final class NotifierEspion implements ClientNotifierInterface
{
    /** @var list<ClientNotification> */
    private array $envois = [];

    public function __construct(private readonly NotificationOutcome $issue)
    {
    }

    public function notify(ClientNotification $notification): NotificationOutcome
    {
        $this->envois[] = $notification;

        return $this->issue;
    }

    public function dernier(): ?ClientNotification
    {
        return [] === $this->envois ? null : $this->envois[array_key_last($this->envois)];
    }
}
