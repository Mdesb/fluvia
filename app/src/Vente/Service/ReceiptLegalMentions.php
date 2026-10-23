<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Compta\Entity\MappingComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Legal\Entity\LegalIdentity;
use App\Offre\Entity\Produit;
use App\Offre\Enum\AxeCategorie;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CE QU'UN TICKET DE CAISSE DOIT PORTER EN FRANCE, EN PLUS DES LIGNES : L'ÉMETTEUR ET LA TVA.
 *
 * Source vérifiée le 21/10/2026 : economie.gouv.fr, « Ticket de caisse : professionnels, quelles sont
 * vos obligations ? » (page du 10/11/2025). Le ticket comporte les coordonnées du professionnel, la
 * désignation des produits achetés, leur quantité et leur prix, la date et l'heure. Pour une prestation
 * de service, la note (arrêté n° 83-50/A du 3 octobre 1983) fait apparaître les prix hors taxes et
 * toutes taxes : d'où la ventilation par taux.
 *
 * ── L'ÉMETTEUR ───────────────────────────────────────────────────────────────────────────────────
 *
 * L'identité légale de l'établissement (`LegalIdentity` : raison sociale, adresse, SIRET), saisie pour
 * les mentions légales du site. Sans elle, le ticket porte le nom de l'établissement, et `null` pour le
 * reste : un SIRET absent se voit, un SIRET deviné ne se voit pas.
 *
 * ── LA TVA ───────────────────────────────────────────────────────────────────────────────────────
 *
 * Même calcul que la facture justificative (`EmissionFactureJustificativeHandler::ligneDepuisVente`) :
 * le taux du mapping de la catégorie comptable du produit, TVA extraite du TTC ligne à ligne, arrondie
 * au centime. **Un écart voulu** : là où la facture retombe sur le taux « hors champ », le ticket
 * range la ligne sous un taux `null` (« taux non renseigné »). Imprimer « TVA 0 % » sur une entrée
 * taxée à 20 % serait une mention fausse sur un document remis au client.
 */
final class ReceiptLegalMentions
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{
     *     emetteur: array{nom: string, raisonSociale: ?string, adresse: ?string, siret: ?string, numeroTva: ?string},
     *     ventilationTva: list<array{taux: ?string, totalHt: string, totalTva: string, totalTtc: string}>,
     * }
     */
    public function pour(Vente $vente): array
    {
        return [
            'emetteur' => $this->emetteur($vente),
            'ventilationTva' => $this->ventilationTva($vente),
        ];
    }

    /** @return array{nom: string, raisonSociale: ?string, adresse: ?string, siret: ?string, numeroTva: ?string} */
    private function emetteur(Vente $vente): array
    {
        $etablissement = $vente->getEtablissement();
        $identite = $etablissement === null
            ? null
            : $this->em->getRepository(LegalIdentity::class)->findOneBy(['establishment' => $etablissement]);

        $texte = static fn (?string $v): ?string => $v === null || trim($v) === '' ? null : trim($v);

        return [
            'nom' => $etablissement?->getNom() ?? '',
            'raisonSociale' => $texte($identite?->getLegalName()),
            'adresse' => $texte($identite?->getRegisteredAddress()),
            'siret' => $texte($identite?->getSiret()),
            'numeroTva' => $texte($identite?->getVatNumber()),
        ];
    }

    /** @return list<array{taux: ?string, totalHt: string, totalTva: string, totalTtc: string}> */
    private function ventilationTva(Vente $vente): array
    {
        $profil = $this->profil($vente);

        /** @var array<string, array{taux: ?string, ht: int, tva: int, ttc: int}> $parTaux */
        $parTaux = [];
        foreach ($vente->getLignes() as $ligne) {
            $ttc = (int) round(((float) $ligne->getMontantLigne()) * 100);
            if ($ttc === 0) {
                continue;
            }
            $taux = $this->taux($profil, $ligne->getProduit());
            $tva = $taux === null ? 0 : (int) round($ttc * (float) $taux / (100 + (float) $taux));
            $cle = $taux ?? '';
            $parTaux[$cle] ??= ['taux' => $taux, 'ht' => 0, 'tva' => 0, 'ttc' => 0];
            $parTaux[$cle]['ht'] += $ttc - $tva;
            $parTaux[$cle]['tva'] += $tva;
            $parTaux[$cle]['ttc'] += $ttc;
        }
        ksort($parTaux, \SORT_STRING);

        $euros = static fn (int $c): string => number_format($c / 100, 2, '.', '');

        return array_values(array_map(static fn (array $t): array => [
            'taux' => $t['taux'],
            'totalHt' => $euros($t['ht']),
            'totalTva' => $euros($t['tva']),
            'totalTtc' => $euros($t['ttc']),
        ], $parTaux));
    }

    private function profil(Vente $vente): ?ProfilExploitant
    {
        $etablissement = $vente->getEtablissement();
        if ($etablissement === null) {
            return null;
        }
        foreach ($this->em->getRepository(ProfilExploitant::class)->findAll() as $profil) {
            if ($profil->couvre($etablissement)) {
                return $profil;
            }
        }

        return null;
    }

    private function taux(?ProfilExploitant $profil, mixed $produitId): ?string
    {
        if ($profil === null || $produitId === null) {
            return null;
        }
        $produit = $this->em->getRepository(Produit::class)->find($produitId);
        $categorie = $produit?->getCategorieParAxe(AxeCategorie::Comptable)?->getId();
        if ($categorie === null) {
            return null;
        }
        $mapping = $this->em->getRepository(MappingComptable::class)->findOneBy([
            'profilExploitant' => $profil->getId(),
            'categorie' => $categorie,
        ]);
        $taux = $mapping?->getTauxTva();

        return $taux === null ? null : number_format((float) $taux->getTaux(), 2, '.', '');
    }
}
