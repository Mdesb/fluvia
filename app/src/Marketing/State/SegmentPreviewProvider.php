<?php

declare(strict_types=1);

namespace App\Marketing\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Crm\Entity\Client;
use App\Marketing\Entity\Segment;
use App\Marketing\Service\SegmentResolver;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /marketing/segments/{id}/apercu` — combien de personnes, et lesquelles.
 *
 * **RG-CMP-02 : le nombre de personnes touchées s'affiche AVANT tout envoi.** Sans ce chiffre,
 * l'exploitant découvre l'ampleur de son geste après l'avoir fait — et un envoi ne se rattrape pas.
 *
 * **L'échantillon compte autant que le compte.** « 1 240 personnes » ne se vérifie pas ; « 1 240
 * personnes, dont Martin, Dupont et Nowak » se reconnaît ou se conteste. Un exploitant qui ne
 * reconnaît personne dans son propre échantillon vient d'apprendre que son critère est faux — et il
 * l'apprend avant d'écrire à mille personnes.
 *
 * ── DEUX VERROUS, ET ILS NE PROTÈGENT PAS LA MÊME CHOSE ─────────────────────────────────────────
 *
 * **1. L'autorité se recalcule contre l'établissement DU SEGMENT.** L'attribut `security` de
 * l'opération contrôle `campagne.lire` sur l'établissement ACTIF, c'est-à-dire sur l'en-tête
 * `X-Etablissement` — un sélecteur envoyé par le client, pas une preuve d'appartenance (D6). Sans ce
 * second contrôle, quelqu'un pouvait passer l'identifiant d'un segment d'un autre établissement et
 * en lire le libellé.
 *
 * Le refus est un **404, pas un 403** : distinguer « hors périmètre » de « inexistant » permet
 * d'énumérer l'activité du voisin en essayant des identifiants.
 *
 * **2. Les clients, eux, sont bornés par `SegmentResolver`** au périmètre de l'appelant. Les deux
 * verrous sont nécessaires et ne se remplacent pas : le premier protège le SEGMENT (son libellé, son
 * existence), le second protège les CLIENTS. Un seul des deux laisserait fuir l'autre.
 *
 * ⚠ L'échantillon porte des noms de clients : c'est un accès à des données personnelles. Il est donc
 * borné à dix.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final readonly class SegmentPreviewProvider implements ProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SegmentResolver $resolver,
        private Security $security,
        private CalculateurDroits $calculateur,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $id = $uriVariables['id'] ?? null;
        $segment = $id instanceof Uuid || \is_string($id)
            ? $this->entityManager->getRepository(Segment::class)->find($id)
            : null;

        if (!$segment instanceof Segment) {
            throw new NotFoundHttpException('Segment introuvable.');
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new NotFoundHttpException('Segment introuvable.');
        }

        // L'autorité contre l'établissement de l'ENTITÉ RÉSOLUE, jamais contre l'en-tête.
        $codes = $this->calculateur->codesEffectifs($utilisateur, $segment->getEstablishment()?->getId());
        if (!$this->calculateur->autorise($codes, 'campagne', 'lire')) {
            // Même message que « segment inexistant » : un refus distinct confirmerait l'existence.
            throw new NotFoundHttpException('Segment introuvable.');
        }

        return new JsonResponse([
            'segment' => $segment->getLabel(),
            'effectif' => $this->resolver->compter($segment),
            'echantillon' => array_map(
                static fn (Client $c): array => [
                    'id' => (string) $c->getId(),
                    'nom' => trim(($c->getPrenom() ?? '') . ' ' . ($c->getNom() ?? ''))
                        ?: ($c->getRaisonSociale() ?? '—'),
                    'derniereVisite' => $c->getDateDerniereVisite()?->format('Y-m-d'),
                ],
                $this->resolver->echantillon($segment),
            ),
            // L'écran doit pouvoir dire « aucun envoi réel » sans le deviner : c'est le serveur qui
            // sait qu'aucun prestataire n'est branché (RG-CMP-05, CA-9).
            'envoiReelDisponible' => false,
        ]);
    }
}
