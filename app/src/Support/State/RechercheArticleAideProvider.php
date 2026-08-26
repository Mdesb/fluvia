<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Securite\Entity\Utilisateur;
use App\Support\Entity\ArticleAide;
use App\Support\Service\EtablissementContexteResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * GET /support/articles/recherche (US-SUP-01/07, RG-SUP-06, CA-1/CA-6) : index MariaDB `FULLTEXT`
 * (mode *natural language*) sur `ArticleAide.rechercheTexte` (§2 plan). Les règles de visibilité
 * (statut publié, ciblage, portée/établissement) sont appliquées **avant** le `MATCH…AGAINST` en SQL
 * brut (Doctrine ORM/DQL n'a pas de support natif `FULLTEXT`). Tri : pertinence MariaDB puis article
 * local en tête (§0 décision n°4) puis date de création.
 *
 * @implements ProviderInterface<list<ArticleAide>>
 */
final class RechercheArticleAideProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly EtablissementContexteResolver $etablissementResolver,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $q = $request?->query->get('q');
        $q = \is_string($q) ? trim($q) : '';

        if ($q === '') {
            return [];
        }

        $conn = $this->em->getConnection();

        $conditions = ['statut = :support_rech_statut'];
        $params = ['support_rech_statut' => 'publie', 'support_rech_q1' => $q, 'support_rech_q2' => $q];

        $publics = $this->security->getUser() instanceof Utilisateur ? ['agent', 'usager', 'tous'] : ['usager', 'tous'];
        $placeholders = [];
        foreach ($publics as $i => $public) {
            $placeholders[] = ':support_rech_public' . $i;
            $params['support_rech_public' . $i] = $public;
        }
        $conditions[] = 'public_cible IN (' . implode(',', $placeholders) . ')';

        $etablissement = $this->etablissementResolver->resoudre();
        if ($etablissement !== null) {
            $conditions[] = "(portee = 'global' OR (portee = 'local' AND etablissement_id = :support_rech_etab))";
            $params['support_rech_etab'] = $etablissement->toBinary();
        } else {
            $conditions[] = "portee = 'global'";
        }

        $conditions[] = 'MATCH(recherche_texte) AGAINST (:support_rech_q1 IN NATURAL LANGUAGE MODE)';

        if ($request !== null) {
            $categorie = $request->query->get('categorie');
            if (\is_string($categorie) && $categorie !== '') {
                $conditions[] = 'categorie_id = :support_rech_categorie';
                $params['support_rech_categorie'] = Uuid::fromString(basename($categorie))->toBinary();
            }
            $publicCibleFiltre = $request->query->get('publicCible');
            if (\is_string($publicCibleFiltre) && $publicCibleFiltre !== '') {
                $conditions[] = 'public_cible = :support_rech_public_filtre';
                $params['support_rech_public_filtre'] = $publicCibleFiltre;
            }
            $moduleLie = $request->query->get('moduleLie');
            if (\is_string($moduleLie) && $moduleLie !== '') {
                $conditions[] = 'module_lie LIKE :support_rech_module';
                $params['support_rech_module'] = '%' . $moduleLie . '%';
            }
        }

        $sql = 'SELECT id, MATCH(recherche_texte) AGAINST (:support_rech_q2 IN NATURAL LANGUAGE MODE) AS pertinence '
            . 'FROM support_article_aide WHERE ' . implode(' AND ', $conditions)
            . " ORDER BY pertinence DESC, (portee = 'local') DESC, date_creation DESC LIMIT 50";

        $lignes = $conn->fetchAllAssociative($sql, $params);
        if ($lignes === []) {
            return [];
        }

        // @cloisonnement-verifie : le find() ci-dessous ne résout PAS un identifiant client — il
        // réhydrate les lignes déjà renvoyées par la requête SQL, laquelle applique le périmètre en
        // amont (statut = 'publie', puis « portee = global OU (portee = local ET etablissement_id =
        // :etab) », §2 plan-support.md). L'établissement de contexte non fiable n'élargit que des
        // articles déjà publics (Risque n°6, EtablissementContexteResolver). Aucun contenu hors
        // périmètre n'est donc atteignable par cette résolution. — claude-B, 26/08.
        $repo = $this->em->getRepository(ArticleAide::class);
        $entites = [];
        foreach ($lignes as $ligne) {
            $article = $repo->find(Uuid::fromBinary($ligne['id'])->toRfc4122());
            if ($article !== null) {
                $entites[] = $article;
            }
        }

        return $entites;
    }
}
