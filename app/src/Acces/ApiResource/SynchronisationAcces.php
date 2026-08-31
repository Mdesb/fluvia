<?php

declare(strict_types=1);

namespace App\Acces\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\RequestBody as OpenApiRequestBody;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\Acces\State\EtatSynchroAccesProvider;
use App\Acces\State\SynchroProcessor;
use App\Acces\State\TerminalSynchroProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Synchronisation hors-ligne (US-L3-07/08, RG-ACC-05, CA-8/9). N'est pas une entité Doctrine : expose
 * l'état réseau des contrôleurs et le point d'ancrage du rejeu chronologique idempotent.
 */
#[ApiResource(
    shortName: 'SynchronisationAcces',
    operations: [
        new Get(
            uriTemplate: '/acces/synchro/etat',
            security: "is_granted('PERM', 'acces.superviser')",
            provider: EtatSynchroAccesProvider::class,
            normalizationContext: ['groups' => ['synchro_acces:read']],
        ),
        new Post(
            uriTemplate: '/acces/synchro',
            read: false,
            input: false,
            security: "is_granted('PERM', 'acces.ingestion')",
            processor: SynchroProcessor::class,
        ),
        // Nouvelle opération terminal (US-TERM-06/07/08, plan-acces-terminal.md §2.4) : généralise
        // `SynchroPassageHandler::synchroniser()` au niveau `Terminal` (plusieurs Controleur/Equipement
        // d'un même itboxRef). `POST /acces/synchro` ci-dessus reste strictement inchangé.
        new Post(
            uriTemplate: '/terminal/passages/lot',
            read: false,
            input: false,
            security: "is_granted('PERM_TERMINAL', 'acces.ingestion')",
            processor: TerminalSynchroProcessor::class,
            // T21 (plan-acces-terminal.md §7 Lot E) : réponse `JsonResponse` construite à la main
            // (`TerminalSynchroProcessor`), non inférable. Remontée hors-ligne d'un lot : rejeu
            // chronologique idempotent, borné à la portée du terminal. Contrairement à
            // `/terminal/passages` (en ligne), le lot ne renvoie PAS `message`/`affichage` (aucun écran
            // à servir en différé) mais un `resultats[]` de réconciliation par clé d'idempotence.
            openapi: new OpenApiOperation(
                summary: 'Remonter un lot de passages hors-ligne depuis une borne (ITBOX).',
                description: 'Rejeu chronologique idempotent d\'un lot accumulé hors-ligne. Chaque entrée est réconciliée '
                    . 'par sa `cleIdempotence` (rejouable sans double décompte) ; une entrée hors de la portée du terminal '
                    . 'est rejetée sans bloquer le reste. Un écart d\'horloge > 5 min est signalé (`ecartHorlogeSuspect`) '
                    . 'sans jamais rejeter l\'entrée.',
                requestBody: new OpenApiRequestBody(
                    description: 'Lot de passages accumulés hors-ligne.',
                    content: new \ArrayObject([
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['lot'],
                                'properties' => [
                                    'lot' => [
                                        'type' => 'array',
                                        'description' => 'Passages à rejouer, dans l\'ordre chronologique.',
                                        'items' => [
                                            'type' => 'object',
                                            'required' => ['equipementId', 'cleIdempotence'],
                                            'properties' => [
                                                'equipementId' => ['type' => 'string', 'description' => 'UUID ou IRI (`/api/equipements/{uuid}`) ; doit appartenir à la portée du terminal.'],
                                                'cleIdempotence' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Clé stable engendrée à la borne, rejouée à l\'identique.'],
                                                'identifiantSupport' => ['type' => 'string', 'nullable' => true],
                                                'sens' => ['type' => 'string', 'enum' => ['entree', 'sortie'], 'nullable' => true],
                                                'horodatage' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'Horodatage retenu pour le rejeu (défaut : `horodatageBorne`).'],
                                                'horodatageBorne' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'Horloge de la borne au moment du scan (calcul de l\'écart d\'horloge).'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ]),
                    required: true,
                ),
                responses: [
                    '200' => new OpenApiResponse(
                        description: 'Compte-rendu de réconciliation, une ligne par clé d\'idempotence reçue.',
                        content: new \ArrayObject([
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['recus', 'resultats'],
                                    'properties' => [
                                        'recus' => ['type' => 'integer', 'description' => 'Nombre d\'entrées reçues dans le lot.'],
                                        'resultats' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'required' => ['cleIdempotence', 'statut', 'enConflit', 'ecartHorlogeSuspect'],
                                                'properties' => [
                                                    'cleIdempotence' => ['type' => 'string', 'format' => 'uuid'],
                                                    'statut' => ['type' => 'string', 'enum' => ['accepte', 'doublon', 'rejete'], 'description' => '`accepte` (rejoué), `doublon` (déjà vu, sans re-décompte), `rejete` (hors portée ou invalide).'],
                                                    'codeMotif' => ['type' => 'string', 'nullable' => true, 'description' => 'Renseigné pour un rejet (ex. `hors_portee`). App\\Acces\\Enum\\CodeMotifRefus.'],
                                                    'enConflit' => ['type' => 'boolean', 'description' => 'Le rejeu a détecté une divergence avec l\'état serveur (tracée dans le journal de réconciliation).'],
                                                    'ecartHorlogeSuspect' => ['type' => 'boolean', 'description' => 'Écart d\'horloge borne/serveur > 5 min (informatif, jamais bloquant).'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ]),
                    ),
                    '401' => new OpenApiResponse(description: 'Jeton terminal invalide, inconnu, expiré ou révoqué (message générique).'),
                ],
            ),
        ),
    ],
)]
final class SynchronisationAcces
{
    #[ApiProperty(identifier: true)]
    #[Groups(['synchro_acces:read'])]
    public string $id = 'etat';

    /** en_ligne | hors_ligne | mixte */
    #[Groups(['synchro_acces:read'])]
    public string $etat = 'en_ligne';

    /** @var list<array<string, mixed>> */
    #[Groups(['synchro_acces:read'])]
    public array $controleurs = [];
}
