<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Facturation\Entity\Facture;
use App\Facturation\Entity\ReglementFacture;
use App\Facturation\Service\InvoiceSettlementCorrectionHandler;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * CORRIGER LE MOYEN DE PAIEMENT D'UNE FACTURE, SANS RIEN EFFACER.
 *
 * Demandé par Maxime : « on fait moins sur le moyen de paiement initial et plus sur le nouveau moyen
 * de paiement […] un bouton corriger le paiement, et automatiquement les écritures qui vont avec ».
 *
 * ⚠ C'EST LA DOCTRINE D45, ET ELLE INTERDIT LA SOLUTION ÉVIDENTE. Un règlement encaissé puis effacé
 * laisse une caisse qui ne tombe plus juste, et rien n'explique l'écart. La correction est donc une
 * écriture compensatoire datée du JOUR DU GESTE — jamais une modification de la saisie d'origine.
 *
 * ⚠ LE TROISIÈME TEST EST CELUI QU'ON OUBLIE, et c'est le seul qui protège l'argent. Sans lui, deux
 * corrections successives déplaceraient plus que le règlement d'origine : on créditerait la carte
 * depuis des espèces qui n'ont jamais été encaissées.
 */
final class CorrectionReglementFactureTest extends FacturationApiTestCase
{
    /**
     * DEUX LIGNES, ET LE SOLDE NE BOUGE PAS.
     *
     * C'est tout l'intérêt de la compensation : le total réglé est inchangé, donc `soldeDu` reste
     * exact sans qu'aucun calcul ne soit recopié. L'automatisme demandé sort de là.
     */
    public function testLaCorrectionProduitDeuxLignesEtLaisseLeSoldeInchange(): void
    {
        [$facture, $handler] = $this->factureRegleeEnVirement('120.00');

        self::assertSame('0.00', $facture->getSoldeDu(), 'témoin : la facture part soldée');

        $handler->corriger($facture, [
            'moyenDebite' => 'virement',
            'moyenCredite' => 'cb',
            'montant' => '120.00',
            'motif' => 'Encaissé par carte, saisi en virement',
        ], null);

        self::assertSame('0.00', $facture->getSoldeDu(), 'la compensation laisse le solde exact');
        self::assertSame('120.00', $facture->getMontantRegle(), 'le total réglé est inchangé');

        $parMoyen = $this->totauxParMoyen($facture);
        self::assertSame('0.00', $parMoyen['virement'], 'le moyen d’origine est ramené à zéro, sans être effacé');
        self::assertSame('120.00', $parMoyen['cb'], 'le moyen réel porte désormais le règlement');

        self::assertCount(3, $facture->getReglements(), 'la saisie d’origine est CONSERVÉE : trois lignes, pas une réécrite');
    }

    /**
     * LE MOTIF EST OBLIGATOIRE.
     *
     * Ce geste déplace de l'argent entre deux moyens. Sans motif il est indéfendable en contrôle, et
     * six mois plus tard personne ne sait pourquoi la carte porte ce que le virement portait.
     */
    public function testUneCorrectionSansMotifEstRefusee(): void
    {
        [$facture, $handler] = $this->factureRegleeEnVirement('120.00');

        $this->expectException(UnprocessableEntityHttpException::class);
        $handler->corriger($facture, [
            'moyenDebite' => 'virement',
            'moyenCredite' => 'cb',
            'montant' => '120.00',
            'motif' => '   ',
        ], null);
    }

    /**
     * ⚠ DEUX CORRECTIONS NE DÉPLACENT PAS PLUS QUE CE QUI A ÉTÉ ENCAISSÉ.
     *
     * C'est le contrôle qu'on oublie, et le seul qui empêche de fabriquer de l'argent : le montant
     * disponible est le NET des corrections déjà passées, pas le règlement initial.
     *
     * Le premier appel sert de témoin — s'il échouait, le second refus ne prouverait rien.
     */
    public function testUneSecondeCorrectionNePeutPasDeplacerCeQuiEstDejaDeplace(): void
    {
        [$facture, $handler] = $this->factureRegleeEnVirement('120.00');

        $handler->corriger($facture, [
            'moyenDebite' => 'virement',
            'moyenCredite' => 'cb',
            'montant' => '120.00',
            'motif' => 'Première correction',
        ], null);
        self::assertSame('0.00', $this->totauxParMoyen($facture)['virement'], 'témoin : le virement est à zéro');

        $this->expectException(UnprocessableEntityHttpException::class);
        $handler->corriger($facture, [
            'moyenDebite' => 'virement',
            'moyenCredite' => 'especes',
            'montant' => '120.00',
            'motif' => 'Seconde correction sur un virement déjà repris',
        ], null);
    }

    // ── Montage ──────────────────────────────────────────────────────────────────────────────────

    /** @return array{0: Facture, 1: InvoiceSettlementCorrectionHandler} */
    private function factureRegleeEnVirement(string $montant): array
    {
        [$client, $entete] = $this->adminSurA();

        $brouillon = $client->request('POST', '/api/factures', $entete + [
            'json' => $this->corpsFactureDirecte(100.0),
        ])->toArray();
        $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete);
        $client->request('POST', '/api/factures/' . $brouillon['id'] . '/reglements', $entete + [
            'json' => ['montant' => $montant, 'moyen' => 'virement', 'reference' => 'VIR-001'],
        ]);
        self::assertResponseIsSuccessful();

        $em = $this->em();
        $em->clear();

        $facture = $em->getRepository(Facture::class)->find($brouillon['id']);
        self::assertInstanceOf(Facture::class, $facture);

        // ⚠ CONSTRUIT DIRECTEMENT, ET C'EST UN SIGNAL PLUS QU'UNE COMMODITÉ.
        //
        // Le conteneur a ÉLIMINÉ ce service à la compilation : rien ne l'injecte encore, puisque
        // l'opération d'API attend son écran. Aller le chercher par le conteneur échouerait donc, et
        // masquerait la vraie information — le mécanisme est prêt et personne ne l'appelle.
        //
        // Sa seule dépendance est le gestionnaire d'entités : l'instancier ici n'invente rien.
        $handler = new InvoiceSettlementCorrectionHandler($em);

        return [$facture, $handler];
    }

    /** @return array<string, string> total réglé par moyen, corrections comprises */
    private function totauxParMoyen(Facture $facture): array
    {
        $centimes = [];
        foreach ($facture->getReglements() as $reglement) {
            \assert($reglement instanceof ReglementFacture);
            $moyen = $reglement->getMoyen();
            $centimes[$moyen] = ($centimes[$moyen] ?? 0) + (int) round(((float) $reglement->getMontant()) * 100);
        }

        return array_map(
            static fn (int $c): string => number_format($c / 100, 2, '.', ''),
            $centimes,
        );
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
