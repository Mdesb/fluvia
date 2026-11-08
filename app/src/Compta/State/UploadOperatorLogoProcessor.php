<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Audit\Service\JournalAudit;
use App\Compta\Entity\ProfilExploitant;
use App\Dms\DocumentStore;
use App\Dms\Dto\StoreDocumentRequest;
use App\Dms\Enum\DocumentCategory;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /profil_exploitants/{id}/logo` (#345) — le logo de l'entité qui facture, imprimé en tête de la
 * facture PDF. Même idiome que `UploadVitrineLogoProcessor` (multipart lu à la main, DMS, type reniflé).
 *
 * ── ⚠ JPEG SEULEMENT — ET LE PNG N'EST PAS UN OUBLI ────────────────────────────────────────────
 *
 * Le logo est incrusté dans un PDF par Dompdf. Dompdf embarque un JPEG tel quel (DCTDecode), mais il
 * lui faut l'extension GD pour un PNG — et l'image PHP de production ne l'a pas (mesuré le
 * 05/11/2026 : `php -m` sans `gd` ; `Cpdf::addPngFromFile` lève « The PHP GD extension is required »).
 * Accepter un PNG ici, c'était rendre toute facture de ce profil impossible à générer. On refuse donc
 * à l'entrée ce que la sortie ne sait pas dessiner. Le SVG est refusé comme sur la vitrine.
 *
 * ── LE PROFIL DOIT ÊTRE CELUI DE L'ÉTABLISSEMENT ACTIF (D41) ────────────────────────────────────
 *
 * Un profil couvre son établissement principal et ses établissements rattachés. Hors de ce périmètre,
 * 404 et non 403 : un refus explicite confirmerait l'existence du profil d'un autre client.
 *
 * @implements ProcessorInterface<mixed, ProfilExploitant>
 */
final readonly class UploadOperatorLogoProcessor implements ProcessorInterface
{
    /** Un mégaoctet : un logo n'est pas une photo. */
    public const MAX_SIZE = 1024 * 1024;

    /** Ce que Dompdf dessine. */
    public const ACCEPTED_TYPES = ['image/jpeg'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RequestStack $requetes,
        private DocumentStore $documents,
        private ContexteEtablissement $contexte,
        private Security $security,
        private JournalAudit $journal,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProfilExploitant
    {
        $requete = $this->requetes->getCurrentRequest();
        $etablissement = $this->contexte->etablissementActif();
        if ($requete === null || $etablissement === null) {
            throw new UnprocessableEntityHttpException('Aucun établissement actif : impossible de ranger ce logo (D41).');
        }

        $id = $uriVariables['id'] ?? null;
        if ($id === null || !Uuid::isValid((string) $id)) {
            throw new NotFoundHttpException('Profil introuvable.');
        }
        $profil = $this->entityManager->getRepository(ProfilExploitant::class)->find(Uuid::fromString((string) $id));
        if (!$profil instanceof ProfilExploitant) {
            throw new NotFoundHttpException('Profil introuvable.');
        }

        // D41 — comparaison DIRECTE contre l'établissement de l'entité résolue, jamais contre l'en-tête.
        $actif = $etablissement->getId()?->toRfc4122();
        $couvert = $profil->getEtablissement()?->getId()?->toRfc4122() === $actif;
        foreach ($profil->getEtablissementsRattaches() as $rattache) {
            $couvert = $couvert || $rattache->getId()?->toRfc4122() === $actif;
        }
        if (!$couvert) {
            throw new NotFoundHttpException('Profil introuvable.');
        }

        $fichier = $requete->files->get('file');
        if (!$fichier instanceof UploadedFile || !$fichier->isValid()) {
            throw new UnprocessableEntityHttpException('Aucun fichier reçu.');
        }
        if ($fichier->getSize() !== null && $fichier->getSize() > self::MAX_SIZE) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Logo trop lourd (%d Ko) : %d Ko au maximum.',
                (int) round(($fichier->getSize() ?? 0) / 1024),
                (int) round(self::MAX_SIZE / 1024),
            ));
        }

        $mesure = @getimagesize($fichier->getPathname());
        $type = \is_array($mesure) ? ($mesure['mime'] ?? null) : null;
        if (!\is_string($type) || !\in_array($type, self::ACCEPTED_TYPES, true)) {
            throw new UnprocessableEntityHttpException(
                'Format non accepté pour l’en-tête de facture : choisissez une image JPEG (.jpg).',
            );
        }

        $flux = fopen($fichier->getPathname(), 'rb');
        if ($flux === false) {
            throw new UnprocessableEntityHttpException('Fichier illisible.');
        }
        try {
            $stocke = $this->documents->store(new StoreDocumentRequest(
                establishment: $etablissement,
                category: DocumentCategory::MarketingAsset,
                title: 'Logo de facturation — ' . ($profil->getRaisonSociale() ?? $profil->getSiren()),
                content: $flux,
                originalFilename: $fichier->getClientOriginalName(),
                mimeType: $type,
                sourceModule: 'compta',
                actor: $this->security->getUser() instanceof Utilisateur ? $this->security->getUser() : null,
            ));
        } finally {
            fclose($flux);
        }

        $avant = $profil->getLogoDocumentId()?->toRfc4122();
        $profil->setLogoDocumentId($stocke->documentId);

        $entree = $this->journal->enregistrer('operator_logo.set', ProfilExploitant::class, (string) $profil->getId(), $etablissement->getId());
        $entree->setValeurAvant(['logo' => $avant]);
        $entree->setValeurApres(['logo' => $stocke->documentId->toRfc4122()]);

        $this->entityManager->flush();

        return $profil;
    }
}
