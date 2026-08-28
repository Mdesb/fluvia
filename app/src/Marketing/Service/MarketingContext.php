<?php

declare(strict_types=1);

namespace App\Marketing\Service;

use App\Crm\Doctrine\CustomerScope;
use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * LES DEUX GARDES DU MODULE, ÉCRITES UNE SEULE FOIS.
 *
 * Six providers et processors posent exactement les mêmes questions : *qui appelle, sur quel
 * établissement, avec quel droit, et ce client est-il dans son périmètre ?* Recopiées six fois,
 * elles manqueront dans la septième — et une garde absente ne se voit pas : elle rend des lignes en
 * trop, pas une erreur.
 *
 * > **Un seul calcul, plusieurs appelants.**
 *
 * ── POURQUOI TOUT ÉCHOUE EN 404 ─────────────────────────────────────────────────────────────────
 *
 * Jamais 403. Distinguer « tu n'as pas le droit » de « ça n'existe pas » permettrait de vérifier
 * qu'une personne est cliente ailleurs en essayant son identifiant — et « untel est client de la
 * piscine d'à côté » est déjà une information de trop.
 *
 * ── POURQUOI L'AUTORITÉ SE RECALCULE ────────────────────────────────────────────────────────────
 *
 * `X-Etablissement` est un **sélecteur**, pas une preuve (D6). Le droit est donc recalculé contre
 * l'établissement désigné, jamais déduit de la présence de l'en-tête.
 */
final readonly class MarketingContext
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Security $security,
        private CalculateurDroits $calculateur,
        private ContexteEtablissement $contexte,
    ) {
    }

    /**
     * L'appelant et son établissement actif, une fois le droit vérifié.
     *
     * @return array{0: Utilisateur, 1: Etablissement}
     */
    public function exigerAutorite(string $module, string $action): array
    {
        $utilisateur = $this->security->getUser();
        $actif = $this->contexte->idActif();
        if (!$utilisateur instanceof Utilisateur || $actif === null) {
            throw new NotFoundHttpException('Introuvable.');
        }

        $codes = $this->calculateur->codesEffectifs($utilisateur, $actif);
        if (!$this->calculateur->autorise($codes, $module, $action)) {
            throw new NotFoundHttpException('Introuvable.');
        }

        $etablissement = $this->entityManager->getRepository(Etablissement::class)->find($actif);
        if (!$etablissement instanceof Etablissement) {
            throw new NotFoundHttpException('Introuvable.');
        }

        return [$utilisateur, $etablissement];
    }

    /**
     * L'autorité sur l'établissement d'une entité DÉJÀ RÉSOLUE (C19).
     *
     * `exigerAutorite()` contrôle l'établissement ACTIF — celui que l'en-tête désigne. Ça ne suffit
     * pas dès qu'on résout une entité par son identifiant : l'appelant peut être légitime sur son
     * établissement actif et viser une ligne d'un autre. Le droit se recalcule donc contre
     * l'établissement de la LIGNE, et le refus est un 404.
     *
     * Comparer les deux identifiants donnerait le même résultat aujourd'hui et cesserait de le
     * donner le jour où un utilisateur portera des droits différents selon l'établissement — ce que
     * `codesEffectifs()` sait déjà faire.
     */
    public function exigerAutoriteSur(?Uuid $etablissement, string $module, string $action): Utilisateur
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur || $etablissement === null) {
            throw new NotFoundHttpException('Introuvable.');
        }

        $codes = $this->calculateur->codesEffectifs($utilisateur, $etablissement);
        if (!$this->calculateur->autorise($codes, $module, $action)) {
            throw new NotFoundHttpException('Introuvable.');
        }

        return $utilisateur;
    }

    /**
     * Le client, s'il est dans le périmètre du lecteur — sinon il n'existe pas.
     *
     * `CustomerScope` est appliqué AVANT l'identifiant : dans cet ordre, aucun chemin ne l'omet.
     */
    public function exigerClientDansLePerimetre(string $identifiant, Utilisateur $utilisateur): Client
    {
        if (!Uuid::isValid($identifiant)) {
            throw new NotFoundHttpException('Client introuvable.');
        }

        $qb = $this->entityManager->getRepository(Client::class)->createQueryBuilder('c');
        CustomerScope::restreindreAuGroupe($qb, 'c', $utilisateur->getId());

        $client = $qb->andWhere('c.id = :client')
            ->setParameter('client', Uuid::fromString($identifiant), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        if (!$client instanceof Client) {
            throw new NotFoundHttpException('Client introuvable.');
        }

        return $client;
    }
}
