<?php

declare(strict_types=1);

namespace App\Offre\Controller;

use App\Dms\DocumentStore;
use App\Offre\Entity\ProductPhoto;
use App\Offre\Enum\Canal;
use App\Offre\Enum\StatutProduit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /media/produit/{id}` — la photo d'un produit, servie au public.
 *
 * ── UNE BRÈCHE VOULUE, ET SEULEMENT CELLE-LÀ ────────────────────────────────────────────────────
 *
 * C'est une lecture **sans authentification** sur un contenu du DMS. Trois choses la bornent, et
 * chacune répond à un moyen d'en abuser :
 *
 *   1. **on ne peut demander qu'une photo.** L'identifiant est celui d'une ligne
 *      `offre_product_photo`, jamais celui d'un document. Aucun identifiant de bulletin de paie ne
 *      passe par cette porte : la table ne contient que des photos ;
 *   2. **le produit doit être réellement en vente en ligne** — publié ET au canal `en_ligne`. La
 *      photo d'un produit en brouillon reste privée : « Nouveauté été 2027 » ne se découvre pas en
 *      essayant des adresses ;
 *   3. **le type servi est celui qu'on a renifflé au téléversement**, jamais celui qu'annonçait le
 *      client, et `nosniff` interdit au navigateur de le réinterpréter.
 *
 * Sans le point 2, la route serait une fuite discrète : les photos existent avant l'annonce.
 *
 * ── POURQUOI PAS UN LIEN PUBLIC DU DMS ──────────────────────────────────────────────────────────
 *
 * Le DMS sait délivrer des liens publics, mais ils **expirent** et se **révoquent** — c'est leur
 * raison d'être. Une photo de catalogue qui cesse de s'afficher au bout de trente jours est un
 * défaut, pas une sécurité. La durée de vie de cette image est celle du produit ; c'est le produit
 * qui la gouverne, pas une horloge.
 */
#[AsController]
final readonly class ProductPhotoController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DocumentStore $documents,
    ) {
    }

    #[Route('/media/produit/{id}', name: 'offre_photo_produit', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException();
        }

        $photo = $this->entityManager->getRepository(ProductPhoto::class)->find(Uuid::fromString($id));
        if (!$photo instanceof ProductPhoto) {
            throw new NotFoundHttpException();
        }

        $produit = $photo->getProduit();
        if ($produit === null
            || $produit->getStatut() !== StatutProduit::Publie
            || !$produit->aCanal(Canal::EnLigne)
        ) {
            // 404 et non 403 : un refus explicite confirmerait qu'une photo existe pour un produit
            // qui n'est pas encore annoncé.
            throw new NotFoundHttpException();
        }

        $reference = $photo->getDocumentRef();
        $version = $reference === null ? null : $this->documents->currentVersion($reference);
        if ($version === null) {
            throw new NotFoundHttpException();
        }

        $flux = $this->documents->readContent($reference);

        $reponse = new StreamedResponse(static function () use ($flux): void {
            fpassthru($flux);
            fclose($flux);
        });

        $reponse->headers->set('Content-Type', $version->mimeType);
        $reponse->headers->set('Content-Length', (string) $version->sizeBytes);
        // Le navigateur n'a pas le droit de deviner un autre type que celui annoncé : c'est ce qui
        // empêche un fichier accepté par erreur d'être exécuté comme du script.
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->headers->set('Content-Disposition', 'inline');
        // Une photo de catalogue ne change pas : elle se remplace. Un cache long est donc sûr, et
        // c'est lui qui évite de déchiffrer le même fichier à chaque visiteur.
        $reponse->headers->set('Cache-Control', 'public, max-age=86400, immutable');

        return $reponse;
    }
}
