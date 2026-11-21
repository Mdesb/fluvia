<?php

declare(strict_types=1);

namespace App\Platform\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Platform\State\EditorNf525AlertNoteProcessor;
use App\Platform\State\EditorNf525AlertProvider;

/**
 * Les alertes « chaîne fiscale bloquée » vues par l'éditeur (NF525, G-6) : tous les établissements, avec le
 * détail technique, le nombre de détections, l'origine, les établissements liés et la note.
 *
 * **Le verrou n'est pas la ligne `security` ci-dessous** (elle n'exige qu'être connecté) : c'est
 * `EditorOnly::assertEditor('editor.nf525_alerts')`, première ligne du fournisseur et du processeur
 * (404 hors du tenant éditeur, 403 sans le droit). `bin/garde-fou-routes-editeur.php` l'exige.
 *
 * **La note est le seul geste d'écriture.** `POST /editor/nf525-alerts/{id}/note`, corps `{"note": "…"}`,
 * 2000 caractères au plus, vide = efface. Elle ne change ni le statut ni les compteurs et laisse une
 * entrée au journal d'audit.
 *
 * @sans-suppression: la note s'efface en la renvoyant vide, et l'entrée du journal d'audit est append-only par construction ; la ressource ne crée aucune ligne.
 */
#[ApiResource(
    shortName: 'EditorNf525Alert',
    operations: [
        new GetCollection(uriTemplate: '/editor/nf525-alerts', provider: EditorNf525AlertProvider::class),
        new Get(uriTemplate: '/editor/nf525-alerts/{id}', provider: EditorNf525AlertProvider::class),
        new Post(
            uriTemplate: '/editor/nf525-alerts/{id}/note',
            read: false,
            input: false,
            status: 200, // une note n'est pas une création
            processor: EditorNf525AlertNoteProcessor::class,
        ),
    ],
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
)]
final class EditorNf525Alert
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    public string $reference = '';

    public string $chainType = '';

    public string $chainLabel = '';

    public string $cause = '';

    public string $causeLabel = '';

    /** `open` ou `resolved`. */
    public string $status = '';

    /** `refusal` ou `verification`. */
    public string $origin = '';

    public string $firstEventAt = '';

    public string $lastEventAt = '';

    public int $refusalCount = 0;

    public int $detectionCount = 0;

    public ?string $resolvedAt = null;

    /** Texte brut, rendu tel quel : le frontal l'affiche comme du texte, jamais comme du HTML. */
    public string $technicalDetail = '';

    /** @var list<string> Les noms des établissements liés à l'alerte. */
    public array $establishmentNames = [];

    public ?string $note = null;

    public ?string $noteAt = null;

    public ?string $noteBy = null;
}
