<?php

declare(strict_types=1);

namespace App\Vente\Nf525;

use App\Caisse\Entity\PointDeVente;
use App\Caisse\Enum\TypeCloture;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Vente\Nf525\Entity\FiscalArchive;
use App\Vente\Nf525\Entity\PeriodClosure;
use App\Vente\Service\PanierCalculateur;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Construit l'archive fiscale annuelle d'un établissement (#342, #343) : un ZIP de fichiers CSV
 * (format ouvert, BOI-TVA-DECLA-30-10-30 § 230), une notice en français et un manifeste qui porte
 * l'empreinte SHA-256 de chaque fichier.
 *
 * **Lu en SQL, mois par mois.** Une année de piscine, ce sont des dizaines de milliers de ventes :
 * hydrater des entités les garderait toutes en mémoire. Les requêtes lisent les colonnes, un mois à la
 * fois, et écrivent sur disque ; seul le ZIP final passe en mémoire.
 *
 * **Ce qui n'y entre pas, délibérément** : l'identité des clients et les supports d'accès (cartes,
 * droits). L'archive sert le contrôle fiscal et la statistique de fréquentation ; les données
 * personnelles ont leur propre durée de conservation, que six ans d'archive fiscale ne doivent pas
 * prolonger (décision à valider, voir la PR).
 */
final class FiscalArchiveBuilder
{
    private const SEP = ';';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly FiscalArchiveSigner $signer,
        private readonly PanierCalculateur $calculateur,
    ) {
    }

    /**
     * Pourquoi cette année ne peut pas être archivée — ou `null`.
     */
    public function refusal(Etablissement $etab, int $year): ?string
    {
        $fuseau = $this->fuseau($etab);
        $cetteAnnee = (int) (new \DateTimeImmutable('now', $fuseau))->format('Y');
        if ($year >= $cetteAnnee) {
            return sprintf('L\'année %d n\'est pas terminée : elle s\'archive à partir du 1er janvier %d.', $year, $year + 1);
        }

        if ($this->em->getRepository(FiscalArchive::class)->findOneBy(['etablissement' => $etab, 'fiscalYear' => $year]) !== null) {
            return sprintf('L\'année %d est déjà archivée pour cet établissement : une archive ne se refait pas.', $year);
        }

        [$debut, $fin] = $this->bornes($etab, $year);
        foreach ($this->pointsDeVente($etab) as $pdv) {
            if (!$this->aDeLActivite($pdv, $year, $debut, $fin)) {
                continue;
            }
            $annuelle = $this->em->getRepository(PeriodClosure::class)->findOneBy([
                'pointDeVente' => $pdv,
                'type' => TypeCloture::Annuelle,
                'periodStart' => new \DateTimeImmutable(sprintf('%04d-01-01', $year)),
            ]);
            if (!$annuelle instanceof PeriodClosure) {
                return sprintf(
                    'Le point de vente « %s » a de l\'activité en %d et n\'a pas de clôture annuelle : clôturez l\'année avant de l\'archiver.',
                    $pdv->getLibelle(),
                    $year,
                );
            }
        }

        return null;
    }

    public function build(Etablissement $etab, int $year, ?Utilisateur $author): FiscalArchive
    {
        $refus = $this->refusal($etab, $year);
        if ($refus !== null) {
            if (str_contains($refus, 'n\'est pas terminée')) {
                throw new UnprocessableEntityHttpException($refus);
            }
            throw new ConflictHttpException($refus);
        }

        [$debut, $fin] = $this->bornes($etab, $year);
        $e = $etab->getId()->toBinary();
        $dossier = sys_get_temp_dir() . '/fiscal-archive-' . bin2hex(random_bytes(8));
        if (!mkdir($dossier, 0700) && !is_dir($dossier)) {
            throw new \RuntimeException('Dossier temporaire indisponible.');
        }

        try {
            $mois = $this->tranchesMensuelles($etab, $year);
            $parMois = [];
            for ($m = 1; $m <= 12; ++$m) {
                $parMois[$m] = ['month' => $m, 'salesCount' => 0, 'salesTotal' => 0, 'entries' => 0, 'countedEntries' => 0, 'refused' => 0];
            }
            $fichiers = [];

            // ── Ventes (scellées : validées, annulées, avoir émis — tout ce qui est entré dans la chaîne)
            $ventes = 0;
            $centimes = 0;
            $fichiers['sales.csv'] = $this->ecrire($dossier, 'sales.csv',
                ['id', 'numero', 'date', 'statut', 'total', 'total_remises', 'point_de_vente_id', 'session_id', 'origine_hors_ligne'],
                function () use ($mois, $e, &$ventes, &$centimes, &$parMois): \Generator {
                    foreach ($mois as $m => [$a, $b]) {
                        $rows = $this->db->executeQuery(
                            'SELECT id, numero, date, statut, total, total_remises, point_de_vente_id, session_id, origine_hors_ligne
                               FROM vente_vente
                              WHERE etablissement_id = :e AND statut <> :encours AND date >= :a AND date < :b
                              ORDER BY date, id',
                            $this->params($e, $a, $b) + ['encours' => 'en_cours'],
                            $this->types(),
                        )->iterateAssociative();
                        foreach ($rows as $r) {
                            ++$ventes;
                            $c = $this->calculateur->centimes((string) $r['total']);
                            $centimes += $c;
                            ++$parMois[$m]['salesCount'];
                            $parMois[$m]['salesTotal'] += $c;
                            yield [$this->uuid($r['id']), $r['numero'], $r['date'], $r['statut'], $r['total'], $r['total_remises'],
                                $this->uuid($r['point_de_vente_id']), $this->uuid($r['session_id']), (int) $r['origine_hors_ligne']];
                        }
                    }
                },
            );

            $fichiers['sale_lines.csv'] = $this->ecrire($dossier, 'sale_lines.csv',
                ['id', 'vente_id', 'libelle_produit', 'libelle_type_tarif', 'quantite', 'prix_unitaire', 'remise_ligne', 'montant_ligne'],
                function () use ($mois, $e): \Generator {
                    foreach ($mois as [$a, $b]) {
                        $rows = $this->db->executeQuery(
                            'SELECT l.id, l.vente_id, l.libelle_produit, l.libelle_type_tarif, l.quantite, l.prix_unitaire, l.remise_ligne, l.montant_ligne
                               FROM vente_ligne l JOIN vente_vente v ON v.id = l.vente_id
                              WHERE v.etablissement_id = :e AND v.statut <> :encours AND v.date >= :a AND v.date < :b
                              ORDER BY v.date, v.id, l.id',
                            $this->params($e, $a, $b) + ['encours' => 'en_cours'],
                            $this->types(),
                        )->iterateAssociative();
                        foreach ($rows as $r) {
                            yield [$this->uuid($r['id']), $this->uuid($r['vente_id']), $r['libelle_produit'], $r['libelle_type_tarif'],
                                $r['quantite'], $r['prix_unitaire'], $r['remise_ligne'], $r['montant_ligne']];
                        }
                    }
                },
            );

            $fichiers['payments.csv'] = $this->ecrire($dossier, 'payments.csv',
                ['id', 'vente_id', 'moyen', 'montant', 'rendu', 'date_heure', 'differe'],
                function () use ($mois, $e): \Generator {
                    foreach ($mois as [$a, $b]) {
                        $rows = $this->db->executeQuery(
                            'SELECT p.id, p.vente_id, p.moyen_code, p.montant, p.rendu, p.date_heure, p.differe
                               FROM vente_paiement p JOIN vente_vente v ON v.id = p.vente_id
                              WHERE v.etablissement_id = :e AND v.statut <> :encours AND v.date >= :a AND v.date < :b
                              ORDER BY v.date, v.id, p.id',
                            $this->params($e, $a, $b) + ['encours' => 'en_cours'],
                            $this->types(),
                        )->iterateAssociative();
                        foreach ($rows as $r) {
                            yield [$this->uuid($r['id']), $this->uuid($r['vente_id']), $r['moyen_code'], $r['montant'], $r['rendu'],
                                $r['date_heure'], (int) $r['differe']];
                        }
                    }
                },
            );

            $fichiers['credit_notes.csv'] = $this->ecrire($dossier, 'credit_notes.csv',
                ['id', 'numero', 'montant', 'motif', 'date_heure', 'nature', 'vente_origine_id'],
                function () use ($e, $debut, $fin): \Generator {
                    $rows = $this->db->executeQuery(
                        'SELECT id, numero, montant, motif, date_heure, nature, vente_origine_id FROM vente_avoir
                          WHERE etablissement_id = :e AND date_heure >= :a AND date_heure < :b ORDER BY date_heure, id',
                        $this->params($e, $debut, $fin),
                        $this->types(),
                    )->iterateAssociative();
                    foreach ($rows as $r) {
                        yield [$this->uuid($r['id']), $r['numero'], $r['montant'], $r['motif'], $r['date_heure'], $r['nature'], $this->uuid($r['vente_origine_id'])];
                    }
                },
            );

            $fichiers['sealed_operations.csv'] = $this->ecrire($dossier, 'sealed_operations.csv',
                ['point_de_vente_id', 'numero_sequence', 'type_operation', 'cible_type', 'cible_id', 'empreinte', 'empreinte_precedente', 'signature', 'horodatage', 'payload_canonique'],
                function () use ($etab, $year, $debut, $fin): \Generator {
                    foreach ($this->pointsDeVente($etab) as $pdv) {
                        [$premier, $dernier] = $this->segment($pdv, $year, $debut, $fin);
                        if ($premier === null || $dernier === null) {
                            continue;
                        }
                        $rows = $this->db->executeQuery(
                            'SELECT point_de_vente_id, numero_sequence, type_operation, cible_type, cible_id, empreinte, empreinte_precedente, signature, horodatage, payload_canonique
                               FROM nf525_operation_scellee
                              WHERE point_de_vente_id = :p AND numero_sequence BETWEEN :s1 AND :s2 ORDER BY numero_sequence',
                            ['p' => $pdv->getId()->toBinary(), 's1' => $premier, 's2' => $dernier],
                            ['p' => ParameterType::BINARY, 's1' => ParameterType::INTEGER, 's2' => ParameterType::INTEGER],
                        )->iterateAssociative();
                        foreach ($rows as $r) {
                            yield [$this->uuid($r['point_de_vente_id']), $r['numero_sequence'], $r['type_operation'], $r['cible_type'],
                                $this->uuid($r['cible_id']), $r['empreinte'], $r['empreinte_precedente'], $r['signature'], $r['horodatage'], $r['payload_canonique']];
                        }
                    }
                },
            );

            $annee = [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)];
            $fichiers['daily_closures.csv'] = $this->ecrire($dossier, 'daily_closures.csv',
                ['id', 'point_de_vente_id', 'business_day', 'sales_count', 'daily_total', 'previous_grand_total', 'grand_total', 'last_sequence', 'closed_at'],
                function () use ($e, $annee): \Generator {
                    $rows = $this->db->executeQuery(
                        'SELECT id, point_de_vente_id, business_day, sales_count, daily_total, previous_grand_total, grand_total, last_sequence, closed_at
                           FROM nf525_daily_closure WHERE etablissement_id = :e AND business_day >= :a AND business_day < :b
                          ORDER BY point_de_vente_id, business_day',
                        ['e' => $e, 'a' => $annee[0], 'b' => $annee[1]],
                        ['e' => ParameterType::BINARY],
                    )->iterateAssociative();
                    foreach ($rows as $r) {
                        yield [$this->uuid($r['id']), $this->uuid($r['point_de_vente_id']), $r['business_day'], $r['sales_count'], $r['daily_total'],
                            $r['previous_grand_total'], $r['grand_total'], $r['last_sequence'], $r['closed_at']];
                    }
                },
            );

            $fichiers['period_closures.csv'] = $this->ecrire($dossier, 'period_closures.csv',
                ['id', 'point_de_vente_id', 'closure_type', 'period_start', 'period_end', 'daily_closure_count', 'sales_count', 'period_total',
                    'fiscal_year_total', 'previous_grand_total', 'grand_total', 'recomputed_total', 'last_sequence', 'closed_at'],
                function () use ($e, $annee): \Generator {
                    $rows = $this->db->executeQuery(
                        'SELECT id, point_de_vente_id, closure_type, period_start, period_end, daily_closure_count, sales_count, period_total,
                                fiscal_year_total, previous_grand_total, grand_total, recomputed_total, last_sequence, closed_at
                           FROM nf525_period_closure WHERE etablissement_id = :e AND period_start >= :a AND period_start < :b
                          ORDER BY point_de_vente_id, closure_type, period_start',
                        ['e' => $e, 'a' => $annee[0], 'b' => $annee[1]],
                        ['e' => ParameterType::BINARY],
                    )->iterateAssociative();
                    foreach ($rows as $r) {
                        yield [$this->uuid($r['id']), $this->uuid($r['point_de_vente_id']), $r['closure_type'], $r['period_start'], $r['period_end'],
                            $r['daily_closure_count'], $r['sales_count'], $r['period_total'], $r['fiscal_year_total'], $r['previous_grand_total'],
                            $r['grand_total'], $r['recomputed_total'], $r['last_sequence'], $r['closed_at']];
                    }
                },
            );

            // ── Fréquentation (#343) : chaque passage, sans le support ni le droit qui l'ont permis.
            $passages = 0;
            $fichiers['attendance.csv'] = $this->ecrire($dossier, 'attendance.csv',
                ['id', 'horodatage', 'sens', 'resultat', 'code_motif', 'espace_id', 'origine_hors_ligne'],
                function () use ($mois, $e, &$passages, &$parMois): \Generator {
                    foreach ($mois as $m => [$a, $b]) {
                        $rows = $this->db->executeQuery(
                            'SELECT id, horodatage, sens, resultat, code_motif, espace_id, origine_hors_ligne FROM acces_passage
                              WHERE etablissement_id = :e AND horodatage >= :a AND horodatage < :b ORDER BY horodatage, id',
                            $this->params($e, $a, $b),
                            $this->types(),
                        )->iterateAssociative();
                        foreach ($rows as $r) {
                            ++$passages;
                            if ($r['sens'] === 'entree' && $r['resultat'] === 'valide') {
                                ++$parMois[$m]['entries'];
                            } elseif ($r['sens'] === 'entree' && $r['resultat'] === 'compte') {
                                ++$parMois[$m]['countedEntries'];
                            } elseif ($r['resultat'] === 'refuse') {
                                ++$parMois[$m]['refused'];
                            }
                            yield [$this->uuid($r['id']), $r['horodatage'], $r['sens'], $r['resultat'], $r['code_motif'], $this->uuid($r['espace_id']),
                                (int) $r['origine_hors_ligne']];
                        }
                    }
                },
            );

            $total = $this->calculateur->decimal($centimes);
            $summary = [
                'months' => array_values(array_map(fn (array $m): array => ['salesTotal' => $this->calculateur->decimal($m['salesTotal'])] + $m, $parMois)),
                'pointsOfSale' => $this->resumePointsDeVente($etab, $year),
            ];

            $manifeste = [
                'format' => FiscalArchive::FORMAT,
                'etablissement' => ['id' => (string) $etab->getId(), 'nom' => $etab->getNom()],
                'exercice' => $year,
                'fuseau' => $this->fuseau($etab)->getName(),
                'genereLe' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                'separateur' => self::SEP,
                'encodage' => 'UTF-8',
                'totaux' => ['nombreVentes' => $ventes, 'totalVentes' => $total, 'nombrePassages' => $passages],
                'fichiers' => array_map(
                    static fn (string $nom, array $f): array => ['nom' => $nom, 'sha256' => $f['sha256'], 'lignes' => $f['rows']],
                    array_keys($fichiers),
                    array_values($fichiers),
                ),
            ];
            file_put_contents($dossier . '/manifest.json', json_encode($manifeste, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
            file_put_contents($dossier . '/LISEZMOI.txt', $this->notice($etab, $year));

            $zipChemin = $dossier . '.zip';
            $zip = new \ZipArchive();
            if ($zip->open($zipChemin, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Création du fichier ZIP impossible.');
            }
            foreach (['LISEZMOI.txt', 'manifest.json', ...array_keys($fichiers)] as $nom) {
                $zip->addFile($dossier . '/' . $nom, $nom);
            }
            $zip->close();
            $contenu = (string) file_get_contents($zipChemin);
            @unlink($zipChemin);
        } finally {
            foreach (glob($dossier . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dossier);
        }

        $nom = sprintf('archive-fiscale-%d-%s.zip', $year, substr((string) $etab->getId(), 0, 8));
        $archive = (new FiscalArchive())
            ->setEtablissement($etab)
            ->setFiscalYear($year)
            ->setSalesCount($ventes)
            ->setSalesTotal($total)
            ->setAttendanceCount($passages)
            ->setSummary($summary)
            ->setAuthor($author);
        $archive->attachFile($nom, $contenu, $this->signer->sign(hash('sha256', $contenu)));

        $this->em->persist($archive);
        $this->em->flush();

        return $archive;
    }

    /**
     * Écrit un CSV et rend son empreinte et son nombre de lignes de données.
     *
     * @param list<string>       $entetes
     * @param callable(): \Generator<list<mixed>> $lignes
     *
     * @return array{sha256: string, rows: int}
     */
    private function ecrire(string $dossier, string $nom, array $entetes, callable $lignes): array
    {
        $chemin = $dossier . '/' . $nom;
        $f = fopen($chemin, 'wb');
        if ($f === false) {
            throw new \RuntimeException('Écriture impossible : ' . $nom);
        }
        fputcsv($f, $entetes, self::SEP, '"', '');
        $n = 0;
        foreach ($lignes() as $ligne) {
            fputcsv($f, array_map(static fn (mixed $v): string => $v === null ? '' : (string) $v, $ligne), self::SEP, '"', '');
            ++$n;
        }
        fclose($f);

        return ['sha256' => (string) hash_file('sha256', $chemin), 'rows' => $n];
    }

    /** @return list<PointDeVente> */
    private function pointsDeVente(Etablissement $etab): array
    {
        /** @var list<PointDeVente> $l */
        $l = $this->em->getRepository(PointDeVente::class)->findBy(['etablissement' => $etab], ['libelle' => 'ASC']);

        return $l;
    }

    private function aDeLActivite(PointDeVente $pdv, int $year, \DateTimeImmutable $debut, \DateTimeImmutable $fin): bool
    {
        $p = $pdv->getId()->toBinary();
        $ventes = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM vente_vente WHERE point_de_vente_id = :p AND statut <> :encours AND date >= :a AND date < :b',
            ['p' => $p, 'encours' => 'en_cours', 'a' => $debut->format('Y-m-d H:i:s'), 'b' => $fin->format('Y-m-d H:i:s')],
            ['p' => ParameterType::BINARY],
        );
        if ($ventes > 0) {
            return true;
        }

        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM nf525_daily_closure WHERE point_de_vente_id = :p AND business_day >= :a AND business_day < :b',
            ['p' => $p, 'a' => sprintf('%04d-01-01', $year), 'b' => sprintf('%04d-01-01', $year + 1)],
            ['p' => ParameterType::BINARY],
        ) > 0;
    }

    /**
     * Le segment de chaîne d'un point de vente pour l'année : du premier maillon scellé dans l'année
     * jusqu'au scellement de sa clôture annuelle **inclus** — qui tombe en janvier suivant. Un segment
     * continu se vérifie maillon par maillon ; un segment coupé au 31 décembre laisserait la clôture
     * annuelle hors de l'archive qu'elle ferme.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function segment(PointDeVente $pdv, int $year, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $p = $pdv->getId()->toBinary();
        $premier = $this->db->fetchOne(
            'SELECT MIN(numero_sequence) FROM nf525_operation_scellee WHERE point_de_vente_id = :p AND horodatage >= :a',
            ['p' => $p, 'a' => $debut->format('Y-m-d H:i:s')],
            ['p' => ParameterType::BINARY],
        );
        if ($premier === null || $premier === false) {
            return [null, null];
        }

        $annuelle = $this->em->getRepository(PeriodClosure::class)->findOneBy([
            'pointDeVente' => $pdv,
            'type' => TypeCloture::Annuelle,
            'periodStart' => new \DateTimeImmutable(sprintf('%04d-01-01', $year)),
        ]);
        $dernier = $annuelle instanceof PeriodClosure
            ? $this->db->fetchOne(
                'SELECT numero_sequence FROM nf525_operation_scellee WHERE point_de_vente_id = :p AND cible_type = :t AND cible_id = :c',
                ['p' => $p, 't' => 'PeriodClosure', 'c' => $annuelle->getId()->toBinary()],
                ['p' => ParameterType::BINARY, 'c' => ParameterType::BINARY],
            )
            : $this->db->fetchOne(
                'SELECT MAX(numero_sequence) FROM nf525_operation_scellee WHERE point_de_vente_id = :p AND horodatage < :b',
                ['p' => $p, 'b' => $fin->format('Y-m-d H:i:s')],
                ['p' => ParameterType::BINARY],
            );
        if ($dernier === null || $dernier === false || (int) $dernier < (int) $premier) {
            return [null, null];
        }

        return [(int) $premier, (int) $dernier];
    }

    /** @return list<array<string, mixed>> */
    private function resumePointsDeVente(Etablissement $etab, int $year): array
    {
        $resume = [];
        foreach ($this->pointsDeVente($etab) as $pdv) {
            $annuelle = $this->em->getRepository(PeriodClosure::class)->findOneBy([
                'pointDeVente' => $pdv,
                'type' => TypeCloture::Annuelle,
                'periodStart' => new \DateTimeImmutable(sprintf('%04d-01-01', $year)),
            ]);
            if (!$annuelle instanceof PeriodClosure) {
                continue;
            }
            $resume[] = [
                'pointDeVente' => (string) $pdv->getId(),
                'libelle' => $pdv->getLibelle(),
                'salesCount' => $annuelle->getSalesCount(),
                'periodTotal' => $annuelle->getPeriodTotal(),
                'grandTotal' => $annuelle->getGrandTotal(),
            ];
        }

        return $resume;
    }

    /**
     * Les douze mois de l'année, bornés dans le fuseau de l'établissement puis ramenés à l'heure du
     * serveur — les colonnes `DATETIME` sont écrites à l'heure du serveur (même règle que
     * `DailyClosureHandler::bornes()`).
     *
     * @return array<int, array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private function tranchesMensuelles(Etablissement $etab, int $year): array
    {
        $fuseau = $this->fuseau($etab);
        $serveur = new \DateTimeZone(date_default_timezone_get());
        $tranches = [];
        for ($m = 1; $m <= 12; ++$m) {
            $a = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $m), $fuseau);
            $tranches[$m] = [$a->setTimezone($serveur), $a->modify('first day of next month')->setTimezone($serveur)];
        }

        return $tranches;
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    private function bornes(Etablissement $etab, int $year): array
    {
        $t = $this->tranchesMensuelles($etab, $year);

        return [$t[1][0], $t[12][1]];
    }

    private function fuseau(Etablissement $etab): \DateTimeZone
    {
        return new \DateTimeZone($etab->getFuseauHoraire() ?? date_default_timezone_get());
    }

    /** @return array<string, string> */
    private function params(string $e, \DateTimeImmutable $a, \DateTimeImmutable $b): array
    {
        return ['e' => $e, 'a' => $a->format('Y-m-d H:i:s'), 'b' => $b->format('Y-m-d H:i:s')];
    }

    /** @return array<string, ParameterType> */
    private function types(): array
    {
        return ['e' => ParameterType::BINARY];
    }

    private function uuid(mixed $binaire): string
    {
        return \is_string($binaire) && \strlen($binaire) === 16 ? Uuid::fromBinary($binaire)->toRfc4122() : '';
    }

    private function notice(Etablissement $etab, int $year): string
    {
        $l = [
            'ARCHIVE FISCALE ANNUELLE - ' . $etab->getNom() . ' - exercice ' . $year,
            '',
            'Produite par Fluvia (format ' . FiscalArchive::FORMAT . ').',
            'Exercice = annee civile, journees comptees dans le fuseau ' . $this->fuseau($etab)->getName() . '.',
            '',
            'CONTENU (fichiers CSV, separateur point-virgule, encodage UTF-8, premiere ligne = noms de colonnes) :',
            '- sales.csv : les ventes scellees de l\'annee (validees, annulees, avec avoir) ; montants en euros TTC.',
            '- sale_lines.csv : les lignes de ces ventes (libelles figes au moment de la vente).',
            '- payments.csv : les reglements de ces ventes, par moyen de paiement.',
            '- credit_notes.csv : les avoirs emis dans l\'annee.',
            '- sealed_operations.csv : le segment de la chaine d\'inalterabilite de chaque point de vente, du premier',
            '  maillon de l\'annee jusqu\'au scellement de la cloture annuelle inclus.',
            '  empreinte(N) = SHA-256( JSON canonique du payload (cles triees) + "|" + empreinte(N-1) ).',
            '  signature(N) = HMAC-SHA256( empreinte(N), cle de scellement de l\'editeur ).',
            '- daily_closures.csv : les arretes de journee (total du jour, cumul perpetuel).',
            '- period_closures.csv : les clotures mensuelles et annuelle (total de la periode, cumul de l\'exercice,',
            '  cumul perpetuel ; recomputed_total = total relu dans les ventes au moment de la cloture, temoin).',
            '- attendance.csv : la frequentation (chaque passage : sens, resultat, espace), sans donnees personnelles.',
            '- manifest.json : l\'empreinte SHA-256 et le nombre de lignes de chaque fichier.',
            '',
            'VERIFIER L\'ARCHIVE :',
            '1. L\'empreinte SHA-256 du fichier ZIP et sa signature sont conservees dans Fluvia (fiche de l\'archive).',
            '2. Chaque fichier doit avoir l\'empreinte indiquee dans manifest.json (commande : sha256sum <fichier>).',
            '3. Pour chaque point de vente, la somme des totaux de sales.csv doit egaler le period_total de sa',
            '   cloture annuelle dans period_closures.csv.',
            '4. Fluvia refait ces controles et le recalcul de la chaine : bouton "Verifier" sur la fiche de l\'archive.',
            '',
            'Conservation : six ans (BOI-TVA-DECLA-30-10-30, par. 250). Les donnees actives ne sont pas purgees.',
        ];

        return implode("\r\n", $l) . "\r\n";
    }
}
