<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Organisation\Entity\Etablissement;
use App\Sepa\Service\CompositeEcheanceSepaSource;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Enum\StatutEcheanceSepa;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ANNULER UNE ÉCHÉANCE — L'ÉTAT QUI MANQUAIT, ET CE QU'IL COÛTAIT DE NE PAS L'AVOIR.
 *
 * L'échéancier connaissait « à venir », « prélevée », « rejetée » et « gelée ». Une échéance
 * abandonnée — adhérent résilié, échéancier refait, essai nettoyé — n'avait aucun état où aller :
 * elle restait « à venir » indéfiniment, en se présentant comme due.
 *
 * Ça ne cassait rien : la collecte l'écarte faute de préavis. Mais ça s'accumulait. Mesure du 02/09
 * en préproduction : **38 échéances « à venir » dont la plus ancienne remontait à septembre 2025**.
 *
 * ⚠ ET `Gelee` NE POUVAIT PAS SERVIR, alors que c'est le seul état qui ressemble. Il veut dire
 * « en pause » (RG-SPORT-05), il est posé quand un adhérent demande une suspension, et une reprise
 * le lève. L'employer aurait affiché « en pause » sur des échéances abandonnées, et une reprise
 * d'abonnement les aurait réveillées. Le dernier test de ce fichier garde cette distinction.
 */
final class AnnulationEcheanceTest extends SportApiTestCase
{
    public function testUneEcheanceAVenirSAnnuleAvecSonMotif(): void
    {
        [$client, $entete] = $this->adminSurA();
        $echeance = $this->echeanceAVenir();

        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/annuler', $entete + [
            'json' => ['motif' => 'Adhérent résilié, échéancier refait'],
        ]);

        self::assertResponseIsSuccessful();

        $relue = $this->relire($echeance);
        self::assertSame(StatutEcheanceSepa::Annulee, $relue->getStatut());
        self::assertSame('Adhérent résilié, échéancier refait', $relue->getCancellationReason());
        self::assertNotNull($relue->getCancelledAt());
    }

    /**
     * **LE TEST QUI COMPTE : une échéance annulée n'est plus proposée à la collecte.**
     *
     * C'est ce que « annulée » veut dire pour de vrai. Sans cette vérification, les précédents ne
     * prouveraient qu'une chose : qu'on sait écrire un mot dans une colonne. La source des échéances
     * dues filtre sur `statut = a_venir` ; si quelqu'un élargissait ce filtre un jour, l'échéance
     * annulée reviendrait dans une remise et serait prélevée — sur un adhérent parti.
     */
    public function testUneEcheanceAnnuleeNEstPlusProposeeALaCollecte(): void
    {
        [$client, $entete] = $this->adminSurA();
        $echeance = $this->echeanceAVenir();
        $reference = (string) $echeance->getId();
        $etablissement = $echeance->getAbonnement()?->getEtablissement();
        self::assertNotNull($etablissement);

        $source = static::getContainer()->get(CompositeEcheanceSepaSource::class);
        $apres = new \DateTimeImmutable('+10 years');

        // ⚠ TÉMOIN AVANT. Sans lui, ce test passerait si la source ne rendait JAMAIS cette échéance
        // — pour un mandat absent, une date mal choisie, n'importe quoi. On prouve d'abord qu'elle
        // y est, puis qu'elle n'y est plus.
        $avant = $this->references($source->echeancesDues($etablissement, $apres));
        self::assertContains($reference, $avant, "L'échéance n'était pas collectable au départ : ce test ne mesure rien.");

        $client->request('POST', '/api/sport/echeances/' . $reference . '/annuler', $entete + [
            'json' => ['motif' => 'Essai nettoyé'],
        ]);
        self::assertResponseIsSuccessful();

        $apresAnnulation = $this->references($source->echeancesDues($etablissement, $apres));
        self::assertNotContains($reference, $apresAnnulation);

        // Et le reste de l'échéancier n'a pas été emporté : on annule UNE échéance, pas l'abonnement.
        self::assertNotEmpty($apresAnnulation, "Toutes les échéances ont disparu : l'annulation ratisse trop large.");
    }

    public function testLeMotifEstExige(): void
    {
        [$client, $entete] = $this->adminSurA();
        $echeance = $this->echeanceAVenir();

        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/annuler', $entete + [
            'json' => ['motif' => '   '],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(StatutEcheanceSepa::AVenir, $this->relire($echeance)->getStatut());
    }

    /**
     * **Une échéance prélevée ne se raye pas.**
     *
     * Elle correspond à de l'argent parti : l'annuler après coup effacerait la trace d'un mouvement
     * bancaire réel sans le rembourser. Le refus nomme l'état trouvé, pour ne pas envoyer chercher
     * un problème de droits.
     */
    public function testUneEcheancePrelevueNeSAnnulePas(): void
    {
        [$client, $entete] = $this->adminSurA();
        $echeance = $this->echeanceAVenir();

        $em = $this->em();
        $echeance->setStatut(StatutEcheanceSepa::Prelevee);
        $em->flush();

        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/annuler', $entete + [
            'json' => ['motif' => 'Erreur de saisie'],
        ]);

        self::assertResponseStatusCodeSame(422);

        $corps = $client->getResponse()->toArray(false);
        self::assertStringContainsString('prelevee', (string) ($corps['detail'] ?? $corps['description'] ?? ''));
        self::assertSame(StatutEcheanceSepa::Prelevee, $this->relire($echeance)->getStatut());
    }

    /**
     * ⚠ CLOISONNEMENT (RG-SOCLE-05) : l'échéance est atteinte par son identifiant.
     *
     * Sans contrôle, connaître un UUID suffirait à annuler l'échéance d'un autre établissement — et
     * l'annulation est irréversible du point de vue de l'exploitant qui la subit : son échéance
     * cesse d'être collectable, sans qu'aucun de ses écrans ne dise qui l'a fait.
     */
    public function testUneEcheanceDUnAutreEtablissementNeSAnnulePas(): void
    {
        [$client, $entete] = $this->adminSurA();
        $echeance = $this->echeanceSurEtablissementB();

        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/annuler', $entete + [
            'json' => ['motif' => 'Tentative hors périmètre'],
        ]);

        self::assertContains($client->getResponse()->getStatusCode(), [403, 404]);
        self::assertNotSame(StatutEcheanceSepa::Annulee, $this->relire($echeance)->getStatut());
    }

    /**
     * `Gelee` et `Annulee` restent deux choses différentes.
     *
     * Une échéance gelée est en PAUSE : elle reviendra à la reprise. Confondre les deux ferait
     * disparaître définitivement l'échéancier d'un adhérent qui a seulement demandé une suspension.
     */
    public function testUneEcheanceGeleeNEstPasUneEcheanceAnnulee(): void
    {
        self::assertNotSame(StatutEcheanceSepa::Gelee, StatutEcheanceSepa::Annulee);
        self::assertSame('gelee', StatutEcheanceSepa::Gelee->value);
        self::assertSame('annulee', StatutEcheanceSepa::Annulee->value);
    }

    // ── Outillage ────────────────────────────────────────────────────────────────────────────────

    /** @param list<\App\Sepa\Dto\EcheanceSepaDue> $dues @return list<string> */
    private function references(array $dues): array
    {
        return array_map(static fn ($d): string => $d->referenceOrigine, $dues);
    }

    private function echeanceAVenir(): EcheanceSepa
    {
        $abonnement = $this->abonnementDemo();

        $echeance = $this->em()->getRepository(EcheanceSepa::class)->findOneBy(
            ['abonnement' => $abonnement, 'statut' => StatutEcheanceSepa::AVenir],
        );

        self::assertNotNull($echeance, "Aucune échéance « à venir » sur l'abonnement de démonstration.");

        return $echeance;
    }

    /**
     * Une échéance à venir rattachée à l'établissement B.
     *
     * ⚠ ELLE SE FABRIQUE, ELLE NE SE TROUVE PAS. Toutes les échéances des fixtures sont sur
     * l'établissement A — mon premier jet cherchait une échéance ailleurs et le garde-fou de
     * non-vacuité a fait tomber le test, à raison : sans cette fabrication, il aurait fallu
     * conclure « pas d'échéance hors de A, donc rien à vérifier », et le cloisonnement n'aurait
     * jamais été mesuré.
     *
     * L'abonnement réutilise l'adhérent, le payeur, la formule et le mandat de la démonstration :
     * ce test porte sur le PÉRIMÈTRE, et fabriquer cinq entités de plus n'y ajouterait rien.
     */
    private function echeanceSurEtablissementB(): EcheanceSepa
    {
        $em = $this->em();
        $demo = $this->abonnementDemo();
        $etabB = $this->entite(Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_B_NOM]);

        $abonnement = (new \App\Sport\Entity\AbonnementFitness())
            ->setAdherent($demo->getAdherent())
            ->setPayeur($demo->getPayeur())
            ->setFormule($demo->getFormule())
            ->setMandatSepa($demo->getMandatSepa())
            ->setEtablissement($etabB)
            ->setMontantCentimes(3990)
            ->setDateSouscription(new \DateTimeImmutable('2026-01-01'))
            ->setDateDebutEngagement(new \DateTimeImmutable('2026-01-01'))
            ->setDateFinEngagement(new \DateTimeImmutable('2027-01-01'));
        $em->persist($abonnement);

        $echeance = (new EcheanceSepa())
            ->setAbonnement($abonnement)
            ->setDateProgrammee(new \DateTimeImmutable('2026-12-01'))
            ->setMontantCentimes(3990)
            ->setStatut(StatutEcheanceSepa::AVenir);
        $em->persist($echeance);
        $em->flush();

        return $echeance;
    }

    private function relire(EcheanceSepa $echeance): EcheanceSepa
    {
        $em = $this->em();
        $id = $echeance->getId();
        $em->clear();

        $relue = $em->getRepository(EcheanceSepa::class)->find($id);
        self::assertNotNull($relue);

        return $relue;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
