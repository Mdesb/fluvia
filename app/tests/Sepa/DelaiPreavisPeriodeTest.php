<?php

declare(strict_types=1);

namespace App\Tests\Sepa;

use App\Sepa\Entity\ConfigCreancierSepa;
use App\Membership\Entity\Membership;
use App\Membership\Enum\MembershipPeriodicity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * UN PRÉAVIS PLUS LONG QUE LA PÉRIODE ÉCARTE CHAQUE ÉCHÉANCE — §8.5, D-1.
 *
 * La reconduction crée la première échéance neuve **une période** après la dernière. Si le délai de
 * préavis dépasse cette période, l'échéance tombe trop tôt pour être couverte, et
 * `GenerationRemiseHandler` l'écarte de la remise. ⚠ Elle n'est pas perdue — elle GLISSE. Aucun
 * montant ne manque, aucune erreur ne s'affiche ; simplement, aucun prélèvement ne part ce cycle-là.
 *
 * ── ⚠ LE PREMIER TEST DE CE FICHIER EST CELUI QUI AUTORISE, ET C'EST DÉLIBÉRÉ ───────────────────
 *
 * La borne est STRICTE : `reasonNotCovered` refuse quand `sentAt > executionDate - délai`. Un
 * préavis envoyé **exactement** `délai` jours avant est donc COUVERT.
 *
 * Un contrôle écrit avec `>=` refuserait cette configuration — qui marche — et **tous ses tests de
 * refus resteraient verts**, plus verts qu'avant. Seul le cas qu'il doit AUTORISER démasque un
 * contrôle trop large. C'est pour cela qu'il est écrit en premier, et qu'il ne doit jamais être
 * supprimé au motif qu'« il ne teste rien ».
 */
final class DelaiPreavisPeriodeTest extends SepaApiTestCase
{
    /** ⚠ L'ÉGALITÉ PASSE. Le seul test qui démasque un `>=` écrit à la place d'un `>`. */
    public function testUnDelaiEgalALaPeriodeEstAccepte(): void
    {
        $em = $this->em();
        $abonnement = $this->passerEnHebdomadaire($em);

        $config = $this->configDuSite($em, $abonnement);
        $config->setPreNotificationDelayDays(7); // exactement la période hebdomadaire
        // D115 : un délai hebdomadaire (<14 j) exige la clause de préavis réduit — un prélèvement
        // hebdomadaire impose de fait un délai <14. On la pose pour isoler ici la seule règle testée
        // (délai > période, la borne haute).
        $config->setPreavisReduitContractuel(true);

        self::assertSame(
            [],
            $this->fautesSur($config, 'preNotificationDelayDays'),
            'un préavis envoyé EXACTEMENT `délai` jours avant est couvert : `reasonNotCovered` refuse '
            . 'sur `>`, pas sur `>=`. Refuser ici casserait une configuration qui marche, et aucun '
            . 'test de refus ne le montrerait.',
        );
    }

    /** Un jour de plus, et chaque échéance glisse. */
    public function testUnDelaiPlusLongQueLaPeriodeEstRefuse(): void
    {
        $em = $this->em();
        $abonnement = $this->passerEnHebdomadaire($em);

        $config = $this->configDuSite($em, $abonnement);
        $config->setPreNotificationDelayDays(8);
        $config->setPreavisReduitContractuel(true); // <14 j : clause requise, pour isoler la borne « délai > période »

        self::assertCount(
            1,
            $this->fautesSur($config, 'preNotificationDelayDays'),
            'huit jours de préavis sur une période de sept : chaque échéance serait écartée de la remise',
        );
    }

    /**
     * ⚠ L'AUTRE CÔTÉ, ET AUCUN DES DEUX NE COUVRE L'AUTRE.
     *
     * Le premier attrape le créancier dont on allonge le délai ; celui-ci attrape le premier
     * abonnement hebdomadaire vendu sur un site déjà paramétré à 14 jours.
     */
    public function testUnAbonnementPlusCourtQueLePreavisEstRefuse(): void
    {
        $em = $this->em();
        $abonnement = $this->unAbonnement($em);
        $config = $this->configDuSite($em, $abonnement);
        $config->setPreNotificationDelayDays(14);
        $em->flush();

        $abonnement->setPeriodicite(MembershipPeriodicity::Hebdomadaire);

        self::assertCount(
            1,
            $this->fautesSur($abonnement, 'periodicite'),
            'sept jours de période sous un préavis de quatorze : chaque échéance serait écartée',
        );
    }

    /**
     * ⚠ LE TÉMOIN DE L'ÉTAT ACTUEL. 5 abonnements mensuels, 4 créanciers à 14 jours : rien ne doit
     * être signalé. S'il l'était, le contrôle refuserait la préproduction telle qu'elle tourne.
     */
    public function testLEtatActuelDuDepotNEstPasSignale(): void
    {
        $em = $this->em();
        $abonnement = $this->unAbonnement($em);
        $abonnement->setPeriodicite(MembershipPeriodicity::Mensuel);
        $em->flush();

        $config = $this->configDuSite($em, $abonnement);
        $config->setPreNotificationDelayDays(14);

        self::assertSame([], $this->fautesSur($config, 'preNotificationDelayDays'));
        self::assertSame([], $this->fautesSur($abonnement, 'periodicite'));
    }

    /** D115 : un délai sous 14 jours SANS clause contractuelle → refus. */
    public function testUnDelaiSousQuatorzeSansClauseEstRefuse(): void
    {
        $em = $this->em();
        $abonnement = $this->unAbonnement($em); // mensuel (28 j) : la borne haute ne se déclenche pas à 10
        $config = $this->configDuSite($em, $abonnement);
        $config->setPreNotificationDelayDays(10);
        // pas de clause : preavisReduitContractuel reste false

        self::assertCount(
            1,
            $this->fautesSur($config, 'preNotificationDelayDays'),
            'dix jours de préavis sans clause de préavis réduit : sous le minimum SEPA de 14 jours',
        );
    }

    /** D115 : un délai sous 14 jours AVEC la clause déclarée → accepté. */
    public function testUnDelaiSousQuatorzeAvecClauseEstAccepte(): void
    {
        $em = $this->em();
        $abonnement = $this->unAbonnement($em);
        $config = $this->configDuSite($em, $abonnement);
        $config->setPreNotificationDelayDays(10);
        $config->setPreavisReduitContractuel(true);

        self::assertSame(
            [],
            $this->fautesSur($config, 'preNotificationDelayDays'),
            'la clause de préavis réduit contractuel autorise un délai sous 14 jours',
        );
    }

    /**
     * Les messages de faute portant sur ce chemin, et eux seuls.
     *
     * ⚠ ON FILTRE PAR CHEMIN. L'entité de fixture peut porter d'autres manques ; les compter ferait
     * passer ces tests pour la mauvaise raison, dans les deux sens.
     *
     * @return list<string>
     */
    private function fautesSur(object $entite, string $chemin): array
    {
        /** @var ValidatorInterface $validateur */
        $validateur = static::getContainer()->get(ValidatorInterface::class);

        $messages = [];
        foreach ($validateur->validate($entite) as $faute) {
            if ($faute->getPropertyPath() === $chemin) {
                $messages[] = (string) $faute->getMessage();
            }
        }

        return $messages;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /**
     * Un abonnement fitness, créé si les fixtures n'en portent aucun.
     *
     * ⚠ AUCUNE FIXTURE N'EN CRÉE — mesuré : mon premier jet en cherchait un et les quatre tests ont
     * échoué sur le témoin, ce qui est exactement ce qu'un témoin doit faire. Les cinq abonnements
     * de la préproduction ont été créés par l'API, pas par un jeu de données.
     *
     * ⚠ Chaque pièce empruntée aux fixtures porte son propre témoin : sans elles, ce test ne
     * mesurerait rien et passerait en croyant le contrôle silencieux.
     */
    private function unAbonnement(EntityManagerInterface $em): Membership
    {
        $existants = $em->getRepository(Membership::class)->findAll();
        if ($existants !== []) {
            return $existants[0];
        }

        $config = $em->getRepository(ConfigCreancierSepa::class)->findOneBy([]);
        self::assertNotNull($config, 'témoin : une configuration créancier SEPA existe en fixtures');
        $etablissement = $config->getEtablissement();
        self::assertNotNull($etablissement, 'témoin : elle est rattachée à un établissement');

        $adherent = $em->getRepository(\App\Crm\Entity\Beneficiaire::class)->findOneBy([]);
        self::assertNotNull($adherent, 'témoin : un bénéficiaire existe en fixtures');

        $payeur = $em->getRepository(\App\Crm\Entity\Client::class)->findOneBy([]);
        self::assertNotNull($payeur, 'témoin : un client existe en fixtures');

        $formule = $em->getRepository(\App\Offre\Entity\Formule::class)->findOneBy([]);
        self::assertNotNull($formule, 'témoin : une formule existe en fixtures');

        $mandat = $em->getRepository(\App\Sepa\Entity\MandatSepa::class)->findOneBy([]);
        self::assertNotNull($mandat, 'témoin : un mandat SEPA existe en fixtures');

        $maintenant = new \DateTimeImmutable();
        $abonnement = (new Membership())
            ->setAdherent($adherent)
            ->setPayeur($payeur)
            ->setFormule($formule)
            ->setEtablissement($etablissement)
            ->setPeriodicite(MembershipPeriodicity::Mensuel)
            ->setDateSouscription($maintenant)
            ->setDateDebutEngagement($maintenant)
            ->setDateFinEngagement($maintenant->modify('+1 year'))
            ->setMandatSepa($mandat)
            ->setMontantCentimes(4900);

        $em->persist($abonnement);
        $em->flush();

        return $abonnement;
    }

    private function passerEnHebdomadaire(EntityManagerInterface $em): Membership
    {
        $abonnement = $this->unAbonnement($em);
        $abonnement->setPeriodicite(MembershipPeriodicity::Hebdomadaire);
        $em->flush();

        return $abonnement;
    }

    private function configDuSite(EntityManagerInterface $em, Membership $abonnement): ConfigCreancierSepa
    {
        $config = $em->getRepository(ConfigCreancierSepa::class)
            ->findOneBy(['etablissement' => $abonnement->getEtablissement()?->getId()]);
        self::assertNotNull(
            $config,
            'témoin : le site de cet abonnement porte bien une configuration créancier SEPA',
        );

        return $config;
    }
}
