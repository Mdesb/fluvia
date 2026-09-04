<?php

declare(strict_types=1);

namespace App\Subscription\Service;

use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\StatutMandatSepa;
use App\Subscription\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le lien entre un abonnement d'éditeur et son mandat de prélèvement.
 *
 * **Extrait parce que TROIS mécanismes en dépendent, et qu'ils doivent répondre pareil.** Le tunnel
 * signe le mandat ; la commande d'échéance d'essai décide de suspendre ou de laisser courir selon
 * qu'il existe ; la facturation refuse de facturer un essai qui n'a jamais abouti à un mandat. Trois
 * copies de la même dérivation, c'est la garantie qu'un jour deux d'entre elles divergent — et le
 * symptôme serait un client suspendu alors qu'il a signé, ou facturé alors qu'il n'a rien signé.
 *
 * **La référence est dérivée de l'abonnement, jamais tirée au hasard** (RG-ED, tunnel §2). Un
 * formulaire renvoyé deux fois arrive deux fois : avec un RUM aléatoire, chaque passage créerait un
 * mandat de plus pour le même client, et la remise suivante ne saurait pas lequel présenter.
 */
final readonly class SubscriptionMandates
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * La référence de mandat de cet abonnement — déterministe, et dans les 35 caractères de la colonne.
     *
     * Vingt-quatre caractères hexadécimaux tirés de l'identifiant de l'abonnement : c'est le même
     * identifiant qui porte l'idempotence du provisionnement, donc deux mécanismes qui ne peuvent pas
     * se désynchroniser.
     */
    public function reference(Subscription $subscription): string
    {
        return 'RUM-ED-'.strtoupper(substr(str_replace('-', '', $subscription->getId()->toRfc4122()), 0, 24));
    }

    /** Le mandat **actif** de cet abonnement, s'il en a un. */
    public function active(Subscription $subscription): ?MandatSepa
    {
        $mandat = $this->em->getRepository(MandatSepa::class)->findOneBy([
            'rum' => $this->reference($subscription),
            'statut' => StatutMandatSepa::Actif,
        ]);

        return $mandat instanceof MandatSepa ? $mandat : null;
    }

    /**
     * Le mandat de cet abonnement quel que soit son statut — pour le mettre à jour plutôt qu'en créer un.
     */
    public function any(Subscription $subscription): ?MandatSepa
    {
        $mandat = $this->em->getRepository(MandatSepa::class)->findOneBy([
            'rum' => $this->reference($subscription),
        ]);

        return $mandat instanceof MandatSepa ? $mandat : null;
    }
}
