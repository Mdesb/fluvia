<?php

declare(strict_types=1);

namespace App\Signature\Service;

use App\Signature\Entity\ElectronicSignature;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Scelle une `ElectronicSignature` : lui donne sa place dans la chaîne de son établissement
 * (séquence + hash précédent), calcule le hash du faisceau de preuve et le signe (HMAC).
 *
 * ── LE PROCÉDÉ EST DUPLIQUÉ, PAS LE CODE ────────────────────────────────────────────────────────
 * Même choix que `ScellementFactureHandler` : on reprend le PROCÉDÉ NF525 (hash SHA-256 chaîné +
 * HMAC, canonicalisation déterministe) sans dépendre d'un autre domaine. Une signature n'est ni une
 * facture ni une vente ; coupler leurs chaînes ferait qu'un défaut de l'une casse l'autre, et qu'on
 * ne saurait plus laquelle relire.
 *
 * Non `final` : `lastLink` est surchargeable en test pour contrôler le maillon précédent sans base.
 * Le procédé de scellement, lui, reste privé — c'est la position dans la chaîne qu'on isole.
 */
class SignatureSealer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        // Aucune valeur par défaut : la clé vient de l'environnement, et son absence doit empêcher le
        // démarrage plutôt que sceller des signatures avec une clé lisible dans le code — un sceau
        // dont la clé est publique ne prouve rien. Même motif que NF525.
        #[Autowire(env: 'SIGNATURE_SEAL_KEY')]
        private readonly string $sealKey,
    ) {
    }

    /**
     * Scelle la signature (séquence / hash / seal / faisceau figé). Ne flush pas : l'appelant maîtrise
     * la transaction — la signature est scellée AVANT sa persistance, dans le même geste que la
     * création du mandat ou du contrat qu'elle atteste.
     */
    public function seal(ElectronicSignature $signature): ElectronicSignature
    {
        $previous = $this->lastLink($signature);
        $previousHash = $previous?->getHash();
        $sequence = $previous === null ? 1 : $previous->getSequenceNumber() + 1;

        $payload = $this->canonicalPayload($signature);
        $hash = $this->computeHash($payload, $previousHash);

        $signature->setSequenceNumber($sequence);
        $signature->setPreviousHash($previousHash);
        $signature->setHash($hash);
        $signature->setSeal($this->sign($hash));
        $signature->setCanonicalPayload($payload);

        return $signature;
    }

    public function lastLink(ElectronicSignature $signature): ?ElectronicSignature
    {
        return $this->em->getRepository(ElectronicSignature::class)->createQueryBuilder('s')
            ->andWhere('IDENTITY(s.etablissement) = :etab')
            ->andWhere('s.sequenceNumber > 0')
            ->setParameter('etab', $signature->getEtablissement()?->getId(), 'uuid')
            ->orderBy('s.sequenceNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Le faisceau de preuve, figé et rejouable. Tout ce qui rend la signature opposable y est — et
     * rien de superflu qui la rendrait irreproductible.
     *
     * @return array<string, mixed>
     */
    private function canonicalPayload(ElectronicSignature $s): array
    {
        return [
            'documentType' => $s->getDocumentType()->value,
            'targetType' => $s->getTargetType(),
            'targetId' => (string) $s->getTargetId(),
            // Le lien signature ↔ document : le hash du document EXACT signé.
            'documentHash' => $s->getDocumentHash(),
            'signerName' => $s->getSignerName(),
            'signer' => (string) $s->getSigner()?->getId(),
            'operator' => (string) $s->getOperator()?->getId(),
            'ip' => $s->getIp(),
            'userAgent' => $s->getUserAgent(),
            'signedAt' => $s->getSignedAt()->format('Y-m-d H:i:s'),
            // ⚠ ON SCELLE LE HASH DE L'IMAGE, PAS SES OCTETS. Le sceau couvre ainsi la signature
            // manuscrite (la remplacer casse le hash) sans gonfler la chaîne d'un PNG rejoué à chaque
            // vérification. `null` reste `null` : un consentement sans dessin est légitime.
            'signatureImageHash' => $s->getSignatureImage() !== null ? hash('sha256', $s->getSignatureImage()) : null,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function computeHash(array $payload, ?string $previousHash): string
    {
        return hash('sha256', $this->canonicalize($payload) . '|' . ($previousHash ?? ''));
    }

    private function sign(string $hash): string
    {
        return hash_hmac('sha256', $hash, $this->sealKey);
    }

    /** @param array<string, mixed> $payload */
    private function canonicalize(array $payload): string
    {
        return json_encode($this->sortRecursive($payload), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function sortRecursive(array $value): array
    {
        $isList = array_is_list($value);
        foreach ($value as $key => $sub) {
            if (\is_array($sub)) {
                $value[$key] = $this->sortRecursive($sub);
            }
        }
        if (!$isList) {
            ksort($value);
        }

        return $value;
    }
}
