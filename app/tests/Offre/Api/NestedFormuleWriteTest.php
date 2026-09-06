<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Offre\OffreApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * MODIFIER UNE FORMULE DEPUIS LA FICHE PRODUIT NE DOIT PAS LA REMPLACER.
 *
 * ── LA QUESTION QUE J'AVAIS LAISSÉE OUVERTE ─────────────────────────────────────────────────────
 *
 * La fiche produit écrit la formule d'abonnement **imbriquée** dans le `PATCH` du produit :
 * `Formule` n'est pas une ressource API, ses propriétés portent directement `produit:write`. J'ai
 * livré ce lot en écrivant que c'était la seule chose que je ne pouvais pas prouver statiquement —
 * API Platform met-il à jour l'objet existant, ou en fabrique-t-il un second ?
 *
 * ⚠ ET LA RÉPONSE N'EST PAS ANODINE. `Produit::$formule` est en `orphanRemoval: true` : un
 * remplacement SUPPRIME l'ancienne. Or `Formule` est référencée ailleurs — `AbonnementFitness`
 * (chaque abonné), `PassAnnuel` du musée, et `ServiceInclus`. Si l'enregistrement remplaçait la
 * formule, corriger un jour de prélèvement depuis la fiche produit casserait le lien avec les
 * abonnés existants.
 *
 * ── CE QUE LE TEST REGARDE, ET POURQUOI PAS LA RÉPONSE HTTP ─────────────────────────────────────
 *
 * Un `200` ne dit rien : il est identique dans les deux cas. Ce qui distingue une mise à jour d'un
 * remplacement, c'est l'IDENTIFIANT de la formule — relu en base après un `clear()`, pour ne pas
 * lire l'objet que l'unité de travail garde en mémoire.
 *
 * Le second témoin est le `ServiceInclus` attaché à la formule de démonstration : une formule
 * remplacée le perd. Deux façons indépendantes de voir le même fait, parce que l'identifiant seul
 * ne dirait pas ce que le remplacement emporte avec lui.
 */
final class NestedFormuleWriteTest extends OffreApiTestCase
{
    public function testModifierLaFormuleGardeLaMemeFormuleEtSesServices(): void
    {
        [$client, $token, $idA] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $idProduit = $this->idProduit(OffreFixtures::PRODUIT_GOLD);
        $produit = $em->getRepository(Produit::class)->find($idProduit);
        self::assertInstanceOf(Produit::class, $produit);

        $formuleAvant = $produit->getFormule();
        self::assertNotNull($formuleAvant, 'Le montage suppose un produit porteur d’une formule.');
        $idFormuleAvant = (string) $formuleAvant->getId();
        $servicesAvant = $formuleAvant->getServicesInclus()->count();
        self::assertGreaterThan(0, $servicesAvant, 'Sans service inclus, le second témoin ne dirait rien.');

        // Exactement ce que la fiche produit envoie : l'objet imbriqué, sans `@id`, avec ses deux
        // sous-objets recomposés entiers (ce sont des colonnes JSON).
        $client->request('PATCH', '/api/produits/' . $idProduit, [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $idA, 'Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'formule' => [
                    'periodicite' => 'annuel',
                    'sepaActif' => false,
                    'renouvellement' => ['auto' => false, 'prix' => 'evolutif'],
                    'droitAcces' => ['mode' => 'quota_passages'],
                ],
            ],
        ]);
        self::assertResponseIsSuccessful();

        // ⚠ ON RELIT EN BASE, PAS DANS LA RÉPONSE. L'unité de travail garde l'objet en mémoire :
        // sans ce `clear()`, on relirait ce qu'on vient d'écrire et non ce qui est stocké.
        $em->clear();
        $produit = $em->getRepository(Produit::class)->find($idProduit);
        self::assertInstanceOf(Produit::class, $produit);

        $formuleApres = $produit->getFormule();
        self::assertNotNull($formuleApres, 'La formule ne doit pas disparaître en la modifiant.');

        self::assertSame(
            $idFormuleAvant,
            (string) $formuleApres->getId(),
            'La formule doit être MISE À JOUR, pas remplacée : `orphanRemoval` supprimerait l’ancienne, '
            .'et `AbonnementFitness` comme `PassAnnuel` la référencent.',
        );

        self::assertSame(
            $servicesAvant,
            $formuleApres->getServicesInclus()->count(),
            'Les services inclus doivent survivre : une formule remplacée les emporterait.',
        );

        // Et la modification demandée doit bien avoir eu lieu — sinon le test passerait aussi sur
        // une écriture qui n'écrit rien, ce qui est l'autre façon de garder le même identifiant.
        self::assertSame(
            'annuel',
            $formuleApres->getPeriodicite()?->value,
            'La périodicité demandée doit être enregistrée : sans ça, « même formule » voudrait dire '
            .'« rien ne s’est écrit ».',
        );
        self::assertFalse($formuleApres->isSepaActif());
    }
}
