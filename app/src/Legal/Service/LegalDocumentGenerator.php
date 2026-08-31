<?php

declare(strict_types=1);

namespace App\Legal\Service;

use App\Legal\Entity\LegalDocument;
use App\Legal\Entity\LegalIdentity;
use App\Legal\Enum\LegalDocumentType;
use App\Legal\Enum\SalesActivity;

/**
 * ÉCRIT LES SIX DOCUMENTS DEPUIS LA FICHE DE L'EXPLOITANT — ET NOMME CE QUI MANQUE.
 *
 * **Le problème réel n'est pas d'écrire des CGV, c'est de les écrire justes.** Maxime : *« le client va
 * devoir remplir donc il faut l'aider là-dessus »*. Un modèle vide avec des `[À COMPLÉTER]` ne l'aide
 * pas : il produit exactement ce qu'on voit sur la moitié des sites français — un texte publié où
 * subsiste `[NOM DE LA SOCIÉTÉ]`, que personne n'a relu parce que personne ne savait quoi y mettre.
 *
 * Ce générateur fait donc deux choses qu'un modèle ne fait pas :
 *
 * 1. **Il compose les clauses depuis ce qui est réellement vendu.** Un exploitant qui vend des entrées
 *    *et* des mugs obtient les deux régimes de rétractation, séparés et nommés. Voir `SalesActivity` :
 *    l'exception de l'art. L221-28 12° couvre le billet daté, pas la marchandise.
 * 2. **Il rend la liste de ce qu'il n'a pas pu remplir**, champ par champ, au lieu de laisser un blanc
 *    dans le texte. L'écran affiche cette liste ; le texte, lui, dit explicitement l'information
 *    manquante à l'endroit où elle manque.
 *
 * > **Un trou nommé se comble ; un trou anonyme se publie.**
 *
 * ⚠ **Ce sont des modèles, pas un avis juridique.** Ils encodent une lecture des obligations
 * habituelles d'un site marchand français ; l'exploitant reste responsable de ce qu'il publie et doit
 * faire relire. Le générateur l'écrit **dans le document lui-même**, en tête du brouillon, de sorte
 * qu'un texte publié sans relecture porte la trace de ce qui n'a pas été fait.
 */
final class LegalDocumentGenerator
{
    /**
     * @return array{0: string, 1: string, 2: list<string>} titre, contenu Markdown, champs manquants
     */
    public function generate(LegalDocumentType $type, LegalIdentity $identity, string $siteName): array
    {
        $manquants = [];
        $champ = function (?string $valeur, string $nom) use (&$manquants): string {
            if ($valeur === null || trim($valeur) === '') {
                $manquants[] = $nom;

                // On écrit CE QUI MANQUE plutôt qu'un crochet vide. Un `[À COMPLÉTER]` se lit comme un
                // gabarit qu'on oublie ; une phrase qui dit « information non renseignée » se lit
                // comme un défaut, et un défaut se corrige.
                return sprintf('*(à renseigner : %s)*', $nom);
            }

            return trim($valeur);
        };

        $contenu = match ($type) {
            LegalDocumentType::LegalNotice => $this->legalNotice($identity, $siteName, $champ),
            LegalDocumentType::TermsOfSale => $this->termsOfSale($identity, $siteName, $champ),
            LegalDocumentType::TermsOfUse => $this->termsOfUse($identity, $siteName, $champ),
            LegalDocumentType::PrivacyPolicy => $this->privacyPolicy($identity, $siteName, $champ),
            LegalDocumentType::CookiePolicy => $this->cookiePolicy($siteName),
            LegalDocumentType::AccessibilityStatement => $this->accessibilityStatement($identity, $siteName, $champ),
        };

        return [$type->label(), $this->preambule() . $contenu, array_values(array_unique($manquants))];
    }

    /**
     * L'avertissement est DANS le document, pas à côté.
     *
     * Placé dans l'écran seulement, il disparaît au moment où le texte est copié, exporté ou publié —
     * c'est-à-dire précisément quand il compte. Il est retiré à la publication par le processeur, une
     * fois que l'exploitant a confirmé la relecture.
     */
    private function preambule(): string
    {
        return "> ⚠ **Brouillon généré automatiquement — à faire relire avant publication.**\n"
            . "> Ce texte est un modèle composé à partir des informations saisies et des activités\n"
            . "> déclarées. Il ne constitue pas un avis juridique, et l'exploitant reste responsable\n"
            . "> de ce qu'il publie. Retirez ce bloc une fois le texte relu.\n\n";
    }

    /** @param callable(?string, string): string $champ */
    private function legalNotice(LegalIdentity $i, string $site, callable $champ): string
    {
        $capital = $i->getShareCapital();
        $ligneCapital = $capital !== null && trim($capital) !== ''
            ? sprintf("- **Capital social** : %s\n", trim($capital))
            // Une régie ou une commune n'a pas de capital social : l'exiger produirait un champ
            // manquant permanent que l'exploitant ne pourrait jamais satisfaire.
            : '';

        return sprintf(
            "# Mentions légales\n\n"
            . "## Éditeur du site\n\n"
            . "- **Dénomination** : %s\n"
            . "- **Forme juridique** : %s\n"
            . "%s"
            . "- **Siège social** : %s\n"
            . "- **SIRET** : %s\n"
            . "- **RCS / immatriculation** : %s\n"
            . "- **TVA intracommunautaire** : %s\n"
            . "- **Courriel** : %s\n"
            . "- **Téléphone** : %s\n"
            . "- **Directeur de la publication** : %s\n\n"
            . "## Hébergeur\n\n"
            . "Conformément à l'article 6-III de la loi pour la confiance dans l'économie numérique, "
            . "le site %s est hébergé par :\n\n"
            . "- **Hébergeur** : %s\n"
            . "- **Adresse** : %s\n"
            . "- **Téléphone** : %s\n\n"
            . "## Propriété intellectuelle\n\n"
            . "L'ensemble des contenus de ce site est protégé. Toute reproduction, même partielle, est "
            . "soumise à l'autorisation préalable de l'éditeur.\n\n"
            . "## Médiation de la consommation\n\n"
            . "Conformément à l'article L612-1 du code de la consommation, l'éditeur adhère à un "
            . "dispositif de médiation :\n\n"
            . "- **Médiateur** : %s\n"
            . "- **Adresse** : %s\n"
            . "- **Site** : %s\n",
            $champ($i->getLegalName(), 'dénomination sociale'),
            $champ($i->getLegalForm(), 'forme juridique'),
            $ligneCapital,
            $champ($i->getRegisteredAddress(), 'adresse du siège'),
            $champ($i->getSiret(), 'SIRET'),
            $champ($i->getTradeRegister(), 'RCS ou numéro d’immatriculation'),
            $champ($i->getVatNumber(), 'TVA intracommunautaire'),
            $champ($i->getContactEmail(), 'courriel de contact'),
            $champ($i->getContactPhone(), 'téléphone'),
            $champ($i->getPublicationDirector(), 'directeur de la publication'),
            $site,
            $champ($i->getHostName(), 'nom de l’hébergeur'),
            $champ($i->getHostAddress(), 'adresse de l’hébergeur'),
            $champ($i->getHostPhone(), 'téléphone de l’hébergeur'),
            $champ($i->getMediatorName(), 'nom du médiateur de la consommation'),
            $champ($i->getMediatorAddress(), 'adresse du médiateur'),
            $champ($i->getMediatorUrl(), 'site du médiateur'),
        );
    }

    /** @param callable(?string, string): string $champ */
    private function termsOfSale(LegalIdentity $i, string $site, callable $champ): string
    {
        $activites = $i->activityCases();

        if ($activites === []) {
            // ON N'ÉCRIT PAS DE CGV SANS SAVOIR CE QUI EST VENDU.
            //
            // Produire un texte générique serait pire que rien : il énoncerait un régime de
            // rétractation au hasard, et l'exploitant le publierait en croyant la question réglée.
            $champ(null, 'activités vendues (aucune n’est déclarée)');

            return "# Conditions générales de vente\n\n"
                . "**Aucune activité n'est déclarée sur la fiche d'identité légale.** Les clauses de "
                . "rétractation dépendent entièrement de ce qui est vendu — un billet daté, une "
                . "marchandise et un abonnement ne relèvent pas du même régime. Renseignez les "
                . "activités, puis régénérez ce document.\n";
        }

        $exemptees = array_values(array_filter($activites, static fn (SalesActivity $a): bool => $a->withdrawalExempted()));
        $soumises = array_values(array_filter($activites, static fn (SalesActivity $a): bool => !$a->withdrawalExempted()));

        $texte = sprintf(
            "# Conditions générales de vente\n\n"
            . "## 1. Objet et vendeur\n\n"
            . "Les présentes conditions régissent les ventes conclues sur %s par %s, "
            . "dont le siège est situé %s.\n\n"
            . "Activités concernées : %s.\n\n"
            . "## 2. Prix\n\n"
            . "Les prix sont indiqués en euros toutes taxes comprises. Le prix applicable est celui "
            . "affiché au moment de la validation de la commande.\n\n"
            . "## 3. Commande et paiement\n\n"
            . "La commande est ferme à réception du paiement. Un courriel de confirmation récapitule "
            . "la commande et donne accès aux titres achetés.\n\n"
            . "## 4. Mise à disposition\n\n"
            . "Les titres dématérialisés sont mis à disposition dans l'espace client dès le paiement "
            . "confirmé.\n\n",
            $site,
            $champ($i->getLegalName(), 'dénomination sociale'),
            $champ($i->getRegisteredAddress(), 'adresse du siège'),
            implode(', ', array_map(static fn (SalesActivity $a): string => mb_strtolower($a->label()), $activites)),
        );

        $texte .= "## 5. Droit de rétractation\n\n";

        if ($exemptees !== []) {
            $texte .= sprintf(
                "**Absence de droit de rétractation** pour : %s.\n\n"
                . "Conformément à l'article L221-28 12° du code de la consommation, le droit de "
                . "rétractation ne s'applique pas aux prestations de services de loisirs, de "
                . "restauration ou d'hébergement fournies à une date ou selon une périodicité "
                . "déterminée. **L'achat est donc définitif** : il n'est ni remboursable ni échangeable, "
                . "sauf annulation du fait de l'organisateur.\n\n",
                implode(', ', array_map(static fn (SalesActivity $a): string => mb_strtolower($a->label()), $exemptees)),
            );
        }

        if ($soumises !== []) {
            $texte .= sprintf(
                "**Droit de rétractation de quatorze jours** pour : %s.\n\n"
                . "Vous disposez de quatorze jours à compter de la réception du bien, ou de la "
                . "conclusion du contrat pour un service, pour exercer votre droit de rétractation "
                . "sans avoir à motiver votre décision. Pour l'exercer, informez-nous de votre "
                . "décision par une déclaration dénuée d'ambiguïté adressée à %s.\n\n"
                . "Le remboursement intervient au plus tard quatorze jours après récupération du bien "
                . "ou preuve de son expédition.\n\n"
                . "### Formulaire type de rétractation\n\n"
                . "> À l'attention de %s, %s\n"
                . ">\n"
                . "> Je vous notifie par la présente ma rétractation du contrat portant sur la vente "
                . "du bien / la prestation de service ci-dessous :\n"
                . "> - Commandé le / reçu le : \n"
                . "> - Nom du consommateur : \n"
                . "> - Adresse du consommateur : \n"
                . "> - Date : \n\n",
                implode(', ', array_map(static fn (SalesActivity $a): string => mb_strtolower($a->label()), $soumises)),
                $champ($i->getContactEmail(), 'courriel de contact'),
                $champ($i->getLegalName(), 'dénomination sociale'),
                $champ($i->getRegisteredAddress(), 'adresse du siège'),
            );

            if (\in_array(SalesActivity::Subscription, $soumises, true)) {
                $texte .= "Pour un abonnement dont l'exécution commence avant la fin du délai à votre "
                    . "demande expresse, vous restez redevable du montant correspondant au service "
                    . "déjà fourni.\n\n";
            }
        }

        // LES DEUX RÉGIMES CÔTE À CÔTE DEMANDENT UNE PHRASE, SINON ILS SE CONTREDISENT À LA LECTURE.
        if ($exemptees !== [] && $soumises !== []) {
            $texte .= "En cas de commande portant à la fois sur des prestations à date déterminée et "
                . "sur des biens ou services soumis au droit de rétractation, **chaque ligne suit son "
                . "propre régime** : la rétractation exercée sur un bien n'emporte pas remboursement "
                . "des titres datés de la même commande.\n\n";
        }

        $texte .= sprintf(
            "## 6. Réclamations et médiation\n\n"
            . "Toute réclamation peut être adressée à %s. À défaut de solution amiable, le "
            . "consommateur peut recourir gratuitement au médiateur de la consommation :\n\n"
            . "- **Médiateur** : %s\n"
            . "- **Site** : %s\n\n"
            . "## 7. Données personnelles\n\n"
            . "Le traitement des données est décrit dans la politique de confidentialité.\n\n"
            . "## 8. Droit applicable\n\n"
            . "Les présentes conditions sont soumises au droit français.\n",
            $champ($i->getContactEmail(), 'courriel de contact'),
            $champ($i->getMediatorName(), 'nom du médiateur de la consommation'),
            $champ($i->getMediatorUrl(), 'site du médiateur'),
        );

        return $texte;
    }

    /** @param callable(?string, string): string $champ */
    private function termsOfUse(LegalIdentity $i, string $site, callable $champ): string
    {
        return sprintf(
            "# Conditions générales d'utilisation\n\n"
            . "## 1. Objet\n\n"
            . "Les présentes conditions définissent les modalités d'accès et d'utilisation du site %s, "
            . "édité par %s.\n\n"
            . "## 2. Compte client\n\n"
            . "La création d'un compte requiert une adresse électronique valide. Vous êtes responsable "
            . "de la confidentialité de vos identifiants et des opérations effectuées depuis votre "
            . "compte.\n\n"
            . "Vous pouvez demander la suppression de votre compte à tout moment. Les documents "
            . "comptables liés à vos achats sont conservés selon les durées légales, indépendamment de "
            . "cette suppression.\n\n"
            . "## 3. Disponibilité\n\n"
            . "Le site est accessible en continu, sous réserve des interruptions nécessaires à la "
            . "maintenance et des cas de force majeure.\n\n"
            . "## 4. Comportement\n\n"
            . "Toute tentative d'accès non autorisé, d'extraction massive de données ou d'entrave au "
            . "fonctionnement du service peut entraîner la suspension du compte.\n\n"
            . "## 5. Contact\n\n"
            . "Pour toute question : %s.\n",
            $site,
            $champ($i->getLegalName(), 'dénomination sociale'),
            $champ($i->getContactEmail(), 'courriel de contact'),
        );
    }

    /** @param callable(?string, string): string $champ */
    private function privacyPolicy(LegalIdentity $i, string $site, callable $champ): string
    {
        return sprintf(
            "# Politique de confidentialité\n\n"
            . "## Responsable du traitement\n\n"
            . "%s, %s.\n\n"
            . "## Délégué à la protection des données\n\n"
            . "%s\n\n"
            . "## Données traitées et finalités\n\n"
            . "| Finalité | Données | Base légale | Conservation |\n"
            . "|---|---|---|---|\n"
            . "| Gestion des commandes et des titres | identité, coordonnées, historique d'achat | exécution du contrat | 3 ans après le dernier achat |\n"
            . "| Facturation et comptabilité | données de facturation | obligation légale | 10 ans |\n"
            . "| Contrôle d'accès | titre présenté, horodatage de passage | exécution du contrat | 1 an |\n"
            . "| Assistance | échanges avec le support | intérêt légitime | 3 ans |\n"
            . "| Lettre d'information | adresse électronique | consentement | jusqu'au retrait |\n\n"
            . "## Destinataires\n\n"
            . "Les données sont destinées aux services habilités de l'établissement et à ses "
            . "prestataires techniques (hébergement, paiement), liés par contrat et tenus à la "
            . "confidentialité. Aucune donnée n'est cédée à des tiers à des fins commerciales.\n\n"
            . "## Vos droits\n\n"
            . "Vous disposez d'un droit d'accès, de rectification, d'effacement, de limitation, "
            . "d'opposition et de portabilité. Pour les exercer : %s.\n\n"
            . "Si vous estimez, après nous avoir contactés, que vos droits ne sont pas respectés, vous "
            . "pouvez adresser une réclamation à la CNIL (3 place de Fontenoy, 75007 Paris — "
            . "www.cnil.fr).\n\n"
            . "## Sécurité\n\n"
            . "Les accès aux données sont cloisonnés par établissement et journalisés. Le site %s "
            . "est servi en HTTPS.\n",
            $champ($i->getLegalName(), 'dénomination sociale'),
            $champ($i->getRegisteredAddress(), 'adresse du siège'),
            $champ($i->getDataProtectionOfficer(), 'contact du délégué à la protection des données'),
            $champ($i->getContactEmail(), 'courriel de contact'),
            $site,
        );
    }

    private function cookiePolicy(string $site): string
    {
        return sprintf(
            "# Gestion des cookies\n\n"
            . "## Ce que nous déposons\n\n"
            . "Le site %s dépose uniquement les traceurs **strictement nécessaires** à son "
            . "fonctionnement :\n\n"
            . "| Traceur | Rôle | Durée |\n"
            . "|---|---|---|\n"
            . "| Jeton de session | vous garder connecté | durée de la session |\n"
            . "| Jeton de panier | conserver votre panier entre deux pages | durée du panier |\n\n"
            . "Ces traceurs sont exemptés de consentement au titre de l'article 82 de la loi "
            . "Informatique et Libertés : sans eux, le service ne peut pas fonctionner.\n\n"
            . "## Ce que nous ne déposons pas\n\n"
            . "Aucun traceur publicitaire, aucune mesure d'audience tierce, aucun bouton de réseau "
            . "social. **Aucune bannière de consentement n'est donc affichée** — non par oubli, mais "
            . "parce qu'il n'y a rien à consentir.\n\n"
            . "Si une mesure d'audience ou un traceur tiers venait à être ajouté, un recueil de "
            . "consentement préalable serait mis en place et ce document mis à jour.\n",
            $site,
        );
    }

    /** @param callable(?string, string): string $champ */
    private function accessibilityStatement(LegalIdentity $i, string $site, callable $champ): string
    {
        return sprintf(
            "# Déclaration d'accessibilité\n\n"
            . "%s s'engage à rendre le site %s accessible, conformément à l'article 47 de la loi "
            . "n° 2005-102 du 11 février 2005.\n\n"
            . "## État de conformité\n\n"
            . "**Ce site n'a pas encore fait l'objet d'un audit de conformité au RGAA.** Le taux de "
            . "conformité ne peut donc pas être déclaré, et cette déclaration doit être complétée à "
            . "l'issue de l'audit.\n\n"
            . "> ⚠ Une déclaration d'accessibilité annonçant une conformité non auditée est une "
            . "déclaration fausse. Tant que l'audit n'a pas eu lieu, dire qu'il n'a pas eu lieu est la "
            . "seule mention exacte.\n\n"
            . "## Améliorations déjà en place\n\n"
            . "- navigation au clavier et lien d'évitement vers le contenu principal ;\n"
            . "- structure sémantique (titres hiérarchisés, régions nommées) ;\n"
            . "- respect de la préférence système « animations réduites » ;\n"
            . "- thèmes clair et sombre suivant le réglage du visiteur.\n\n"
            . "## Retour d'information\n\n"
            . "Si vous ne parvenez pas à accéder à un contenu, contactez %s afin qu'une alternative "
            . "vous soit proposée.\n\n"
            . "## Voies de recours\n\n"
            . "En cas d'absence de réponse satisfaisante, vous pouvez signaler la difficulté au "
            . "Défenseur des droits (www.defenseurdesdroits.fr).\n",
            $champ($i->getLegalName(), 'dénomination sociale'),
            $site,
            $champ($i->getContactEmail(), 'courriel de contact'),
        );
    }

    /** Remplit un document existant avec le texte régénéré. */
    public function fill(LegalDocument $document, LegalIdentity $identity, string $siteName): LegalDocument
    {
        [$titre, $contenu, $manquants] = $this->generate($document->getType(), $identity, $siteName);

        return $document
            ->setTitle($titre)
            ->setContent($contenu)
            ->setMissingFields($manquants);
    }
}
