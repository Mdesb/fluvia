<?php

declare(strict_types=1);

namespace App\Compta\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Compta\State\AdoptLegalVatRateProcessor;
use App\Compta\State\HideLegalVatRateProcessor;
use App\Compta\State\UnhideLegalVatRateProcessor;
use App\Compta\State\VatRateCatalogProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * LE CATALOGUE DES TAUX LEGAUX, VU DEPUIS UN ETABLISSEMENT — et pourquoi ce n'est pas l'entite.
 *
 * ⚠ CETTE VUE EXISTE PARCE QU'EXPOSER `LegalVatRate` DIRECTEMENT AURAIT DESSERRE UN CLIQUET.
 *
 * Le referentiel est global a dessein : un taux hongrois est un fait de droit, il ne se cloisonne
 * pas, et lui donner une copie par etablissement recreerait la proliferation qu'il corrige (31 taux
 * saisis a la main en base pour trois etablissements de demonstration). Mais le garde-fou de
 * couverture de perimetre refuse — a juste titre — toute ENTITE exposee que rien ne peut filtrer, et
 * son cliquet est opposable : le plafond ne remonte pas.
 *
 * Trois issues se presentaient, et deux etaient mauvaises :
 *
 *   — donner un `etablissement` au referentiel : un cloisonnement qui filtre a cote, « pire qu'une
 *     absence de filtre, parce qu'il rassure » — les mots de `AccountingScopeExtension` ;
 *   — faire remonter le plafond : desserrer un cliquet de cloisonnement pour une commodite, alors
 *     qu'il ne se resserre jamais tout seul ;
 *   — servir une VUE, contextuelle par construction. C'est celle-ci.
 *
 * La table reste globale ; ce qu'on EXPOSE est « les taux applicables ici », qui depend de
 * l'etablissement actif. La difference n'est pas cosmetique : la question qu'un exploitant se pose
 * n'est pas « quels taux existent dans l'Union » mais « lesquels puis-je employer », et la seconde a
 * un contexte.
 *
 * @sans-suppression: chacun des trois POST porte deja son inverse, ou vise une entite qui a le sien.
 *
 * ⚠ Le garde-fou a raison de demander, et la reponse merite d'etre nommee operation par operation
 * plutot que d'etre accordee en bloc :
 *
 *   /hide    a `/unhide` pour inverse, par le MEME identifiant. C'est ce qui en fait un masque et
 *            non une suppression : un oeil qu'on ne peut rallumer qu'en sachant comment il s'est
 *            eteint n'est pas un oeil.
 *   /unhide  supprime deja une ligne ; lui donner un DELETE n'aurait aucun sens.
 *   /adopt   cree un `TauxTva`, qui se desactive par son propre `Patch` (`actif: false`). Et il ne
 *            se SUPPRIME pas, volontairement : un taux employe par une facture ne s'efface pas sans
 *            effacer l'explication de cette facture. C'est la regle du referentiel de taux, pas une
 *            omission de cette vue.
 */
#[ApiResource(
    shortName: 'VatRateCatalog',
    operations: [
        new Get(
            uriTemplate: '/compta/vat-rate-catalog',
            security: "is_granted('PERM', 'compta.lire')",
            provider: VatRateCatalogProvider::class,
        ),
        // « Reprendre » : l'ecran n'envoie qu'un identifiant, le serveur compose le taux depuis la
        // source. Voir `AdoptLegalVatRateProcessor` pour les trois defauts que ce deplacement
        // supprime — dont une fonctionnalite qui s'eteignait chez les clients a plusieurs profils.
        // Masquer et demasquer par identifiant, pas par IRI : le referentiel n'etant pas une
        // ressource, il n'a pas d'IRI a donner. Deux operations symetriques plutot qu'un POST et un
        // DELETE sur une ligne de preference — l'ecran n'a alors aucun identifiant de masquage a
        // retenir, et un oeil qui se rallume ne demande pas de savoir comment il s'est eteint.
        new Post(
            uriTemplate: '/compta/vat-rate-catalog/hide',
            security: "is_granted('PERM', 'compta.gerer')",
            input: false,
            provider: VatRateCatalogProvider::class,
            processor: HideLegalVatRateProcessor::class,
        ),
        new Post(
            uriTemplate: '/compta/vat-rate-catalog/unhide',
            security: "is_granted('PERM', 'compta.gerer')",
            input: false,
            provider: VatRateCatalogProvider::class,
            processor: UnhideLegalVatRateProcessor::class,
        ),
        new Post(
            uriTemplate: '/compta/vat-rate-catalog/adopt',
            security: "is_granted('PERM', 'compta.gerer')",
            input: false,
            provider: VatRateCatalogProvider::class,
            processor: AdoptLegalVatRateProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['vat_catalog:read']],
)]
final class VatRateCatalog
{
    #[ApiProperty(identifier: true)]
    #[Groups(['vat_catalog:read'])]
    public string $id = 'catalog';

    /** Le pays effectivement lu — jamais deduit en silence, voir le fournisseur. */
    #[Groups(['vat_catalog:read'])]
    public string $country = '';

    /**
     * Les taux en vigueur, du plus eleve au plus bas.
     *
     * @var list<array<string, mixed>>
     */
    #[Groups(['vat_catalog:read'])]
    public array $rates = [];

    /**
     * Les identifiants des taux que CET exploitant a masques. Une simple liste suffit depuis que
     * demasquer se fait par le meme identifiant que masquer : plus de ligne de preference a
     * retrouver, donc plus rien a retenir cote ecran.
     *
     * ⚠ LES DEUX IDENTIFIANTS, ET PAS SEULEMENT LE PREMIER. Rendre la seule liste des taux masques
     * aurait dit a l'ecran QUOI cacher sans lui donner de quoi DEFAIRE — il aurait fallu une
     * seconde lecture pour retrouver la ligne de masquage a supprimer. Un oeil qui masque sans
     * pouvoir demasquer est une suppression deguisee.
     *
     * @var list<string>
     */
    #[Groups(['vat_catalog:read'])]
    public array $hidden = [];
}
