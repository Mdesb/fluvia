<?php

declare(strict_types=1);

namespace App\Vente\Nf525;

use App\Caisse\Entity\PointDeVente;
use App\Platform\Nf525\Alert\SealAlertRecorder;
use App\Platform\Nf525\SealChainRef;
use App\Vente\Enum\TypeOperationScellee;
use App\Vente\Nf525\Entity\FiscalArchive;
use App\Vente\Nf525\Entity\OperationScellee;
use App\Vente\Service\PanierCalculateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Vérifie une archive fiscale (#342) **à partir de son seul contenu**, sans relire les tables actives :
 * une archive qui ne se prouverait qu'en interrogeant la base qu'elle archive ne prouverait rien le
 * jour où la base aura changé.
 *
 * Cinq contrôles, chacun nommé dans le rapport :
 * 1. l'empreinte SHA-256 du ZIP est celle enregistrée à sa création ;
 * 2. la signature HMAC de cette empreinte est valide (clé de scellement) ;
 * 3. chaque fichier du manifeste est présent, avec son empreinte et son nombre de lignes ;
 * 4. le segment de chaîne de chaque point de vente se recalcule maillon par maillon ;
 * 5. pour chaque point de vente, la somme des ventes archivées égale le total de sa clôture annuelle.
 */
final class FiscalArchiveChecker
{
    public function __construct(
        private readonly FiscalArchiveSigner $signer,
        private readonly SignataireOperation $signataire,
        private readonly PanierCalculateur $calculateur,
        // #370 (Q-5) — l'alerte NF525 de la chaîne vivante ; null : aucune alerte (constructions manuelles).
        private readonly ?SealAlertRecorder $alerts = null,
        private readonly ?EntityManagerInterface $em = null,
    ) {
    }

    /**
     * @return array{intact: bool, checks: list<array{check: string, ok: bool, detail: string}>}
     */
    public function verify(FiscalArchive $archive): array
    {
        $broken = [];
        $report = $this->inspect($archive->getContent(), $archive->getSha256(), $archive->getSignature(), $broken);
        $this->openAlertsForBrokenChains($archive, $broken);

        return $report;
    }

    /**
     * ⚠ N'OUVRE JAMAIS D'ALERTE : le contenu vient de l'appelant (un ZIP quelconque), et un faux ZIP ne doit pas
     * pouvoir ouvrir une alerte sur la chaîne d'un tiers (plan, R-5). Seule `verify(FiscalArchive)`, qui lit une
     * archive enregistrée par le serveur, écrit une alerte.
     *
     * @return array{intact: bool, checks: list<array{check: string, ok: bool, detail: string}>}
     */
    public function verifyContent(string $contenu, string $sha256Attendu, string $signature): array
    {
        $ignored = [];

        return $this->inspect($contenu, $sha256Attendu, $signature, $ignored);
    }

    /**
     * @param list<string> $broken identifiants complets des points de vente dont le segment est en défaut (sortie)
     *
     * @return array{intact: bool, checks: list<array{check: string, ok: bool, detail: string}>}
     */
    private function inspect(string $contenu, string $sha256Attendu, string $signature, array &$broken): array
    {
        $checks = [];
        $sha = hash('sha256', $contenu);
        $checks[] = $this->check('empreinte', hash_equals($sha256Attendu, $sha),
            $sha === $sha256Attendu ? 'Empreinte SHA-256 conforme.' : 'Le fichier ne correspond plus à l\'empreinte enregistrée à sa création.');
        $checks[] = $this->check('signature', $this->signer->verify($sha256Attendu, $signature),
            'Signature de l\'empreinte ' . ($this->signer->verify($sha256Attendu, $signature) ? 'valide.' : 'invalide.'));

        $fichiers = $this->lireZip($contenu);
        if ($fichiers === null || !isset($fichiers['manifest.json'])) {
            $checks[] = $this->check('manifeste', false, 'Archive illisible ou sans manifeste.');

            return $this->rapport($checks);
        }

        /** @var array{fichiers?: list<array{nom: string, sha256: string, lignes: int}>} $manifeste */
        $manifeste = json_decode($fichiers['manifest.json'], true) ?: [];
        $ecarts = [];
        foreach ($manifeste['fichiers'] ?? [] as $f) {
            $nom = $f['nom'];
            if (!isset($fichiers[$nom])) {
                $ecarts[] = $nom . ' absent';
                continue;
            }
            if (!hash_equals($f['sha256'], hash('sha256', $fichiers[$nom]))) {
                $ecarts[] = $nom . ' modifié';
            }
            if (\count($this->csv($fichiers[$nom])) !== $f['lignes']) {
                $ecarts[] = $nom . ' : nombre de lignes différent';
            }
        }
        $checks[] = $this->check('fichiers', $ecarts === [] && ($manifeste['fichiers'] ?? []) !== [],
            $ecarts === [] ? sprintf('%d fichiers conformes au manifeste.', \count($manifeste['fichiers'] ?? [])) : implode(' ; ', $ecarts));

        $checks[] = $this->verifierChaine($this->csv($fichiers['sealed_operations.csv'] ?? ''), $broken);
        $checks[] = $this->verifierTotaux($this->csv($fichiers['sales.csv'] ?? ''), $this->csv($fichiers['period_closures.csv'] ?? ''));

        return $this->rapport($checks);
    }

    /**
     * @param list<array<string, string>> $operations
     * @param list<string>                $broken      identifiants complets des points de vente en défaut (sortie)
     */
    private function verifierChaine(array $operations, array &$broken): array
    {
        $parPdv = [];
        foreach ($operations as $r) {
            $parPdv[$r['point_de_vente_id']][] = $r;
        }
        $anomalies = [];
        $total = 0;
        foreach ($parPdv as $pdv => $lignes) {
            $ops = [];
            foreach ($lignes as $r) {
                $payload = json_decode($r['payload_canonique'], true);
                $type = TypeOperationScellee::tryFrom($r['type_operation']);
                if (!\is_array($payload) || $type === null) {
                    $anomalies[] = sprintf('%s n°%s : maillon illisible', substr($pdv, 0, 8), $r['numero_sequence']);
                    $broken[] = (string) $pdv;
                    continue;
                }
                $ops[] = (new OperationScellee())
                    ->setTypeOperation($type)
                    ->setCibleType($r['cible_type'])
                    ->setNumeroSequence((int) $r['numero_sequence'])
                    ->setEmpreintePrecedente($r['empreinte_precedente'] === '' ? null : $r['empreinte_precedente'])
                    ->setPayloadCanonique($payload)
                    ->setEmpreinte($r['empreinte'])
                    ->setSignature($r['signature'])
                    // #361 — identifiant de la clé du maillon ; absent des archives antérieures (et vide
                    // pour les maillons d'avant le trousseau) : la vérification retombe alors sur le
                    // chemin « sans identifiant ».
                    ->setSealKeyId(isset($r['seal_key_id']) && $r['seal_key_id'] !== '' ? $r['seal_key_id'] : null);
            }
            if ($ops === []) {
                continue;
            }
            $premier = $ops[0];
            $rapport = $this->signataire->verifieSegment($ops, $premier->getEmpreintePrecedente(), $premier->getNumeroSequence());
            $total += $rapport->nbOperations;
            foreach ($rapport->anomalies as $a) {
                $anomalies[] = sprintf('%s n°%d : %s', substr($pdv, 0, 8), $a['sequence'], $a['probleme']);
                $broken[] = (string) $pdv;
            }
        }

        return $this->check('chaine', $anomalies === [],
            $anomalies === [] ? sprintf('%d maillons recalculés, chaîne continue.', $total) : implode(' ; ', \array_slice($anomalies, 0, 20)));
    }

    /**
     * Q-5 : une archive en défaut ouvre l'alerte de la chaîne VIVANTE de CE point de vente (origine vérification) ;
     * une archive intacte ne ferme rien (elle ne prouve rien sur la chaîne vivante). Les identifiants viennent du
     * contenu de l'archive : chacun doit être un UUID, désigner un point de vente existant ET appartenir à
     * l'établissement de l'archive, sinon il est ignoré (aucune alerte, aucune erreur).
     *
     * @param list<string> $broken
     */
    private function openAlertsForBrokenChains(FiscalArchive $archive, array $broken): void
    {
        $establishment = $archive->getEtablissement();
        if ($this->alerts === null || $this->em === null || $establishment === null) {
            return;
        }
        foreach (array_unique($broken) as $id) {
            if (!Uuid::isValid($id)) {
                continue;
            }
            $pointOfSale = $this->em->getRepository(PointDeVente::class)->find(Uuid::fromString($id));
            if ($pointOfSale === null || !$pointOfSale->getEtablissement()?->getId()->equals($establishment->getId())) {
                continue;
            }
            $this->alerts->recordVerification(
                SealChainRef::forSales($pointOfSale),
                false,
                sprintf('Archive fiscale %d : le segment de chaîne de ce point de vente est en défaut.', $archive->getFiscalYear()),
            );
        }
    }

    /**
     * @param list<array<string, string>> $ventes
     * @param list<array<string, string>> $clotures
     */
    private function verifierTotaux(array $ventes, array $clotures): array
    {
        $parPdv = [];
        foreach ($ventes as $v) {
            $parPdv[$v['point_de_vente_id']] = ($parPdv[$v['point_de_vente_id']] ?? 0) + $this->calculateur->centimes($v['total']);
        }
        $ecarts = [];
        $n = 0;
        foreach ($clotures as $c) {
            if ($c['closure_type'] !== 'annuelle') {
                continue;
            }
            ++$n;
            $archive = $this->calculateur->decimal($parPdv[$c['point_de_vente_id']] ?? 0);
            if ($archive !== $c['period_total']) {
                $ecarts[] = sprintf('%s : ventes archivées %s ≠ clôture annuelle %s', substr($c['point_de_vente_id'], 0, 8), $archive, $c['period_total']);
            }
            unset($parPdv[$c['point_de_vente_id']]);
        }
        foreach ($parPdv as $pdv => $centimes) {
            $ecarts[] = sprintf('%s : %s de ventes sans clôture annuelle', substr((string) $pdv, 0, 8), $this->calculateur->decimal($centimes));
        }

        return $this->check('totaux', $ecarts === [],
            $ecarts === [] ? sprintf('Ventes archivées égales aux %d clôture(s) annuelle(s).', $n) : implode(' ; ', $ecarts));
    }

    /** @return array<string, string>|null */
    private function lireZip(string $contenu): ?array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fav');
        if ($tmp === false) {
            return null;
        }
        try {
            file_put_contents($tmp, $contenu);
            $zip = new \ZipArchive();
            if ($zip->open($tmp, \ZipArchive::RDONLY) !== true) {
                return null;
            }
            $fichiers = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $nom = (string) $zip->getNameIndex($i);
                $fichiers[$nom] = (string) $zip->getFromIndex($i);
            }
            $zip->close();

            return $fichiers;
        } finally {
            @unlink($tmp);
        }
    }

    /** @return list<array<string, string>> */
    private function csv(string $texte): array
    {
        if ($texte === '') {
            return [];
        }
        $f = fopen('php://temp', 'r+b');
        \assert($f !== false);
        fwrite($f, $texte);
        rewind($f);
        $entetes = fgetcsv($f, null, ';', '"', '');
        $lignes = [];
        while (($l = fgetcsv($f, null, ';', '"', '')) !== false) {
            if ($l === [null] || !\is_array($entetes) || \count($l) !== \count($entetes)) {
                continue;
            }
            $lignes[] = array_combine($entetes, array_map(static fn ($v): string => (string) $v, $l));
        }
        fclose($f);

        return $lignes;
    }

    /** @return array{check: string, ok: bool, detail: string} */
    private function check(string $nom, bool $ok, string $detail): array
    {
        return ['check' => $nom, 'ok' => $ok, 'detail' => $detail];
    }

    /**
     * @param list<array{check: string, ok: bool, detail: string}> $checks
     *
     * @return array{intact: bool, checks: list<array{check: string, ok: bool, detail: string}>}
     */
    private function rapport(array $checks): array
    {
        return ['intact' => array_reduce($checks, static fn (bool $c, array $x): bool => $c && $x['ok'], true), 'checks' => $checks];
    }
}
