<?php

declare(strict_types=1);

namespace App\Facturation\Einvoicing;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\RegieRecettes;
use App\Dms\DocumentStore;
use App\Facturation\Entity\Facture;

/**
 * THE ISSUER AS IT WAS WHEN THE INVOICE WAS ISSUED (#345).
 *
 * The PDF header and the Factur-X seller party used to read the LIVE `ProfilExploitant`. Reprinting
 * an invoice issued in March, after the operator moved in June, printed the June address on a March
 * invoice: a document that claims to be the original and is not. An issued invoice is immutable
 * (`FactureInalterableListener`); who issued it is part of what it says.
 *
 * `capture()` is called once, by `ScellementFactureHandler::sceller()` — the single point every
 * issuance goes through (direct invoice, receipt invoice, credit note). The result is stored on the
 * invoice (`Facture::$emetteurFige`), and the inalterability guard refuses any later change to it.
 *
 * ⚠ THE LOGO IS FROZEN BY VERSION, NOT BY BYTES. Each upload stores a new DMS document, and the DMS
 * keeps every version: the document id + version id are enough to read back the exact image printed
 * at issuance, without copying a JPEG into a JSON column.
 *
 * ⚠ INVOICES ISSUED BEFORE THIS CHANGE have no snapshot. Their original issuer cannot be
 * reconstructed, so `fromInvoice()` returns null and readers fall back to the live profile — the
 * previous behaviour, stated rather than invented.
 */
final class InvoiceIssuerSnapshot
{
    public function __construct(private readonly DocumentStore $documents)
    {
    }

    /**
     * @return array<string, mixed>|null null when the invoice has no operator profile
     */
    public function capture(Facture $facture): ?array
    {
        $profil = $facture->getProfilExploitant();
        if (!$profil instanceof ProfilExploitant) {
            return null;
        }

        $etablissement = $facture->getEtablissement() ?? $profil->getEtablissement();
        $pays = $etablissement?->getPays();

        return [
            'raisonSociale' => $profil->getRaisonSociale(),
            'siren' => $profil->getSiren(),
            'siret' => $profil->getSiret(),
            'tva' => $profil->getTvaIntracommunautaire(),
            'adresse' => $profil->getAdresse(),
            'adresseElectronique' => $profil->getElectronicAddress(),
            // The issuing establishment's country, or null when unknown: each reader keeps its own
            // fallback (the PDF assumes the address country, the XML declares no registry).
            'pays' => \is_string($pays) && $pays !== '' ? strtoupper($pays) : null,
            'logo' => $this->logo($profil),
            'regie' => $this->regie($facture),
            'figeLe' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * The frozen issuer of an issued invoice, or null when it was issued before snapshots existed.
     *
     * @return array<string, mixed>|null
     */
    public static function fromInvoice(Facture $facture): ?array
    {
        return $facture->getEmetteurFige();
    }

    /** @return array{document: string, version: string, mime: string}|null */
    private function logo(ProfilExploitant $profil): ?array
    {
        $document = $profil->getLogoDocumentId();
        if ($document === null) {
            return null;
        }
        $version = $this->documents->currentVersion($document);
        if ($version === null) {
            return null;
        }

        return [
            'document' => $document->toRfc4122(),
            'version' => $version->versionId->toRfc4122(),
            'mime' => $version->mimeType,
        ];
    }

    /** @return array{libelle: string, acte: string|null}|null */
    private function regie(Facture $facture): ?array
    {
        // Same rule as the renderer: the cash office comes from the sale's counter, never guessed.
        $regie = $facture->getVenteOrigine()?->getPointDeVente()?->getRegie();
        if (!$regie instanceof RegieRecettes) {
            return null;
        }

        return ['libelle' => $regie->getLibelle(), 'acte' => $regie->getActeNomination()];
    }
}
