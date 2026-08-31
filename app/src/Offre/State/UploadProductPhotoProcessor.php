<?php

declare(strict_types=1);

namespace App\Offre\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dms\DocumentStore;
use App\Dms\Dto\StoreDocumentRequest;
use App\Dms\Enum\DocumentCategory;
use App\Offre\Doctrine\ProductScope;
use App\Offre\Entity\ProductPhoto;
use App\Offre\Entity\Produit;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /offre/produits/{id}/photos` — multipart, corps lu à la main (idiome du dépôt).
 *
 * ── LE TYPE EST RENIFLÉ, JAMAIS CRU ─────────────────────────────────────────────────────────────
 *
 * `UploadedFile::getClientMimeType()` est **déclaré par le navigateur**. Le DMS s'en contente, et il
 * le peut : ses documents ne se lisent qu'authentifié, avec un `Content-Disposition: attachment`.
 * Ici, la lecture est **publique et affichée en ligne** — un fichier qui se dit `image/png` et
 * contient du HTML deviendrait du script exécuté depuis notre propre domaine.
 *
 * On lit donc les octets. `getimagesize()` refuse tout ce qui n'est pas une image réelle, et c'est
 * son verdict — pas celui du client — qui est enregistré et renvoyé plus tard en `Content-Type`.
 *
 * > **Ce qu'on sert publiquement doit être vérifié dans les octets, pas dans l'en-tête.**
 *
 * ── L'ÉTABLISSEMENT VIENT DU SERVEUR ────────────────────────────────────────────────────────────
 *
 * D41. Le document est rangé dans l'établissement actif, jamais dans un identifiant reçu — sans quoi
 * on déposerait des fichiers dans le coffre du voisin.
 *
 * @implements ProcessorInterface<mixed, ProductPhoto>
 */
final readonly class UploadProductPhotoProcessor implements ProcessorInterface
{
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
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductPhoto
    {
        $requete = $this->requetes->getCurrentRequest();
        $etablissement = $this->contexte->etablissementActif();
        if ($requete === null || $etablissement === null) {
            throw new UnprocessableEntityHttpException(
                'Aucun établissement actif : impossible de ranger cette photo (D41).',
            );
        }

        $id = $uriVariables['id'] ?? null;
        if ($id === null) {
            throw new NotFoundHttpException('Produit introuvable.');
        }

        $produit = $this->produitVisible($id);

        $alt = trim((string) $requete->request->get('altText', ''));
        if ($alt === '') {
            throw new UnprocessableEntityHttpException(
                'Décrivez la photo en une phrase : sans texte alternatif, elle est invisible pour un '
                . 'lecteur d’écran et pour les moteurs de recherche (RGAA).',
            );
        }

        $fichier = $requete->files->get('file');
        if (!$fichier instanceof UploadedFile || !$fichier->isValid()) {
            throw new UnprocessableEntityHttpException('Aucun fichier reçu.');
        }
        if ($fichier->getSize() !== null && $fichier->getSize() > ProductPhoto::TAILLE_MAX) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Photo trop lourde (%d Ko) : %d Ko au maximum. Au-delà, c’est une image qu’on n’a pas '
                . 'redimensionnée, et la boutique la fera attendre à chaque visiteur.',
                (int) round(($fichier->getSize() ?? 0) / 1024),
                (int) round(ProductPhoto::TAILLE_MAX / 1024),
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
                title: 'Photo produit — ' . $produit->getCode(),
                content: $stream,
                originalFilename: $fichier->getClientOriginalName(),
                // Le type RENIFLÉ, jamais celui annoncé.
                mimeType: $type,
                sourceModule: 'offre',
                actor: $this->security->getUser() instanceof Utilisateur ? $this->security->getUser() : null,
            ));
        } finally {
            fclose($stream);
        }

        $photo = (new ProductPhoto())
            ->setProduit($produit)
            ->setDocumentRef($stocke->documentId)
            ->setAltText(mb_substr($alt, 0, 160))
            ->setPosition($this->prochainePosition($produit));

        $this->entityManager->persist($photo);
        $this->entityManager->flush();

        return $photo;
    }

    /**
     * Le produit, s'il est visible du lecteur — sinon il n'existe pas.
     *
     * @cloisonnement-verifie : resolution par `ProductScope::restreindre()`, la clause que
     * `PerimetreProduitExtension` applique deja aux collections du module (socle + etablissement
     * actif, D51). Ce n'est pas `codesEffectifs()` parce qu'un produit du socle n'appartient a AUCUN
     * etablissement : recalculer un droit contre un etablissement inexistant refuserait precisement
     * les produits partages. Refus en 404. — claude-A, 28/08
     */
    private function produitVisible(mixed $id): Produit
    {
        $qb = $this->entityManager->getRepository(Produit::class)->createQueryBuilder('p');
        ProductScope::restreindre($qb, 'p', $this->contexte->idActif());

        $produit = $qb->andWhere('p.id = :produit')
            ->setParameter('produit', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        if (!$produit instanceof Produit) {
            throw new NotFoundHttpException('Produit introuvable.');
        }

        return $produit;
    }

    /**
     * Le type d'image tel que les OCTETS le disent.
     *
     * `getimagesize()` lit l'en-tête du fichier ; il échoue sur tout ce qui n'est pas une image
     * qu'une bibliothèque graphique reconnaît. C'est exactement le contrôle qu'on veut : ce que le
     * navigateur d'un visiteur saura afficher.
     */
    private function typeReel(string $chemin): string
    {
        $mesure = @getimagesize($chemin);
        $type = \is_array($mesure) ? ($mesure['mime'] ?? null) : null;

        if (!\is_string($type) || !\in_array($type, ProductPhoto::TYPES_ACCEPTES, true)) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Ce fichier n’est pas une image affichable. Formats acceptés : %s.',
                implode(', ', array_map(
                    static fn (string $t): string => strtoupper(substr($t, 6)),
                    ProductPhoto::TYPES_ACCEPTES,
                )),
            ));
        }

        return $type;
    }

    private function prochainePosition(Produit $produit): int
    {
        // ⚠ D58 — `IDENTITY(...)` et le type explicite : passer l'entité lierait son identifiant
        // sans son type `uuid`, le maximum vaudrait zéro, et toutes les photos porteraient la
        // même position (garde-fou n°16).
        $max = $this->entityManager->getRepository(ProductPhoto::class)->createQueryBuilder('p')
            ->select('COALESCE(MAX(p.position), -1)')
            ->andWhere('IDENTITY(p.produit) = :produit')
            ->setParameter('produit', $produit->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $max + 1;
    }
}
