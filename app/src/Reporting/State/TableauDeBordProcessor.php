<?php

declare(strict_types=1);

namespace App\Reporting\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Reporting\Entity\Indicateur;
use App\Reporting\Entity\TableauDeBord;
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
 * COMPOSITION D'UN TABLEAU DE BORD — même défaut que l'objectif, en pire.
 *
 * ── CE QUI ÉTAIT CASSÉ ──────────────────────────────────────────────────────────────────────────
 *
 * `TableauDeBord` implémente `RattachementNiveauInterface` et utilise `RattachementNiveauTrait`,
 * dont les quatre propriétés de périmètre sont **privées, sans aucun setter** — seulement
 * `definirRattachement*()`. Or `Post` et `Patch` désérialisaient : le dénormaliseur n'avait rien à
 * appeler et ignorait `niveau`, `etablissement`, `region` et `groupe` en silence, alors que les
 * quatre sont annoncées écrivables par le groupe `tdb:write`.
 *
 * C'est le défaut déjà mesuré sur `ObjectifIndicateur` le 05/09 — 201 rendu avec le niveau par
 * défaut et aucune clé écrite. Il n'a pas été sondé ici, et délibérément :
 *
 * ⚠ **`TableauDeBord` NE SE SUPPRIME PAS, ET C'EST VOULU.** Son propre docbloc le pose : même
 * patron que `Indicateur`, on retire de la circulation par `Patch(actif=false)`, on n'efface
 * jamais. Ce n'est donc pas un `Delete` qui manque, c'est un `Delete` qu'on ne veut pas — et cela
 * change ce qu'on a le droit de faire pour prouver le défaut.
 *
 * Une ligne créée sans rattachement tombe hors de `PerimetreReportingExtension`, donc hors de la
 * collection ET de l'accès unitaire. Sur `ObjectifIndicateur` le `DELETE` répondait au moins 404 ;
 * ici il n'y en a aucun, par choix. Sonder par un corps valide aurait laissé une ligne que seul du
 * SQL pouvait retirer.
 *
 * La preuve est donc mécanique et non expérimentale, et chaque maillon a été lu plutôt que
 * supposé : le trait n'expose aucun setter, ses quatre propriétés sont privées, et le document
 * OpenAPI **servi par l'API en ligne** déclare les deux opérations avec un `requestBody`.
 *
 * ── L'ORDRE DES DEUX GESTES N'EST PAS INDIFFÉRENT ───────────────────────────────────────────────
 *
 * On résout l'entité **puis** on contrôle l'objet résolu, jamais l'identifiant reçu.
 * `bin/garde-fou-cloisonnement.php` a refusé deux fois l'ordre inverse sur le processeur
 * d'objectifs, en citant l'IDOR d'appairage du 22/08 : un contrôle qui ne porte pas sur ce qui a
 * été résolu ne suit pas le jour où la résolution change.
 */
final class TableauDeBordProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
        private readonly PerimetreReportingResolver $resolver,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TableauDeBord
    {
        $corps = $this->lecteur->corps();
        $utilisateur = $this->security->getUser();
        \assert($utilisateur instanceof Utilisateur);

        $tdb = $data instanceof TableauDeBord ? $data : new TableauDeBord();
        $creation = !$data instanceof TableauDeBord;

        // ── Le nom ──────────────────────────────────────────────────────────────────────────
        //
        // ⚠ UN NOM VIDE ENVOYÉ EXPRESSÉMENT DOIT S'ENTENDRE. La première version se contentait de
        // « si c'est une chaîne non vide, on pose » : un PATCH portant `nom: ""` ne remplissait
        // aucune branche, ne changeait rien, et rendait 200. Une écriture acceptée qui n'écrit
        // rien — la famille des « 200 menteurs » du dépôt, reproduite dans le correctif censé
        // en sortir une ressource.
        if (\array_key_exists('nom', $corps)) {
            $nom = $corps['nom'];
            if (!\is_string($nom) || trim($nom) === '') {
                throw new UnprocessableEntityHttpException('nom ne peut pas être vide.');
            }
            $tdb->setNom(trim($nom));
        } elseif ($creation) {
            throw new UnprocessableEntityHttpException('nom requis.');
        }

        // ── Le rattachement ─────────────────────────────────────────────────────────────────
        $niveauBrut = $corps['niveau'] ?? null;
        $entiteBrute = $corps['entiteId'] ?? $corps['etablissement'] ?? $corps['region'] ?? $corps['groupe'] ?? null;
        if (\is_string($niveauBrut) || \is_string($entiteBrute)) {
            $niveau = NiveauEntite::tryFrom((string) $niveauBrut);
            $entiteId = $this->uuidDepuis($entiteBrute);
            if ($niveau === null || $entiteId === null) {
                throw new UnprocessableEntityHttpException(
                    'niveau (etablissement|region|groupe) et entiteId requis ensemble : un tableau de '
                    . 'bord sans rattachement serait invisible pour tout le monde, y compris son '
                    . 'auteur — et cette ressource ne se supprime pas, par choix assumé.',
                );
            }

            // La résolution est une instruction affectée, et `$cible` — l'objet — est ce qui part
            // au contrôle. Voir le docbloc de classe : l'ordre inverse a été refusé deux fois.
            $classe = match ($niveau) {
                NiveauEntite::Etablissement => Etablissement::class,
                NiveauEntite::Region => Region::class,
                NiveauEntite::Groupe => Groupe::class,
            };
            $cible = $this->em->getRepository($classe)->find($entiteId);
            if ($cible === null) {
                throw new UnprocessableEntityHttpException('entiteId introuvable pour ce niveau.');
            }

            $this->verifierPerimetreEcriture($cible, $utilisateur);

            match (true) {
                $cible instanceof Etablissement => $tdb->definirRattachementEtablissement($cible),
                $cible instanceof Region => $tdb->definirRattachementRegion($cible),
                $cible instanceof Groupe => $tdb->definirRattachementGroupe($cible),
            };
        } elseif ($creation) {
            throw new UnprocessableEntityHttpException(
                'niveau et entiteId requis : sans eux le tableau de bord serait enregistré hors de '
                . 'tout périmètre, et aucune opération ne permettrait de le retrouver.',
            );
        }

        // ── Les indicateurs composés ────────────────────────────────────────────────────────
        //
        // ⚠ ABSENT N'EST PAS VIDE. Une clé `indicateurs` absente laisse la composition telle
        // quelle ; une liste vide la vide vraiment. Confondre les deux ferait qu'un PATCH portant
        // sur le seul nom effacerait tout le contenu du tableau — c'est la convention que le
        // frontal applique déjà entre « pas lu » et « lu et vide ».
        if (\array_key_exists('indicateurs', $corps)) {
            $liste = $corps['indicateurs'];
            if (!\is_array($liste)) {
                throw new UnprocessableEntityHttpException('indicateurs doit être une liste.');
            }

            $voulus = [];
            foreach ($liste as $ref) {
                if (!\is_string($ref) || $ref === '') {
                    throw new UnprocessableEntityHttpException('Chaque indicateur doit être une IRI ou un code.');
                }
                $indicateur = $this->indicateurDepuis($ref);
                if ($indicateur === null) {
                    throw new UnprocessableEntityHttpException(sprintf('indicateur introuvable : %s', $ref));
                }
                $voulus[$indicateur->getId()->toRfc4122()] = $indicateur;
            }

            // On retire ce qui sort avant d'ajouter ce qui entre, et on itère sur une COPIE :
            // retirer pendant le parcours de la collection sauterait un élément sur deux.
            foreach ($tdb->getIndicateurs()->toArray() as $present) {
                if (!isset($voulus[$present->getId()->toRfc4122()])) {
                    $tdb->removeIndicateur($present);
                }
            }
            foreach ($voulus as $indicateur) {
                $tdb->addIndicateur($indicateur);
            }
        }

        // ⚠ RG-M7-06 — « au moins un indicateur » — REPRISE ICI PARCE QU'ELLE VENAIT DE MOURIR.
        //
        // Elle vivait dans `Assert\Count(min: 1)` sur la collection. En passant les opérations en
        // `input: false`, plus rien n'est désérialisé et plus aucune validation ne s'exécute : la
        // règle serait devenue décorative sans que rien ne le dise. Le garde-fou n°34 ne l'a PAS
        // signalée — il apparie les contraintes aux champs qu'un processeur `set`, et une
        // collection se remplit par `add`/`remove`. C'est son angle mort, pas une absence de
        // risque : retirer la contrainte sans reposer la règle l'aurait effacée en silence.
        //
        // Le contrôle est ici et non dans le bloc au-dessus, pour qu'il vaille aussi quand la
        // requête ne parle pas des indicateurs : un tableau vidé par une autre voie serait refusé
        // tout autant.
        if ($tdb->getIndicateurs()->isEmpty()) {
            throw new UnprocessableEntityHttpException(
                'Un tableau de bord doit référencer au moins un indicateur (RG-M7-06).',
            );
        }

        // ── La mise en page et l'activation ─────────────────────────────────────────────────
        if (\array_key_exists('miseEnPage', $corps)) {
            $mise = $corps['miseEnPage'];
            if ($mise !== null && !\is_array($mise)) {
                throw new UnprocessableEntityHttpException('miseEnPage doit être un objet ou null.');
            }
            $tdb->setMiseEnPage($mise);
        }
        if (\array_key_exists('actif', $corps)) {
            if (!\is_bool($corps['actif'])) {
                throw new UnprocessableEntityHttpException('actif doit valoir true ou false.');
            }
            $tdb->setActif($corps['actif']);
        }

        if ($creation) {
            $this->em->persist($tdb);
        }
        $this->em->flush();

        return $tdb;
    }

    /**
     * LE CONTRÔLE PORTE SUR L'ENTITÉ RÉSOLUE, ET SUR RIEN D'AUTRE.
     *
     * ⚠ `configurer`, pas `lire` : composer un tableau de bord sur un site est une écriture sur ce
     * site. Quelqu'un qui peut lire une région ne doit pas pouvoir lui accrocher un tableau.
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

    /**
     * @cloisonnement-verifie: `Indicateur` N'A AUCUN PÉRIMÈTRE À CONFRONTER — mesuré, pas supposé.
     *
     * L'entité porte neuf propriétés : code, libellé, unité, mode de calcul, nature, module source,
     * seuil de complétude, actif, et son id. **Ni niveau, ni établissement, ni région, ni groupe.**
     * Elle n'implémente pas `RattachementNiveauInterface`, et `PerimetreReportingExtension` sort
     * immédiatement pour toute classe qui ne l'implémente pas. C'est un catalogue de définitions,
     * global : le `GetCollection` le rend déjà en entier à quiconque a `reporting.lire`.
     *
     * ⚠ CETTE EXEMPTION NE COUVRE QUE CETTE MÉTHODE, et le garde-fou ne sait pas la borner : son
     * motif cherche l'annotation dans **tout le fichier**. La résolution qui compte ici est celle
     * du rattachement, dans `process()`, affectée à `$cible` et contrôlée par
     * `verifierPerimetreEcriture()`. Si quelqu'un ajoute une résolution d'entité cloisonnée dans ce
     * fichier, cette ligne la rendra muette : c'est à vérifier à la main.
     *
     * Falsifiable en une commande — si `Indicateur` gagne un rattachement, elle doit tomber :
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
}
