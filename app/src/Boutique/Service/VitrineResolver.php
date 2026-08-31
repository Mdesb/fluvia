<?php

declare(strict_types=1);

namespace App\Boutique\Service;

use App\Boutique\Config\ReservedHostnames;
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
    /**
     * @param string $domaineDePlateforme le domaine sous lequel vivent les sous-domaines clients
     *                                    (D104 : `fluvia-app.com`), lie depuis `PLATFORM_BASE_DOMAIN`
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $domaineDePlateforme = 'fluvia-app.com',
    ) {
    }

    /**
     * LA VITRINE DESIGNEE PAR L'HOTE : `piscine-ville.fluvia-app.com` -> la vitrine `piscine-ville`.
     *
     * D104, decide par Maxime le 31/08 : une boutique par sous-domaine, resolue depuis l'hote.
     * *« Ajouter cela maintenant coute peu ; le retro-adapter quand vingt clients ont des liens en
     * circulation coute cher. »*
     *
     * ── AUCUNE COLONNE N'EST NECESSAIRE ICI, ET C'EST LE POINT ────────────────────────────────
     *
     * L'etiquette du sous-domaine EST le slug. Le domaine propre a un client
     * (`billetterie.ville-x.fr`) en demanderait une -- mais D104 le repousse explicitement au
     * premier client qui le demande, et il lui faudrait d'abord un parcours de preuve de
     * propriete : un champ librement ecrivable laisserait un client capter le trafic d'un autre.
     *
     * ── QUATRE REFUS, ET CHACUN FERME UN CHEMIN REEL ──────────────────────────────────────────
     *
     * L'en-tete `Host` est fourni par l'appelant, et aucun `trusted_hosts` n'est declare dans ce
     * depot. Tout ce qui n'est pas reconnu doit donc rendre `null`, jamais une vitrine par defaut.
     *
     *   1. Le suffixe est teste AVEC SON POINT (`.fluvia-app.com`). Sans le point,
     *      `mechantfluvia-app.com` -- un domaine que n'importe qui peut acheter -- passerait.
     *   2. Une etiquette qui contient un point est refusee : `a.b.fluvia-app.com` ne doit pas
     *      chercher la vitrine nommee `a.b`.
     *   3. Une etiquette reservee (D106) ne resout jamais, meme si une vitrine ancienne en portait
     *      le nom. `pro.` sert le back-office ; il ne doit pas AUSSI servir une boutique.
     *   4. Le port est retire : `piscine-a.fluvia-app.com:8443` designe le meme hote.
     */
    public function resoudreParHote(?string $hote): ?Vitrine
    {
        if ($hote === null || $hote === '') {
            return null;
        }

        $hote = mb_strtolower(trim($hote));

        // `piscine-a.fluvia-app.com:8443` designe le meme hote que sans le port.
        if (str_contains($hote, ':')) {
            $hote = (string) strstr($hote, ':', true);
        }

        // ⚠ AVEC LE POINT. Sans lui, `mechantfluvia-app.com` satisferait `str_ends_with`.
        $suffixe = '.' . mb_strtolower($this->domaineDePlateforme);
        if (!str_ends_with($hote, $suffixe)) {
            return null;
        }

        $etiquette = substr($hote, 0, -\strlen($suffixe));

        // Une etiquette a un seul niveau. `a.b.fluvia-app.com` ne designe pas la vitrine « a.b ».
        if ($etiquette === '' || str_contains($etiquette, '.')) {
            return null;
        }

        // D106 : `pro.` sert le back-office. Il ne sert jamais AUSSI une boutique.
        if (ReservedHostnames::isReserved($etiquette)) {
            return null;
        }

        $vitrine = $this->em->getRepository(Vitrine::class)->findOneBy(['slug' => $etiquette]);

        return $vitrine instanceof Vitrine ? $vitrine : null;
    }

    /**
     * UNE VITRINE EST-ELLE VISIBLE D'UN VISITEUR PUBLIC ? (RG-M3-01)
     *
     * ⚠ Cette regle vivait uniquement dans `VitrinesPubliquesProvider`. La resolution par hote en a
     * besoin AUSSI -- et l'avoir recopiee aurait laisse un hote servir une vitrine depubliee le jour
     * ou l'une des deux copies aurait bouge. Un seul calcul, deux appelants.
     */
    public function estVisibleDuPublic(Vitrine $vitrine): bool
    {
        $etablissement = $vitrine->getEtablissement();

        if ($etablissement === null || !$etablissement->isActif()) {
            return false;
        }

        return \in_array('en_ligne', $vitrine->getCanauxActifs(), true);
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

        // ⚠ UN ETABLISSEMENT NOMME « Pro » PRODUIRAIT L'HOTE DU BACK-OFFICE (D106).
        //
        // Le refus pose sur l'entite ne suffit pas ici : `EstablishmentStampProcessor` appelle
        // cette fabrique depuis un *processor*, donc APRES la validation. Ce chemin-la ne passe
        // devant aucun `Assert`, et c'est celui que personne ne regarde -- l'exploitant ne saisit
        // rien, le nom apparait tout seul.
        if (ReservedHostnames::isReserved($slug)) {
            $slug .= '-boutique';
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
