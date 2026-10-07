<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Crm\Entity\Client;
use App\Membership\Entity\Membership;
use App\Membership\Entity\SubscriptionContract;
use App\Securite\Entity\Utilisateur;
use App\Signature\Entity\ElectronicSignature;
use App\Signature\Enum\SignedDocumentType;
use App\Signature\Service\SignatureSealer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Signe le contrat d'abonnement : compose le texte gelé depuis les termes de l'abonnement, en calcule
 * le hash, recueille la signature (image manuscrite + faisceau de preuve) et scelle le tout.
 *
 * Ne flush pas : la signature du contrat s'inscrit dans la MÊME transaction que la souscription — le
 * contrat, l'abonnement, le mandat et (à venir) l'encaissement comptant réussissent ou échouent
 * ensemble. Un contrat signé sur un abonnement qui n'aurait pas été créé ne prouverait rien.
 */
final class SubscriptionContractSigner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SubscriptionContractComposer $composer,
        private readonly SignatureSealer $sealer,
    ) {
    }

    public function sign(
        Membership $subscription,
        ?Client $signer,
        ?Utilisateur $operator,
        ?string $signatureImage,
        ?string $ip = null,
        ?string $userAgent = null,
    ): SubscriptionContract {
        $documentText = $this->composer->compose($subscription);

        $contract = (new SubscriptionContract())
            ->setSubscription($subscription)
            ->setEtablissement($subscription->getEtablissement())
            ->setDocumentText($documentText);

        // La signature lie la preuve au DOCUMENT EXACT (son hash) et à ce contrat précis (targetId).
        $signature = (new ElectronicSignature())
            ->setEtablissement($subscription->getEtablissement())
            ->setDocumentType(SignedDocumentType::SubscriptionContract)
            ->setTargetType('SubscriptionContract')
            ->setTargetId($contract->getId())
            ->setDocumentHash(hash('sha256', $documentText))
            ->setSignatureImage($signatureImage)
            ->setSignerName($this->composer->clientName($signer))
            ->setSigner($signer)
            ->setOperator($operator)
            ->setIp($ip)
            ->setUserAgent($userAgent);

        $this->sealer->seal($signature);
        $contract->setSignature($signature);

        $this->em->persist($signature);
        $this->em->persist($contract);

        return $contract;
    }
}
