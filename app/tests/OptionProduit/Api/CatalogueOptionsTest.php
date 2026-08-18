<?php

declare(strict_types=1);

namespace App\Tests\OptionProduit\Api;

use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Vente\VenteApiTestCase;

/**
 * CRUD du référentiel `App\OptionProduit` : réutilisation d'un `GroupeOption` entre produits (CA-1),
 * caractère obligatoire/facultatif propre à chaque rattachement (CA-2, RG-OPT-02), contrainte
 * d'unicité (produit, groupeOption), non-proposabilité d'une option désactivée tout en restant
 * historisée (CA-9, RG-OPT-08), et restriction de disponibilité par établissement (CA-8, RG-OPT-07).
 */
final class CatalogueOptionsTest extends VenteApiTestCase
{
    /** CA-1 / RG-OPT-01/02 — Un groupe existant est rattaché à un produit sans être recréé. */
    public function testMemeGroupeReutilisableSansRecreation(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idProduit = $this->idProduit(OffreFixtures::PRODUIT_ENTREE);

        $groupe = $this->creerGroupeOption($client, $entete, 'Taille', 'unique');
        $groupeIri = '/api/groupe_options/' . $groupe['id'];

        $rattachement = $client->request('POST', '/api/option_produits', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $idProduit,
                'groupeOption' => $groupeIri,
                'obligatoire' => true,
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame($groupeIri, $rattachement['groupeOption']['@id']);
        self::assertTrue($rattachement['obligatoire']);

        // Le référentiel groupe n'a pas été dupliqué : un seul GroupeOption « Taille » existe.
        $liste = $client->request('GET', '/api/groupe_options?libelle=Taille', $entete)->toArray();
        self::assertSame(1, $this->total($liste));
    }

    /** CA-2 / RG-OPT-02 — obligatoire/facultatif diffère par produit sans dupliquer le référentiel. */
    public function testObligatoireDiffereParProduitSansDupliquerReferentiel(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idA = $this->idProduit(OffreFixtures::PRODUIT_ENTREE);
        $idB = $this->idProduit(OffreFixtures::PRODUIT_GOLD);

        $groupe = $this->creerGroupeOption($client, $entete, 'Extras', 'multiple');
        $groupeIri = '/api/groupe_options/' . $groupe['id'];

        $rattA = $client->request('POST', '/api/option_produits', $entete + [
            'json' => ['produit' => '/api/produits/' . $idA, 'groupeOption' => $groupeIri, 'obligatoire' => true],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $rattB = $client->request('POST', '/api/option_produits', $entete + [
            'json' => ['produit' => '/api/produits/' . $idB, 'groupeOption' => $groupeIri, 'obligatoire' => false],
        ])->toArray();
        self::assertResponseIsSuccessful();

        self::assertSame($groupeIri, $rattA['groupeOption']['@id']);
        self::assertSame($groupeIri, $rattB['groupeOption']['@id']);
        self::assertTrue($rattA['obligatoire']);
        self::assertFalse($rattB['obligatoire']);
    }

    /** RG-OPT-02 — contrainte unique (produit, groupeOption) : second rattachement refusé (422). */
    public function testCreationRattachementRefuseSiDejaRattache(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idProduit = $this->idProduit(OffreFixtures::PRODUIT_ENTREE);
        $groupe = $this->creerGroupeOption($client, $entete, 'Extras bis', 'multiple');
        $groupeIri = '/api/groupe_options/' . $groupe['id'];

        $client->request('POST', '/api/option_produits', $entete + [
            'json' => ['produit' => '/api/produits/' . $idProduit, 'groupeOption' => $groupeIri],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/option_produits', $entete + [
            'json' => ['produit' => '/api/produits/' . $idProduit, 'groupeOption' => $groupeIri],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /** CA-9 / RG-OPT-08 — Option désactivée : non proposable pour une nouvelle vente, reste historisée. */
    public function testOptionDesactiveeNonProposableMaisResteHistorisee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idProduit = $this->idProduit(OffreFixtures::PRODUIT_CARTE);
        $groupe = $this->creerGroupeOption($client, $entete, 'Casier', 'unique');
        $groupeIri = '/api/groupe_options/' . $groupe['id'];
        $valeur = $this->creerValeurOption($client, $entete, $groupeIri, 'Casier standard', 'montant', '1.00');
        $valeurIri = '/api/valeur_options/' . $valeur['id'];
        $client->request('POST', '/api/option_produits', $entete + [
            'json' => ['produit' => '/api/produits/' . $idProduit, 'groupeOption' => $groupeIri],
        ]);
        self::assertResponseIsSuccessful();

        // Vente avec cette option, encore active : acceptée, figée sur la ligne.
        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);
        $reponse = $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $idProduit,
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
                'options' => [$valeurIri],
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();
        $ligne = $reponse['lignes'][0];
        self::assertNotEmpty($ligne['optionsSelectionnees']);

        // Désactivation du référentiel.
        $client->request('PATCH', $valeurIri, $this->entetePatch($entete) + [
            'json' => ['actif' => false],
        ]);
        self::assertResponseIsSuccessful();

        // La ligne historique reste inchangée (snapshot conservé).
        $venteApres = $client->request('GET', '/api/ventes/' . $vente['id'], $entete)->toArray();
        self::assertNotEmpty($venteApres['lignes'][0]['optionsSelectionnees']);

        // Une nouvelle tentative de sélection est refusée (422).
        $venteBis = $this->creerVente($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $venteBis['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $idProduit,
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
                'options' => [$valeurIri],
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /** CA-8 / RG-OPT-07 — Option restreinte à l'établissement A : absente hors du site. */
    public function testOptionRestreinteEtablissementNonProposeeHorsSite(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        // Produit disponible sur A ET B (produit partagé) : la restriction porte sur l'OPTION, pas
        // sur l'accès au produit. Sans rattachement à B, la requête sur B serait un 404 de
        // cloisonnement (garde-fou OptionsDisponiblesProvider), pas une liste d'options vide.
        [$produit] = $this->creerProduitBase();
        $etabB = $this->em()->getRepository(\App\Organisation\Entity\Etablissement::class)
            ->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(\App\Organisation\Entity\Etablissement::class, $etabB);
        $produit->addEtablissement($etabB);
        $this->em()->flush();
        $idProduit = (string) $produit->getId();

        $groupe = $this->creerGroupeOption($client, $entete, 'Casier restreint', 'unique');
        $groupeIri = '/api/groupe_options/' . $groupe['id'];
        $this->creerValeurOption($client, $entete, $groupeIri, 'Casier A', 'montant', '1.00');

        $client->request('POST', '/api/option_produits', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $idProduit,
                'groupeOption' => $groupeIri,
                'etablissementsRestriction' => ['/api/etablissements/' . $idA],
            ],
        ]);
        self::assertResponseIsSuccessful();

        $surA = $client->request('GET', '/api/produits/' . $idProduit . '/options-disponibles', $entete)->toArray();
        self::assertNotEmpty($surA['groupes'], 'Disponible sur son établissement de restriction.');

        $enteteB = ['auth_bearer' => $entete['auth_bearer'], 'headers' => [ContexteEtablissement::HEADER => $idB]];
        $surB = $client->request('GET', '/api/produits/' . $idProduit . '/options-disponibles', $enteteB)->toArray();
        self::assertEmpty($surB['groupes'], 'Non proposée hors du site de restriction (RG-OPT-07).');
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function creerGroupeOption(object $client, array $entete, string $libelle, string $modeSelection): array
    {
        $reponse = $client->request('POST', '/api/groupe_options', $entete + [
            'json' => ['libelle' => $libelle, 'modeSelection' => $modeSelection],
        ])->toArray();
        self::assertResponseIsSuccessful();

        return $reponse;
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function creerValeurOption(object $client, array $entete, string $groupeIri, string $libelle, string $impactType, string $impactValeur): array
    {
        $reponse = $client->request('POST', '/api/valeur_options', $entete + [
            'json' => [
                'groupeOption' => $groupeIri,
                'libelle' => $libelle,
                'impactType' => $impactType,
                'impactValeur' => $impactValeur,
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        return $reponse;
    }

    /**
     * @param array<string, mixed> $reponse
     */
    private function total(array $reponse): int
    {
        return (int) ($reponse['totalItems'] ?? $reponse['hydra:totalItems'] ?? 0);
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function entetePatch(array $entete): array
    {
        $entete['headers'] = ($entete['headers'] ?? []) + ['Content-Type' => 'application/merge-patch+json'];

        return $entete;
    }
}
