<?php

declare(strict_types=1);

namespace App\Boutique\Controller;

use App\Boutique\Entity\Vitrine;
use App\Dms\DocumentStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /media/vitrine-logo/{id}` — le logo d'une boutique, servi au public.
 *
 * ── UNE BRÈCHE VOULUE, ET SEULEMENT CELLE-LÀ ────────────────────────────────────────────────────
 *
 * C'est une lecture **sans authentification** sur un contenu du DMS (la boutique est publique). Deux
 * choses la bornent, chacune contre un moyen d'en abuser :
 *
 *   1. **on ne peut demander qu'un logo de vitrine.** On ne sert le document `{id}` que si une
 *      `Vitrine` le référence RÉELLEMENT comme son logo (`logo = /media/vitrine-logo/{id}`). Un
 *      identifiant de bulletin de paie — ou même une photo de produit — ne passe pas par cette porte :
 *      aucune vitrine ne le désigne. C'est le même principe que `ProductPhotoController`, l'ancrage en
 *      plus (la vitrine) plutôt qu'une table dédiée ;
 *   2. **le type servi est celui qu'on a reniflé au téléversement** (jamais celui qu'annonçait le
 *      client), et `nosniff` interdit au navigateur de le réinterpréter.
 *
 * ── POURQUOI PAS UN LIEN PUBLIC DU DMS ──────────────────────────────────────────────────────────
 *
 * Les liens publics du DMS expirent et se révoquent. Un logo de boutique qui cesse de s'afficher au
 * bout de trente jours est un défaut, pas une sécurité : sa durée de vie est celle de la vitrine.
 */
#[AsController]
final readonly class VitrineLogoController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DocumentStore $documents,
    ) {
    }

    #[Route('/media/vitrine-logo/{id}', name: 'boutique_logo_vitrine', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException();
        }

        // Ancrage : on ne sert ce document que s'il est le logo déclaré d'une vitrine. Sans cette
        // vérification, la route servirait n'importe quel document du DMS dont on devinerait l'id.
        $vitrine = $this->entityManager->getRepository(Vitrine::class)
            ->findOneBy(['logo' => '/media/vitrine-logo/' . $id]);
        if (!$vitrine instanceof Vitrine) {
            throw new NotFoundHttpException();
        }

        $reference = Uuid::fromString($id);
        $version = $this->documents->currentVersion($reference);
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
        // Le navigateur n'a pas le droit de deviner un autre type : un fichier accepté par erreur ne
        // doit pas pouvoir être exécuté comme du script.
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->headers->set('Content-Disposition', 'inline');
        // Un logo se remplace plutôt qu'il ne change : un cache long est sûr et évite de déchiffrer le
        // même fichier à chaque visiteur.
        $reponse->headers->set('Cache-Control', 'public, max-age=86400, immutable');

        return $reponse;
    }
}
