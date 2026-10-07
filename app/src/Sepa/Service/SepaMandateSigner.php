<?php

declare(strict_types=1);

namespace App\Sepa\Service;

use App\Crm\Entity\Client;
use App\Securite\Entity\Utilisateur;
use App\Sepa\Entity\MandatSepa;
use App\Signature\Entity\ElectronicSignature;
use App\Signature\Enum\SignedDocumentType;
use App\Signature\Service\SignatureSealer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Signe un mandat SEPA : compose le document du mandat, le hache, recueille la signature (image
 * manuscrite + faisceau de preuve) et scelle le tout via le socle `App\Signature`.
 *
 * La signature RÉFÉRENCE le mandat (targetType « SepaMandate » + targetId) : on ne touche pas à
 * l'entité `MandatSepa`, et on ne crée pas d'entité miroir — le document est reproductible depuis le
 * mandat, et le `documentHash` de la signature l'ancre. Pour retrouver la signature d'un mandat :
 * chercher une `ElectronicSignature` par (targetType='SepaMandate', targetId=mandat.id).
 *
 * Remplace le « signé par simple saisie IBAN » d'aujourd'hui : jusqu'ici, poser IBAN + titulaire
 * suffisait à marquer le mandat « Actif » — aucune preuve de consentement. Ne flush pas : la
 * signature s'inscrit dans la transaction de souscription.
 */
final class SepaMandateSigner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SepaMandateComposer $composer,
        private readonly SignatureSealer $sealer,
    ) {
    }

    public function sign(
        MandatSepa $mandate,
        ?Client $signer,
        ?Utilisateur $operator,
        ?string $signatureImage,
        ?string $ip = null,
        ?string $userAgent = null,
    ): ElectronicSignature {
        $documentText = $this->composer->compose($mandate);

        $signature = (new ElectronicSignature())
            ->setEtablissement($mandate->getEtablissement())
            ->setDocumentType(SignedDocumentType::SepaMandate)
            ->setTargetType('SepaMandate')
            ->setTargetId($mandate->getId())
            ->setDocumentHash(hash('sha256', $documentText))
            ->setSignatureImage($signatureImage)
            // Le nom du signataire est celui du TITULAIRE déclaré au mandat : c'est lui qui autorise le
            // prélèvement, pas nécessairement l'opérateur ni même le payeur.
            ->setSignerName($mandate->getDebiteurNom())
            ->setSigner($signer)
            ->setOperator($operator)
            ->setIp($ip)
            ->setUserAgent($userAgent);

        $this->sealer->seal($signature);
        $this->em->persist($signature);

        return $signature;
    }
}
