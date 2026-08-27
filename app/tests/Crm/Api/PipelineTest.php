<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Crm\Entity\Client as CrmClient;
use App\Crm\Entity\Opportunity;
use App\Crm\Enum\OpportunityStage;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Crm\CrmApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * LE PIPELINE — un écran livré sans test d'API, et cassé sur deux lignes pendant tout ce temps.
 *
 * `PipelineProvider` appelait `bcadd`, absent de l'image PHP du projet, et
 * `Connection::PARAM_STR_ARRAY`, supprimé en DBAL 4. Ni l'un ni l'autre ne lève à la lecture du
 * code : le premier fatalait dès qu'une affaire portait un montant, le second dès qu'une affaire
 * portait un devis. Les deux étaient les chemins normaux de l'écran, pas des cas limites.
 *
 * Ces deux tests n'ajoutent rien à la fonctionnalité. Ils empêchent de la reperdre en silence.
 *
 * > **Un appel qui n'existe pas se lit exactement comme un appel qui existe.**
 */
final class PipelineTest extends CrmApiTestCase
{
    /**
     * **Une affaire chiffrée se totalise** — le chemin que `bcadd` fatalait.
     */
    public function testLePipelineTotaliseLesAffairesChiffrees(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->affaire('60.00');
        $this->affaire('40.50');

        $reponse = $client->request('GET', '/api/crm/pipeline', $entete)->toArray();

        self::assertResponseIsSuccessful();
        // Les colonnes sont rendues en LISTE : leur ordre porte celui du tableau, pas leur cle.
        $aQualifier = array_values(array_filter(
            $reponse['colonnes'],
            static fn (array $colonne): bool => $colonne['etape'] === OpportunityStage::ToQualify->value,
        ));
        self::assertCount(1, $aQualifier);
        self::assertSame('100.50', $aQualifier[0]['montantTotal']);
    }

    /**
     * **Une affaire liée à un devis se lit** — le chemin que `PARAM_STR_ARRAY` fatalait.
     *
     * La référence est volontairement fantôme : le devis n'existe pas. C'est le cas le plus dur, et
     * c'est un cas réel — une référence libre (D2) ne garantit aucune cible.
     */
    public function testUneAffaireLieeAUnDevisNeCassePasLEcran(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->affaire('80.00', Uuid::v4());

        $client->request('GET', '/api/crm/pipeline', $entete);

        self::assertResponseIsSuccessful();
    }

    private function affaire(string $montant, ?Uuid $devis = null): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        /** @var Etablissement $etablissement */
        $etablissement = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        /** @var CrmClient $payeur */
        $payeur = $this->entite(CrmClient::class, ['email' => \App\Crm\DataFixtures\CrmFixtures::PAYEUR_EMAIL]);

        $em->persist(
            (new Opportunity())
                ->setEstablishment($etablissement)
                ->setCustomer($payeur)
                ->setTitle('Affaire de test')
                ->setStage(OpportunityStage::ToQualify)
                ->setEstimatedAmount($montant)
                ->setCommercialDocumentRef($devis)
        );
        $em->flush();
    }
}
