<?php

declare(strict_types=1);

namespace App\Reporting\Service;

use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\Caisse\Entity\SessionCaisse;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * LES BILLETS DÉLIVRÉS, COMPTÉS SUR LES LIGNES DE VENTE — PAS SUR LES PASSAGES AUX PORTES.
 *
 * `FREQUENTATION_CUMULEE` compte les passages : un billet délivré mais pas encore utilisé n'y figure
 * pas, et un abonné qui passe dix fois y figure dix fois. Un régisseur qui rend compte de « 43 000
 * billets par an » parle de ce qui a été délivré au guichet : c'est la somme des quantités des lignes
 * de vente scellées (validées, ou validées puis remboursées par avoir, comme le chiffre d'affaires de
 * `ProjectionVenteDoctrine::caEncaisse`), gratuits compris.
 *
 * Une ligne compte comme billet quand son produit émet un support (`TypeProduit::emetUnSupport()`,
 * la règle de `ValiderVenteService`) : un article de boutique n'est pas un billet.
 *
 * ── LE FUSEAU ──────────────────────────────────────────────────────────────────────────────────
 *
 * Les dates sont écrites en UTC. Le jour, la semaine, le mois et la tranche horaire se calculent dans
 * le fuseau de l'établissement : sinon une vente de 0 h 30 à Perpignan tomberait la veille.
 *
 * ── LE CLOISONNEMENT ───────────────────────────────────────────────────────────────────────────
 *
 * La requête porte TOUJOURS l'établissement reçu, que le contrôleur tire de l'établissement actif
 * dont l'utilisateur a les droits. Rien ici ne lit un autre établissement.
 */
final class SalesLinesReport
{
    public const GROUPINGS = ['jour', 'semaine', 'mois', 'heure', 'produit', 'poste', 'pointDeVente'];

    private const MOIS = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août',
        'septembre', 'octobre', 'novembre', 'décembre'];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param \DateTimeImmutable $du premier jour inclus (date locale)
     * @param \DateTimeImmutable $au dernier jour inclus (date locale)
     *
     * @return array{total: int, lignes: list<array{cle: string, libelle: string, billets: int}>}
     */
    public function ticketsIssued(Etablissement $etablissement, \DateTimeImmutable $du, \DateTimeImmutable $au, string $par): array
    {
        if (!\in_array($par, self::GROUPINGS, true)) {
            throw new \InvalidArgumentException(sprintf('Regroupement inconnu « %s ».', $par));
        }

        $lignes = $this->lignes($etablissement, $du, $au);
        $billets = $this->produitsBillets(array_column($lignes, 'produit'));
        $libelles = $this->libellesPour($par, $lignes);

        $groupes = [];
        $total = 0;
        foreach ($lignes as $l) {
            if (!isset($billets[$l['produit']])) {
                continue;
            }
            [$cle, $libelle] = $this->groupe($par, $l, $libelles);
            $groupes[$cle] ??= ['cle' => $cle, 'libelle' => $libelle, 'billets' => 0];
            $groupes[$cle]['billets'] += $l['quantite'];
            $total += $l['quantite'];
        }

        $groupes = array_values($groupes);
        if (\in_array($par, ['jour', 'semaine', 'mois', 'heure'], true)) {
            usort($groupes, static fn (array $a, array $b): int => strcmp($a['cle'], $b['cle']));
        } else {
            usort($groupes, static fn (array $a, array $b): int => [$b['billets'], $a['libelle']] <=> [$a['billets'], $b['libelle']]);
        }

        return ['total' => $total, 'lignes' => $groupes];
    }

    /**
     * Les lignes de vente scellées de l'établissement sur la période, une ligne par ligne de vente.
     *
     * En SQL et en scalaires : 43 000 billets par an ne s'hydratent pas en entités pour être sommés.
     * Les noms de tables et de colonnes viennent du mapping, pas d'une chaîne recopiée.
     *
     * @return list<array{date: \DateTimeImmutable, produit: string, libelle: string, quantite: int, montant: string, pointDeVente: string, caisse: ?string}>
     */
    public function lignes(Etablissement $etablissement, \DateTimeImmutable $du, \DateTimeImmutable $au): array
    {
        $tz = $this->fuseau($etablissement);
        $utc = new \DateTimeZone('UTC');
        $debut = (new \DateTimeImmutable($du->format('Y-m-d') . ' 00:00:00', $tz))->setTimezone($utc);
        $fin = (new \DateTimeImmutable($au->format('Y-m-d') . ' 00:00:00', $tz))->modify('+1 day')->setTimezone($utc);

        $lv = $this->em->getClassMetadata(LigneVente::class);
        $v = $this->em->getClassMetadata(Vente::class);
        $s = $this->em->getClassMetadata(SessionCaisse::class);

        $sql = sprintf(
            'SELECT v.%s AS d, l.%s AS produit, l.%s AS libelle, l.%s AS quantite, l.%s AS montant, v.%s AS pdv, s.%s AS caisse'
            . ' FROM %s l INNER JOIN %s v ON v.%s = l.%s LEFT JOIN %s s ON s.%s = v.%s'
            . ' WHERE v.%s = :etab AND v.%s IN (:statuts) AND v.%s >= :debut AND v.%s < :fin',
            $v->getColumnName('date'),
            $lv->getColumnName('produit'),
            $lv->getColumnName('libelleProduit'),
            $lv->getColumnName('quantite'),
            $lv->getColumnName('montantLigne'),
            $v->getSingleAssociationJoinColumnName('pointDeVente'),
            $s->getSingleAssociationJoinColumnName('caisse'),
            $lv->getTableName(),
            $v->getTableName(),
            $v->getSingleIdentifierColumnName(),
            $lv->getSingleAssociationJoinColumnName('vente'),
            $s->getTableName(),
            $s->getSingleIdentifierColumnName(),
            $v->getSingleAssociationJoinColumnName('session'),
            $v->getSingleAssociationJoinColumnName('etablissement'),
            $v->getColumnName('statut'),
            $v->getColumnName('date'),
            $v->getColumnName('date'),
        );

        $rows = $this->em->getConnection()->fetchAllAssociative($sql, [
            'etab' => $etablissement->getId()->toBinary(),
            'statuts' => [StatutVente::Validee->value, StatutVente::AvoirEmis->value],
            'debut' => $debut->format('Y-m-d H:i:s'),
            'fin' => $fin->format('Y-m-d H:i:s'),
        ], [
            'statuts' => ArrayParameterType::STRING,
        ]);

        $lignes = [];
        foreach ($rows as $r) {
            $lignes[] = [
                'date' => (new \DateTimeImmutable((string) $r['d'], $utc))->setTimezone($tz),
                'produit' => $this->uuid($r['produit']) ?? '',
                'libelle' => $this->libelle($r['libelle']),
                'quantite' => (int) $r['quantite'],
                'montant' => (string) $r['montant'],
                'pointDeVente' => $this->uuid($r['pdv']) ?? '',
                'caisse' => $this->uuid($r['caisse']),
            ];
        }

        return $lignes;
    }

    public function fuseau(Etablissement $etablissement): \DateTimeZone
    {
        try {
            return new \DateTimeZone($etablissement->getFuseauHoraire() ?: 'Europe/Paris');
        } catch (\Exception) {
            return new \DateTimeZone('Europe/Paris');
        }
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, Produit> les produits qui sont des billets, par identifiant
     */
    private function produitsBillets(array $ids): array
    {
        $billets = [];
        foreach (array_unique(array_filter($ids)) as $id) {
            $produit = $this->em->getRepository(Produit::class)->find(Uuid::fromString($id));
            if ($produit?->getType()?->emetUnSupport() === true) {
                $billets[$id] = $produit;
            }
        }

        return $billets;
    }

    /**
     * @param list<array<string, mixed>> $lignes
     *
     * @return array<string, string> libellé par identifiant (poste ou point de vente)
     */
    private function libellesPour(string $par, array $lignes): array
    {
        $libelles = [];
        if ($par === 'poste') {
            foreach (array_unique(array_filter(array_column($lignes, 'caisse'))) as $id) {
                $libelles[$id] = $this->em->getRepository(Caisse::class)->find(Uuid::fromString($id))?->getLibelle() ?? 'Poste inconnu';
            }
        } elseif ($par === 'pointDeVente') {
            foreach (array_unique(array_filter(array_column($lignes, 'pointDeVente'))) as $id) {
                $libelles[$id] = $this->em->getRepository(PointDeVente::class)->find(Uuid::fromString($id))?->getLibelle() ?? 'Point de vente inconnu';
            }
        }

        return $libelles;
    }

    /**
     * @param array{date: \DateTimeImmutable, produit: string, libelle: string, pointDeVente: string, caisse: ?string} $l
     * @param array<string, string> $libelles
     *
     * @return array{string, string}
     */
    private function groupe(string $par, array $l, array $libelles): array
    {
        $d = $l['date'];

        return match ($par) {
            'jour' => [$d->format('Y-m-d'), $d->format('d/m/Y')],
            'semaine' => [$d->format('o-\WW'), sprintf('Semaine %s (%s)', $d->format('W'), $d->format('o'))],
            'mois' => [$d->format('Y-m'), self::MOIS[(int) $d->format('n')] . ' ' . $d->format('Y')],
            'heure' => [$d->format('H'), sprintf('%s h – %02d h', $d->format('H'), ((int) $d->format('H') + 1) % 24)],
            'produit' => [$l['produit'], $l['libelle']],
            'poste' => $l['caisse'] === null
                ? ['', 'Vente directe (sans caisse)']
                : [$l['caisse'], $libelles[$l['caisse']] ?? 'Poste inconnu'],
            'pointDeVente' => [$l['pointDeVente'], $libelles[$l['pointDeVente']] ?? 'Point de vente inconnu'],
        };
    }

    private function uuid(mixed $brut): ?string
    {
        if (!\is_string($brut) || $brut === '') {
            return null;
        }
        if (\strlen($brut) === 16) {
            return Uuid::fromBinary($brut)->toRfc4122();
        }

        return Uuid::isValid($brut) ? Uuid::fromString($brut)->toRfc4122() : null;
    }

    /** Le libellé figé à la vente (`['fr' => '…']`) : le français, sinon la première langue. */
    private function libelle(mixed $brut): string
    {
        $valeur = \is_string($brut) ? json_decode($brut, true) : $brut;
        if (\is_array($valeur)) {
            $texte = $valeur['fr'] ?? reset($valeur);

            return \is_string($texte) && $texte !== '' ? $texte : 'Article';
        }

        return \is_string($valeur) && $valeur !== '' ? $valeur : 'Article';
    }
}
