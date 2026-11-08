<?php

declare(strict_types=1);

namespace App\Acces\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Acces\State\OfflineTicketCheckBatchProcessor;
use App\Acces\State\OfflineTicketCheckListProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * LE CONTRÔLE MANUEL D'UN BILLET PENDANT UNE COUPURE RÉSEAU (#338).
 *
 * L'écran « Composter » (`POST /acces/controle-billet`) demande au serveur, billet par billet. Sans
 * réseau, l'agent n'avait plus rien : il laissait entrer tout le monde, ou personne. Deux routes :
 *
 * - `GET /acces/controle-billet/liste-locale` — la liste que le poste garde pour décider seul. Elle
 *   ne contient AUCUN identifiant de billet en clair : seulement une empreinte SHA-256 salée (le sel
 *   change à chaque téléchargement). Qui copierait la base du navigateur n'en tirerait pas un QR
 *   imprimable. Ni nom, ni client : rien de personnel n'y figure.
 * - `POST /acces/controle-billet/lot` — la remontée des contrôles faits pendant la coupure, rejouée
 *   dans l'ordre chronologique, idempotente par `cleIdempotence` (rejouer = no-op).
 *
 * @sans-suppression: un contrôle remonté est un FAIT (quelqu'un est entré ou a été refusé), comme le
 * contrôle en ligne (`ControleBillet`). L'effacer retirerait la réponse à « qui est entré, quand ».
 */
#[ApiResource(
    shortName: 'ControleBilletHorsLigne',
    operations: [
        new Get(
            uriTemplate: '/acces/controle-billet/liste-locale',
            security: "is_granted('PERM', 'acces.controler')",
            provider: OfflineTicketCheckListProvider::class,
            normalizationContext: ['groups' => ['controle_hors_ligne:read']],
        ),
        new Post(
            uriTemplate: '/acces/controle-billet/lot',
            read: false,
            input: false,
            security: "is_granted('PERM', 'acces.controler')",
            processor: OfflineTicketCheckBatchProcessor::class,
            description: 'Remonte les contrôles manuels faits hors connexion. Corps : { controles: [{ cleIdempotence: uuid, '
                . 'identifiantSupport: string, horodatage: iso8601 avec fuseau, verdictLocal: "valide"|"refuse", motifLocal?: string }] }. '
                . 'Réponse : { recus, resultats: [{ cleIdempotence, statut: accepte|doublon|rejete, enConflit }] }.',
        ),
    ],
)]
final class OfflineTicketCheck
{
    #[ApiProperty(identifier: true)]
    #[Groups(['controle_hors_ligne:read'])]
    public string $id = 'liste-locale';

    /** Instant de génération (ISO 8601) : l'écran dit à l'agent l'âge de sa liste. */
    #[Groups(['controle_hors_ligne:read'])]
    public string $genereeLe = '';

    /** Sel des empreintes de CETTE liste (hexadécimal). À renvoyer pour les pages suivantes. */
    #[Groups(['controle_hors_ligne:read'])]
    public string $sel = '';

    #[Groups(['controle_hors_ligne:read'])]
    public int $page = 1;

    #[Groups(['controle_hors_ligne:read'])]
    public bool $pageSuivante = false;

    /**
     * Les titres valides du site : `empreinte` (sha256(sel + identifiant)), `credit` (null = entrée
     * unique), `dejaControleLe` (entrée unique déjà consommée). Ni libellé ni porteur : le poste n'a
     * besoin que de décider, et tout ce qui est en plus est exposé sur le disque du poste.
     *
     * @var list<array{empreinte: string, credit: ?int, dejaControleLe: ?string}>
     */
    #[Groups(['controle_hors_ligne:read'])]
    public array $entrees = [];
}
