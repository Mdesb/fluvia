<?php

declare(strict_types=1);

namespace App\Website\Service;

use App\Website\Entity\BlogPost;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Les données structurées des pages publiques — schema.org (ED-11).
 *
 * **À quoi ça sert vraiment, et ce n'est plus seulement le référencement.** Un moteur s'en sert pour
 * comprendre ce qu'est la page ; un assistant génératif s'en sert pour savoir **quoi citer** et
 * **comment nous nommer**. Une page sans balisage est lisible ; une page balisée est reprise avec
 * son nom, son auteur et sa date, au lieu d'être paraphrasée sans source.
 *
 * ⚠ **ON NE DÉCLARE QUE CE QUE LA PAGE MONTRE.** La tentation est d'ajouter un `Offer` avec un prix,
 * ou un `aggregateRating` avec quatre étoiles et demie : les deux améliorent l'affichage dans les
 * résultats, et les deux sont **faux**. Les prix vivent dans le catalogue et changent sans que ce
 * code le sache ; la note n'existe pas. Un balisage démenti par la page est sanctionné par les
 * moteurs — et repris tel quel par les assistants, ce qui est pire : l'erreur circule.
 *
 * ⚠ **`JSON_HEX_TAG` N'EST PAS COSMÉTIQUE.** Sans lui, un titre d'article contenant `</script>`
 * ferme le bloc `<script>` et rend la suite comme du HTML — sur une page publique, avec un contenu
 * que quelqu'un saisit dans un écran. C'est la raison pour laquelle ce JSON est fabriqué ici et non
 * dans un gabarit : Twig n'a pas de manière naturelle de poser ce drapeau, donc quelqu'un l'oublie.
 */
final readonly class StructuredData
{
    private const DRAPEAUX = \JSON_HEX_TAG | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR;

    public function __construct(
        private UrlGeneratorInterface $urls,
        /**
         * ⚠ L'ADRESSE PUBLIQUE DU SITE, PAS CELLE DE LA REQUÊTE.
         *
         * `UrlGeneratorInterface::ABSOLUTE_URL` construit depuis la requête ; derrière le proxy,
         * Symfony ne voit pas que l'appel est arrivé en HTTPS — aucun proxy de confiance n'est
         * déclaré — et rendait `http://`. Google traite `http` et `https` comme deux adresses
         * distinctes : la canonique et le plan du site désignaient des pages qui redirigent.
         *
         * Corrigé sans toucher `trusted_proxies`, qui décide aussi de l'adresse IP que voit le
         * limiteur de débit du tunnel : on ne paie pas une question de sécurité pour une question
         * d'affichage.
         */
        #[Autowire(env: 'VITRINE_BASE_URL')] private string $baseUrl = '',
    ) {
    }

    /** L'éditeur lui-même — présent sur toutes les pages. */
    public function organisation(): string
    {
        return $this->encoder([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => 'Fluvia',
            'url' => $this->absolue('website_home'),
            'logo' => rtrim($this->baseUrl, '/').'/marque/FLUVIA_Logo_Color.svg',
            'description' => "Plateforme de gestion pour les lieux qui accueillent du public : billetterie, "
                ."réservation, contrôle d'accès, caisse, boutique en ligne, CRM et facturation, activables module par module.",
            'areaServed' => 'FR',
            'knowsLanguage' => 'fr',
        ]);
    }

    /**
     * Le produit, sur la page d'accueil et sur chaque page de module.
     *
     * `SoftwareApplication` et non `Product` : c'est un logiciel en ligne, et le type dit à quoi on a
     * affaire sans avoir à l'inventer. `featureList` porte les modules réellement au catalogue — donc
     * la liste bouge avec le produit, et jamais avec ce fichier.
     *
     * @param list<string> $fonctionnalites
     */
    public function application(array $fonctionnalites, ?string $nomPrecis = null, ?string $description = null): string
    {
        return $this->encoder(array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => $nomPrecis ?? 'Fluvia',
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'url' => $this->absolue('website_home'),
            'inLanguage' => 'fr',
            'description' => $description,
            'featureList' => [] === $fonctionnalites ? null : $fonctionnalites,
            'publisher' => ['@type' => 'Organization', 'name' => 'Fluvia'],
        ], static fn (mixed $v): bool => null !== $v));
    }

    public function article(BlogPost $article): string
    {
        return $this->encoder(array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $article->getTitle(),
            'description' => $article->getMetaDescription() ?? $article->getExcerpt(),
            'url' => $this->absolue('website_blog_post', ['slug' => $article->getSlug()]),
            'datePublished' => $article->getPublishedAt()?->format(\DateTimeInterface::ATOM),
            'dateModified' => $article->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'image' => $article->getCoverUrl(),
            'inLanguage' => 'fr',
            // ⚠ L'AUTEUR N'EST DÉCLARÉ QUE S'IL EST AFFICHÉ. Mettre « Fluvia » par défaut ferait
            // signer par la maison un article que la page présente comme anonyme.
            'author' => null === $article->getAuthorName()
                ? null
                : ['@type' => 'Person', 'name' => $article->getAuthorName()],
            'publisher' => ['@type' => 'Organization', 'name' => 'Fluvia'],
        ], static fn (mixed $v): bool => null !== $v));
    }

    /**
     * Le fil d'Ariane — celui que la page affiche, pas un chemin inventé.
     *
     * @param list<array{nom: string, url: string}> $etapes
     */
    public function filDariane(array $etapes): string
    {
        $elements = [];

        foreach ($etapes as $rang => $etape) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $rang + 1,
                'name' => $etape['nom'],
                'item' => $etape['url'],
            ];
        }

        return $this->encoder([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $elements,
        ]);
    }

    /**
     * Les questions fréquentes.
     *
     * ⚠ **CHAQUE QUESTION DÉCLARÉE ICI DOIT ÊTRE VISIBLE SUR LA PAGE.** Un `FAQPage` qui contient des
     * réponses que le visiteur ne voit pas est du contenu caché : les moteurs le sanctionnent, et
     * c'est la faute la plus courante de ce balisage. Les gabarits rendent donc la même liste.
     *
     * @param list<array{question: string, reponse: string}> $questions
     */
    public function questions(array $questions): string
    {
        $entrees = [];

        foreach ($questions as $q) {
            $entrees[] = [
                '@type' => 'Question',
                'name' => $q['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q['reponse']],
            ];
        }

        return $this->encoder([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $entrees,
        ]);
    }

    /** @param array<string, mixed> $donnees */
    private function encoder(array $donnees): string
    {
        return json_encode($donnees, self::DRAPEAUX);
    }

    private function absolue(string $route, array $parametres = []): string
    {
        return rtrim($this->baseUrl, '/').$this->urls->generate($route, $parametres);
    }
}
