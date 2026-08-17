<?php

declare(strict_types=1);

namespace App\Support\Service;

use App\Securite\Entity\Utilisateur;
use App\Support\Entity\ArticleAide;
use App\Support\Entity\VersionArticle;
use App\Support\Enum\OrigineArticle;
use App\Support\Enum\StatutArticle;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Point d'écriture unique de tout `ArticleAide` (création, modification manuelle, import) —
 * RG-SUP-03 : persiste les champs, crée systématiquement une nouvelle `VersionArticle` (numéro =
 * max+1), rafraîchit `rechercheTexte` (§2 plan) et `dateDerniereModification`. N'altère jamais
 * `statut`/`versionPubliee` sauf demande explicite de l'appelant (republication en ligne, §5.2 plan
 * pour le cas import — géré par `ImporteurAideService`, pas ici).
 *
 * Simplification assumée (⚠, hors lettre stricte spec) : une modification manuelle d'un article déjà
 * `publie` republie immédiatement (verrsionPubliee = nouvelle version) sans repasser par
 * `ArticlePublierProcessor` — seul le mécanisme d'**import** applique la règle de dépublication
 * explicite RG-SUP-08 (implémentée séparément par `ImporteurAideService`).
 */
final class ArticleAideEcritureService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function enregistrer(ArticleAide $article, Utilisateur $auteurVersion, OrigineArticle $origine, bool $republierSiPublie = true): VersionArticle
    {
        $article->rafraichirRechercheTexte();
        $article->toucherDateModification();

        $numero = $this->prochainNumero($article);

        $version = new VersionArticle();
        $version->setArticle($article)
            ->setNumero($numero)
            ->setContenu($article->getContenu())
            ->setStatutAuMoment($article->getStatut())
            ->setAuteur($auteurVersion)
            ->setOrigine($origine);

        $this->em->persist($article);
        $this->em->persist($version);

        if ($republierSiPublie && $article->getStatut() === StatutArticle::Publie) {
            $article->setVersionPubliee($version);
        }

        $this->em->flush();

        return $version;
    }

    private function prochainNumero(ArticleAide $article): int
    {
        if ($article->getId() === null) {
            return 1;
        }

        $max = $this->em->getRepository(VersionArticle::class)->createQueryBuilder('v')
            ->select('MAX(v.numero)')
            ->andWhere('v.article = :article')
            ->setParameter('article', $article->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return $max === null ? 1 : ((int) $max) + 1;
    }
}
