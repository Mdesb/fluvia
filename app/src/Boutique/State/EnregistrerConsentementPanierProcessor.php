<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\LignePanierEnLigne;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Vente\Service\LecteurCorps;
use App\Boutique\Service\PanierTarificationHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /boutique/paniers/{id}/consentement — dernier appel de l'écran « Vos billets » (#101, après
 * `identifier` et `beneficiaires`). Corps :
 * { "mentionVersion": string, "marketing"?: bool, "marketingVersion"?: string,
 *   "autorisationsParentales"?: {ligneId: bool} }.
 *
 * ⚠ LA GESTION DE LA COMMANDE N'EST PAS UN CONSENTEMENT (#101, avis juridique du 04/10). Cet appel
 * créait un `Consentement(Email, Accordé)` à chaque commande, depuis une case OBLIGATOIRE « j'accepte
 * que mes données soient traitées pour la gestion de ma commande ». Le moteur de campagnes le lisait
 * comme un accord marketing : tout acheteur recevait les campagnes. Or une case qu'on ne peut pas
 * décocher n'est pas un consentement libre (RGPD art. 7.4), et la commande se traite sur la base de
 * l'exécution du contrat (art. 6.1.b) — sans rien demander. Désormais :
 * - la MENTION d'information est horodatée sur le panier, avec sa version (preuve de l'information) ;
 * - seule la case FACULTATIVE « recevoir les nouveautés » crée un `Consentement(Email, Accordé)`,
 *   avec la version de son texte — au PAIEMENT CONFIRMÉ, d'après l'état final de la case gardé sur le
 *   panier (relecture, D2) : l'écran se renvoie autant de fois qu'il faut, la case peut changer.
 *
 * @implements ProcessorInterface<PanierEnLigne, PanierEnLigne>
 */
final class EnregistrerConsentementPanierProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PanierTarificationHandler $tarification,
        private readonly LecteurCorps $lecteur,
        private readonly PanierProprietaireGuard $guard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PanierEnLigne
    {
        \assert($data instanceof PanierEnLigne);
        $this->guard->verifier($data);

        $corps = $this->lecteur->corps();
        $mentionVersion = self::version($corps['mentionVersion'] ?? null);
        if ($mentionVersion === null) {
            throw new UnprocessableEntityHttpException('« mentionVersion » est requis : la version de la mention d\'information affichée.');
        }
        $marketing = ($corps['marketing'] ?? false) === true;
        $marketingVersion = self::version($corps['marketingVersion'] ?? null);
        if ($marketing && $marketingVersion === null) {
            // Un accord dont on ne sait pas quel texte il approuvait ne se prouve pas : on refuse de
            // l'enregistrer plutôt que d'en garder une moitié.
            throw new UnprocessableEntityHttpException('« marketingVersion » est requis quand la case marketing est cochée.');
        }
        /** @var array<string, mixed> $autorisations */
        $autorisations = \is_array($corps['autorisationsParentales'] ?? null) ? $corps['autorisationsParentales'] : [];

        $data->recordPrivacyNotice($mentionVersion);

        // ON ENREGISTRE CE QUI A ETE ACCEPTE, PAS SEULEMENT QU'IL L'A ETE.
        //
        // `LegalDocument` conserve chaque version publiee des CGV ; le panier garde laquelle
        // s'appliquait au moment ou le client a valide sa commande. Des CGV ne sont opposables que
        // dans la version que le client a pu lire au moment ou il a paye.
        $cgv = $this->cgvPubliees($data);
        if ($cgv !== null) {
            $data->accepterCgv($cgv['id'], $cgv['version']);
        }

        $data->setMarketingOptInVersion($marketing ? $marketingVersion : null);

        foreach ($data->getLignes() as $ligne) {
            \assert($ligne instanceof LignePanierEnLigne);
            if (!$ligne->isAutorisationParentaleRequise()) {
                continue;
            }
            $id = (string) $ligne->getId();
            if (($autorisations[$id] ?? false) === true) {
                $ligne->setAutorisationParentaleHorodatage(new \DateTimeImmutable());
            }
        }

        $this->em->flush();

        // ⚠ LE PANIER REPART AVEC SES PRIX. Sans cette ligne, la réponse d'une mutation ne
        // porte ni `total` ni `prixUnitaire` — seul `PanierAvecTotalProvider` (le GET) enrichit —
        // et le frontal, qui garde cette réponse en état, affichait un panier sans aucun montant
        // jusqu'au prochain rechargement. Mesuré à l'écran : « Total 8,00 € » disparaissait au
        // premier clic sur « − », remplacé par « le montant total sera calculé à l'étape de
        // paiement », qui se lit comme une politique et non comme un raté.
        // `calculer()` est pur : les trois champs sont transitoires, sans `#[ORM\Column]`.
        $this->tarification->calculer($data);

        return $data;
    }

    /** Une version de texte : chaîne non vide, 40 caractères au plus (la colonne qui la garde). */
    private static function version(mixed $valeur): ?string
    {
        if (!\is_string($valeur)) {
            return null;
        }
        $valeur = trim($valeur);

        return $valeur === '' || mb_strlen($valeur) > 40 ? null : $valeur;
    }

    /**
     * Les CGV publiees de l'etablissement de ce panier, ou `null` s'il n'y en a pas.
     *
     * **`null` ne bloque pas le paiement, et c'est un choix.** Un exploitant qui n'a pas encore publie
     * ses CGV a un probleme de conformite -- mais refuser la vente le decouvrirait un samedi soir, sur
     * un client qui n'y est pour rien. L'ecran des mentions legales le signale la ou c'est reparable.
     *
     * La lecture passe par du SQL : `LegalDocument` vit dans `App" + chr(92) + "Legal`, et une relation Doctrine
     * creerait une dependance de mapping entre deux modules qui doivent vivre separement (D2).
     *
     * @return array{id: Uuid, version: int}|null
     */
    private function cgvPubliees(PanierEnLigne $panier): ?array
    {
        $etablissement = $panier->getVitrine()?->getEtablissement()?->getId();
        if ($etablissement === null) {
            return null;
        }

        $ligne = $this->em->getConnection()->executeQuery(
            'SELECT id, version FROM legal_document
             WHERE establishment_id = ? AND type = ? AND status = ?
             ORDER BY version DESC LIMIT 1',
            [$etablissement->toBinary(), 'terms_of_sale', 'published'],
        )->fetchAssociative();

        if ($ligne === false) {
            return null;
        }

        return ['id' => Uuid::fromBinary($ligne['id']), 'version' => (int) $ligne['version']];
    }
}
