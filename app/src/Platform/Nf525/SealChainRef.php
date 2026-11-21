<?php

declare(strict_types=1);

namespace App\Platform\Nf525;

use App\Caisse\Entity\PointDeVente;
use App\Compta\Entity\Journal;
use App\Compta\Entity\ProfilExploitant;
use Symfony\Component\Uid\Uuid;

/**
 * Identité d'une chaîne scellée, pour l'alerte NF525 : une clé stable et unique par chaîne (≤ 100
 * caractères, colonne `chain_key`) et un libellé PUBLIC, lisible par un administrateur d'établissement
 * (ni UUID, ni nom de variable d'environnement).
 */
final class SealChainRef
{
    public const MAX_KEY_LENGTH = 100;
    private const MAX_LABEL_LENGTH = 160;

    private function __construct(
        public readonly SealChainType $type,
        private readonly string $key,
        private readonly string $label,
        public readonly ?Uuid $pointOfSaleId = null,
        public readonly ?Uuid $operatorProfileId = null,
        public readonly ?Uuid $journalId = null,
    ) {
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new \LogicException('Clé de chaîne NF525 trop longue.');
        }
    }

    public static function forSales(PointDeVente $pointOfSale): self
    {
        $id = $pointOfSale->getId();

        return new self(
            SealChainType::Sales,
            'sales:' . $id->toRfc4122(),
            self::compose('Caisse', $pointOfSale->getLibelle()),
            pointOfSaleId: $id,
        );
    }

    public static function forAccounting(ProfilExploitant $profile, Journal $journal): self
    {
        return new self(
            SealChainType::Accounting,
            'accounting:' . $profile->getId()->toRfc4122() . ':' . $journal->getId()->toRfc4122(),
            self::compose('Comptabilité', $journal->getCode(), ' — journal '),
            operatorProfileId: $profile->getId(),
            journalId: $journal->getId(),
        );
    }

    public static function forInvoicing(ProfilExploitant $profile): self
    {
        return new self(
            SealChainType::Invoicing,
            'invoicing:' . $profile->getId()->toRfc4122(),
            'Facturation',
            operatorProfileId: $profile->getId(),
        );
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** `<base><séparateur><détail>`, ou `<base>` seul si le détail est vide (jamais d'espace finale). */
    private static function compose(string $base, string $detail, string $separator = ' '): string
    {
        $detail = trim($detail);

        return mb_substr($detail === '' ? $base : $base . $separator . $detail, 0, self::MAX_LABEL_LENGTH);
    }
}
