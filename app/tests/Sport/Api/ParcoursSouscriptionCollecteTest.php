<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Offre\Entity\Formule;
use App\Organisation\Entity\Etablissement;
use App\Sepa\Entity\DebitPreNotification;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\PreNotificationReason;
use App\Sepa\Enum\StatutMandatSepa;
use App\Platform\Notification\NotificationOutcome;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Enum\PeriodiciteAbonnementFitness;
use App\Sport\Enum\StatutEcheanceSepa;
use App\Sport\Service\DemanderResiliationHandler;
use App\Sport\Service\GenererRemiseSepaHandler;
use App\Sport\Service\SouscriptionAbonnementHandler;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * PARCOURS — de la souscription à la collecte, puis à son arrêt.
 *
 * ⚠ AUCUN TEST NE PARCOURAIT CETTE CHAÎNE DEPUIS UNE VRAIE SOUSCRIPTION. Mesuré :
 * `MoteurRecouvrementTest` couvre six maillons et ne contient pas une seule occurrence de
 * `souscrire` — comme les autres, il part de fixtures posées au milieu. Chaque maillon est donc
 * éprouvé, et la **jonction** ne l'est pas.
 *
 * C'est exactement là que vivaient les défauts trouvés le 03/09 : le filtre de mandat révoqué et les
 * prix du panier étaient tous deux *entre* deux morceaux bien testés. Un test qui part d'une fixture
 * ne peut pas voir qu'une création produit quelque chose que la collecte refuse — il reçoit une
 * donnée déjà conforme.
 *
 * ── CE QUE CE PARCOURS ÉTABLIT, EN DEUX MOITIÉS QUI SE SERVENT DE TÉMOIN ───────────────────────
 *
 * 1. Un adhérent qui vient de souscrire **est collectable** : la souscription produit un mandat
 *    actif et un échéancier que `GenerationRemiseHandler` accepte.
 * 2. Le même adhérent, après résiliation, **cesse de l'être** — et la remise le dit.
 *
 * La première moitié est indispensable : sans elle, la seconde serait verte le jour où la
 * souscription cesserait de produire un échéancier collectable, et elle affirmerait une protection
 * qu'elle ne mesure pas.
 */
final class ParcoursSouscriptionCollecteTest extends SportApiTestCase
{
    public function testUnAdherentQuiVientDeSouscrireEstCollectablePuisCesseDeLEtre(): void
    {
        [, , $idA] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etablissement = $em->getRepository(Etablissement::class)->find($idA);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        // ── Le geste du guichet : on souscrit, comme l'écran le fait ───────────────────────────
        /** @var SouscriptionAbonnementHandler $souscriptions */
        $souscriptions = static::getContainer()->get(SouscriptionAbonnementHandler::class);

        $adherent = $this->unBeneficiaireSansAbonnement($em);
        $payeur = $adherent->getClient();
        self::assertInstanceOf(Client::class, $payeur, 'Le bénéficiaire de démonstration doit porter un client payeur.');
        $formule = $em->getRepository(Formule::class)->findOneBy([]);
        self::assertInstanceOf(Formule::class, $formule, 'Le jeu de démonstration doit porter une formule.');

        $abonnement = $souscriptions->souscrire(
            $adherent,
            $payeur,
            $formule,
            $etablissement,
            new \DateTimeImmutable('-1 month'),
            12,
            'FR7630006000011234567890189',
            'Parcours Démonstration',
        );

        // ⚠ CE QUE LA SOUSCRIPTION DOIT PRODUIRE POUR QUE LA SUITE EXISTE.
        $mandat = $abonnement->getMandatSepa();
        self::assertInstanceOf(MandatSepa::class, $mandat, 'Souscrire sans mandat ne rend personne prélevable.');
        self::assertSame(
            StatutMandatSepa::Actif,
            $mandat->getStatut(),
            'Un mandat né inactif ferait un adhérent facturé et jamais collecté.',
        );

        // ⚠ ON REPASSE PAR LA BASE, PARCE QUE LA PRODUCTION LE FAIT. Souscrire et collecter sont
        // deux requêtes distinctes ; sans ce `clear()`, l'échéancier reste en mémoire avec l'heure
        // de la souscription, alors que `dateProgrammee` est une colonne `date_immutable` — la base
        // ne stocke que le jour.
        //
        // Ça m'a coûté un faux défaut : une échéance datée « aujourd'hui 22:22:38 » en mémoire
        // était refusée par `DebitPreNotifier` (« prélèvement avancé par rapport à la date annoncée »)
        // alors qu'elle est relue à 00:00:00 dès la requête suivante. Un test qui ne franchit pas la
        // frontière de requête invente des états que la production ne connaît pas.
        $abonnementId = $abonnement->getId();
        $em->flush();
        $em->clear();
        $etablissement = $em->getRepository(Etablissement::class)->find($idA);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $moisCourant = new \DateTimeImmutable('today');
        $duesAvant = $this->echeancesDues($em, $etablissement, $moisCourant);
        self::assertGreaterThan(
            0,
            $duesAvant,
            'La souscription n’a produit aucune échéance due : la chaîne s’arrête au premier maillon.',
        );

        // ── Moitié 1 : LE TÉMOIN. Cet adhérent tout neuf est bien collecté ────────────────────
        $this->preavisPourToutesLesDues($em, $etablissement, $moisCourant);

        /** @var GenererRemiseSepaHandler $remises */
        $remises = static::getContainer()->get(GenererRemiseSepaHandler::class);
        $remise = $remises->generer($etablissement, $moisCourant);

        // ⚠ LE MESSAGE PORTE LE MOTIF. Un « 3 au lieu de 4 » n'apprend rien : c'est le motif
        // d'exclusion qui dit lequel a été retenu, et pourquoi.
        self::assertSame(
            $duesAvant,
            $remise->getNbTxs(),
            sprintf(
                'Toutes les échéances dues devaient entrer dans la remise : %d sur %d seulement. '
                .'Écartées : %d — motif : %s. Sans ce témoin, la moitié 2 serait verte même si la '
                .'souscription ne produisait plus rien de collectable.',
                $remise->getNbTxs(),
                $duesAvant,
                $remise->getNbExclues(),
                $remise->getMotifExclusion() ?? 'aucun',
            ),
        );
        self::assertSame(0, $remise->getNbExclues());

        // ── Moitié 2 : après résiliation, la collecte s'arrête ────────────────────────────────
        $em->clear();
        $etablissement = $em->getRepository(Etablissement::class)->find($idA);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $abonnement = $em->getRepository(\App\Sport\Entity\AbonnementFitness::class)->find($abonnementId);
        self::assertNotNull($abonnement);

        /** @var DemanderResiliationHandler $resiliations */
        $resiliations = static::getContainer()->get(DemanderResiliationHandler::class);
        $resiliation = $resiliations->demander(
            $abonnement,
            $abonnement->getDateFinEngagement()->modify('+1 day'),
            'Parcours — départ de l’adhérent',
            false,
            null,
        );
        $resiliations->executerEffet($resiliation);
        $em->clear();
        $etablissement = $em->getRepository(Etablissement::class)->find($idA);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        // ⚠ ON REGARDE LE CYCLE SUIVANT, PAS LE MÊME. La remise ci-dessus a consommé les
        // échéances déjà dues : il n'en reste aucune à la date du jour. C'est la remise du mois
        // suivant qui doit refuser — et c'est aussi la séquence réelle, un exploitant ne relance
        // pas deux remises le même jour.
        $moisSuivant = $moisCourant->modify('+2 months');
        $duesApres = $this->echeancesDues($em, $etablissement, $moisSuivant);
        self::assertGreaterThan(
            0,
            $duesApres,
            'Sans échéance restante, la suite ne mesurerait plus l’arrêt de la collecte mais son épuisement.',
        );

        $this->preavisPourToutesLesDues($em, $etablissement, $moisSuivant);

        // ⚠ LA REMISE PEUT REUSSIR, ET C'EST NORMAL. D'autres adherents restent collectables :
        // seule celle du resilie doit sortir. Asserter que TOUTE la remise echoue confondrait
        // « cet adherent n'est plus preleve » avec « plus personne ne l'est » — j'ai ecrit cette
        // assertion-la d'abord, et elle passait pour une bonne raison dans un jeu a un seul
        // abonnement, ce qui la rendait fausse ici.
        $suivante = null;
        $refus = null;
        try {
            $suivante = $remises->generer($etablissement, $moisSuivant);
        } catch (UnprocessableEntityHttpException $e) {
            // Tout etait exclu : la remise est vide et le handler leve. C'est l'autre forme du
            // meme resultat, et le message doit nommer la meme cause.
            $refus = $e->getMessage();
        }

        $motif = $refus ?? ($suivante?->getMotifExclusion() ?? '');
        self::assertStringContainsString(
            'mandat non actif',
            $motif,
            sprintf(
                'Apres resiliation, la remise du cycle suivant ne mentionne pas le mandat : %d '
                .'transaction(s), %d ecartee(s), motif « %s ». Un adherent qui a retire son '
                .'autorisation est donc toujours preleve.',
                $suivante?->getNbTxs() ?? 0,
                $suivante?->getNbExclues() ?? 0,
                $motif === '' ? 'aucun' : $motif,
            ),
        );

        if ($suivante !== null) {
            self::assertGreaterThan(
                0,
                $suivante->getNbExclues(),
                'La remise nomme le mandat mais elle n’écarte rien : le compteur et le motif se contredisent.',
            );
        }
    }

    private function unBeneficiaireSansAbonnement(EntityManagerInterface $em): Beneficiaire
    {
        foreach ($em->getRepository(Beneficiaire::class)->findAll() as $b) {
            \assert($b instanceof Beneficiaire);
            if ($b->getClient() instanceof Client) {
                return $b;
            }
        }

        self::fail('Aucun bénéficiaire rattaché à un client dans le jeu de démonstration.');
    }

    private function echeancesDues(EntityManagerInterface $em, Etablissement $etablissement, \DateTimeImmutable $dateExecution): int
    {
        return \count($em->getRepository(EcheanceSepa::class)->createQueryBuilder('e')
            ->join('e.abonnement', 'a')
            ->andWhere('IDENTITY(a.etablissement) = :etab')
            ->andWhere('e.statut = :av')
            ->andWhere('e.dateProgrammee <= :date')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('av', StatutEcheanceSepa::AVenir->value)
            ->setParameter('date', $dateExecution, 'date_immutable')
            ->getQuery()->getResult());
    }

    /**
     * Énonce le préavis qu'un prélèvement licite suppose déjà émis — même forme que
     * `RemiseSepaRecablageTest`, où sa raison d'être est expliquée en détail.
     */
    private function preavisPourToutesLesDues(EntityManagerInterface $em, Etablissement $etablissement, \DateTimeImmutable $dateExecution): void
    {
        $echeances = $em->getRepository(EcheanceSepa::class)->createQueryBuilder('e')
            ->join('e.abonnement', 'a')
            ->andWhere('IDENTITY(a.etablissement) = :etab')
            ->andWhere('e.statut = :av')
            ->andWhere('e.dateProgrammee <= :date')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('av', StatutEcheanceSepa::AVenir->value)
            ->setParameter('date', $dateExecution, 'date_immutable')
            ->getQuery()->getResult();

        foreach ($echeances as $echeance) {
            $mandat = $echeance->getAbonnement()?->getMandatSepa();
            if ($mandat === null) {
                continue;
            }
            $em->persist((new DebitPreNotification())
                ->setMandate($mandat)
                ->setOriginReference((string) $echeance->getId())
                ->setAmountCents($echeance->getMontantCentimes())
                ->setAnnouncedDueDate($echeance->getDateProgrammee())
                ->setSentAt($dateExecution->modify('-20 days'))
                ->setReason(PreNotificationReason::Schedule)
                ->setOutcome(NotificationOutcome::Envoyee));
        }

        $em->flush();
    }
}
