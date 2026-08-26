<?php

declare(strict_types=1);

namespace App\Tests\Sepa;

use App\Platform\Notification\ClientNotification;
use App\Platform\Notification\ClientNotifierInterface;
use App\Platform\Notification\NotificationOutcome;
use App\Sepa\Entity\CardFallbackDebt;
use App\Sepa\Entity\ConfigCreancierSepa;
use App\Sepa\Entity\DebitPreNotification;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\PreNotificationReason;
use App\Sepa\Enum\StatutMandatSepa;
use App\Sepa\Exception\CardFallbackRefusedException;
use App\Sepa\Exception\PreNotificationRefusedException;
use App\Sepa\Service\CardDebitFallback;
use App\Sepa\Service\DebitPreNotifier;
use App\Sepa\Source\CardFallbackDebtSource;
use Doctrine\ORM\EntityManagerInterface;

/**
 * PAY-2 — la bascule carte vers prélèvement (D43).
 *
 * **Ce que ces tests protègent.** Une carte refusée ne se rejoue pas : le refus vient de la banque du
 * porteur. La bascule change de moyen, en s'appuyant sur un mandat déjà signé — et elle ne prélève
 * pas tout de suite, parce qu'un prélèvement sur un moyen que le client ne s'attendait pas à voir
 * utilisé ce mois-ci est exactement la situation où l'absence de préavis se conteste.
 */
final class CardDebitFallbackTest extends SepaApiTestCase
{
    /** **La bascule prévient avant de créer une dette, et la dette n'est exigible qu'après le délai.** */
    public function testLaBasculePrevientEtDiffereLExigibilite(): void
    {
        [$bascule, $espion, , $mandat] = $this->montage();
        $maintenant = new \DateTimeImmutable('2026-09-01 10:00:00');

        $dette = $bascule->basculer($mandat, 'paiement-refuse-1', 4990, null, $maintenant);

        self::assertSame(4990, $dette->getAmountCents());
        self::assertSame(
            $maintenant->modify('+14 days')->format('Y-m-d'),
            $dette->getDueDate()->format('Y-m-d'),
            'exigible seulement une fois le preavis couru',
        );
        self::assertNull($dette->getCollectedAt());

        $envoi = $espion->dernier();
        self::assertNotNull($envoi);
        self::assertSame('sepa.prenotification.card_fallback', $envoi->templateKey, 'le texte dit que la carte a ete refusee');

        $preavis = $this->em()->getRepository(DebitPreNotification::class)->findOneBy(['originReference' => 'paiement-refuse-1']);
        self::assertNotNull($preavis);
        self::assertSame(PreNotificationReason::CardFallback, $preavis->getReason());
    }

    /**
     * **Sans mandat actif, pas de bascule — et sans échappatoire.**
     *
     * Prélever sans mandat serait prélever sans autorisation. Le mandat se signe à la souscription,
     * pas au moment du refus : au moment du refus, le client n'est plus devant nous.
     */
    public function testSansMandatLaBasculeEstRefusee(): void
    {
        [$bascule] = $this->montage();

        $this->expectException(CardFallbackRefusedException::class);
        $bascule->basculer(null, 'paiement-refuse-1', 4990, null, new \DateTimeImmutable());
    }

    public function testUnMandatRevoqueNAutorisePlusRien(): void
    {
        [$bascule, , , $mandat] = $this->montage();
        $mandat->setStatut(StatutMandatSepa::Revoque);
        $this->em()->flush();

        $this->expectException(CardFallbackRefusedException::class);
        $bascule->basculer($mandat, 'paiement-refuse-1', 4990, null, new \DateTimeImmutable());
    }

    /**
     * **Si le préavis ne part pas, aucune dette n'est enregistrée.**
     *
     * L'ordre n'est pas indifférent : l'inverse laisserait une dette prélevable derrière un préavis
     * qui n'est jamais parti — c'est-à-dire un prélèvement dont personne n'a été averti, avec une
     * trace en base qui laisserait croire le contraire.
     */
    public function testUnPreavisQuiNePartPasNeLaissePasDeDette(): void
    {
        [$bascule, , , $mandat] = $this->montage(NotificationOutcome::Refusee);

        try {
            $bascule->basculer($mandat, 'paiement-refuse-1', 4990, null, new \DateTimeImmutable());
            self::fail('la bascule aurait du etre refusee');
        } catch (PreNotificationRefusedException) {
            // attendu
        }

        self::assertCount(0, $this->em()->getRepository(CardFallbackDebt::class)->findAll());
    }

    /** Rejouer le même refus met à jour la dette : un événement redélivré ne fait pas payer deux fois. */
    public function testRejouerLeMemeRefusNeDoublePasLaDette(): void
    {
        [$bascule, , , $mandat] = $this->montage();
        $maintenant = new \DateTimeImmutable('2026-09-01 10:00:00');

        $bascule->basculer($mandat, 'paiement-refuse-1', 4990, null, $maintenant);
        $bascule->basculer($mandat, 'paiement-refuse-1', 4990, null, $maintenant);

        self::assertCount(1, $this->em()->getRepository(CardFallbackDebt::class)->findAll());
    }

    /**
     * **La boucle se referme : ce que la bascule enregistre, la collecte le retrouve et le préavis le couvre.**
     *
     * C'est l'assertion qui vérifie que les trois moments portent bien la même référence et le même
     * montant. Une source qui arrondirait ou renommerait ferait reconnaître « une annonce qui
     * ressemble à la bonne sans en être une » : tout serait vert et rien ne serait couvert.
     */
    public function testLaDetteEstPresenteeALaCollecteEtCouverteParSonPreavis(): void
    {
        [$bascule, , $preNotifier, $mandat] = $this->montage();
        $maintenant = new \DateTimeImmutable('2026-09-01 10:00:00');

        $dette = $bascule->basculer($mandat, 'paiement-refuse-1', 4990, null, $maintenant);
        $etablissement = $mandat->getEtablissement();
        self::assertNotNull($etablissement);

        $source = new CardFallbackDebtSource($this->em());

        self::assertSame(
            [],
            $source->echeancesDues($etablissement, $maintenant),
            'rien n est presente avant que le preavis ait couru',
        );

        $dues = $source->echeancesDues($etablissement, $dette->getDueDate());
        self::assertCount(1, $dues);
        self::assertSame('paiement-refuse-1', $dues[0]->referenceOrigine);
        self::assertSame(4990, $dues[0]->montantCentimes);
        self::assertTrue($dues[0]->paiementUnique, 'une bascule est un prelevement isole, pas une recurrence');

        self::assertTrue(
            $preNotifier->covers($mandat, $dues[0]->referenceOrigine, $dues[0]->montantCentimes, $dette->getDueDate()),
            'le preavis emis par la bascule doit couvrir l echeance qu elle presente',
        );
    }

    // ---------------------------------------------------------------- montage

    /** @return array{0: CardDebitFallback, 1: NotifieurDeBascule, 2: DebitPreNotifier, 3: MandatSepa} */
    private function montage(NotificationOutcome $issue = NotificationOutcome::Envoyee): array
    {
        $em = $this->em();

        $config = $em->getRepository(ConfigCreancierSepa::class)->findOneBy([]);
        self::assertNotNull($config);
        $config->setPreNotificationDelayDays(14);

        $mandat = $em->getRepository(MandatSepa::class)->findOneBy(['etablissement' => $config->getEtablissement()]);
        self::assertNotNull($mandat, 'un mandat est requis');
        $mandat->setStatut(StatutMandatSepa::Actif);
        $em->flush();

        $espion = new NotifieurDeBascule($issue);
        $preNotifier = new DebitPreNotifier($em, $espion);

        return [new CardDebitFallback($em, $preNotifier), $espion, $preNotifier, $mandat];
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}

final class NotifieurDeBascule implements ClientNotifierInterface
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
