<?php

declare(strict_types=1);

namespace App\Tests\Vente\Unit;

use App\Vente\Service\TicketPdfRenderer;
use PHPUnit\Framework\TestCase;

/**
 * LE TICKET IMPRIMÉ — ce qu'il doit dire, et ce qu'il ne doit surtout pas dire.
 *
 * Les assertions portent sur le HTML, pas sur les octets du PDF : un PDF Dompdf est un flux
 * compressé, y chercher une chaîne ne prouve rien, et un test qui se contente de « ça commence par
 * %PDF » laisserait passer un ticket dont toutes les mentions ont disparu.
 */
final class TicketPdfRendererTest extends TestCase
{
    /** Un duplicata le dit — sans ça, un second papier se fait passer pour l'original. */
    public function testUnDuplicataPorteLaMention(): void
    {
        self::assertStringContainsString('DUPLICATA', $this->html(['duplicata' => true]));
    }

    /** Et le témoin de ce que le rendu ÉPARGNE : un original ne porte pas la mention. */
    public function testUnOriginalNePorteAucuneMentionDeDuplicata(): void
    {
        self::assertStringNotContainsString('DUPLICATA', $this->html(['duplicata' => false]));
    }

    /** Une ventilation incomplète est ÉCRITE, avec ce qu'elle n'a pas su ventiler. */
    public function testUneVentilationIncompleteEstImprimee(): void
    {
        $html = $this->html([
            'vat' => [
                'breakdown' => [],
                'withoutRate' => ['lines' => 2, 'grossAmount' => '57.00'],
                'complete' => false,
            ],
        ]);

        self::assertStringContainsString('VENTILATION INCOMPLETE', $html);
        self::assertStringContainsString('2 ligne(s) sans taux', $html);
        self::assertStringContainsString('57,00 EUR', $html);
    }

    /**
     * ⚠ LE TÉMOIN QUI VALIDE L'AVERTISSEMENT : il disparaît quand tout est ventilé.
     *
     * Un avertissement qui s'imprime toujours ne signale rien — on apprend à le sauter, et le jour
     * où il dit vrai personne ne le lit. C'est ce test-là qui prouve qu'il discrimine.
     */
    public function testUneVentilationCompleteNAvertitDeRien(): void
    {
        $html = $this->html([
            'vat' => [
                'breakdown' => [[
                    'rate' => '10.00',
                    'netAmount' => '40.91',
                    'vatAmount' => '4.09',
                    'grossAmount' => '45.00',
                ]],
                'withoutRate' => ['lines' => 0, 'grossAmount' => '0.00'],
                'complete' => true,
            ],
        ]);

        self::assertStringNotContainsString('INCOMPLETE', $html);
        self::assertStringContainsString('10.00% sur 40,91 EUR', $html);
        self::assertStringContainsString('4,09 EUR', $html);
    }

    /** L'identité du vendeur manque au modèle : le papier le dit au lieu de l'omettre. */
    public function testLIdentiteDuVendeurManquanteEstEcriteSurLePapier(): void
    {
        self::assertStringContainsString('VENDEUR NON RENSEIGNE', $this->html([]));
    }

    /**
     * ⚠ LE TEST LE PLUS IMPORTANT DU FICHIER : on n'écrit PAS « certifié NF525 ».
     *
     * La mention est obligatoire pour un logiciel certifié. Nous ne le sommes pas — l'auto-attestation
     * a été supprimée par la loi de finances 2025 puis rétablie par celle de 2026, et rien n'est
     * tranché. L'imprimer serait une affirmation fausse sur un document opposable, c'est-à-dire
     * exactement ce qu'un contrôle cherche. Ce test existe pour que personne ne l'ajoute « pour faire
     * pro » sans que ça se voie.
     */
    public function testLeTicketNAffirmeAucuneCertification(): void
    {
        $html = $this->html([
            'vat' => ['breakdown' => [], 'withoutRate' => ['lines' => 0, 'grossAmount' => '0.00'], 'complete' => true],
        ]);

        self::assertStringNotContainsString('NF525', $html);
        self::assertStringNotContainsString('certifi', $html);
    }

    /**
     * Le libellé est un tableau traduisible : écrit tel quel, il imprimerait « Array ».
     *
     * Le français est pris quand il existe. La langue de l'établissement le remplacera quand le champ
     * existera — il n'existe pas, et on ne la déduit pas du pays.
     */
    public function testUnLibelleTraduisibleSortEnTexteEtJamaisEnArray(): void
    {
        $html = $this->html([
            'lignes' => [[
                'libelle' => ['fr' => 'Carte 10=12 piscine', 'en' => 'Pool card'],
                'quantite' => 2,
                'montantLigne' => '90.00',
            ]],
        ]);

        self::assertStringContainsString('Carte 10=12 piscine', $html);
        self::assertStringNotContainsString('Array', $html);
        self::assertStringContainsString('2 x', $html);
        self::assertStringContainsString('90,00 EUR', $html);
    }

    /** Aucune traduction : un tiret, jamais un nom de remplacement qui ferait document complet. */
    public function testUnLibelleAbsentDonneUnTiretPasUnNomInvente(): void
    {
        $html = $this->html(['lignes' => [['libelle' => null, 'quantite' => 1, 'montantLigne' => '5.00']]]);

        self::assertStringContainsString('—', $html);
        self::assertStringNotContainsString('Produit', $html);
    }

    /** Et le PDF sort bien — le format, pas le contenu, qui se prouve sur le HTML. */
    public function testLeRenduProduitUnPdf(): void
    {
        $pdf = (new TicketPdfRenderer())->render($this->document([]));

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(500, \strlen($pdf), 'Un PDF de quelques octets serait une page vide.');
    }

    /** @param array<string, mixed> $surcharges */
    private function html(array $surcharges): string
    {
        return (new TicketPdfRenderer())->html($this->document($surcharges));
    }

    /**
     * @param array<string, mixed> $surcharges
     *
     * @return array<string, mixed>
     */
    private function document(array $surcharges): array
    {
        return $surcharges + [
            'vente' => '11111111-1111-1111-1111-111111111111',
            'numero' => 'V-2026-000042',
            'date' => '2026-09-08T10:22:01+02:00',
            'lignes' => [[
                'libelle' => ['fr' => 'Entree piscine'],
                'quantite' => 1,
                'montantLigne' => '45.00',
            ]],
            'total' => '45.00',
            'totalRemises' => '0.00',
            'vat' => [
                'breakdown' => [],
                'withoutRate' => ['lines' => 1, 'grossAmount' => '45.00'],
                'complete' => false,
            ],
            'duplicata' => false,
        ];
    }
}
