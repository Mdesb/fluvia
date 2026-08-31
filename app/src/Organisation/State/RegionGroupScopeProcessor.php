<?php

declare(strict_types=1);

namespace App\Organisation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Rattache une region au groupe de l'appelant, et refuse celui d'un autre.
 *
 * ⚠ DEUX DEFAUTS D'UN SEUL TENANT, ET C'EST LE MEME CHAMP QUI LES PORTE.
 *
 * `Region::$groupe` etait `nullable: false` ET dans le groupe d'ecriture, sans aucun processeur.
 * De la, deux consequences opposees :
 *
 *   1. L'ECRAN NE POUVAIT PAS CREER DE REGION. `RegionsSection.jsx` n'envoie que `nom` — c'est son
 *      unique champ. Le `groupe` manquant faisait echouer l'insertion sur une colonne non nulle.
 *      Or ce meme ecran dit : « Créez-en une avant votre premier établissement : un site ne peut
 *      pas exister sans elle. » Il envoyait donc l'exploitant faire un geste impossible.
 *
 *   2. QUI FOURNISSAIT LE CHAMP POUVAIT DESIGNER LE GROUPE D'UN AUTRE. Mesure du 31/08 sur base
 *      jetable : POST /api/regions avec l'IRI d'un groupe etranger rend **201**, et la ligne
 *      EXISTE. Voir `CloisonnementEcritureGroupeTest`.
 *
 * ⚠ POURQUOI CE DEFAUT-LA N'EST PAS RATTRAPE ALORS QUE SES VOISINS LE SONT. Quatre entites
 * comptables portent la meme forme et resistent pourtant a la meme attaque : API Platform resout
 * l'IRI d'une relation via le fournisseur d'item, et `ProfilExploitant` est cloisonne en lecture —
 * on ne peut pas ecrire ce qu'on ne peut pas nommer. `Groupe` echappe aux trois conditions : aucune
 * extension d'item ne le nomme, et son `Get` n'exige que `IS_AUTHENTICATED_FULLY`. N'importe quel
 * utilisateur connecte resout donc l'IRI de n'importe quel groupe.
 *
 * ── ⚠ ON REFUSE UN GROUPE ETRANGER, ON NE LE REMPLACE PAS ───────────────────────────────────────
 *
 * Estampiller par-dessus une valeur soumise ferait reussir une requete en faisant autre chose que
 * ce qu'elle demandait — l'appelant repartirait en croyant sa region posee la ou il l'a dite. Ce
 * depot a deja paye trois fois la confusion entre « accepte » et « enregistre » ; on n'y ajoute pas
 * « enregistre ailleurs ». Absent, on pose ; etranger, on refuse.
 *
 * Le message ne nomme pas le groupe vise : le nommer confirmerait son existence a qui essaie des
 * identifiants.
 *
 * @implements ProcessorInterface<Region, Region>
 */
final class RegionGroupScopeProcessor implements ProcessorInterface
{
    /** @param ProcessorInterface<Region, Region> $persist */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof Region);

        $mien = $this->groupeDeLAppelant();

        $soumis = $data->getGroupe();
        if (!$soumis instanceof Groupe) {
            $data->setGroupe($mien);

            return $this->persist->process($data, $operation, $uriVariables, $context);
        }

        if (!$soumis->getId()->equals($mien->getId())) {
            throw new NotFoundHttpException('Groupe introuvable.');
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }

    /**
     * Le groupe de l'appelant, deduit de l'etablissement actif : etablissement -> region -> groupe.
     *
     * ⚠ LA LIMITE EST REELLE ET ELLE EST DITE PLUTOT QUE DEVINEE. Un groupe qui n'aurait encore
     * aucun etablissement ne peut pas etre trouve par ce chemin — c'est le cas de la toute premiere
     * region d'un client tout neuf. Cette creation-la passe par le parcours d'ouverture de
     * structure, pas par cet ecran. Plutot que de choisir un groupe au hasard (« le seul en base »
     * marcherait en developpement et rattacherait un client a un autre en production), on refuse en
     * disant quoi faire.
     */
    private function groupeDeLAppelant(): Groupe
    {
        $etablissement = $this->contexte->etablissementActif();
        if (null === $etablissement) {
            throw new UnprocessableEntityHttpException(
                'Etablissement actif requis (en-tete X-Etablissement) : une region se cree dans le '
                . 'groupe du site ou vous travaillez.'
            );
        }

        $groupe = $etablissement->getRegion()?->getGroupe();
        if (!$groupe instanceof Groupe) {
            throw new UnprocessableEntityHttpException(
                'Le site actif n\'est rattache a aucun groupe : impossible d\'en deduire ou creer '
                . 'cette region.'
            );
        }

        return $groupe;
    }
}
