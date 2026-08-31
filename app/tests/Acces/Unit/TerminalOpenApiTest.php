<?php

declare(strict_types=1);

namespace App\Tests\Acces\Unit;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * T21 (plan-acces-terminal.md §7 Lot E — durcissement) : la documentation OpenAPI des opérations
 * terminal expose les schémas `message`/`affichage`.
 *
 * Les réponses de `/terminal/passages*` sont des `JsonResponse` construits à la main (les processors
 * n'exposent pas d'entité sérialisable) : sans déclaration `openapi:` explicite, API Platform génère
 * l'opération **sans schéma de réponse**. Ce test échoue si cette documentation régresse.
 */
final class TerminalOpenApiTest extends KernelTestCase
{
    private OpenApi $openapi;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var OpenApiFactoryInterface $factory */
        $factory = static::getContainer()->get(OpenApiFactoryInterface::class);
        $this->openapi = $factory();
    }

    public function testPassageEnLigneDocumenteMessageEtAffichage(): void
    {
        $reponse = $this->schemaReponse('/api/terminal/passages', '200');
        $props = $reponse['properties'] ?? [];

        self::assertArrayHasKey('resultat', $props);
        self::assertArrayHasKey('message', $props, 'Le schéma `message` doit être documenté (T21).');
        self::assertArrayHasKey('affichage', $props, 'Le schéma `affichage` doit être documenté (T21).');

        $message = $props['message']['properties'] ?? [];
        self::assertArrayHasKey('codeMessage', $message);
        self::assertArrayHasKey('libelle', $message);
        self::assertContains('BONNE_SEANCE', $message['codeMessage']['enum'] ?? [], 'L\'enum codeMessage doit refléter CodeMessageAffichage.');

        $affichage = $props['affichage']['properties'] ?? [];
        foreach (['nomPorteur', 'numeroBillet', 'typeSupport', 'compostagesRestants', 'validiteAbonnement'] as $champ) {
            self::assertArrayHasKey($champ, $affichage, sprintf('`affichage.%s` doit être documenté.', $champ));
        }

        $requete = $this->schemaRequete('/api/terminal/passages');
        self::assertArrayHasKey('equipementId', $requete['properties'] ?? []);
        self::assertArrayHasKey('cleIdempotence', $requete['properties'] ?? []);
    }

    public function testLotHorsLigneDocumenteRecusEtResultats(): void
    {
        $reponse = $this->schemaReponse('/api/terminal/passages/lot', '200');
        $props = $reponse['properties'] ?? [];

        self::assertArrayHasKey('recus', $props);
        self::assertArrayHasKey('resultats', $props);
        $item = $props['resultats']['items']['properties'] ?? [];
        self::assertArrayHasKey('cleIdempotence', $item);
        self::assertArrayHasKey('statut', $item);
        self::assertContains('accepte', $item['statut']['enum'] ?? []);
    }

    public function testSnapshotDocumenteLaFormeDUneEntree(): void
    {
        // Le schéma de `SnapshotTerminal` est un composant (`$ref`) construit par API Platform, et ses
        // nœuds sont des `\ArrayObject` imbriqués sous `allOf`. On normalise en tableau (json) puis on
        // cherche récursivement le `entrees.items` documenté (identifié par la présence de `versionMaj`,
        // propre à une entrée de snapshot), sans dépendre du nom exact du schéma ni de sa structure.
        $schemas = json_decode((string) json_encode($this->openapi->getComponents()->getSchemas()), true);
        self::assertIsArray($schemas);

        $entreeProps = [];
        $chercher = function (mixed $noeud) use (&$chercher, &$entreeProps): void {
            if (!\is_array($noeud)) {
                return;
            }
            $itemProps = $noeud['entrees']['items']['properties'] ?? null;
            if (\is_array($itemProps) && isset($itemProps['versionMaj'])) {
                $entreeProps = array_keys($itemProps);
            }
            foreach ($noeud as $enfant) {
                $chercher($enfant);
            }
        };
        $chercher($schemas);

        self::assertNotEmpty($entreeProps, 'La forme d\'une entrée de snapshot doit être documentée (T21).');
        foreach (['identifiant', 'revoque', 'versionMaj', 'portesEligibles'] as $champ) {
            self::assertContains($champ, $entreeProps);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaReponse(string $chemin, string $code): array
    {
        $operation = $this->openapi->getPaths()->getPath($chemin)?->getPost();
        self::assertNotNull($operation, sprintf('Opération POST %s absente de l\'OpenAPI.', $chemin));
        $reponse = ($operation->getResponses() ?? [])[$code] ?? null;
        self::assertNotNull($reponse, sprintf('Réponse %s absente sur %s.', $code, $chemin));
        $content = $reponse->getContent();
        self::assertNotNull($content);

        return $content['application/json']['schema'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaRequete(string $chemin): array
    {
        $operation = $this->openapi->getPaths()->getPath($chemin)?->getPost();
        self::assertNotNull($operation);
        $body = $operation->getRequestBody();
        self::assertNotNull($body, sprintf('Corps de requête absent sur %s.', $chemin));
        $content = $body->getContent();
        self::assertNotNull($content);

        return $content['application/json']['schema'] ?? [];
    }
}
