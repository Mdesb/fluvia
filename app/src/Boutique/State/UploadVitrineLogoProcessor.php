<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\Vitrine;
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
 * `POST /boutique/vitrines/{id}/logo` — le logo de la boutique, téléversé par l'exploitant
 * (multipart, corps lu à la main — idiome du dépôt, mêmes raisons que les photos de produit).
 *
 * ── LE TYPE EST RENIFLÉ, JAMAIS CRU ─────────────────────────────────────────────────────────────
 *
 * Le logo est servi **publiquement** sur la boutique (`/media/vitrine-logo/{doc}`), en ligne, sans
 * authentification. Un fichier qui se dit `image/png` et contient du HTML deviendrait du script
 * exécuté depuis notre propre domaine. On ne se fie donc pas à l'en-tête annoncé par le navigateur :
 * `getimagesize()` lit les OCTETS et n'accepte qu'une image raster réelle (jpeg/png/webp/avif).
 *
 * ⚠ LE SVG EST REFUSÉ, ET C'EST VOLONTAIRE. Un SVG est du XML qui peut porter du script ; le servir
 * publiquement sans le nettoyer serait une faille. Un logo raster (PNG de préférence) fait le travail.
 *
 * ── L'ÉTABLISSEMENT VIENT DU SERVEUR (D41) ──────────────────────────────────────────────────────
 *
 * La vitrine visée doit appartenir à l'établissement actif, sinon on déposerait un fichier chez le
 * voisin. Refus en 404 (et non 403) : un refus explicite confirmerait l'existence d'une vitrine
 * d'un autre établissement.
 *
 * ── OÙ VIT LE FICHIER ───────────────────────────────────────────────────────────────────────────
 *
 * Dans le **DMS** (`App\Dms\DocumentStore`), comme les photos de produit : un seul endroit où vivent
 * les fichiers, une seule façon de les chiffrer. La référence du document est encodée dans l'URL
 * publique `vitrine.logo` = `/media/vitrine-logo/{documentId}` ; c'est `VitrineLogoController` qui la
 * ressert, en n'exposant qu'un document réellement référencé par une vitrine comme son logo.
 *
 * @implements ProcessorInterface<mixed, Vitrine>
 */
final readonly class UploadVitrineLogoProcessor implements ProcessorInterface
{
    /** Un mégaoctet : un logo n'est pas une photo de catalogue. */
    public const TAILLE_MAX = 1024 * 1024;

    /** Ce qu'un navigateur sait afficher sans danger — le SVG en est volontairement absent. */
    public const TYPES_ACCEPTES = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RequestStack $requetes,
        private DocumentStore $documents,
        private ContexteEtablissement $contexte,
        private Security $security,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Vitrine
    {
        $requete = $this->requetes->getCurrentRequest();
        $etablissement = $this->contexte->etablissementActif();
        if ($requete === null || $etablissement === null) {
            throw new UnprocessableEntityHttpException(
                'Aucun établissement actif : impossible de ranger ce logo (D41).',
            );
        }

        $id = $uriVariables['id'] ?? null;
        if ($id === null || !Uuid::isValid((string) $id)) {
            throw new NotFoundHttpException('Vitrine introuvable.');
        }

        $vitrine = $this->entityManager->getRepository(Vitrine::class)->find(Uuid::fromString((string) $id));
        if (!$vitrine instanceof Vitrine) {
            throw new NotFoundHttpException('Vitrine introuvable.');
        }

        // D41 — l'autorité se recalcule contre l'établissement de l'ENTITÉ résolue, jamais contre
        // l'en-tête. Comparaison DIRECTE `$vitrine->getEtablissement()` (la forme que le garde-fou de
        // cloisonnement sait lire), le cas null ayant été écarté juste au-dessus. 404 et non 403 :
        // ne pas confirmer l'existence d'une vitrine d'un autre établissement.
        if ($vitrine->getEtablissement()?->getId()?->toRfc4122() !== $etablissement->getId()?->toRfc4122()) {
            throw new NotFoundHttpException('Vitrine introuvable.');
        }

        $fichier = $requete->files->get('file');
        if (!$fichier instanceof UploadedFile || !$fichier->isValid()) {
            throw new UnprocessableEntityHttpException('Aucun fichier reçu.');
        }
        if ($fichier->getSize() !== null && $fichier->getSize() > self::TAILLE_MAX) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Logo trop lourd (%d Ko) : %d Ko au maximum.',
                (int) round(($fichier->getSize() ?? 0) / 1024),
                (int) round(self::TAILLE_MAX / 1024),
            ));
        }

        $type = $this->typeReel($fichier->getPathname());

        $stream = fopen($fichier->getPathname(), 'rb');
        if ($stream === false) {
            throw new UnprocessableEntityHttpException('Fichier illisible.');
        }

        try {
            $stocke = $this->documents->store(new StoreDocumentRequest(
                establishment: $etablissement,
                category: DocumentCategory::MarketingAsset,
                title: 'Logo boutique — ' . ($etablissement->getNom() ?? ''),
                content: $stream,
                originalFilename: $fichier->getClientOriginalName(),
                // Le type RENIFLÉ, jamais celui annoncé.
                mimeType: $type,
                sourceModule: 'boutique',
                actor: $this->security->getUser() instanceof Utilisateur ? $this->security->getUser() : null,
            ));
        } finally {
            fclose($stream);
        }

        $vitrine->setLogo('/media/vitrine-logo/' . $stocke->documentId->toRfc4122());
        $this->entityManager->flush();

        return $vitrine;
    }

    /**
     * Le type d'image tel que les OCTETS le disent. `getimagesize()` échoue sur tout ce qui n'est pas
     * une image raster qu'une bibliothèque graphique reconnaît (le SVG, vectoriel, y échoue donc — et
     * c'est le comportement voulu, voir le docblock de classe).
     */
    private function typeReel(string $chemin): string
    {
        $mesure = @getimagesize($chemin);
        $type = \is_array($mesure) ? ($mesure['mime'] ?? null) : null;

        if (!\is_string($type) || !\in_array($type, self::TYPES_ACCEPTES, true)) {
            throw new UnprocessableEntityHttpException(
                'Format non accepté. Choisissez une image PNG, JPEG, WebP ou AVIF (le SVG n’est pas accepté).',
            );
        }

        return $type;
    }
}
