<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeSupport;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Acces\AccesApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Validation d'un passage au tourniquet (US-L3-03, RG-ACC-01/02, CA-3/4/11) : accepté seulement si
 * droit valide + marges + anti-passback ; décompte crédit atomique ; anti-passback ; carte épuisée.
 */
final class ValidationPassageTest extends AccesApiTestCase
{
    public function testCa3PassageValideDecompteLeCreditEtEstHorodateMotive(): void
    {
        [$client, $entete] = $this->adminSurA();

        $debut = microtime(true);
        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            ],
        ]);
        $duree = microtime(true) - $debut;

        self::assertResponseIsSuccessful();
        $reponse = $client->getResponse()->toArray();
        self::assertSame('valide', $reponse['resultat']);
        self::assertNotEmpty($reponse['horodatage']);
        self::assertLessThan(1.0, $duree, 'La réponse doit être rendue en moins de 1 s (US-L3-03).');

        $droit = $this->entite(DroitAcces::class, []);
        self::assertSame(11, $droit->getCreditRestant(), 'Le crédit doit être décompté atomiquement (RG-ACC-02).');
    }

    public function testCa4ReScanAvantDelaiAntiPassbackRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $body = [
            'equipement' => '/api/equipements/' . $this->idEquipement(),
            'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
        ];

        $client->request('POST', '/api/acces/passages', $entete + ['json' => $body]);
        self::assertSame('valide', $client->getResponse()->toArray()['resultat']);

        // Re-scan immédiat du même support : refusé (anti-passback, défaut ~5 min).
        $client->request('POST', '/api/acces/passages', $entete + ['json' => $body]);
        $reponse = $client->getResponse()->toArray();
        self::assertSame('refuse', $reponse['resultat']);
        self::assertSame('anti_passback', $reponse['codeMotif']);
    }

    public function testCa11CarteEpuiseeRefusExplicteSansDecompteEtPropositionRecharge(): void
    {
        [$client, $entete] = $this->adminSurA();

        $base = new \DateTimeImmutable('2026-06-01T08:00:00+00:00');
        $equipement = '/api/equipements/' . $this->idEquipement();

        // Le droit de démonstration porte un crédit de 12 (carte 10=12, RG-M1-04/13) : 12 passages
        // valides espacés au-delà du délai anti-passback épuisent le crédit.
        for ($i = 0; $i < 12; ++$i) {
            $horodatage = $base->modify(sprintf('+%d seconds', $i * 301));
            $client->request('POST', '/api/acces/passages', $entete + [
                'json' => [
                    'equipement' => $equipement,
                    'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                    'horodatage' => $horodatage->format(DATE_ATOM),
                ],
            ]);
            self::assertSame('valide', $client->getResponse()->toArray()['resultat'], sprintf('Passage %d attendu valide.', $i));
        }

        $droit = $this->entite(DroitAcces::class, []);
        self::assertSame(0, $droit->getCreditRestant());

        // 13e passage : crédit à zéro → refus explicite « carte épuisée », sans décompte.
        $horodatage = $base->modify(sprintf('+%d seconds', 12 * 301));
        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => $equipement,
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                'horodatage' => $horodatage->format(DATE_ATOM),
            ],
        ]);
        $reponse = $client->getResponse()->toArray();
        self::assertSame('refuse', $reponse['resultat']);
        self::assertSame('credit_epuise', $reponse['codeMotif']);
        self::assertSame(['caisse', 'borne', 'app'], $reponse['propositionRecharge']);

        $droitApres = $this->entite(DroitAcces::class, []);
        self::assertSame(0, $droitApres->getCreditRestant(), 'Aucun décompte supplémentaire sur refus (CA-11).');
    }

    /**
     * Concurrence (§4.3, atomicité) : deux supports distincts appairés à un même droit à 1 crédit ;
     * le premier passage décompte, le second échoue (0 ligne affectée) → refus « carte épuisée »,
     * sans double décompte ni crédit négatif.
     */
    public function testConcurrenceDecrementAtomiqueEviteDoubleDecompte(): void
    {
        [$client, $entete] = $this->adminSurA();

        [$droit, $supportA, $supportB] = $this->creerDroitPartageAUnCredit();

        $equipement = '/api/equipements/' . $this->idEquipement();

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => $equipement, 'identifiantSupport' => $supportA->getIdentifiant()],
        ]);
        self::assertSame('valide', $client->getResponse()->toArray()['resultat']);

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => $equipement, 'identifiantSupport' => $supportB->getIdentifiant()],
        ]);
        $reponse = $client->getResponse()->toArray();
        self::assertSame('refuse', $reponse['resultat']);
        self::assertSame('credit_epuise', $reponse['codeMotif']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $droitFrais = $em->getRepository(DroitAcces::class)->find($droit->getId());
        self::assertSame(0, $droitFrais->getCreditRestant(), 'Le crédit ne doit jamais devenir négatif.');
    }

    /**
     * @return array{0: DroitAcces, 1: Support, 2: Support}
     */
    private function creerDroitPartageAUnCredit(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        $droit = new DroitAcces();
        $droit->setSourceType(TypeDroitAcces::CarteQuota)
            ->setCreditRestant(1)
            ->setStatutProjection(StatutProjectionDroit::Valide)
            ->setEtablissement($etab);
        $em->persist($droit);

        $supportA = (new Support())->setIdentifiant('CONC-A-' . substr((string) Uuid::v4(), 0, 8))->setType(TypeSupport::Qr)->setEtablissement($etab);
        $supportB = (new Support())->setIdentifiant('CONC-B-' . substr((string) Uuid::v4(), 0, 8))->setType(TypeSupport::Qr)->setEtablissement($etab);
        $em->persist($supportA);
        $em->persist($supportB);

        $em->persist((new Appairage())->setSupport($supportA)->setDroit($droit)->setMode(ModeAppairage::Caisse)->setActif(true)->setEtablissement($etab));
        $em->persist((new Appairage())->setSupport($supportB)->setDroit($droit)->setMode(ModeAppairage::Caisse)->setActif(true)->setEtablissement($etab));

        $em->flush();

        return [$droit, $supportA, $supportB];
    }
}
