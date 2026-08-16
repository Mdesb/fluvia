<?php

declare(strict_types=1);

namespace App\Reporting\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Reporting\Entity\Export;
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
        $entiteIdBrut = $corps['entiteId'] ?? null;
        if ($niveau === null || !\is_string($entiteIdBrut) || !Uuid::isValid($entiteIdBrut)) {
            throw new UnprocessableEntityHttpException('niveau et entiteId requis (etablissement|region|groupe).');
        }
        $entiteId = Uuid::fromString($entiteIdBrut);

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

        $periode = Periode::jour();
        $entetes = ['indicateur', 'valeur', 'statutCompletude'];
        $lignes = [];
        foreach ($codes as $code) {
            $mesure = $this->lookup->trouver($code, $niveau, $entiteId, $periode);
            $lignes[] = [
                'indicateur' => $code,
                'valeur' => $mesure?->getValeur() ?? '',
                'statutCompletude' => $mesure?->getStatutCompletude()->value ?? '',
            ];
        }

        $export = new Export();
        $export->setFormat($format);
        $export->setDemandePar($utilisateur);
        $export->setAxesAppliques(['indicateurs' => $codes, 'periodeDebut' => $periode->debut->format('Y-m-d'), 'periodeFin' => $periode->fin->format('Y-m-d')]);
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
}
