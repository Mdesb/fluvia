<?php

declare(strict_types=1);

namespace App\Legal\Enum;

/**
 * Les documents qu'un site marchand doit publier — et ce que leur absence coûte.
 *
 * La liste n'est pas un choix de produit : chacun répond à une obligation distincte, avec sa propre
 * sanction. Les regrouper en une page « Mentions légales » fourre-tout, comme le font beaucoup de
 * sites, ne satisfait aucune des quatre.
 */
enum LegalDocumentType: string
{
    /**
     * Mentions légales — LCEN art. 6-III. Identité de l'éditeur, et **celle de l'hébergeur**, qui est
     * la partie systématiquement oubliée alors qu'elle est nommément exigée.
     */
    case LegalNotice = 'legal_notice';

    /**
     * Conditions générales de vente. Le seul document dont le contenu **dépend de ce qui est vendu** :
     * voir `SalesActivity`. Obligatoire avant tout paiement, et opposable seulement si le client a pu
     * en prendre connaissance — d'où le versionnement.
     */
    case TermsOfSale = 'terms_of_sale';

    /** Conditions générales d'utilisation du site et du compte client. */
    case TermsOfUse = 'terms_of_use';

    /**
     * Politique de confidentialité — RGPD art. 13. Finalités, base légale, durées de conservation,
     * destinataires, droits, et **la voie de réclamation auprès de la CNIL**, dont l'omission est le
     * manquement le plus fréquemment relevé.
     */
    case PrivacyPolicy = 'privacy_policy';

    /** Politique cookies — art. 82 de la loi Informatique et Libertés. */
    case CookiePolicy = 'cookie_policy';

    /**
     * Déclaration d'accessibilité — RGAA.
     *
     * **Celle-ci n'est pas optionnelle ici, et c'est facile de le manquer.** Le pied de page de la
     * boutique affiche déjà « Service public » : les exploitants du produit sont des collectivités et
     * des délégataires de service public, pour qui la déclaration d'accessibilité est **obligatoire**,
     * avec mention du taux de conformité et un schéma pluriannuel.
     */
    case AccessibilityStatement = 'accessibility_statement';

    public function label(): string
    {
        return match ($this) {
            self::LegalNotice => 'Mentions légales',
            self::TermsOfSale => 'Conditions générales de vente',
            self::TermsOfUse => 'Conditions générales d’utilisation',
            self::PrivacyPolicy => 'Politique de confidentialité',
            self::CookiePolicy => 'Gestion des cookies',
            self::AccessibilityStatement => 'Accessibilité',
        };
    }

    /** Le slug d'URL publique : `/vitrine/{id}/mentions-legales`. */
    public function slug(): string
    {
        return match ($this) {
            self::LegalNotice => 'mentions-legales',
            self::TermsOfSale => 'cgv',
            self::TermsOfUse => 'cgu',
            self::PrivacyPolicy => 'confidentialite',
            self::CookiePolicy => 'cookies',
            self::AccessibilityStatement => 'accessibilite',
        };
    }

    /**
     * Vrai quand le document doit être **accepté** avant paiement, et non seulement consultable.
     *
     * Seules les CGV le sont. Faire cocher les cinq autres transformerait un consentement en formalité
     * — et un consentement que personne ne lit n'en est plus un, y compris devant un juge.
     */
    public function requiresAcceptance(): bool
    {
        return $this === self::TermsOfSale;
    }
}
