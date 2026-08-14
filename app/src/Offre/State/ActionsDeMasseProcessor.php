<?php

declare(strict_types=1);

namespace App\Offre\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Offre\Entity\Categorie;
use App\Offre\Entity\Produit;
use App\Offre\Service\TransitionProduitHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Actions de masse sur une sélection de produits (CA-2) : archiver / publier / recatégoriser.
 * L'action ne s'applique qu'aux produits sélectionnés. L'archivage étant irréversible, il exige
 * confirmer=true. Corps attendu :
 *   { "action": "archiver|publier|recategoriser", "produits": ["<uuid>", ...],
 *     "categorie": "<uuid>"?, "confirmer": true? }
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class ActionsDeMasseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransitionProduitHandler $handler,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $corps = $this->corps();
        $action = \is_string($corps['action'] ?? null) ? $corps['action'] : '';
        $ids = \is_array($corps['produits'] ?? null) ? $corps['produits'] : [];

        if (!\in_array($action, ['archiver', 'publier', 'recategoriser'], true)) {
            throw new UnprocessableEntityHttpException('Action inconnue (archiver|publier|recategoriser).');
        }
        if ($ids === []) {
            throw new UnprocessableEntityHttpException('Aucun produit sélectionné.');
        }
        if ($action === 'archiver' && ($corps['confirmer'] ?? false) !== true) {
            throw new UnprocessableEntityHttpException('L\'archivage est irréversible : confirmer=true requis.');
        }

        $categorie = null;
        if ($action === 'recategoriser') {
            $categorie = $this->resoudreCategorie($corps['categorie'] ?? null);
        }

        $traites = [];
        $echecs = [];
        foreach ($ids as $ref) {
            $produit = $this->resoudreProduit($ref);
            if ($produit === null) {
                $echecs[] = ['produit' => (string) $ref, 'raison' => 'introuvable'];
                continue;
            }
            try {
                $this->appliquer($action, $produit, $categorie);
                $produit->toucherModifieLe();
                $traites[] = (string) $produit->getId();
            } catch (\Throwable $e) {
                $echecs[] = ['produit' => (string) $produit->getId(), 'raison' => $e->getMessage()];
            }
        }

        $this->em->flush();

        return new JsonResponse([
            'action' => $action,
            'traites' => $traites,
            'echecs' => $echecs,
            'nbTraites' => \count($traites),
        ], JsonResponse::HTTP_OK);
    }

    private function appliquer(string $action, Produit $produit, ?Categorie $categorie): void
    {
        switch ($action) {
            case 'archiver':
                $this->handler->archiver($produit);
                break;
            case 'publier':
                $this->handler->publier($produit);
                break;
            case 'recategoriser':
                if ($categorie === null) {
                    throw new UnprocessableEntityHttpException('Catégorie cible obligatoire pour recatégoriser.');
                }
                // Remplace la catégorie du même axe.
                $axe = $categorie->getAxe();
                if ($axe !== null) {
                    $ancienne = $produit->getCategorieParAxe($axe);
                    if ($ancienne !== null) {
                        $produit->removeCategorie($ancienne);
                    }
                }
                $produit->addCategorie($categorie);
                break;
        }
    }

    /** @return array<string, mixed> */
    private function corps(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || $request->getContent() === '') {
            return [];
        }
        $decode = json_decode($request->getContent(), true);

        return \is_array($decode) ? $decode : [];
    }

    private function resoudreProduit(mixed $ref): ?Produit
    {
        if (!\is_string($ref) || $ref === '') {
            return null;
        }
        $segment = str_contains($ref, '/') ? basename($ref) : $ref;
        if (!Uuid::isValid($segment)) {
            return null;
        }

        return $this->em->getRepository(Produit::class)->find(Uuid::fromString($segment));
    }

    private function resoudreCategorie(mixed $ref): Categorie
    {
        if (!\is_string($ref) || $ref === '') {
            throw new UnprocessableEntityHttpException('Catégorie cible obligatoire pour recatégoriser.');
        }
        $segment = str_contains($ref, '/') ? basename($ref) : $ref;
        if (!Uuid::isValid($segment)) {
            throw new UnprocessableEntityHttpException('Référence de catégorie invalide.');
        }
        $categorie = $this->em->getRepository(Categorie::class)->find(Uuid::fromString($segment));
        if ($categorie === null) {
            throw new UnprocessableEntityHttpException('Catégorie introuvable.');
        }

        return $categorie;
    }
}
