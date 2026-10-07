<?php

declare(strict_types=1);

namespace App\Signature\Enum;

/**
 * Ce qu'une `ElectronicSignature` atteste. Le type est FIGÉ dans le sceau : il dit à quelle famille
 * de document la preuve se rattache. Pas de cas « autre » — un document dont on ne sait pas dire la
 * nature ne se signe pas ici, parce qu'on ne saurait pas quoi opposer en cas de litige.
 */
enum SignedDocumentType: string
{
    case SepaMandate = 'sepa_mandate';
    case SubscriptionContract = 'subscription_contract';
}
