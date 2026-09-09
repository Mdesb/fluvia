<?php

declare(strict_types=1);

namespace App\Tests\Integrations\Unit;

use App\Integrations\Entity\OutboundEndpoint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * L'ADRESSE D'UN WEBHOOK DOIT PORTER UN DOMAINE PUBLIC — ET C'EST UN CHANGEMENT DE COMPORTEMENT.
 *
 * `Assert\Url` ne recevait pas `requireTld`. Symfony 7.1 déprécie ce silence et changera le défaut à
 * `true` : la contrainte acceptait donc `https://localhost/hook` ou `https://intranet/hook`, et
 * l'aurait refusé un jour, à la faveur d'une mise à jour de dépendance — c'est-à-dire au pire
 * moment, sans que personne n'ait décidé.
 *
 * ⚠ CE FICHIER EXISTE PARCE QUE LE MODULE N'AVAIT AUCUN TEST. Ni la contrainte, ni l'exigence
 * `https` d'`OutboundEndpointProcessor` n'étaient éprouvées. Le premier cas ci-dessous est celui qui
 * AUTORISE : sans lui, un `requireTld` posé de travers — ou une contrainte qui refuserait tout —
 * laisserait les cas de refus verts, et plus verts qu'avant.
 *
 * La destination reste par ailleurs soumise à `https` et à la présence d'un hôte, vérifiées par le
 * processeur : l'URL vaut un mot de passe, elle ne peut pas circuler en clair.
 */
final class OutboundEndpointUrlTest extends TestCase
{
    /** Le cas qui autorise, écrit en premier et à ne jamais supprimer au motif qu'« il ne teste rien ». */
    public function testUneAdressePubliqueEstAcceptee(): void
    {
        self::assertCount(0, $this->fautesPour('https://exemple.fr/webhooks/fluvia'));
    }

    public function testUnSousDomaineEstAccepte(): void
    {
        self::assertCount(0, $this->fautesPour('https://hooks.exemple.co.uk/entree?jeton=abc'));
    }

    /** Le comportement qui change : jusqu'ici la contrainte laissait passer. */
    public function testUnHoteSansDomaineDePremierNiveauEstRefuse(): void
    {
        self::assertGreaterThan(
            0,
            \count($this->fautesPour('https://localhost/hook')),
            'Un webhook vers un hôte sans domaine public ne sort pas du serveur : il ne peut pas être une destination.',
        );
    }

    public function testUnNomDeMachineInterneEstRefuse(): void
    {
        self::assertGreaterThan(0, \count($this->fautesPour('https://intranet/hook')));
    }

    /** L'adresse reste facultative au niveau de la contrainte : c'est le processeur qui l'exige à la création. */
    public function testUneAdresseAbsenteNestPasUneFauteDeContrainte(): void
    {
        self::assertCount(0, $this->fautesPour(null));
    }

    /**
     * ⚠ `validatePropertyValue` ET NON `validate($entite)`. Le second éprouve TOUTES les contraintes
     * de l'entité : une destination neuve en porte au moins une autre non satisfaite, si bien que les
     * cas de refus ci-dessus passaient au vert sans que l'URL y soit pour rien — et le cas qui
     * AUTORISE, lui, échouait. Mesuré le 07/09, à la première exécution.
     *
     * Celui-ci lit les contraintes déclarées sur la propriété dans les métadonnées de la classe : il
     * éprouve donc l'attribut réellement posé, sans le ré-épeler dans le test.
     */
    private function fautesPour(?string $url): \Countable
    {
        return $this->validateur()->validatePropertyValue(OutboundEndpoint::class, 'url', $url);
    }

    private function validateur(): ValidatorInterface
    {
        // Pas de noyau ni de base : on éprouve l'attribut porté par la propriété, rien d'autre.
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
