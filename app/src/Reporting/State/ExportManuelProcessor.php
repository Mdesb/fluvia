<?php

declare(strict_types=1);

namespace App\Reporting\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Reporting\Entity\Export;
use App\Reporting\Entity\Indicateur;
use App\Reporting\Enum\FormatExport;
use App\Reporting\Enum\NiveauEntite;
use App\Reporting\Enum\StatutExport;
use App\Reporting\Exception\GenerationExportNonSupporteeException;
use App\Reporting\Security\PerimetreReportingResolver;
use App\Reporting\Service\LecteurCorps;
use App\Reporting\Service\MesureLookupService;
use App\Reporting\Service\ResolveurGenerateurExport;
use App\Reporting\Service\StockageExportInterface;
use App\Reporting\ValueObject\Periode;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /reporting/exports` (export manuel, §2.8 plan-reporting.md, ⚠ HYPOTHÈSE reconduite §3
 * spec) : génère immédiatement un `Export(rapportPlanifie=null, demandePar=$user)` borné au
 * périmètre de l'appelant (`reporting.lire` = « exporter ce qu'on a le droit de voir »). Corps :
 *   { "format": "csv"|"pdf"|"xlsx", "niveau": "etablissement"|"region"|"groupe", "entiteId": uuid,
 *     "indicateurs"?: [code, ...] }
 *
 * @implements ProcessorInterface<mixed, Export>
 */
final class ExportManuelProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
        private readonly PerimetreReportingResolver $resolver,
        private readonly MesureLookupService $lookup,
        private readonly ResolveurGenerateurExport $resolveurGenerateur,
        private readonly StockageExportInterface $stockage,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Export
    {
        $corps = $this->lecteur->corps();
        $utilisateur = $this->security->getUser();
        \assert($utilisateur instanceof Utilisateur);

        $niveau = NiveauEntite::tryFrom((string) ($corps['niveau'] ?? ''));
        // On accepte l'IRI comme l'identifiant nu : l'ecran envoie l'une, un script d'integration
        // envoie souvent l'autre, et les processeurs voisins de ce module font deja les deux.
        // Auparavant `Uuid::isValid()` refusait l'IRI avec « niveau et entiteId requis », message
        // qui ne disait rien de la vraie cause.
        $entiteIdBrut = $corps['entiteId'] ?? null;
        $entiteIdNu = \is_string($entiteIdBrut) && str_contains($entiteIdBrut, '/')
            ? substr((string) strrchr($entiteIdBrut, '/'), 1)
            : $entiteIdBrut;
        if ($niveau === null || !\is_string($entiteIdNu) || !Uuid::isValid($entiteIdNu)) {
            throw new UnprocessableEntityHttpException('niveau et entiteId requis (etablissement|region|groupe).');
        }
        $entiteId = Uuid::fromString($entiteIdNu);

        $perimetre = $this->resolver->perimetreEffectif($utilisateur, 'lire');
        $autorise = match ($niveau) {
            NiveauEntite::Etablissement => $perimetre->estAutoriseEtablissement($entiteId),
            NiveauEntite::Region => $perimetre->estAutoriseRegion($entiteId),
            NiveauEntite::Groupe => $perimetre->estAutoriseGroupe($entiteId),
        };
        if (!$autorise) {
            throw new AccessDeniedHttpException('Entité hors périmètre (reporting.lire = exporter ce qu\'on a le droit de voir).');
        }

        $format = FormatExport::tryFrom((string) ($corps['format'] ?? 'csv')) ?? FormatExport::Csv;

        $codes = $corps['indicateurs'] ?? null;
        $codes = \is_array($codes) && $codes !== [] ? array_map('strval', $codes) : $this->codesActifs();

        // ── LA PERIODE, ET NON PLUS « AUJOURD'HUI » ─────────────────────────────────────
        //
        // Cette methode posait `Periode::jour()` en dur : l'export ne couvrait que la journee en
        // cours, ce qui ne sert a rien a qui doit produire un etat mensuel ou annuel. Les deux
        // dates sont desormais lues dans le corps, et restent facultatives — un appel qui n'en
        // envoie pas obtient la journee, comme avant.
        $jours = $this->joursDemandes($corps);

        $entetes = ['jour', 'indicateur', 'valeur', 'unite', 'etat', 'statutCompletude'];
        $lignes = [];
        $unites = $this->unitesParCode();

        foreach ($jours as $jour) {
            $periode = Periode::jour($jour);
            foreach ($codes as $code) {
                $mesure = $this->lookup->trouver($code, $niveau, $entiteId, $periode);

                // ⚠ UN JOUR NON AGREGE N'EST PAS UN ZERO, ET LE FICHIER DOIT LE DIRE LUI-MEME.
                // La version precedente sortait une valeur vide. Dans un tableur, une cellule vide
                // prise dans une somme vaut zero : le fichier aurait fait mentir un total chez son
                // destinataire, hors de toute application et sans que rien ne le rattrape. La
                // colonne `etat` porte la distinction, et la valeur reste vide.
                $lignes[] = [
                    'jour' => $jour->format('Y-m-d'),
                    'indicateur' => $code,
                    'valeur' => $mesure?->getValeur() ?? '',
                    'unite' => $unites[$code] ?? '',
                    'etat' => $mesure === null ? 'non agrege' : 'mesure',
                    'statutCompletude' => $mesure?->getStatutCompletude()->value ?? '',
                ];
            }
        }

        $export = new Export();
        $export->setFormat($format);
        $export->setDemandePar($utilisateur);
        // ⚠ LA PLAGE DEMANDEE, PAS LA VARIABLE DE BOUCLE. Cette ligne lisait `$periode`,
        // devenue la variable du `foreach` : elle enregistrait le dernier jour comme si
        // c'etait toute la periode. Le fichier etait juste, sa fiche mentait — et c'est la
        // fiche que l'ecran affiche dans la liste des exports demandes.
        $export->setAxesAppliques([
            'indicateurs' => $codes,
            'periodeDebut' => $jours[0]->format('Y-m-d'),
            'periodeFin' => $jours[array_key_last($jours)]->format('Y-m-d'),
        ]);
        $this->rattacher($export, $niveau, $entiteId);

        try {
            $genere = $this->resolveurGenerateur->pour($format)->generer($entetes, $lignes);
            $reference = $this->stockage->stocker($genere->contenu, $genere->extension);
            $export->setCheminStockage($reference);
            $export->setStatut(StatutExport::Genere);
        } catch (GenerationExportNonSupporteeException $e) {
            $export->setStatut(StatutExport::Echec);
            $export->setMessageErreur($e->getMessage());
        }

        $this->em->persist($export);
        $this->em->flush();

        return $export;
    }

    private function rattacher(Export $export, NiveauEntite $niveau, Uuid $entiteId): void
    {
        match ($niveau) {
            NiveauEntite::Etablissement => $export->definirRattachementEtablissement($this->trouverOu404(Etablissement::class, $entiteId)),
            NiveauEntite::Region => $export->definirRattachementRegion($this->trouverOu404(Region::class, $entiteId)),
            NiveauEntite::Groupe => $export->definirRattachementGroupe($this->trouverOu404(Groupe::class, $entiteId)),
        };
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function trouverOu404(string $classe, Uuid $id): object
    {
        $entite = $this->em->getRepository($classe)->find($id);
        if ($entite === null) {
            throw new NotFoundHttpException('Entité introuvable.');
        }

        return $entite;
    }

    /** @return list<string> */
    private function codesActifs(): array
    {
        $indicateurs = $this->em->getRepository(\App\Reporting\Entity\Indicateur::class)->findBy(['actif' => true]);

        return array_map(static fn ($i): string => $i->getCode(), $indicateurs);
    }

    /** Nombre de jours qu'un seul export peut couvrir. Au-dela, on refuse en le nommant. */
    private const JOURS_MAX = 366;

    /**
     * Les jours couverts par l'export, du plus ancien au plus recent.
     *
     * ⚠ UNE REQUETE PAR JOUR ET PAR INDICATEUR. C'est le prix de la granularite reelle des
     * mesures — `MesureLookupService::trouver` compare une cle d'agregation qui inclut les dates
     * exactes, donc demander une plage d'un coup ne trouve rien. D'ou la borne : sans elle, une
     * demande de dix ans sur neuf indicateurs lancerait plus de trente mille requetes.
     *
     * \param array<string, mixed> $corps
     *
     * \return list<\DateTimeImmutable>
     */
    private function joursDemandes(array $corps): array
    {
        $debut = $this->dateOuNull($corps['periodeDebut'] ?? null, 'periodeDebut');
        $fin = $this->dateOuNull($corps['periodeFin'] ?? null, 'periodeFin');

        if ($debut === null && $fin === null) {
            return [new \DateTimeImmutable('today')];
        }
        $debut ??= $fin;
        $fin ??= $debut;
        \assert($debut instanceof \DateTimeImmutable && $fin instanceof \DateTimeImmutable);

        if ($fin < $debut) {
            throw new UnprocessableEntityHttpException('periodeFin anterieure a periodeDebut.');
        }

        $nombre = (int) $debut->diff($fin)->days + 1;
        if ($nombre > self::JOURS_MAX) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Periode trop longue : %d jours demandes, %d au maximum par export. '
                . 'Decoupez en plusieurs exports.',
                $nombre,
                self::JOURS_MAX,
            ));
        }

        $jours = [];
        for ($i = 0; $i < $nombre; ++$i) {
            $jours[] = $debut->modify(sprintf('+%d days', $i));
        }

        return $jours;
    }

    private function dateOuNull(mixed $brut, string $champ): ?\DateTimeImmutable
    {
        if (!\is_string($brut) || trim($brut) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable(substr(trim($brut), 0, 10));
        } catch (\Exception) {
            throw new UnprocessableEntityHttpException(sprintf('%s illisible : attendu AAAA-MM-JJ.', $champ));
        }
    }

    /**
     * L'unite de chaque indicateur, par code.
     *
     * Elle est dans le fichier parce que le destinataire n'a pas l'application sous les yeux :
     * une colonne de nombres sans unite se lit de travers, et une somme d'euros melangee a des
     * pourcentages ne se voit pas.
     *
     * \return array<string, string>
     */
    private function unitesParCode(): array
    {
        $par = [];
        foreach ($this->em->getRepository(Indicateur::class)->findAll() as $indicateur) {
            $par[$indicateur->getCode()] = $indicateur->getUnite()->value;
        }

        return $par;
    }

}
