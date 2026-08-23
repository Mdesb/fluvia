<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\DataFixtures\SocleFixtures;
use App\Facturation\Entity\Facture;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Facturation\FacturationApiTestCase;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Non-régression de l'IDOR n°6 (D8, corrigé le 23/08), trouvé par claude-C en auditant la dette de
 * cloisonnement.
 *
 * **Le défaut.** `POST /factures/depuis-vente` est en `read: false` : aucune extension de périmètre ne
 * s'applique. `PerimetreVenteExtension` couvre pourtant bien `Vente` — mais seulement sur les requêtes
 * `Get`/`GetCollection` d'API Platform, jamais sur un `find()` fait dans un Processor. La vente arrivait
 * donc du corps de la requête sans être confrontée à quoi que ce soit.
 *
 * **Pourquoi ce cas est pire que les cinq précédents.** Le handler prend l'établissement **depuis la
 * vente**, pose la facture dessus, et lui attribue un numéro de la séquence de cet établissement. Un
 * agent portant `facturation.emettre_justificative` sur A émettait donc une facture réelle dans B, en
 * consommant un numéro de la séquence de B — une séquence que la réglementation impose ininterrompue.
 * Le dommage n'est pas une fuite de lecture : il est comptable, et il ne s'annule pas en effaçant une
 * ligne.
 *
 * **Deux assertions, et la seconde est la vraie.** Le 404 — et non 403, qui ferait de la route un
 * oracle d'énumération — puis **l'absence de toute facture créée**. Un refus qui laisserait passer
 * l'attribution du numéro serait pire qu'aucun contrôle : la séquence porterait un trou.
 */
final class CloisonnementFactureVenteTest extends FacturationApiTestCase
{
    public function testFactureSurVenteDunAutreEtablissementEstIntrouvableEtAucunNumeroNestConsomme(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->creerVenteValidee($client, $entete);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $facturesAvant = (int) $em->getRepository(Facture::class)->createQueryBuilder('f')
            ->select('COUNT(f.id)')->getQuery()->getSingleScalarResult();

        // On ne déplace **pas** la vente : le verrou d'inaltérabilité NF525 l'interdit, à juste titre —
        // une vente validée a ses champs figés, établissement compris. C'est le premier montage que
        // j'avais tenté, et son refus est une bonne nouvelle en soi.
        //
        // Le montage correct est d'ailleurs plus fidèle, car il ne touche à rien : la vente reste sur A,
        // et c'est **l'établissement actif** de l'agent qui passe à B. L'administrateur socle est
        // affecté sur A et sur B, donc l'en-tête B lui est parfaitement légitime : ce qui ne l'est pas,
        // c'est d'atteindre depuis B une vente qui vit sur A.
        $entiteVente = $em->getRepository(Vente::class)->find($vente['id']);
        self::assertNotNull($entiteVente);

        $enteteB = [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)],
        ];

        $reponse = $client->request('POST', '/api/factures/depuis-vente', $enteteB + [
            'json' => ['vente' => '/api/ventes/' . $vente['id']],
        ]);

        self::assertSame(
            404,
            $reponse->getStatusCode(),
            'Une vente hors périmètre doit être introuvable, jamais interdite : '
            . (string) $reponse->getContent(false),
        );

        $em->clear();
        $facturesApres = (int) $em->getRepository(Facture::class)->createQueryBuilder('f')
            ->select('COUNT(f.id)')->getQuery()->getSingleScalarResult();

        self::assertSame(
            $facturesAvant,
            $facturesApres,
            'Aucune facture ne doit avoir été émise, donc aucun numéro consommé dans la séquence d\'un autre établissement.',
        );
    }
}
