<?php

declare(strict_types=1);

namespace App\Boutique\Service;

use App\Boutique\Entity\Vitrine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

/**
 * RESOUT UNE VITRINE PAR SON IDENTIFIANT **OU** PAR SON NOM D'URL.
 *
 * **Pourquoi ça n'est pas deux points d'entrée.** Maxime, le 27/08 : *« il faudra penser à ce que
 * l'URL soit au nom du client et il y a `vitrine?vitrine=` c'est pas propre »*. Il a raison :
 * `…/vitrine?vitrine=77eee25d-0ab5-4e69-9e17-85242e79d5aa` n'est pas une adresse qu'on donne à un
 * client, qu'on imprime sur une affiche, ou qu'on dicte au téléphone.
 *
 * La tentation était d'ajouter des routes « par slug » à côté des routes « par identifiant ». Elle
 * aurait doublé **toute** la chaîne publique — vitrine, catalogue, créneaux — et chaque règle
 * (établissement inactif, canal en ligne coupé) aurait eu deux endroits où être vérifiée. D51, en
 * substance : *en inventer un second serait pire que le problème.*
 *
 * Une seule résolution, deux formes d'identifiant. Les URL existantes continuent de fonctionner —
 * elles sont peut-être déjà dans un courriel de confirmation, et un lien qu'on a envoyé ne se casse
 * pas parce qu'on a trouvé mieux.
 *
 * **L'ordre d'essai n'est pas anodin.** On teste l'UUID d'abord : c'est la seule forme dont on est sûr
 * qu'elle n'est pas ambiguë. Un slug ne peut pas ressembler à un UUID — `Uuid::isValid()` tranche —
 * donc aucune vitrine ne peut se rendre inatteignable en choisissant un nom malheureux.
 */
final class VitrineResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Rend la vitrine désignée, ou `null` — c'est à l'appelant de décider du 404.
     *
     * ⚠ **L'ARGUMENT N'EST PAS TOUJOURS UNE CHAÎNE, ET C'EST LE PIÈGE.**
     *
     * Sur une opération **item** (`GET /boutique/vitrines/{id}`), API Platform a déjà converti
     * l'identifiant : `$uriVariables['id']` est un objet `Uuid`. Sur une opération **collection** à
     * chemin personnalisé (`…/{id}/catalogue`), il reste une chaîne brute.
     *
     * Ma première version gardait `if (!is_string($reference)) return null;`. Résultat : le catalogue
     * marchait, la fiche de vitrine rendait 404 — « Vitrine introuvable » sur une vitrine qui existe.
     * Deux appelants, deux types, un seul garde : le test `CatalogueVitrineTest` l'a attrapé, pas la
     * relecture.
     *
     * On normalise donc en chaîne au lieu de refuser ce qui n'en est pas une.
     */
    public function resoudre(mixed $reference): ?Vitrine
    {
        if ($reference instanceof Uuid) {
            $vitrine = $this->em->getRepository(Vitrine::class)->find($reference);

            return $vitrine instanceof Vitrine ? $vitrine : null;
        }

        if (\is_object($reference) && method_exists($reference, '__toString')) {
            $reference = (string) $reference;
        }

        if (!\is_string($reference) || $reference === '') {
            return null;
        }

        // Une IRI (`/api/boutique/vitrines/<id>`) se réduit à son dernier segment, comme partout
        // ailleurs dans ce dépôt.
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        if (Uuid::isValid($segment)) {
            $vitrine = $this->em->getRepository(Vitrine::class)->find(Uuid::fromString($segment));

            return $vitrine instanceof Vitrine ? $vitrine : null;
        }

        $vitrine = $this->em->getRepository(Vitrine::class)->findOneBy(['slug' => $segment]);

        return $vitrine instanceof Vitrine ? $vitrine : null;
    }

    /**
     * Fabrique un nom d'URL à partir d'un libellé, sans jamais rendre une chaîne vide.
     *
     * **Le repli n'est pas décoratif.** Un établissement nommé « ﻿» ou « 中心 » produirait un slug vide,
     * donc une URL `/b/` qui ne désigne rien — et, avec la contrainte d'unicité, **une seule vitrine
     * au monde pourrait porter le slug vide**. La deuxième création échouerait sur une violation
     * d'intégrité que personne ne saurait relier à un nom d'établissement.
     */
    public function fabriquerSlug(string $libelle): string
    {
        $slug = (new AsciiSlugger('fr'))->slug($libelle)->lower()->toString();
        $slug = trim($slug, '-');

        if ($slug === '') {
            return 'boutique-' . substr(bin2hex(random_bytes(4)), 0, 6);
        }

        return mb_substr($slug, 0, 70);
    }

    /**
     * Le même, garanti libre : suffixe numérique en cas de collision.
     *
     * Deux communes voisines peuvent avoir une « piscine municipale ». La première prend
     * `piscine-municipale`, la seconde `piscine-municipale-2` — et l'exploitant reste libre de le
     * changer ensuite pour quelque chose de mieux.
     */
    public function slugLibre(string $libelle, ?Vitrine $sauf = null): string
    {
        $base = $this->fabriquerSlug($libelle);
        $candidat = $base;
        $rang = 1;

        while (true) {
            $existante = $this->em->getRepository(Vitrine::class)->findOneBy(['slug' => $candidat]);
            if (!$existante instanceof Vitrine || ($sauf !== null && $existante->getId()->equals($sauf->getId()))) {
                return $candidat;
            }
            ++$rang;
            $candidat = $base . '-' . $rang;
        }
    }
}
