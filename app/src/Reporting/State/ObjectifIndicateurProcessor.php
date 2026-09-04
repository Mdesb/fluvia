<?php

declare(strict_types=1);

namespace App\Reporting\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Reporting\Entity\Indicateur;
use App\Reporting\Entity\ObjectifIndicateur;
use App\Reporting\Enum\GranulariteMesure;
use App\Reporting\Enum\NiveauEntite;
use App\Reporting\Security\PerimetreReportingResolver;
use App\Reporting\Service\LecteurCorps;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * ÉCRITURE D'UN OBJECTIF — parce que la voie standard n'écrivait PAS son périmètre.
 *
 * ── LE DÉFAUT, MESURÉ AVANT D'ÊTRE CORRIGÉ ──────────────────────────────────────────────────────
 *
 * `POST /api/objectif_indicateurs` répondait **201** et enregistrait une ligne **sans aucun
 * rattachement**. Éprouvé contre la préproduction, avec le témoin qui ne laisse pas de doute :
 *
 *     envoyé    niveau: region · region: /api/regions/78cb…
 *     réponse   201 Created
 *     rendu     niveau: etablissement · region absente · etablissement absent
 *
 * `etablissement` n'était pas la valeur envoyée : c'était la **valeur par défaut de la propriété**.
 * Rien n'avait été écrit.
 *
 * La cause tient en une ligne : `RattachementNiveauTrait` n'expose **aucun setter**. Ni `setNiveau`,
 * ni `setEtablissement`, ni `setRegion`, ni `setGroupe` — seulement `definirRattachement*()`, qui
 * pose le niveau ET la clé ensemble et dénormalise les ancêtres. Le désérialiseur n'avait donc rien
 * à appeler, et ignorait les quatre champs en silence.
 *
 * ⚠ ET LA CONSÉQUENCE ALLAIT PLUS LOIN QUE L'INVISIBILITÉ. Sans rattachement, la ligne tombe hors
 * de `PerimetreReportingExtension` : elle disparaît de la collection **et de l'accès unitaire**.
 * Mesuré : `DELETE` sur une ligne qu'on venait de créer rendait **404**. L'API produisait des lignes
 * que personne ne pouvait plus ni lire ni retirer. Il a fallu du SQL pour les enlever.
 *
 * Famille des « 200 menteurs » du dépôt — une écriture acceptée qui n'enregistre rien — appliquée
 * ici à une **relation simple**, là où le garde-fou existant surveille les collections.
 *
 * ── POURQUOI UN PROCESSEUR, ET PAS TROIS SETTERS ────────────────────────────────────────────────
 *
 * Ajouter `setEtablissement()` rendrait le champ écrivable et **rouvrirait ce que le trait ferme** :
 * on pourrait poser `niveau: groupe` avec un `etablissement`, ou un rattachement dont la région ne
 * correspond pas à l'établissement. `definirRattachement*()` existe précisément pour rendre cet état
 * inatteignable — les trois méthodes dénormalisent les ancêtres elles-mêmes.
 *
 * Le module a déjà ce patron pour la même raison : `RapportPlanifieProcessor` et
 * `ExportManuelProcessor` lisent un corps JSON manuel (`input: false`) parce que leur contrôle de
 * périmètre est un calcul ensembliste, incompatible avec une dénormalisation.
 *
 * ── LE PÉRIMÈTRE SE VÉRIFIE EN ÉCRITURE, PAS EN LECTURE ─────────────────────────────────────────
 *
 * `ExportManuelProcessor` résout son périmètre avec `'lire'` — exporter, c'est sortir ce qu'on a le
 * droit de voir. Poser une cible est autre chose : c'est écrire sur un site. On résout donc avec
 * **`'configurer'`**, le droit que l'opération exige déjà. Sans ça, quelqu'un qui peut lire une
 * région pourrait lui fixer des objectifs.
 */
final class ObjectifIndicateurProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
        private readonly PerimetreReportingResolver $resolver,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ObjectifIndicateur
    {
        $corps = $this->lecteur->corps();
        $utilisateur = $this->security->getUser();
        \assert($utilisateur instanceof Utilisateur);

        $objectif = $data instanceof ObjectifIndicateur ? $data : new ObjectifIndicateur();
        $creation = !$data instanceof ObjectifIndicateur;

        // ── L'indicateur ────────────────────────────────────────────────────────────────────
        // Accepté sous IRI (`/api/indicateurs/<uuid>`) ou sous son code métier : l'écran a l'un,
        // un script d'intégration a souvent l'autre, et refuser le second n'apporte rien.
        $ref = $corps['indicateur'] ?? null;
        if (\is_string($ref) && $ref !== '') {
            $indicateur = $this->indicateurDepuis($ref);
            if ($indicateur === null) {
                throw new UnprocessableEntityHttpException('indicateur introuvable.');
            }
            $objectif->setIndicateur($indicateur);
        } elseif ($creation) {
            throw new UnprocessableEntityHttpException('indicateur requis.');
        }

        // ── Le rattachement, seul point qui manquait ────────────────────────────────────────
        $niveauBrut = $corps['niveau'] ?? null;
        $entiteBrute = $corps['entiteId'] ?? $corps['etablissement'] ?? $corps['region'] ?? $corps['groupe'] ?? null;
        if (\is_string($niveauBrut) || \is_string($entiteBrute)) {
            $niveau = NiveauEntite::tryFrom((string) $niveauBrut);
            $entiteId = $this->uuidDepuis($entiteBrute);
            if ($niveau === null || $entiteId === null) {
                throw new UnprocessableEntityHttpException(
                    'niveau (etablissement|region|groupe) et entiteId requis ensemble : un objectif sans '
                    . 'rattachement serait invisible pour tout le monde, y compris son auteur.',
                );
            }

            // ⚠ UNE SEULE RÉSOLUTION, AFFECTÉE, ET LE CONTRÔLE REÇOIT L'OBJET RÉSOLU.
            //
            // Deux formes ont été refusées par `bin/garde-fou-cloisonnement.php` avant celle-ci, et
            // il avait raison les deux fois. La première contrôlait `$entiteId` puis résolvait
            // ailleurs : rien ne liait les deux, et il suffisait qu'un jour la résolution prenne un
            // autre identifiant pour que la garde ne suive pas — le schéma exact de l'IDOR
            // d'appairage du 22/08 que son message cite. La seconde résolvait dans un `match` sans
            // affectation : sa règle n°2 le dit mieux que moi, une résolution non affectée est le cas
            // le plus net, il n'existe *aucune* variable à laquelle un contrôle pourrait se rattacher.
            //
            // Ici le dépot d'entité est choisi par le niveau, la résolution tient en une instruction
            // affectée, et `$cible` — l'objet, pas l'identifiant reçu — est ce qui part au contrôle.
            $classe = match ($niveau) {
                NiveauEntite::Etablissement => Etablissement::class,
                NiveauEntite::Region => Region::class,
                NiveauEntite::Groupe => Groupe::class,
            };
            $cible = $this->em->getRepository($classe)->find($entiteId);
            if ($cible === null) {
                // Le niveau est valide et l'UUID bien formé : c'est l'entité qui n'existe pas. On ne
                // rend pas 403, qui ferait chercher un problème de droits là où il n'y en a pas.
                throw new UnprocessableEntityHttpException('entiteId introuvable pour ce niveau.');
            }

            $this->verifierPerimetreEcriture($cible, $utilisateur);

            match (true) {
                $cible instanceof Etablissement => $objectif->definirRattachementEtablissement($cible),
                $cible instanceof Region => $objectif->definirRattachementRegion($cible),
                $cible instanceof Groupe => $objectif->definirRattachementGroupe($cible),
            };
        } elseif ($creation) {
            throw new UnprocessableEntityHttpException(
                'niveau et entiteId requis : sans eux, l\'objectif serait enregistré hors de tout '
                . 'périmètre et deviendrait irrécupérable.',
            );
        }

        // ── La période ──────────────────────────────────────────────────────────────
        //
        // ⚠ CE BLOC EXISTE PARCE QUE LE GARDE-FOU N°34 A POSÉ LA QUESTION, ET QUE LA RÉPONSE
        // ÉTAIT « le contrôle a disparu ». Il a signalé que `$periodeDebut`, `$periodeFin`,
        // `$indicateur`, `$granularite` et `$valeurCible` portent un `Assert\NotNull` **et** sont
        // posés ici. Sa documentation le dit : il signale une collision, pas une faute, et ne
        // tranche pas à la place du propriétaire du module. En regardant, c'était un trou :
        //
        //   API Platform valide ENTRE la désérialisation et le processeur. Avec `input: false`,
        //   il n'y a plus de désérialisation : ces cinq `NotNull` ne voient plus jamais la charge
        //   du client. Ils ne gardent plus rien.
        //
        // Pour trois d'entre eux c'est sans conséquence — `granularite` et `valeurCible` ont une
        // valeur par défaut, `indicateur` est déjà exigé plus haut. Mais **`periodeDebut` et
        // `periodeFin` sont typées non-nullables SANS défaut** : sans elles, la comparaison qui
        // suit lit une propriété typée jamais initialisée, ce qui est une `Error` — un 500, pas
        // un 422. Le processeur reprend à son compte ce que la validation ne peut plus faire.
        $debut = $this->dateEventuelle($corps, 'periodeDebut', $creation);
        if ($debut !== null) {
            $objectif->setPeriodeDebut($debut);
        }
        $fin = $this->dateEventuelle($corps, 'periodeFin', $creation);
        if ($fin !== null) {
            $objectif->setPeriodeFin($fin);
        }
        if ($objectif->getPeriodeFin() < $objectif->getPeriodeDebut()) {
            throw new UnprocessableEntityHttpException('periodeFin antérieure à periodeDebut.');
        }

        // ── La granularité et la cible ─────────────────────────────────────────────
        if (isset($corps['granularite']) && \is_string($corps['granularite'])) {
            $g = GranulariteMesure::tryFrom($corps['granularite']);
            if ($g === null) {
                throw new UnprocessableEntityHttpException('granularite invalide (jour|semaine|mois|annee).');
            }
            $objectif->setGranularite($g);
        }
        if (isset($corps['valeurCible'])) {
            $v = $corps['valeurCible'];
            if (!is_numeric($v)) {
                throw new UnprocessableEntityHttpException('valeurCible doit être un nombre.');
            }
            $objectif->setValeurCible(number_format((float) $v, 2, '.', ''));
        } elseif ($creation) {
            throw new UnprocessableEntityHttpException('valeurCible requise.');
        }

        if ($creation) {
            $this->em->persist($objectif);
        }
        $this->em->flush();

        return $objectif;
    }

    /**
     * Une date du corps, ou `null` s'il est légitime de laisser celle qui est déjà posée.
     *
     * ⚠ `new \DateTimeImmutable('la semaine prochaine peut-être')` lève une `Exception`, et une
     * exception non attrapée dans un processeur est un **500**. Une date malformée est une faute
     * du client, pas une panne du serveur : elle doit s'entendre comme telle, avec le nom du champ.
     *
     * \param array<string, mixed> $corps
     */
    private function dateEventuelle(array $corps, string $champ, bool $creation): ?\DateTimeImmutable
    {
        $brut = $corps[$champ] ?? null;

        if (!\is_string($brut) || trim($brut) === '') {
            if ($creation) {
                throw new UnprocessableEntityHttpException(sprintf('%s requise (AAAA-MM-JJ).', $champ));
            }

            return null;
        }

        try {
            return new \DateTimeImmutable($brut);
        } catch (\Exception) {
            throw new UnprocessableEntityHttpException(sprintf('%s illisible : attendu AAAA-MM-JJ.', $champ));
        }
    }

    /**
     * @cloisonnement-verifie: `Indicateur` N'A AUCUN PÉRIMÈTRE À CONFRONTER — mesuré, pas supposé.
     *
     * L'entité porte neuf propriétés : code, libellé, unité, mode de calcul, nature, module source,
     * seuil de complétude, actif, et son id. **Ni niveau, ni établissement, ni région, ni groupe.**
     * Elle n'implémente pas `RattachementNiveauInterface`, et `PerimetreReportingExtension` sort par
     * `if (!is_subclass_of($resourceClass, RattachementNiveauInterface::class)) return;` pour tout ce
     * qui ne l'implémente pas. C'est un catalogue de définitions de mesures, global : le
     * `GetCollection` le rend déjà en entier à quiconque a `reporting.lire`, sans filtre. Résoudre
     * un indicateur par son identifiant ne donne donc accès à rien que la collection ne donne déjà.
     *
     * ⚠ CETTE EXEMPTION NE COUVRE QUE CETTE MÉTHODE, et le garde-fou ne sait pas la borner : son
     * motif cherche l'annotation dans **tout le fichier**. La résolution qui compte vraiment ici —
     * celle de l'établissement, de la région ou du groupe — est dans `process()`, elle est affectée
     * à `$cible` et part au contrôle par `verifierPerimetreEcriture()`. Si quelqu'un ajoute un jour
     * une résolution d'entité cloisonnée dans ce fichier, cette ligne la rendra muette : c'est à
     * vérifier à la main, la règle ne le fera pas.
     *
     * Falsifiable en une commande : si `Indicateur` gagne un rattachement, cette justification
     * devient fausse et l'exemption doit tomber.
     *
     *     grep -nE 'niveau|etablissement|region|groupe' app/src/Reporting/Entity/Indicateur.php
     */
    private function indicateurDepuis(string $ref): ?Indicateur
    {
        $depot = $this->em->getRepository(Indicateur::class);
        $dernier = substr((string) strrchr($ref, '/'), 1);
        if ($dernier !== '' && Uuid::isValid($dernier)) {
            return $depot->find(Uuid::fromString($dernier));
        }
        if (Uuid::isValid($ref)) {
            return $depot->find(Uuid::fromString($ref));
        }

        return $depot->findOneBy(['code' => $ref]);
    }

    private function uuidDepuis(mixed $brut): ?Uuid
    {
        if (!\is_string($brut) || $brut === '') {
            return null;
        }
        $dernier = str_contains($brut, '/') ? substr((string) strrchr($brut, '/'), 1) : $brut;

        return Uuid::isValid($dernier) ? Uuid::fromString($dernier) : null;
    }

    /**
     * LE CONTRÔLE PORTE SUR L'ENTITÉ RÉSOLUE, ET SUR RIEN D'AUTRE.
     *
     * Il reçoit l'objet, jamais l'identifiant du corps : un contrôle qui juge la chaîne reçue au
     * lieu de la ligne trouvée est ce qui a produit quatre IDOR sur ce projet.
     *
     * ⚠ `configurer`, pas `lire`. `ExportManuelProcessor` résout son périmètre avec `lire` parce
     * qu'exporter, c'est sortir ce qu'on a le droit de voir. Poser une cible est une écriture sur
     * un site : sans ce durcissement, quelqu'un qui peut lire une région pourrait lui fixer des
     * objectifs.
     */
    private function verifierPerimetreEcriture(Etablissement|Region|Groupe $cible, Utilisateur $utilisateur): void
    {
        $perimetre = $this->resolver->perimetreEffectif($utilisateur, 'configurer');

        $autorise = match (true) {
            $cible instanceof Etablissement => $perimetre->estAutoriseEtablissement($cible->getId()),
            $cible instanceof Region => $perimetre->estAutoriseRegion($cible->getId()),
            $cible instanceof Groupe => $perimetre->estAutoriseGroupe($cible->getId()),
        };

        if (!$autorise) {
            throw new AccessDeniedHttpException('Entité hors périmètre (reporting.configurer).');
        }
    }
}
