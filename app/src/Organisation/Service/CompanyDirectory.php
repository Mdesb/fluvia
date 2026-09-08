<?php

declare(strict_types=1);

namespace App\Organisation\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * L'ANNUAIRE DES ENTREPRISES — pour ne plus faire retaper ce que l'État publie déjà.
 *
 * Source : `recherche-entreprises.api.gouv.fr`, l'API de l'Annuaire des Entreprises (DINUM).
 * **Aucune clé, aucun contrat, aucune facture** — c'est ce qui l'a fait préférer à l'API Sirene de
 * l'INSEE, qui rend les mêmes données mais exige un compte et un jeton à renouveler. Une
 * dépendance qu'on ne peut pas provisionner soi-même est une dépendance qui tombe un dimanche.
 *
 * `societe.com` et `infogreffe.fr` rendent aussi ce service, contre abonnement : payer pour de la
 * donnée publique n'avait pas de raison d'être ici.
 *
 * ── POURQUOI L'APPEL PART DU SERVEUR ET NON DU NAVIGATEUR ───────────────────────────────────────
 *
 * Trois raisons, dans l'ordre où elles comptent :
 *
 *   1. **la donnée saisie ne quitte pas notre périmètre par la porte du client.** Un exploitant qui
 *      cherche « Gione fitness » depuis son poste enverrait sa frappe à un tiers sans que rien ne
 *      l'en informe ;
 *   2. le jour où cette API tombe, un seul endroit à débrancher ;
 *   3. CORS, accessoirement.
 *
 * ── CE QUE FAIT CETTE CLASSE QUAND L'ANNUAIRE NE RÉPOND PAS ─────────────────────────────────────
 *
 * Elle rend une liste vide et le DIT — jamais une exception qui remonterait en 500. Chercher une
 * société est un confort : son indisponibilité ne doit pas empêcher de créer la structure à la
 * main. Un service externe en panne ne doit pas fermer le produit.
 */
final readonly class CompanyDirectory
{
    private const BASE = 'https://recherche-entreprises.api.gouv.fr/search';

    /** Au-delà, l'exploitant ne lit plus : il reformule sa recherche. */
    private const MAX_RESULTATS = 8;

    /** Un annuaire lent ne doit pas faire attendre une saisie. */
    private const DELAI_SECONDES = 6;

    public function __construct(private HttpClientInterface $http)
    {
    }

    /**
     * @return array{disponible: bool, resultats: list<array<string, mixed>>, raison?: string}
     */
    public function chercher(string $terme): array
    {
        $terme = trim($terme);
        if (mb_strlen($terme) < 3) {
            return [
                'disponible' => true,
                'resultats' => [],
                'raison' => 'Tapez au moins trois caractères.',
            ];
        }

        try {
            $reponse = $this->http->request('GET', self::BASE, [
                'query' => [
                    'q' => $terme,
                    'per_page' => self::MAX_RESULTATS,
                    // Les entreprises cessées n'intéressent personne ici : on ouvre un compte
                    // client, pas une archive.
                    'etat_administratif' => 'A',
                ],
                'timeout' => self::DELAI_SECONDES,
            ]);

            $donnees = $reponse->toArray(false);
        } catch (ExceptionInterface|\JsonException) {
            // On le DIT plutôt que de rendre zéro résultat : « rien trouvé » et « je n'ai pas pu
            // chercher » demandent deux gestes différents de la part de l'exploitant.
            return [
                'disponible' => false,
                'resultats' => [],
                'raison' => 'L’annuaire des entreprises n’a pas répondu. Saisissez les informations '
                    . 'à la main : rien n’est bloqué.',
            ];
        }

        $resultats = [];
        foreach ($donnees['results'] ?? [] as $brut) {
            if (\is_array($brut)) {
                $resultats[] = $this->normaliser($brut);
            }
        }

        return ['disponible' => true, 'resultats' => $resultats];
    }

    /**
     * Ce que l'écran affiche et ce que la création reprendra — rien de plus.
     *
     * L'annuaire rend une trentaine de champs par société : finances, effectifs, dirigeants,
     * coordonnées géographiques. Les recopier tous ferait entrer dans notre base des données que
     * personne n'a demandées et que personne ne mettra à jour.
     *
     * @param array<string, mixed> $brut
     *
     * @return array<string, mixed>
     */
    private function normaliser(array $brut): array
    {
        $siege = \is_array($brut['siege'] ?? null) ? $brut['siege'] : [];
        $tva = $brut['tva'] ?? null;

        // L'annuaire publie la rue en morceaux (numero_voie / type_voie / libelle_voie) et la
        // ville a part (libelle_commune). La facture EN 16931 veut l'adresse vendeur decoupee
        // (BT-35 rue / BT-38 CP / BT-37 ville) : on la transmet deja decoupee plutot que de
        // redecouper le texte libre `adresse`, ce qui serait fragile et faux sur les cas limites.
        $rue = trim(implode(' ', array_filter([
            (string) ($siege['numero_voie'] ?? ''),
            (string) ($siege['type_voie'] ?? ''),
            (string) ($siege['libelle_voie'] ?? ''),
        ], static fn (string $part): bool => $part !== '')));

        return [
            'denomination' => (string) ($brut['nom_complet'] ?? $brut['nom_raison_sociale'] ?? ''),
            'raisonSociale' => (string) ($brut['nom_raison_sociale'] ?? ''),
            'siren' => (string) ($brut['siren'] ?? ''),
            'siret' => (string) ($siege['siret'] ?? ''),
            // Le numéro de TVA est publié : le recalculer depuis le SIREN serait refaire un travail
            // déjà fait, et se tromper sur les cas particuliers.
            'numeroTva' => \is_array($tva) ? (string) ($tva[0] ?? '') : (string) ($tva ?? ''),
            'formeJuridique' => (string) ($brut['nature_juridique'] ?? ''),
            'codeNaf' => (string) ($brut['activite_principale'] ?? ''),
            'adresse' => (string) ($siege['adresse'] ?? ''),
            'rue' => $rue,
            'complement' => (string) ($siege['complement_adresse'] ?? ''),
            'codePostal' => (string) ($siege['code_postal'] ?? ''),
            'ville' => (string) ($siege['libelle_commune'] ?? ''),
            'dateCreation' => (string) ($brut['date_creation'] ?? ''),
        ];
    }
}
