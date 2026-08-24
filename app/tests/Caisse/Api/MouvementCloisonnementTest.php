<?php

declare(strict_types=1);

namespace App\Tests\Caisse\Api;

use App\Caisse\DataFixtures\CaisseClotureRoleFixtures;
use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\MouvementCaisse;
use App\Caisse\Entity\PointDeVente;
use App\Caisse\Entity\SessionCaisse;
use App\Caisse\Enum\EtatCaisse;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Tests\Caisse\CaisseClotureRoleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * C11 — non-régression de l'IDOR de cloisonnement sur les mouvements d'espèces
 * (`App\Caisse\State\MouvementCaisseProcessor::assertSessionDansLePerimetre`, D3/D8). Le POST
 * `/api/mouvements-caisse` résout sa session cible depuis le corps de la requête (pas via les
 * extensions Doctrine de lecture) : un régisseur autorisé (`caisse.mouvement`) sur l'établissement A
 * qui connaît l'UUID d'une session de l'établissement B ne doit pas pouvoir y enregistrer un retrait.
 *
 * L'autorité est recalculée contre l'établissement de la **session visée**, jamais contre l'en-tête
 * `X-Etablissement` (sélecteur client). Échec fermé : **404** et non 403 — confirmer l'existence d'une
 * session hors périmètre renseignerait déjà l'appelant sur l'activité d'un autre établissement.
 */
final class MouvementCloisonnementTest extends CaisseClotureRoleApiTestCase
{
    public function testMouvementSurSessionDunAutreEtablissementRenvoie404(): void
    {
        $idSessionB = $this->creerSessionSurB();

        // Régisseur A porte caisse.mouvement SUR A (X-Etablissement = A) : la sécurité de la route
        // passe, seul le contrôle applicatif du processor peut refuser l'accès cross-établissement.
        [$client, $entete] = $this->regisseurSurA();
        $reponse = $client->request('POST', '/api/mouvements-caisse', $entete + [
            'json' => [
                'session' => $idSessionB,
                'type' => 'retrait',
                'montant' => '100.00',
                'motif' => 'Intrusion cross-etablissement (C11)',
            ],
        ]);

        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        self::assertSame(
            0,
            (int) $this->em()->getRepository(MouvementCaisse::class)->count([]),
            'Aucun mouvement ne doit etre enregistre sur la session de B.',
        );
    }

    /**
     * Contrôle positif — le même régisseur, avec la même permission, enregistre bien un mouvement sur
     * une session de SON établissement (201). Sans lui, le 404 ci-dessus pourrait masquer une simple
     * permission manquante plutôt que le cloisonnement.
     */
    public function testMouvementSurSaPropreSessionReussit(): void
    {
        $session = $this->ouvrirSession(); // session ouverte sur A par l'admin.

        [$client, $entete] = $this->regisseurSurA();
        $reponse = $client->request('POST', '/api/mouvements-caisse', $entete + [
            'json' => [
                'session' => $session['id'],
                'type' => 'apport',
                'montant' => '50.00',
                'motif' => 'Apport regisseur (C11)',
            ],
        ]);

        self::assertSame(201, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        self::assertSame(1, (int) $this->em()->getRepository(MouvementCaisse::class)->count([]));
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /** Crée une session de caisse ouverte sur l'établissement B (PDV + Caisse + Session ad-hoc). */
    private function creerSessionSurB(): string
    {
        $em = $this->em();
        $etabB = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);
        $regisseurB = $this->entite(Utilisateur::class, ['email' => CaisseClotureRoleFixtures::REGISSEUR_B_EMAIL]);

        $pdv = (new PointDeVente())->setLibelle('PDV test C11 B ' . uniqid())->setEtablissement($etabB);
        $em->persist($pdv);
        $caisse = (new Caisse())->setLibelle('Caisse test C11 B ' . uniqid())->setPointDeVente($pdv)->setEtat(EtatCaisse::Securisee);
        $em->persist($caisse);
        $session = (new SessionCaisse())
            ->setNumero('S-C11B-' . substr(uniqid(), -8))
            ->setPointDeVente($pdv)
            ->setCaisse($caisse)
            ->setRegisseur($regisseurB)
            ->setOperateur($regisseurB)
            ->setFondDeCaisse('0.00')
            ->setEtablissement($etabB);
        $em->persist($session);
        $em->flush();

        return (string) $session->getId();
    }
}
