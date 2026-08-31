<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Facturation\Entity\Facture;
use App\Compta\Entity\TauxTva;
use App\Facturation\Nf525\ScellementFactureHandler;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * L'INSTANTANÉ SCELLÉ EST CONSERVÉ — DONC UN RÉFÉRENTIEL QUI BOUGE N'ACCUSE PLUS PERSONNE.
 *
 * ── LE DÉFAUT QUE CE FILET GARDE FERMÉ ──────────────────────────────────────────────────────────
 *
 * La vérification reconstruisait le payload canonique depuis les entités VIVANTES :
 *
 *     ScellementFactureHandler   'taux' => $ligne->getTauxTvaValeur()
 *     LigneFacture               return $this->tauxTva?->getTaux()      ← VIVANT
 *     TauxTva::$taux             exposé en PATCH
 *
 * Un exploitant qui corrige un taux — geste légitime, un décret change les taux — faisait dériver
 * l'empreinte recalculée de CHAQUE document scellé avec lui. Le logiciel répondait alors
 * « la donnée a été altérée » : il accusait son utilisateur de falsification pour un geste qu'il
 * l'autorise lui-même à faire. Devant un expert-comptable, c'est le pire mot possible.
 *
 * ── LES QUATRE CAS, ET LES DEUX DERNIERS SONT CEUX QU'ON N'ÉCRIT PAS ────────────────────────────
 *
 *   1. le scellement conserve l'instantané
 *   2. un taux corrigé APRÈS scellement laisse la chaîne intacte   ← le cœur du lot
 *   3. une altération réelle est TOUJOURS détectée                 ← sans lui, désarmer passerait
 *   4. un instantané existant ne peut pas être réécrit             ← sans lui, la porte reste ouverte
 *
 * ⚠ Le cas 3 est celui sans lequel ce lot serait indistinguable d'un contrôle désarmé : un correctif
 * qui aurait simplement cessé de vérifier passerait le cas 2 avec le même vert.
 */
final class InstantaneScelleTest extends FacturationApiTestCase
{
    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    private function handler(): ScellementFactureHandler
    {
        /** @var ScellementFactureHandler $h */
        $h = static::getContainer()->get(ScellementFactureHandler::class);

        return $h;
    }

    /**
     * Émet une facture scellée, et rend son identifiant.
     *
     * @return array{0: object, 1: array<string, mixed>, 2: string}
     */
    private function factureEmise(): array
    {
        [$client, $entete] = $this->adminSurA();

        $brouillon = $client->request('POST', '/api/factures', $entete + [
            'json' => [
                'destinataire' => [
                    'type' => 'personne_morale',
                    'raisonSociale' => 'Client de contrôle',
                    'siret' => '12345678900011',
                    'adresse' => ['rue' => '2 rue du Test', 'cp' => '75000', 'ville' => 'Paris', 'pays' => 'FR'],
                ],
                'lignes' => [[
                    'designation' => 'Prestation',
                    'quantite' => 1,
                    'prixUnitaireHT' => '100.00',
                    'tauxTva' => '/api/taux_tvas/' . $this->idTauxTva('Taux normal 20 %'),
                ]],
            ],
        ])->toArray();

        $emise = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete)->toArray();

        return [$client, $entete, (string) $emise['id']];
    }

    private function facture(string $id): Facture
    {
        /** @var Facture $facture */
        $facture = $this->em()->getRepository(Facture::class)->find(Uuid::fromString($id));

        return $facture;
    }

    /** 1. Le scellement conserve l'instantané sur lequel l'empreinte a été calculée. */
    public function testLeScellementConserveSonInstantane(): void
    {
        [, , $id] = $this->factureEmise();
        $this->em()->clear();

        $facture = $this->facture($id);

        self::assertNotSame('', $facture->getEmpreinte(), 'Témoin : la facture émise est bien scellée.');

        $instantane = $facture->getPayloadCanonique();
        self::assertIsArray($instantane, "Un document scellé conserve désormais son instantané.");
        self::assertArrayHasKey('lignes', $instantane);
        self::assertSame('20.00', $instantane['lignes'][0]['taux'], "L'instantané fige le taux du moment.");
    }

    /**
     * 2. LE CŒUR DU LOT — corriger un taux après scellement ne casse plus la chaîne.
     *
     * Le taux est modifié par l'ORM et non par l'API : ce qu'on éprouve est la vérification, pas le
     * chemin d'écriture du référentiel. Un décret qui change les taux produit exactement cet état.
     */
    public function testUnTauxCorrigeApresScellementNAccusePlusPersonne(): void
    {
        [, , $id] = $this->factureEmise();

        // Témoin positif : la chaîne est intacte AVANT qu'on ne touche au référentiel.
        $avant = $this->handler()->verifieChaine([$this->facture($id)]);
        self::assertTrue($avant['intacte'], 'La chaîne doit être intacte au départ, sinon le test ne mesure rien.');

        /** @var TauxTva $taux */
        $taux = $this->em()->getRepository(TauxTva::class)->find(Uuid::fromString($this->idTauxTva('Taux normal 20 %')));
        $taux->setTaux('21.00');
        $this->em()->flush();
        $this->em()->clear();

        $apres = $this->handler()->verifieChaine([$this->facture($id)]);

        self::assertTrue(
            $apres['intacte'],
            "Un taux corrigé ne doit plus casser la chaîne : l'empreinte se vérifie contre l'instantané, "
            .'pas contre le référentiel vivant. Anomalies : ' . json_encode($apres['anomalies'], JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 3. Une altération réelle est toujours détectée — et le mot « altérée » est mérité.
     *
     * ⚠ On écrit en SQL direct : le garde d'inaltérabilité refuse par l'ORM, et c'est justement ce
     * qu'un falsificateur contournerait. C'est le cas que le scellement existe pour attraper.
     */
    public function testUneAlterationReelleEstToujoursDetectee(): void
    {
        [, , $id] = $this->factureEmise();

        $connexion = $this->em()->getConnection();
        $connexion->executeStatement(
            'UPDATE facturation_ligne SET montant_ht = :montant WHERE facture_id = UNHEX(REPLACE(:id, :tiret, :vide))',
            ['montant' => '999.00', 'id' => $id, 'tiret' => '-', 'vide' => '']
        );
        $this->em()->clear();

        $rapport = $this->handler()->verifieChaine([$this->facture($id)]);

        self::assertFalse($rapport['intacte'], 'Une altération en base doit rester détectée.');
        self::assertStringContainsString(
            'altérée',
            json_encode($rapport['anomalies'], JSON_UNESCAPED_UNICODE) ?: '',
            "Avec l'instantané stocké, l'écart PROUVE une altération : le mot est mérité."
        );
    }

    /**
     * 4. Un instantané existant ne peut pas être réécrit.
     *
     * ⚠ C'est la moitié qui rend l'ouverture du garde d'inaltérabilité acceptable. Sans elle, le
     * champ deviendrait une porte : on pourrait changer après coup ce qu'un document est censé avoir
     * été, ce qui est exactement ce que le scellement interdit.
     */
    public function testUnInstantaneExistantNePeutPasEtreReecrit(): void
    {
        [, , $id] = $this->factureEmise();
        $this->em()->clear();

        $facture = $this->facture($id);
        self::assertNotNull($facture->getPayloadCanonique(), 'Témoin : la facture porte bien un instantané.');

        $facture->setPayloadCanonique(['lignes' => [], 'numero' => 'FAUX']);

        $this->expectExceptionMessage('inaltérable');
        $this->em()->flush();
    }

    /**
     * 5. LA FACTURE RENDUE S'ADDITIONNE, MEME APRES UN CHANGEMENT DE TAUX.
     *
     * `FactureRenduProvider` melangeait deux temps : le taux etait relu en direct pendant que les
     * trois montants restaient figes. Apres un changement, un client recevait un document montrant
     * 5,5 % en face de 20 € de TVA sur 100 € HT.
     *
     * ⚠ Il n'en conclut pas que le referentiel a bouge. Il conclut que la facture est fausse — et un
     * document qui ne s'additionne pas EST faux. C'est le seul defaut de cette famille qu'un CLIENT
     * voit.
     *
     * L'assertion est celle qu'il ferait lui-meme : la TVA affichee correspond-elle au taux affiche
     * applique au HT affiche ? On ne compare a aucune valeur litterale — on verifie la coherence
     * INTERNE du document, qui est la seule chose qu'il puisse controler.
     */
    public function testLaFactureRenduesAdditionneApresUnChangementDeTaux(): void
    {
        [$client, $entete, $id] = $this->factureEmise();

        $coherent = static function (array $rendu): void {
            foreach ($rendu['lignes'] as $ligne) {
                $attendu = round((float) $ligne['montantHT'] * (float) $ligne['tauxTva'] / 100, 2);
                self::assertSame(
                    $attendu,
                    round((float) $ligne['montantTva'], 2),
                    sprintf(
                        'Le document doit s\'additionner : %s %% de %s devrait faire %s, il affiche %s.',
                        $ligne['tauxTva'],
                        $ligne['montantHT'],
                        $attendu,
                        $ligne['montantTva']
                    )
                );
            }
        };

        // Temoin : il s'additionne AVANT qu'on ne touche au referentiel.
        $avant = $client->request('GET', '/api/factures/' . $id . '/rendu', $entete)->toArray();
        self::assertNotEmpty($avant['lignes'], 'Temoin : le rendu porte bien des lignes.');
        $coherent($avant);

        /** @var TauxTva $taux */
        $taux = $this->em()->getRepository(TauxTva::class)->find(Uuid::fromString($this->idTauxTva('Taux normal 20 %')));
        $taux->setTaux('5.50');
        $this->em()->flush();
        $this->em()->clear();

        $apres = $client->request('GET', '/api/factures/' . $id . '/rendu', $entete)->toArray();
        $coherent($apres);

        self::assertSame(
            $avant['lignes'][0]['tauxTva'],
            $apres['lignes'][0]['tauxTva'],
            'Le taux affiche est celui qui a ete SCELLE, pas celui du referentiel du jour.'
        );
    }

}
