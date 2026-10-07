<?php

declare(strict_types=1);

namespace App\Tests\Recouvrement\Api;

use App\Compta\Entity\EcritureComptable;
use App\Recouvrement\Entity\IncidentImpaye;
use App\Membership\Entity\EcheanceSepa;
use App\Tests\Recouvrement\RecouvrementApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * G-5 / G-2 — régler un impayé fait entrer l'argent au grand livre.
 *
 * ⚠ CE QUE LA SUITE `Recouvrement` NE POUVAIT PAS VOIR. Ses tests vérifient que l'incident passe à
 * `resolu` et que l'accès se rouvre — ce qui était déjà vrai AVANT ce lot, alors même qu'aucune
 * écriture comptable n'existait. « Le dossier est réglé » et « l'argent est entré dans les comptes »
 * sont deux affirmations distinctes, et une seule des deux était vérifiée.
 */
final class SettleInvoiceOnIncidentResolvedTest extends RecouvrementApiTestCase
{
    /**
     * Le cas de TOUS les incidents ouverts avant la facturation des échéances — dont l'unique dossier
     * de la préproduction. Aucune pièce derrière eux, et l'argent rentre quand même.
     */
    public function testUnImpayeSansPieceEcritQuandMemeSonEncaissement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $incidentId = $this->creerIncident($client, $entete);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $avant = $this->nbEcrituresEncaissement($em);

        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/resoudre', $entete + [
            'json' => ['canal' => 'virement', 'moyenPaiement' => 'virement', 'reference' => 'VIR-77'],
        ]);
        self::assertResponseIsSuccessful();

        self::assertSame(
            $avant + 1,
            $this->nbEcrituresEncaissement($em),
            'Un impayé réglé écrit son encaissement au journal ENC, même sans facture derrière lui.',
        );

        $em->clear();
        $incident = $em->getRepository(IncidentImpaye::class)->find($incidentId);
        self::assertNotNull($incident);
        self::assertSame('virement', $incident->getMoyenResolution(), 'Le moyen déclaré est enregistré.');
        self::assertSame('VIR-77', $incident->getReferenceResolution());
        self::assertNotNull($incident->getResoluPar(), 'Le dossier doit dire QUI a déclaré le règlement.');
        self::assertNull($incident->getFactureOrigineRef(), 'Ce dossier n\'a aucune pièce — l\'écran doit le dire, pas l\'inventer.');
    }

    /**
     * ⚠ LE TÉMOIN QUI DISTINGUE « ÇA MARCHE » DE « ÇA ÉCRIT N'IMPORTE QUOI ». Sans lui, un listener qui
     * écrirait une écriture pour tout et n'importe quoi passerait le test ci-dessus. Ici on vérifie
     * qu'un incident NON résolu n'écrit rien du tout.
     */
    public function testUnIncidentNonResoluNEcritAucunEncaissement(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $avant = $this->nbEcrituresEncaissement($em);

        $this->creerIncident($client, $entete);

        self::assertSame(
            $avant,
            $this->nbEcrituresEncaissement($em),
            'Détecter un impayé n\'encaisse rien — seule sa résolution le fait.',
        );
    }

    /** Le refus du canal carte n'écrit rien non plus : un encaissement non confirmé n'est pas un encaissement. */
    public function testUnEncaissementRefuseNEcritRien(): void
    {
        [$client, $entete] = $this->adminSurA();
        $incidentId = $this->creerIncident($client, $entete);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $avant = $this->nbEcrituresEncaissement($em);

        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/resoudre', $entete + [
            'json' => ['canal' => 'app_1_clic', 'moyenPaiement' => 'cb'],
        ]);
        self::assertResponseStatusCodeSame(422);

        self::assertSame($avant, $this->nbEcrituresEncaissement($em));
    }

    private function nbEcrituresEncaissement(EntityManagerInterface $em): int
    {
        return (int) $em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(EcritureComptable::class, 'e')
            ->join('e.journal', 'j')
            ->where('j.code = :enc')
            ->setParameter('enc', 'ENC')
            ->getQuery()->getSingleScalarResult();
    }

    private function creerIncident(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete): string
    {
        $echeance = $this->premiereEcheanceContratDemo();
        \assert($echeance instanceof EcheanceSepa);
        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/simuler-rejet', $entete + [
            'json' => ['codeRetour' => 'AM04'],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }
}
