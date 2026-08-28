<?php

declare(strict_types=1);

namespace App\Tests\SmartFlow\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Ressource;
use App\SmartFlow\Entity\SlotWaitlistEntry;
use App\Tests\SmartFlow\SmartFlowApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * LE RANG D'UNE FILE D'ATTENTE — le seul chiffre qui la rende utile.
 *
 * `SlotWaitlistPromotionTest` vérifie la promotion FIFO, mais il POSE les rangs à la main : il
 * n'exerce jamais leur ATTRIBUTION. Le 28/08, cette attribution était cassée depuis le durcissement
 * de cohérence du lot précédent — `MAX(e.rank)` filtré sur `e.establishment = :establishment` avec
 * l'ENTITÉ ne trouvait rien, rendait 0, et **tout le monde était premier**.
 *
 * Le durcissement était juste dans son intention ; c'est sa liaison de paramètre qui a tout annulé.
 * Rien ne l'a signalé : la requête ne lève pas, elle compte zéro.
 *
 * > **Un chemin nominal que personne ne parcourt en test est un chemin qu'on découvre en production.**
 */
final class SlotWaitlistRangTest extends SmartFlowApiTestCase
{
    public function testChaqueInscritRecoitLeRangSuivantEtNonLePremier(): void
    {
        [$client, $entete] = $this->managerOn(SocleFixtures::ETAB_A_NOM);
        $ressource = $this->ressourceSurA();

        $premier = $this->inscrire($client, $entete, $ressource, $this->idBeneficiairePayeur());
        $second = $this->inscrire($client, $entete, $ressource, $this->idBeneficiairePayeur());

        self::assertSame(1, $premier, 'Le premier inscrit ouvre la file.');
        self::assertSame(
            2,
            $second,
            'Le second doit passer APRÈS. Deux rangs 1 ne font pas une file d’attente : ils font '
            . 'un tas, et la promotion FIFO n’a plus rien à ordonner.',
        );
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return int le rang attribué
     */
    private function inscrire(object $client, array $entete, Uuid $ressource, string $beneficiaire): int
    {
        $reponse = $client->request('POST', '/api/smart-flow/waitlist-entries', $entete + [
            'json' => [
                'resourceId' => (string) $ressource,
                'beneficiaryId' => $beneficiaire,
                'searchWindowStart' => (new \DateTimeImmutable('+1 day'))->format(\DATE_ATOM),
                'searchWindowEnd' => (new \DateTimeImmutable('+8 days'))->format(\DATE_ATOM),
            ],
        ]);

        self::assertResponseIsSuccessful();
        $id = $reponse->toArray()['id'] ?? null;
        self::assertNotNull($id);

        $entree = $this->em()->getRepository(SlotWaitlistEntry::class)->find($id);
        self::assertInstanceOf(SlotWaitlistEntry::class, $entree);

        return $entree->getRank();
    }

    private function ressourceSurA(): Uuid
    {
        $em = $this->em();
        /** @var Etablissement $etablissement */
        $etablissement = $em->getRepository(Etablissement::class)
            ->find(Uuid::fromString($this->establishmentId(SocleFixtures::ETAB_A_NOM)));

        $ressource = (new Ressource())
            ->setEtablissement($etablissement)
            ->setCodeType('court')
            ->setLibelle('Court d’essai — file d’attente')
            ->setCapacitePropre(1);

        $em->persist($ressource);
        $em->flush();

        return $ressource->getId();
    }
}
