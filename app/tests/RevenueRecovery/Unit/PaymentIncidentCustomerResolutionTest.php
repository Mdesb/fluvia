<?php

declare(strict_types=1);

namespace App\Tests\RevenueRecovery\Unit;

use App\Crm\Entity\Client;
use App\Crm\Recouvrement\ClientDebtorName;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Recouvrement\Entity\IncidentImpaye;
use App\RevenueRecovery\Service\RecoverySubjectCustomerResolver;
use App\Tests\RevenueRecovery\RevenueRecoveryApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * UN IMPAYÉ DÉSIGNE ENFIN SON CLIENT (lot du 06/09).
 *
 * ── CE QUE LA MESURE A MONTRÉ ───────────────────────────────────────────────────────────────────
 *
 * Sur les quatre déclencheurs réellement émis, deux portent `EventSubject('Reservation', …)` et deux
 * portent `EventSubject('PaymentIncident', …)`. Le résolveur ne traitait que le premier type : un
 * impayé ouvrait son dossier, programmait ses relances, et chacune finissait « ignorée » faute de
 * client identifiable.
 *
 * Et ce sont précisément les deux déclencheurs de paiement qui portent une base légale
 * CONTRACTUELLE — la relance d'impayé, celle qui donne son nom au module, était la seule à ne pas
 * pouvoir partir.
 *
 * ── POURQUOI CE TEST ET PAS SEULEMENT L'ANCIEN ──────────────────────────────────────────────────
 *
 * `RecoveryEngineTest` couvre déjà le cas NÉGATIF : un `PaymentIncident` introuvable rend `null`, et
 * il continue de le faire — son identifiant est tiré au hasard. Mais un résolveur ne se prouve pas
 * par ce qu'il refuse : sans témoin POSITIF, la branche neuve pourrait rendre `null` en toutes
 * circonstances et les deux suites resteraient vertes.
 */
final class PaymentIncidentCustomerResolutionTest extends RevenueRecoveryApiTestCase
{
    public function testUnImpayeRendLIdentifiantDeSonRedevable(): void
    {
        $etablissement = $this->entity(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $client = $this->em()->getRepository(Client::class)->findOneBy(['etablissementCreation' => $etablissement]);
        self::assertInstanceOf(Client::class, $client, 'Le montage suppose un client sur l’établissement A.');

        $incident = $this->incident($etablissement, ClientDebtorName::TYPE, (string) $client->getId());

        self::assertSame(
            (string) $client->getId(),
            (string) $this->resolveur()->resolveCustomerId('PaymentIncident', (string) $incident->getId(), $etablissement->getId()),
            'Un impayé doit désigner le client de son mandat, sinon aucune relance d’impayé ne part.',
        );
    }

    /**
     * ⚠ UN AUTRE TYPE DE REDEVABLE NE DOIT PAS RENDRE SA RÉFÉRENCE.
     *
     * Aujourd'hui tout rejet SEPA produit `crm.client`, mais le champ est une chaîne libre. Le jour
     * où un redevable d'un autre type apparaît, sa référence ne désigne pas un client — et l'écrire
     * enverrait un courriel à quelqu'un d'autre. C'est le cas qu'on n'aurait pas pensé à écrire.
     */
    public function testUnRedevableDUnAutreTypeNeDesignePersonne(): void
    {
        $etablissement = $this->entity(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $incident = $this->incident($etablissement, 'compta.tiers', (string) Uuid::v4());

        self::assertNull(
            $this->resolveur()->resolveCustomerId('PaymentIncident', (string) $incident->getId(), $etablissement->getId()),
            'Une référence qui n’est pas celle d’un client ne doit désigner personne.',
        );
    }

    /**
     * RG-RR-07 : un impayé d'un autre établissement est traité comme introuvable.
     *
     * Sans ce cas, la branche neuve serait la seule lecture cross-module du module à ne pas
     * revérifier son périmètre.
     */
    public function testUnImpayeHorsPerimetreEstTraiteCommeIntrouvable(): void
    {
        $etablissementA = $this->entity(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $etablissementB = $this->entity(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);
        $client = $this->em()->getRepository(Client::class)->findOneBy(['etablissementCreation' => $etablissementA]);
        self::assertInstanceOf(Client::class, $client);

        $incident = $this->incident($etablissementB, ClientDebtorName::TYPE, (string) $client->getId());

        self::assertNull(
            $this->resolveur()->resolveCustomerId('PaymentIncident', (string) $incident->getId(), $etablissementA->getId()),
            'Un impayé de B ne doit rien rendre à un dossier de A.',
        );
    }

    private function resolveur(): RecoverySubjectCustomerResolver
    {
        /** @var RecoverySubjectCustomerResolver $resolveur */
        $resolveur = static::getContainer()->get(RecoverySubjectCustomerResolver::class);

        return $resolveur;
    }

    private function incident(Etablissement $etablissement, string $typeRedevable, string $reference): IncidentImpaye
    {
        $incident = (new IncidentImpaye())
            ->setEtablissement($etablissement)
            ->setTypeRedevable($typeRedevable)
            ->setReferenceRedevable($reference)
            ->setMontantCentimes(4500)
            // `dateRejet` est typée non nulle et SANS valeur par défaut : l'omettre fait échouer
            // l'écriture, pas la lecture — donc loin d'ici, sur un message qui n'en parlerait pas.
            ->setDateRejet(new \DateTimeImmutable('-2 days'))
            ->setMotifBancaire('AM04');

        $this->em()->persist($incident);
        $this->em()->flush();

        return $incident;
    }
}
