<?php

declare(strict_types=1);

namespace App\Support\Service;

use App\Organisation\Entity\Etablissement;
use App\Support\Entity\ArticleAide;
use App\Support\Entity\CategorieAide;
use App\Support\Entity\JournalImportAide;
use App\Support\Enum\OrigineArticle;
use App\Support\Enum\PorteeArticle;
use App\Support\Enum\PublicCible;
use App\Support\Enum\ResultatImport;
use App\Support\Enum\StatutArticle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Service applicatif du mécanisme « doc vivante → KB » (US-SUP-08, RG-SUP-07/08, §5 plan-support.md)
 * — réutilisé par `ImporterAideCommand` (`support:importer-aide`) **et**
 * `POST /support/import/executer`. Upsert par `cleImport` (index unique partiel), idempotence
 * stricte au hash inchangé, republication conditionnelle après import (RG-SUP-08).
 */
final class ImporteurAideService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArticleMarkdownParser $parser,
        private readonly ArticleAideEcritureService $ecriture,
        private readonly UtilisateurSystemeSupportResolver $utilisateurSysteme,
    ) {
    }

    public function executer(string $cheminBase, bool $dryRun = false): ResumeImportAide
    {
        $resume = new ResumeImportAide();

        if (!is_dir($cheminBase)) {
            return $resume;
        }

        $marqueurDryRun = new \RuntimeException('__support_import_dry_run__');

        try {
            $this->em->wrapInTransaction(function () use ($cheminBase, $resume, $dryRun, $marqueurDryRun): void {
                $this->executerInterne($cheminBase, $resume);
                if ($dryRun) {
                    throw $marqueurDryRun;
                }
            });
        } catch (\RuntimeException $e) {
            if ($e !== $marqueurDryRun) {
                throw $e;
            }
            // Dry-run : la transaction a été annulée par `wrapInTransaction` (rollback), le résumé
            // calculé reste néanmoins exploitable (§5.2 plan, --dry-run).
        }

        return $resume;
    }

    private function executerInterne(string $cheminBase, ResumeImportAide $resume): void
    {
        $finder = new Finder();
        $finder->files()->in($cheminBase)->name('*.md')->sortByName();

        foreach ($finder as $fichier) {
            $cheminRelatif = ltrim(str_replace('\\', '/', substr($fichier->getPathname(), \strlen(rtrim($cheminBase, '/\\')))), '/');

            try {
                $this->traiterFichier($fichier->getPathname(), $cheminRelatif, $resume);
            } catch (\InvalidArgumentException $e) {
                $this->journaliser($cheminRelatif, '', '', ResultatImport::Erreur, null, $e->getMessage());
                $resume->ajouter($cheminRelatif, ResultatImport::Erreur, $e->getMessage());
            }
        }
    }

    private function traiterFichier(string $cheminAbsolu, string $cheminRelatif, ResumeImportAide $resume): void
    {
        $brut = file_get_contents($cheminAbsolu);
        if ($brut === false) {
            throw new \InvalidArgumentException('Fichier illisible.');
        }

        $parse = $this->parser->parser($brut);
        $frontMatter = $parse['frontMatter'];
        $corps = $parse['corps'];

        $titre = $frontMatter['titre'] ?? null;
        $categorieSlug = $frontMatter['categorie'] ?? null;
        $publicCibleValeur = $frontMatter['publicCible'] ?? null;
        $porteeValeur = $frontMatter['portee'] ?? null;

        if (!\is_string($titre) || trim($titre) === '') {
            throw new \InvalidArgumentException('Champ "titre" requis.');
        }
        if (!\is_string($categorieSlug) || trim($categorieSlug) === '') {
            throw new \InvalidArgumentException('Champ "categorie" requis.');
        }
        $publicCible = \is_string($publicCibleValeur) ? PublicCible::tryFrom($publicCibleValeur) : null;
        if ($publicCible === null) {
            throw new \InvalidArgumentException('Champ "publicCible" requis, valeurs autorisées : agent|usager|tous.');
        }
        $portee = \is_string($porteeValeur) ? PorteeArticle::tryFrom($porteeValeur) : null;
        if ($portee === null) {
            throw new \InvalidArgumentException('Champ "portee" requis, valeurs autorisées : global|local.');
        }

        $etablissement = null;
        if ($portee === PorteeArticle::Local) {
            $nomEtablissement = $frontMatter['etablissement'] ?? null;
            if (!\is_string($nomEtablissement) || trim($nomEtablissement) === '') {
                throw new \InvalidArgumentException('Champ "etablissement" requis si portee=local.');
            }
            $etablissement = $this->em->getRepository(Etablissement::class)->findOneBy(['nom' => $nomEtablissement]);
            if (!$etablissement instanceof Etablissement) {
                throw new \InvalidArgumentException(sprintf('Établissement "%s" introuvable.', $nomEtablissement));
            }
        }

        $statutValeur = $frontMatter['statut'] ?? 'brouillon';
        $statutDemande = \is_string($statutValeur) ? StatutArticle::tryFrom($statutValeur) : null;
        if ($statutDemande === null || $statutDemande === StatutArticle::Archive) {
            throw new \InvalidArgumentException('Champ "statut" invalide, valeurs autorisées : brouillon|publie.');
        }

        $resume2 = $frontMatter['resume'] ?? null;
        $motsCles = $frontMatter['motsCles'] ?? null;
        $moduleLie = $frontMatter['moduleLie'] ?? null;

        [$moduleChemin, $cleImport] = $this->resoudreCleImport($cheminRelatif, $frontMatter);

        $hashSource = json_encode([
            'titre' => $titre,
            'categorie' => $categorieSlug,
            'publicCible' => $publicCible->value,
            'portee' => $portee->value,
            'etablissement' => $etablissement?->getNom(),
            'moduleLie' => $moduleLie,
            'statut' => $statutDemande->value,
            'resume' => $resume2,
            'motsCles' => $motsCles,
            'corps' => trim($corps),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', (string) $hashSource);

        $categorie = $this->resoudreOuCreerCategorie($categorieSlug);

        /** @var ArticleAide|null $article */
        $article = $this->em->getRepository(ArticleAide::class)->findOneBy([
            'cleImport' => $cleImport,
            'origine' => OrigineArticle::Import,
        ]);

        $systeme = $this->utilisateurSysteme->utilisateurSysteme();

        if ($article === null) {
            $article = new ArticleAide();
            $article->setTitre($titre)
                ->forcerSlug($this->sluggerCleImport($cleImport))
                ->setCategorie($categorie)
                ->setResume(\is_string($resume2) ? $resume2 : null)
                ->setContenu(trim($corps))
                ->setMotsCles(\is_array($motsCles) ? array_values(array_map('strval', $motsCles)) : null)
                ->setPortee($portee)
                ->setEtablissement($etablissement)
                ->setPublicCible($publicCible)
                ->setModuleLie(\is_string($moduleLie) ? $moduleLie : $moduleChemin)
                ->setAuteur($systeme)
                ->setOrigine(OrigineArticle::Import)
                ->setCleImport($cleImport)
                ->setStatut($statutDemande)
                ->setHashImportCourant($hash);

            $this->ecriture->enregistrer($article, $systeme, OrigineArticle::Import, true);

            $this->journaliser($cheminRelatif, $cleImport, $hash, ResultatImport::Cree, $article);
            $resume->ajouter($cheminRelatif, ResultatImport::Cree);

            return;
        }

        if ($article->getHashImportCourant() === $hash) {
            $this->journaliser($cheminRelatif, $cleImport, $hash, ResultatImport::Inchange, $article);
            $resume->ajouter($cheminRelatif, ResultatImport::Inchange);

            return;
        }

        $etaitPublie = $article->getStatut() === StatutArticle::Publie;

        $article->setTitre($titre)
            ->setCategorie($categorie)
            ->setResume(\is_string($resume2) ? $resume2 : null)
            ->setContenu(trim($corps))
            ->setMotsCles(\is_array($motsCles) ? array_values(array_map('strval', $motsCles)) : null)
            ->setModuleLie(\is_string($moduleLie) ? $moduleLie : $moduleChemin)
            ->setPublicCible($publicCible)
            ->setHashImportCourant($hash);

        if ($etaitPublie && $statutDemande !== StatutArticle::Publie) {
            // Dépublication automatique : le lecteur continue de voir la dernière version validée
            // (`versionPubliee` inchangée), RG-SUP-08/RG-SUP-03.
            $article->setStatut(StatutArticle::Brouillon);
        } else {
            // Conserve/atteint `publie` (mention explicite `statut: publie`, RG-SUP-08) ou reste au
            // statut demandé par le front matter (brouillon).
            $article->setStatut($statutDemande);
        }

        // `versionPubliee` republiée dès que le statut résultant est `publie` — que l'article ait déjà
        // été publié avant cet import ou non (republication assumée sur mention explicite RG-SUP-08).
        $republierSiPublie = $statutDemande === StatutArticle::Publie;
        $this->ecriture->enregistrer($article, $systeme, OrigineArticle::Import, $republierSiPublie);

        $this->journaliser($cheminRelatif, $cleImport, $hash, ResultatImport::Maj, $article);
        $resume->ajouter($cheminRelatif, ResultatImport::Maj);
    }

    /** @param array<string, mixed> $frontMatter @return array{0: string, 1: string} [module, cleImport] */
    private function resoudreCleImport(string $cheminRelatif, array $frontMatter): array
    {
        $cleExplicite = $frontMatter['cleImport'] ?? null;
        $cheminSansExtension = preg_replace('/\.md$/', '', str_replace('\\', '/', $cheminRelatif)) ?? $cheminRelatif;
        $segments = explode('/', $cheminSansExtension);
        $module = $segments[0] ?? 'divers';

        if (\is_string($cleExplicite) && trim($cleExplicite) !== '') {
            return [$module, trim($cleExplicite)];
        }

        return [$module, $cheminSansExtension];
    }

    private function sluggerCleImport(string $cleImport): string
    {
        return (new AsciiSlugger())->slug(str_replace('/', '-', $cleImport))->lower()->toString();
    }

    private function resoudreOuCreerCategorie(string $slug): CategorieAide
    {
        $slugNormalise = (new AsciiSlugger())->slug($slug)->lower()->toString();

        $existante = $this->em->getRepository(CategorieAide::class)->findOneBy(['slug' => $slugNormalise]);
        if ($existante instanceof CategorieAide) {
            return $existante;
        }

        $categorie = new CategorieAide();
        $categorie->setNom(ucfirst(str_replace('-', ' ', $slugNormalise)));
        $categorie->setSlug($slugNormalise);
        $this->em->persist($categorie);
        $this->em->flush();

        return $categorie;
    }

    private function journaliser(string $cheminRelatif, string $cleImport, string $hash, ResultatImport $resultat, ?ArticleAide $article, ?string $messageErreur = null): void
    {
        $journal = new JournalImportAide();
        $journal->setCheminFichier($cheminRelatif)
            ->setCleImport($cleImport)
            ->setHashContenu($hash)
            ->setResultat($resultat)
            ->setArticle($article)
            ->setMessageErreur($messageErreur);

        $this->em->persist($journal);
        $this->em->flush();
    }
}
