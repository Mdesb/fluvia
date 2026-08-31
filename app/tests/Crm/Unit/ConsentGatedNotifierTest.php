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
use Symfony\Component\Uid\Uuid;

/**
 * Ce qu'on vérifie ici, c'est que le décorateur **refuse** (D42, RG-CMP-06/07).
 *
 * Un décorateur qui laisserait tout passer serait invisible : aucun test d'intégration ne tomberait,
 * puisque tout continuerait à fonctionner. C'est exactement la forme de défaut qui a laissé
 * trente-cinq entités sans protection d'écriture pendant des semaines. Les cinq cas ci-dessous sont
 * donc le contrat, pas des cas d'erreur.
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

    private function notifier(?Consentement $consentement): ConsentGatedNotifier
    {
        $decore = $this->createStub(ClientNotifierInterface::class);
        $decore->method('notify')->willReturn(NotificationOutcome::Journalisee);

        $depotClient = $this->createStub(EntityRepository::class);
        $depotClient->method('find')->willReturn(new Client());

        $depotConsentement = $this->createStub(EntityRepository::class);
        $depotConsentement->method('findOneBy')->willReturn($consentement);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $classe): EntityRepository => $classe === Client::class ? $depotClient : $depotConsentement
        );

        return new ConsentGatedNotifier($decore, $em);
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
