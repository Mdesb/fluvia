<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * QUI PEUT AGIR SUR CET ÉTABLISSEMENT — ET DONC QUI DOIT ÊTRE PRÉVENU.
 *
 * ── ON NE NOTIFIE PAS « LES ADMINISTRATEURS » ───────────────────────────────────────────────────
 *
 * On notifie ceux qui **peuvent faire le geste**. L'impayé du jour intéresse qui tient le
 * recouvrement, pas le caissier — et une cloche qui sonne chez quelqu'un qui ne peut rien y faire
 * apprend surtout à ignorer la cloche.
 *
 * ── LE CALCUL DES DROITS N'EST PAS REJOUÉ ICI ───────────────────────────────────────────────────
 *
 * ⚠ `CalculateurDroits` est la seule autorité, et il est appelé, pas recopié. Une règle de droits
 * réécrite ailleurs diverge au premier correctif — c'est ce qui s'est produit trois fois cette
 * semaine sur d'autres sujets, et sur les permissions la divergence ne se voit pas : elle donne
 * simplement à quelqu'un une information qu'il n'aurait pas dû recevoir.
 *
 * ── LE PÉRIMÈTRE DE DÉPART EST L'AFFECTATION, PAS LA TABLE DES UTILISATEURS ─────────────────────
 *
 * On part de ceux qui sont rattachés à CET établissement. Balayer tous les comptes et interroger
 * leurs droits un par un donnerait le même résultat en interrogeant des dizaines de personnes qui
 * n'ont rien à voir avec ce site.
 *
 * ── LA LISTE EST FIGÉE AU MOMENT DE L'ÉVÉNEMENT, ET C'EST VOULU ─────────────────────────────────
 *
 * Une notification s'adresse à qui pouvait agir **quand le fait s'est produit**. Quelqu'un qui
 * reçoit le droit trois jours plus tard ne découvre pas l'arriéré des alertes passées ; quelqu'un
 * qui le perd garde celles qu'il a déjà reçues, avec leur trace. C'est ce que fait une ligne par
 * destinataire — et c'est ce qu'une notification adressée à un rôle ne saurait pas faire.
 */
final class NotificationRecipientResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CalculateurDroits $droits,
    ) {
    }

    /**
     * Les comptes rattachés à cet établissement qui possèdent `module.action`.
     *
     * @return list<Utilisateur>
     */
    public function resolve(Uuid $establishmentId, string $module, string $action): array
    {
        /** @var list<Utilisateur> $candidats */
        $candidats = $this->em->createQuery(
            'SELECT DISTINCT u FROM '.Utilisateur::class.' u
             JOIN '.Affectation::class.' a WITH IDENTITY(a.utilisateur) = u.id
             WHERE IDENTITY(a.etablissement) = :etablissement',
        )
            // ⚠ Le type `uuid` : sans lui la comparaison porte une chaîne contre une colonne binaire,
            // ne trouve personne, et ne lève rien. Une cloche muette pour cause de liaison non typée
            // est indiscernable d'une cloche sans rien à dire.
            ->setParameter('etablissement', $establishmentId, 'uuid')
            ->getResult();

        $destinataires = [];
        foreach ($candidats as $candidat) {
            $codes = $this->droits->codesEffectifs($candidat, $establishmentId);
            if ($this->droits->autorise($codes, $module, $action)) {
                $destinataires[] = $candidat;
            }
        }

        return $destinataires;
    }
}
