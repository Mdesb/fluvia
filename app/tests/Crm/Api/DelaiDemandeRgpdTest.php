<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Crm\Entity\Client;
use App\Crm\Entity\DemandeRGPD;
use App\Crm\Enum\StatutDemandeRgpd;
use App\Crm\Enum\TypeDemandeRgpd;
use App\Tests\Crm\CrmApiTestCase;

/**
 * LE DÉLAI LÉGAL D'UN MOIS N'ÉTAIT INTERROGEABLE PAR PERSONNE.
 *
 * `DemandeRGPD` déclarait un filtre sur `statut` et un sur `client`, et RIEN sur `dateDemande`.
 * Aucun compteur, aucune tâche, aucun écran ne pouvait donc demander « lesquelles ont dépassé le
 * mois ». La seule façon d'y répondre était de tout charger et de comparer à la main — sur une
 * collection PAGINÉE, donc avec un compte faux dès la 31ᵉ demande, et faux dans le sens rassurant.
 *
 * ⚠ ET CETTE ENTITÉ A DÉJÀ EU UN FILTRE QUI MENTAIT. Son propre commentaire le raconte : `client`
 * était déclaré dans le `SearchFilter` et rendait TOUJOURS une liste vide — sur l'écran même qui
 * porte le délai légal. Un filtre posé ici se vérifie, il ne se suppose pas.
 *
 * ⚠ LES ASSERTIONS PORTENT SUR DES IDENTIFIANTS, PAS SUR DES TYPES. Premier jet : je distinguais
 * les trois demandes par leur type, en inventant `portabilite` et `rectification`. L'enum n'en
 * compte que DEUX — `effacement` et `anonymisation`. Un test qui identifie ses sujets par une
 * valeur qu'il n'a pas vérifiée se casse sur le nom, pas sur le comportement.
 */
final class DelaiDemandeRgpdTest extends CrmApiTestCase
{
    public function testOnPeutDemanderLesDemandesQuiOntDepasseLeMois(): void
    {
        [$http, $entete] = $this->adminSurA();
        $ids = $this->poserTroisDemandes();

        // ── LE TÉMOIN, D'ABORD ──────────────────────────────────────────────────────────────────
        // Sans lui, un filtre qui rendrait TOUJOURS vide passerait l'assertion « il n'y a que les
        // vieilles » — et l'écran annoncerait « aucun retard » sur une file en retard. C'est très
        // exactement le défaut qu'a eu `client` sur cette même entité.
        $toutes = $http->request('GET', '/api/demande_rgpds?itemsPerPage=100', $entete)->toArray();
        self::assertSame(3, $toutes['totalItems'] ?? 0, 'témoin : les trois demandes sont lisibles');

        $rendus = $this->idsRendus($http, $entete, '&dateDemande[before]=' . urlencode($this->seuil()));

        // Deux des trois ont plus d'un mois : la vieille en attente et la vieille déjà traitée.
        // Le filtre de DATE ne connaît pas le statut, et c'est bien ce qu'on veut vérifier ici.
        self::assertCount(2, $rendus, 'deux demandes dépassent le mois — un zéro se lirait « aucun retard »');
        self::assertContains($ids['vieilleEnAttente'], $rendus);
        self::assertContains($ids['vieilleTraitee'], $rendus);
    }

    /**
     * ⚠ ET LE FILTRE DOIT AUSSI ÉPARGNER.
     *
     * Un filtre trop large — qui rendrait tout — passerait le compte ci-dessus si, par malchance,
     * une seule demande existait. Celui-ci prouve qu'une demande RÉCENTE n'est pas comptée en
     * retard : c'est le cas que le seuil doit laisser passer, et le seul qui démasque un seuil mal
     * posé.
     */
    public function testUneDemandeRECENTENEstPasComptEeEnRetard(): void
    {
        [$http, $entete] = $this->adminSurA();
        $ids = $this->poserTroisDemandes();

        $rendus = $this->idsRendus($http, $entete, '&dateDemande[before]=' . urlencode($this->seuil()));

        self::assertNotContains($ids['recente'], $rendus, 'une demande d’hier n’est pas en retard');
        self::assertContains($ids['vieilleEnAttente'], $rendus, 'témoin : le filtre rend bien quelque chose');
    }

    /** Et les deux filtres se combinent : c'est exactement ce dont le compteur du menu a besoin. */
    public function testLeStatutETLaDateSeCombinent(): void
    {
        [$http, $entete] = $this->adminSurA();
        $ids = $this->poserTroisDemandes();
        $seuil = urlencode($this->seuil());

        $aTraiter = $this->idsRendus($http, $entete, '&statut=recue&dateDemande[before]=' . $seuil);
        self::assertSame(
            [$ids['vieilleEnAttente']],
            $aTraiter,
            'reçue ET en retard : c’est ce que le badge doit compter, et rien d’autre',
        );

        // ⚠ LE TÉMOIN PORTE SUR UN STATUT QUI N'A RIEN EN RETARD, et `realisee` n'en est pas un :
        // la vieille traitée a trois mois. `en_cours` n'a aucune demande — c'est lui qui prouve que
        // la combinaison des deux filtres sait aussi rendre zéro.
        self::assertSame(
            [],
            $this->idsRendus($http, $entete, '&statut=en_cours&dateDemande[before]=' . $seuil),
            'témoin : aucune « en cours » n’est en retard',
        );

        // Et le statut seul discrimine encore : `realisee` en rend une, la vieille traitée.
        self::assertSame(
            [$ids['vieilleTraitee']],
            $this->idsRendus($http, $entete, '&statut=realisee'),
            'témoin : le filtre de statut voit toujours',
        );
    }

    /**
     * ⚠ LA REQUETE EXACTE DU BADGE, SUR LA PORTE QU'IL EMPRUNTE.
     *
     * Le compteur du menu demande `statut[]=recue&statut[]=en_cours` — deux statuts d'un coup. J'ai
     * prouvé cette forme sur `/api/clients` ; c'est le même filtre d'API Platform, mais « le même
     * mécanisme ailleurs » n'est pas une mesure. Sur CETTE entité, un filtre déclaré a déjà rendu
     * une liste vide en silence.
     */
    public function testLeBadgePeutDemanderLesDeuxStatutsEnAttente(): void
    {
        [$http, $entete] = $this->adminSurA();
        $ids = $this->poserTroisDemandes();

        $enAttente = $this->idsRendus($http, $entete, '&statut[]=recue&statut[]=en_cours');
        sort($enAttente);
        $attendus = [$ids['vieilleEnAttente'], $ids['recente']];
        sort($attendus);

        self::assertSame($attendus, $enAttente, 'les deux en attente, et la traitée exclue');

        // Le témoin : la traitée existe bien et n'est écartée que par le filtre.
        self::assertNotContains($ids['vieilleTraitee'], $enAttente);
        self::assertCount(3, $this->idsRendus($http, $entete, ''), 'témoin : les trois sont lisibles');
    }

    private function seuil(): string
    {
        return (new \DateTimeImmutable('-1 month'))->format(\DATE_ATOM);
    }

    /** @return list<string> les identifiants rendus par la collection, dans l'ordre reçu */
    private function idsRendus(object $http, array $entete, string $queryEnPlus): array
    {
        $corps = $http->request('GET', '/api/demande_rgpds?itemsPerPage=100' . $queryEnPlus, $entete)
            ->toArray();

        return array_values(array_map(
            static fn (array $d): string => (string) ($d['id'] ?? ''),
            $corps['member'] ?? $corps['hydra:member'] ?? [],
        ));
    }

    /**
     * Une vieille en attente, une récente en attente, une vieille déjà traitée.
     *
     * @return array{vieilleEnAttente: string, recente: string, vieilleTraitee: string}
     */
    private function poserTroisDemandes(): array
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $client = $em->getRepository(Client::class)->find($this->idPayeur());
        self::assertNotNull($client, 'témoin : le client des fixtures existe');

        $cas = [
            'vieilleEnAttente' => [TypeDemandeRgpd::Effacement, StatutDemandeRgpd::Recue, '-2 months'],
            'recente' => [TypeDemandeRgpd::Anonymisation, StatutDemandeRgpd::Recue, '-1 day'],
            'vieilleTraitee' => [TypeDemandeRgpd::Anonymisation, StatutDemandeRgpd::Realisee, '-3 months'],
        ];

        $ids = [];
        foreach ($cas as $cle => [$type, $statut, $quand]) {
            $d = new DemandeRGPD();
            $d->setClient($client)->setType($type)->setStatut($statut);
            // `dateDemande` est posée au constructeur : on la repositionne par réflexion plutôt que
            // d'ajouter au domaine un setter dont seuls les tests auraient l'usage.
            (new \ReflectionProperty(DemandeRGPD::class, 'dateDemande'))
                ->setValue($d, new \DateTimeImmutable($quand));
            $em->persist($d);
            $ids[$cle] = (string) $d->getId();
        }
        $em->flush();

        return $ids;
    }
}
