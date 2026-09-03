<?php

declare(strict_types=1);

namespace App\Acces\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Acces\State\ControleBilletProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * CONTRÔLE MANUEL D'UN BILLET — l'outil de l'agent, là où il n'y a pas de matériel (D86, D87).
 *
 * Demandé par Maxime : « certains n'ont pas de contrôle d'accès, mais le billet pourra être quand
 * même validé par un contrôle manuel ». Sans cette route, un billet vendu sur un site sans
 * tourniquet était invendable en pratique — les deux entrées existantes
 * (`/acces/passages`, `/acces/passages/manuel`) exigent toutes deux une référence d'équipement.
 *
 * ⚠ CETTE ROUTE NE PARLE PAS DE PORTE, ET C'EST TOUT SON INTÉRÊT. Pas d'équipement, pas d'espace,
 * pas de zone déclarée, pas de jauge, pas d'anti-passback. Elle répond à « ce billet est-il
 * valide ? » et rien d'autre — la règle est celle de `VerdictBilletHandler`, partagée avec le
 * franchissement pour qu'aucun porteur ne se voie refuser à la porte ce qu'un agent vient de lui
 * accorder à la main.
 *
 * ⚠ ELLE CONSOMME, ET ELLE LE DIT. Tranché par Maxime. Sans consommation, l'outil ne remplace pas
 * un tourniquet et le même billet entre dix fois sans que personne ne s'en aperçoive. Le second
 * scan rend donc `deja_consomme` AVEC l'heure du premier (`dejaControleLe`) : « il y a trente
 * secondes » (l'agent a scanné deux fois) et « ce matin » (quelqu'un d'autre avec le même billet)
 * appellent des gestes opposés, et c'est l'agent qui tranche. C'est le seul des deux choix qui
 * laisse un humain rattraper.
 *
 * @sans-suppression: un contrôle est un FAIT, pas une saisie — l'effacer retirerait la réponse à
 * « qui est entré, où, quand », y compris pour un refus.
 *
 * ⚠ Ce POST crée un `Passage`, et aucun DELETE ne le rattrape. Ce n'est pas un oubli : un journal
 * d'accès qu'on peut effacer ne prouve plus rien, et il ne sert QU'À prouver. Un refus supprimé
 * serait pire encore — c'est justement la ligne qu'on voudra retrouver le jour où quelqu'un
 * conteste être resté dehors.
 *
 * Corriger une erreur ne passe donc pas par la suppression : un billet refusé par méprise se
 * rattrape en laissant entrer la personne, geste qui laisse sa propre trace. Deux lignes qui se
 * contredisent racontent ce qui s'est passé ; une ligne manquante ne raconte rien.
 */
#[ApiResource(
    shortName: 'ControleBillet',
    operations: [
        new Post(
            uriTemplate: '/acces/controle-billet',
            security: "is_granted('PERM', 'acces.controler')",
            input: false,
            processor: ControleBilletProcessor::class,
            normalizationContext: ['groups' => ['controle_billet:read']],
        ),
    ],
)]
final class ControleBillet
{
    #[ApiProperty(identifier: true)]
    #[Groups(['controle_billet:read'])]
    public string $id = 'controle';

    /** `valide` ou `refuse`. */
    #[Groups(['controle_billet:read'])]
    public string $resultat = 'refuse';

    #[Groups(['controle_billet:read'])]
    public ?string $codeMotif = null;

    /** Phrase pour l'agent — l'écran peut l'afficher telle quelle. */
    #[Groups(['controle_billet:read'])]
    public string $libelleMotif = '';

    /**
     * Ce que l'agent doit pouvoir lire sans le demander au porteur (R19).
     *
     * ⚠ `produit` RENDAIT L'ENUM DE SOURCE (« billet », « abonnement ») ET NON UN NOM, et `porteur`
     * valait `null` EN DUR — alors que l'ecran avait deja le code pour l'afficher. Les deux se
     * lisent maintenant sur la ligne de vente, ou le libelle est FIGE au moment de l'achat : un
     * produit renomme six mois plus tard ne change pas ce qu'un billet d'hier affirme.
     *
     * ⚠ `porteur` N'EST RENSEIGNE QUE POUR UN TITRE NOMINATIF — c'est-a-dire quand la ligne de
     * vente porte un beneficiaire. Arbitrage de Maxime : afficher le nom de quelqu'un sur un ecran
     * de controle l'expose a qui passe derriere l'agent, et un billet anonyme n'a personne a nommer.
     *
     * @var array{produit: ?string, tarif: ?string, prix: ?string, porteur: ?string, validite: array{debut: ?string, fin: ?string}}|null
     */
    #[Groups(['controle_billet:read'])]
    public ?array $billet = null;

    /**
     * `null` veut dire « ce billet n'a pas de notion de crédit » — une entrée unique — et RIEN
     * D'AUTRE. Il n'existe aucun chemin où le calcul échoue : `DroitAcces::$creditRestant` est une
     * colonne nullable, elle porte un entier ou rien. Un `null` qui dirait deux choses obligerait
     * l'écran à afficher la même chose pour « sans objet » et pour « je ne sais pas ».
     *
     * @var array{restant: int}|null
     */
    #[Groups(['controle_billet:read'])]
    public ?array $credit = null;

    /** Vrai si CE contrôle a consommé quelque chose. */
    #[Groups(['controle_billet:read'])]
    public bool $consomme = false;

    /**
     * Heure du contrôle précédent, en ISO — à côté du libellé, jamais dedans. Un écran qui devrait
     * analyser une phrase française pour retrouver l'heure casserait le jour où quelqu'un corrige
     * une faute d'orthographe.
     */
    #[Groups(['controle_billet:read'])]
    public ?string $dejaControleLe = null;
}
