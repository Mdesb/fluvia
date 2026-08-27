<?php

declare(strict_types=1);

namespace App\Legal\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Legal\Entity\LegalDocument;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /legal/publics/{establishmentId}` — ce que le visiteur d'une boutique peut lire, sans compte.
 *
 * **Public par nécessité, pas par facilité.** La LCEN exige des mentions « aisément accessibles ». Un
 * document derrière une authentification ne l'est pas : le visiteur qui hésite à acheter est
 * précisément celui qui n'a pas encore de compte.
 *
 * **Seul le publié sort.** Les brouillons portent l'avertissement de relecture et souvent des champs
 * non renseignés ; les versions remplacées ont cessé d'engager. Rendre l'un ou l'autre publierait un
 * texte que personne n'a validé — et sur ce sujet, publier trop est aussi fautif que ne rien publier.
 *
 * **Aucun cloisonnement à appliquer, et il faut le dire.** L'identifiant d'établissement est fourni par
 * l'URL, sans utilisateur ni en-tête `X-Etablissement` : c'est voulu, ces documents sont destinés au
 * public. Le seul risque serait d'exposer autre chose que du texte légal — d'où le fait que ce
 * fournisseur rende une charge **construite à la main**, champ par champ, plutôt que l'entité sérialisée.
 *
 * @cloisonnement-verifie: ressource volontairement publique. La LCEN exige des mentions
 * << aisement accessibles >> : un visiteur sans compte doit pouvoir les lire, il n'y a donc ni
 * utilisateur ni en-tete X-Etablissement a confronter. L'identifiant vient de l'URL, comme pour le
 * catalogue public d'une vitrine. Deux garde-corps remplacent le controle de perimetre : seuls les
 * documents PUBLIES sortent (un brouillon n'a pas ete relu), et la charge est construite champ par
 * champ plutot que serialisee depuis l'entite -- rien d'autre que du texte legal ne peut fuir par la.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class PublicLegalDocumentProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $id = $this->resoudreEtablissement($uriVariables);
        $etablissement = $id !== null ? $this->em->getRepository(Etablissement::class)->find($id) : null;

        if (!$etablissement instanceof Etablissement) {
            throw new NotFoundHttpException('Établissement introuvable.');
        }

        /** @var list<LegalDocument> $documents */
        $documents = $this->em->getRepository(LegalDocument::class)->findBy(
            ['establishment' => $etablissement, 'status' => LegalDocument::STATUS_PUBLISHED],
        );

        $charge = [];
        foreach ($documents as $document) {
            $charge[] = [
                'type' => $document->getType()->value,
                'slug' => $document->getType()->slug(),
                'titre' => $document->getTitle(),
                'contenu' => $document->getContent(),
                'version' => $document->getVersion(),
                'publieLe' => $document->getPublishedAt()?->format(\DATE_ATOM),
            ];
        }

        return new JsonResponse(['etablissement' => (string) $etablissement->getId(), 'documents' => $charge]);
    }

    /**
     * @param array<string, mixed> $uriVariables
     *
     * Même filet que `OptionsDisponiblesProvider` : le comportement d'`$uriVariables` n'est pas garanti
     * pour une clé personnalisée sur `GetCollection`, et un identifiant non résolu rendrait un 404 qui
     * ressemblerait à « cet établissement n'a pas de mentions légales ».
     */
    private function resoudreEtablissement(array $uriVariables): ?string
    {
        foreach (['establishmentId', 'establishment_id', 'id'] as $cle) {
            $valeur = $uriVariables[$cle] ?? null;
            if (\is_string($valeur) && $valeur !== '' && Uuid::isValid(basename($valeur))) {
                return basename($valeur);
            }
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null
            && preg_match('#/legal/publics/([^/]+)#', $request->getPathInfo(), $m) === 1
            && Uuid::isValid($m[1])
        ) {
            return $m[1];
        }

        return null;
    }
}
