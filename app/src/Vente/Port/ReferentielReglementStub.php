<?php

declare(strict_types=1);

namespace App\Vente\Port;

/**
 * Stub L2 du référentiel des moyens de paiement (RG-M2-02) : jeu standard en attendant le
 * référentiel M6 réel. Seules les espèces autorisent le rendu de monnaie (RG-M2-05) ; la CB exige
 * une référence TPE (US-L2-07) ; le différé autorise une validation avec reste dû > 0 (RG-M2-03).
 */
final class ReferentielReglementStub implements ReferentielReglementInterface
{
    /** @var array<string, MoyenPaiement>|null */
    private ?array $cache = null;

    public function moyensDisponibles(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $moyens = [
            new MoyenPaiement('especes', 'Espèces', autoriseRendu: true),
            new MoyenPaiement('cb', 'Carte bancaire (TPE)', exigeReference: true),
            new MoyenPaiement('cheque', 'Chèque'),
            new MoyenPaiement('virement', 'Virement'),
            new MoyenPaiement('cheque_vacances', 'Chèques Vacances'),
            new MoyenPaiement('cheque_culture', 'Chèque Culture'),
            new MoyenPaiement('cheque_loisirs', 'Chèque Loisirs'),
            new MoyenPaiement('pmv', 'Passe / Monnaie de ville (PMV)'),
            new MoyenPaiement('avoir', 'Avoir'),
            new MoyenPaiement('differe', 'Paiement différé', autoriseDiffere: true),
        ];

        $indexe = [];
        foreach ($moyens as $moyen) {
            $indexe[$moyen->code] = $moyen;
        }

        return $this->cache = $indexe;
    }

    public function moyen(string $code): ?MoyenPaiement
    {
        return $this->moyensDisponibles()[$code] ?? null;
    }
}
