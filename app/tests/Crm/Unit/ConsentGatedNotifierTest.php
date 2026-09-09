<?php

declare(strict_types=1);

namespace App\Tests\Crm\Unit;

use App\Crm\Entity\Client;
use App\Crm\Entity\Consentement;
use App\Crm\Enum\CanalConsentement;
use App\Crm\Enum\EtatConsentement;
use App\Crm\Notification\ConsentGatedNotifier;
use App\Platform\Notification\ClientNotification;
use App\Platform\Notification\ClientNotifierInterface;
use App\Platform\Notification\NotificationBasis;
use App\Platform\Notification\NotificationChannel;
use App\Platform\Notification\NotificationOutcome;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Uid\Uuid;

/**
 * Ce qu'on vérifie ici, c'est que le décorateur **refuse** (D42, RG-CMP-06/07).
 *
 * Un décorateur qui laisserait tout passer serait invisible : aucun test d'intégration ne tomberait,
 * puisque tout continuerait à fonctionner. C'est exactement la forme de défaut qui a laissé
 * trente-cinq entités sans protection d'écriture pendant des semaines. Les cinq cas ci-dessous sont
 * donc le contrat, pas des cas d'erreur.
 *
 * ⚠ **Et deux verdicts identiques ne sont pas le même fait.** « Ce client a refusé » et « cet
 * identifiant ne désigne aucun client » rendaient tous deux `Refusee`, en silence — c'est ce qui a
 * laissé Smart Flow notifier des bénéficiaires pendant des semaines sans que rien ne le signale. Le
 * verdict reste le même ; la trace, non.
 */
final class ConsentGatedNotifierTest extends TestCase
{
    public function testUnConsentementAccordeLaissePasser(): void
    {
        $notifier = $this->notifier($this->consentement(EtatConsentement::Accorde));

        self::assertSame(NotificationOutcome::Journalisee, $notifier->notify($this->notification()));
    }

    public function testUnConsentementRefuseBloque(): void
    {
        $notifier = $this->notifier($this->consentement(EtatConsentement::Refuse));

        self::assertSame(NotificationOutcome::Refusee, $notifier->notify($this->notification()));
    }

    /** `a_renouveler` n'est pas un « oui en attendant » : c'est un non (échec fermé). */
    public function testUnConsentementARenouvelerBloque(): void
    {
        $notifier = $this->notifier($this->consentement(EtatConsentement::ARenouveler));

        self::assertSame(NotificationOutcome::Refusee, $notifier->notify($this->notification()));
    }

    /** L'absence de consentement n'est pas un « peut-être ». */
    public function testUnConsentementAbsentBloque(): void
    {
        $notifier = $this->notifier(null);

        self::assertSame(NotificationOutcome::Refusee, $notifier->notify($this->notification()));
    }

    public function testUnConsentementExpireBloque(): void
    {
        $consentement = $this->consentement(EtatConsentement::Accorde);
        $consentement->setDateExpiration(new \DateTimeImmutable('2020-01-01'));

        $notifier = $this->notifier($consentement);

        self::assertSame(NotificationOutcome::Refusee, $notifier->notify($this->notification()));
    }

    /**
     * Le cas qui manquait, et qui aurait coûté cher : un courriel de bienvenue, une facture ou un
     * accès ouvert n'ont pas besoin d'un consentement **marketing**. Les refuser priverait le client
     * de ce qu'il a acheté. Trouvé en répondant à une question de `claude-D` sur le courriel de
     * bienvenue — pas par un test, ce qui montre bien qu'il manquait.
     */
    public function testUneNotificationContractuellePasseSansConsentement(): void
    {
        $notifier = $this->notifier(null);

        $notification = new ClientNotification(
            Uuid::v7(),
            NotificationChannel::Email,
            'souscription.bienvenue',
            [],
            new \DateTimeImmutable(),
            'subscription',
            NotificationBasis::Contractuelle,
        );

        self::assertSame(NotificationOutcome::Journalisee, $notifier->notify($notification));
    }

    /**
     * ⚠ LE CAS QUI MANQUAIT, ET QUI A COÛTÉ DEUX DÉFAUTS. Le dépôt client de ce montage rendait
     * TOUJOURS un `Client` : la branche « aucun client derrière cet identifiant » n'était visitée par
     * aucun des cinq tests ci-dessus. C'est pourtant celle que Smart Flow empruntait à chaque
     * promotion, en passant un identifiant de bénéficiaire.
     *
     * La politique ne change pas — un destinataire qu'on ne peut pas identifier ne reçoit rien.
     */
    public function testUnDestinataireInconnuEstRefuse(): void
    {
        $notifier = $this->notifier($this->consentement(EtatConsentement::Accorde), clientExiste: false);

        self::assertSame(NotificationOutcome::Refusee, $notifier->notify($this->notification()));
    }

    /**
     * Refuser d'écrire à quelqu'un qui a dit non est un résultat métier normal. Refuser parce que
     * l'appelant a passé un identifiant qui ne désigne aucun client est un défaut de cet appelant.
     * Les deux rendaient `Refusee` sans rien dire, et c'est ce qui les rendait indiscernables.
     */
    public function testUnDestinataireInconnuSeDitDansLeJournal(): void
    {
        $journal = $this->journalEspion();

        $this->notifier($this->consentement(EtatConsentement::Accorde), clientExiste: false, journal: $journal)
            ->notify($this->notification());

        self::assertSame(['crm.notification.destinataire_inconnu'], $journal->avertissements);
    }

    /**
     * ⚠ LE TÉMOIN QUI FAIT TENIR LE PRÉCÉDENT. Un journal qui avertirait aussi sur les refus
     * légitimes ne distinguerait toujours rien : il rendrait seulement le bruit symétrique. Un
     * client qui a dit non est un cas métier NORMAL et fréquent (RG-CMP-08) — il se compte et
     * s'affiche, il ne s'alerte pas.
     *
     * Ce test est celui qui tombe si quelqu'un déplace l'avertissement d'un cran en aval.
     */
    public function testUnConsentementRefuseNeDitRienDansLeJournal(): void
    {
        $journal = $this->journalEspion();

        $this->notifier($this->consentement(EtatConsentement::Refuse), journal: $journal)
            ->notify($this->notification());

        self::assertSame([], $journal->avertissements, 'Un refus de consentement est un cas normal, pas un incident.');
    }

    private function journalEspion(): LoggerInterface
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $avertissements = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($level === LogLevel::WARNING) {
                    $this->avertissements[] = (string) $message;
                }
            }
        };
    }

    private function notifier(
        ?Consentement $consentement,
        bool $clientExiste = true,
        ?LoggerInterface $journal = null,
    ): ConsentGatedNotifier {
        $decore = $this->createStub(ClientNotifierInterface::class);
        $decore->method('notify')->willReturn(NotificationOutcome::Journalisee);

        $depotClient = $this->createStub(EntityRepository::class);
        $depotClient->method('find')->willReturn($clientExiste ? new Client() : null);

        $depotConsentement = $this->createStub(EntityRepository::class);
        $depotConsentement->method('findOneBy')->willReturn($consentement);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $classe): EntityRepository => $classe === Client::class ? $depotClient : $depotConsentement
        );

        return new ConsentGatedNotifier($decore, $em, $journal);
    }

    private function consentement(EtatConsentement $etat): Consentement
    {
        $consentement = new Consentement();
        $consentement->setCanal(CanalConsentement::Email);
        $consentement->setEtat($etat);

        return $consentement;
    }

    private function notification(): ClientNotification
    {
        return new ClientNotification(
            Uuid::v7(),
            NotificationChannel::Email,
            'campagne.relance',
            ['prenom' => 'Test'],
            new \DateTimeImmutable(),
            'test',
        );
    }
}
