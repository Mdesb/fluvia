<?php

declare(strict_types=1);

namespace App\Facturation\Einvoicing;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\RegieRecettes;
use App\Dms\DocumentStore;
use App\I18n\Locales;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\LigneFacture;
use Twig\Environment;

/**
 * LE RENDU VISUEL D'UNE FACTURE — la moitié que l'humain lit.
 *
 * Factur-X est un couple : un PDF lisible par une personne, et le XML qu'une machine dépouille. Le
 * XML a son sérialiseur ; il manquait un point d'entrée pour la première moitié qui parte d'une
 * `Facture` plutôt que d'une `Vente`.
 *
 * ── ⚠ POURQUOI CETTE CLASSE PLUTÔT QU'UNE RECOPIE ──────────────────────────────────────────────
 *
 * `Boutique\Billet\GenerateurPdfFacture` construisait déjà ce contexte, mais à partir d'une `Vente`.
 * Or une facture directe n'a pas de vente : la commande d'émission ne pouvait pas l'appeler.
 *
 * Recopier les dix lignes de correspondance aurait créé deux vérités sur le même gabarit — et le
 * jour où l'une gagne une colonne, l'autre l'ignore en silence. C'est exactement la forme des
 * défauts que ce dépôt collectionne. Le générateur de `Boutique` appelle donc cette classe.
 *
 * ⚠ ELLE NE SAIT RIEN DE FACTUR-X, ET C'EST VOULU. Elle rend du HTML. C'est `FacturXAssembler` qui
 * décide d'en faire un PDF/A-3 avec un XML dedans. Mélanger les deux ferait dépendre le rendu
 * ordinaire d'une contrainte d'archivage qui ne le concerne pas.
 *
 * ── L'ÉMETTEUR, QUI MANQUAIT AU PDF (#345) ─────────────────────────────────────────────────────
 *
 * Jusqu'au 05/11/2026, le PDF n'affichait que le numéro, la date et le destinataire : rien ne disait
 * QUI facturait, alors que le XML embarqué portait déjà raison sociale, SIREN et adresse. L'en-tête
 * lit désormais le même `ProfilExploitant` que `CiiSerializer` — une seule source pour les deux
 * moitiés, qui ne peuvent donc pas se contredire — plus son logo et la régie qui a encaissé.
 *
 * ⚠ LA RÉGIE VIENT DU GUICHET DE LA VENTE, JAMAIS DU PROFIL. Un profil peut tenir plusieurs régies
 * (piscine et patinoire) : choisir parmi elles serait une devinette (voir `PointDeVente::$regie`).
 * Une facture sans vente de guichet, ou un guichet sans régie, n'affiche donc aucune régie.
 */
final class InvoiceHtmlRenderer
{
    private const GABARIT = 'boutique/billet/facture_pdf.html.twig';

    /**
     * Ce que Dompdf dessine SANS l'extension GD, absente de l'image de production — le même filtre que
     * `UploadOperatorLogoProcessor` à l'entrée. ⚠ Un PNG passé ici ferait LEVER le rendu de toute la
     * facture (`Cpdf::addPngFromFile`) : on l'écarte, et la facture part sans logo plutôt que pas du tout.
     */
    private const LOGO_TYPES = ['image/jpeg'];

    public function __construct(
        private readonly Environment $twig,
        private readonly DocumentStore $documents,
    ) {
    }

    public function render(Facture $facture): string
    {
        $lignes = [];
        foreach ($facture->getLignes() as $ligne) {
            \assert($ligne instanceof LigneFacture);
            $lignes[] = [
                'designation' => $ligne->getDesignation(),
                'quantite' => $ligne->getQuantite(),
                'prixUnitaireHT' => $ligne->getPrixUnitaireHT(),
                'montantTTC' => $ligne->getMontantTTC(),
            ];
        }

        return $this->twig->render(self::GABARIT, [
            'locale' => Locales::ofEstablishment($facture->getEtablissement()),
            'numero' => $facture->getNumero(),
            'dateEmission' => $facture->getDateEmission(),
            'destinataire' => $facture->getDestinataire()?->denomination(),
            'emetteur' => $this->emetteur($facture),
            'regie' => $this->regie($facture),
            'lignes' => $lignes,
            'totalHT' => $facture->getTotalHT(),
            'totalTVA' => $facture->getTotalTVA(),
            'totalTTC' => $facture->getTotalTTC(),
            'mentionAcquittee' => $facture->isMentionAcquittee(),
        ]);
    }

    /**
     * @return array{raisonSociale: string|null, siren: string|null, siret: string|null, tva: string|null, adresse: list<string>, francais: bool, logo: string|null}|null
     */
    private function emetteur(Facture $facture): ?array
    {
        $profil = $facture->getProfilExploitant();
        if (!$profil instanceof ProfilExploitant) {
            return null;
        }

        $adresse = $profil->getAdresse();
        $ville = trim(((string) ($adresse['cp'] ?? '')) . ' ' . ((string) ($adresse['ville'] ?? '')));
        $lignes = array_values(array_filter(
            [(string) ($adresse['rue'] ?? ''), (string) ($adresse['complement'] ?? ''), $ville],
            static fn (string $l): bool => trim($l) !== '',
        ));

        // Le SIREN et le SIRET ne s'appellent ainsi qu'en France (même règle que `CiiSerializer`) :
        // ailleurs, le champ porte un autre identifiant, que l'en-tête ne doit pas baptiser SIREN.
        $emetteur = $facture->getEtablissement() ?? $profil->getEtablissement();
        $pays = strtoupper((string) ($emetteur?->getPays() ?? ($adresse['pays'] ?? 'FR')));

        return [
            'raisonSociale' => $profil->getRaisonSociale(),
            'siren' => $profil->getSiren() !== '' ? $profil->getSiren() : null,
            'siret' => $profil->getSiret(),
            'tva' => $profil->getTvaIntracommunautaire(),
            'adresse' => $lignes,
            'francais' => $pays === 'FR',
            'logo' => $this->logo($profil),
        ];
    }

    /**
     * Le logo en URI `data:` : Dompdf tourne sans accès distant (`setIsRemoteEnabled(false)`) et sous
     * `chroot`, il ne peut donc lire ni une URL ni un fichier hors de son dossier.
     */
    private function logo(ProfilExploitant $profil): ?string
    {
        $document = $profil->getLogoDocumentId();
        if ($document === null) {
            return null;
        }
        $version = $this->documents->currentVersion($document);
        if ($version === null || !\in_array($version->mimeType, self::LOGO_TYPES, true)) {
            return null;
        }
        $flux = $this->documents->readContent($document);
        try {
            $octets = stream_get_contents($flux);
        } finally {
            if (\is_resource($flux)) {
                fclose($flux);
            }
        }
        if (!\is_string($octets) || $octets === '') {
            return null;
        }

        return 'data:' . $version->mimeType . ';base64,' . base64_encode($octets);
    }

    /** @return array{libelle: string, acte: string|null}|null */
    private function regie(Facture $facture): ?array
    {
        $regie = $facture->getVenteOrigine()?->getPointDeVente()?->getRegie();
        if (!$regie instanceof RegieRecettes) {
            return null;
        }

        return ['libelle' => $regie->getLibelle(), 'acte' => $regie->getActeNomination()];
    }
}
