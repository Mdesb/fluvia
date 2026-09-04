<?php

declare(strict_types=1);

namespace App\Website\Service;

/**
 * Les questions qu'on nous pose, et leurs réponses (ED-11).
 *
 * **Pourquoi ça n'est pas de la décoration.** Un assistant génératif ne cite pas une page : il cite
 * une **réponse**. Une phrase qui répond seule, sans exiger le reste de la page, est reprise ; un
 * argumentaire de vente ne l'est pas. C'est la même exigence qu'un extrait enrichi dans un moteur,
 * poussée un cran plus loin.
 *
 * ⚠ **CHAQUE RÉPONSE EST VISIBLE SUR LA PAGE QUI LA DÉCLARE.** Un balisage `FAQPage` dont les
 * réponses ne s'affichent pas est du contenu caché — c'est la faute la plus courante de ce format,
 * et elle est sanctionnée. Le gabarit et le balisage lisent donc la même liste.
 *
 * ⚠ **ET AUCUNE RÉPONSE NE PORTE DE PRIX.** Les tarifs vivent dans le catalogue et changent ; une
 * réponse figée ici les contredirait le jour d'un changement, et c'est précisément ce qu'un
 * assistant citerait six mois plus tard. On dit *comment* on facture, jamais *combien*.
 */
final class SiteFaq
{
    /**
     * @return list<array{question: string, reponse: string}>
     */
    public static function generales(): array
    {
        return [
            [
                'question' => "Qu'est-ce que Fluvia ?",
                'reponse' => "Fluvia est une plateforme de gestion pour les lieux qui accueillent du public : "
                    ."piscines, patinoires, salles de sport, clubs de padel, musées, campings. Elle réunit la "
                    ."billetterie, la réservation de créneaux, le contrôle d'accès, la caisse, la boutique en "
                    ."ligne, le suivi des clients et la facturation dans un seul outil, et chaque partie "
                    ."s'active séparément.",
            ],
            [
                'question' => "À qui s'adresse Fluvia ?",
                'reponse' => "Aux exploitants d'équipements recevant du public, publics comme privés, d'un site "
                    ."unique à un réseau de plusieurs établissements. Le vocabulaire s'adapte à l'activité : un "
                    ."créneau est une réservation de terrain au padel, une séance à la piscine, une visite au musée.",
            ],
            [
                'question' => 'Comment Fluvia est-il facturé ?',
                'reponse' => "Par abonnement mensuel : une formule, plus les modules ajoutés à la carte. La "
                    ."facturation porte sur les modules activés, jamais sur le nombre de billets vendus ni de "
                    ."visiteurs accueillis. Il n'y a pas d'engagement de durée, et les tarifs à jour sont affichés "
                    ."sur la page d'accueil.",
            ],
            [
                'question' => "Peut-on essayer Fluvia avant de s'engager ?",
                'reponse' => "Oui : quatorze jours d'essai gratuit, sans carte bancaire. On compose son offre, on "
                    ."confirme son adresse e-mail, et la plateforme s'ouvre avec les modules choisis. À l'échéance, "
                    ."l'accès se ferme si aucun mandat de prélèvement n'a été signé — aucune donnée n'est supprimée.",
            ],
            [
                'question' => "Que se passe-t-il si on désactive un module ?",
                'reponse' => "L'accès au module se ferme, et rien n'est effacé. Les données saisies restent en "
                    ."place et redeviennent accessibles le jour où le module est réactivé. C'est aussi ce qui se "
                    ."passe en cas d'impayé : l'exposition est coupée, la base est intacte.",
            ],
            [
                'question' => "Qui voit les données d'un établissement ?",
                'reponse' => "Lui seul. Le périmètre de lecture est dérivé de la session, côté serveur, et ne "
                    ."dépend d'aucun paramètre envoyé par le navigateur : un établissement d'un même réseau ne "
                    ."voit pas les ventes ni les clients d'un autre.",
            ],
            // ⚠ « OÙ SONT HÉBERGÉES LES DONNÉES ? » MANQUE, ET C'EST DÉLIBÉRÉ. C'est la question que
            //   pose tout acheteur public, et je ne peux pas y répondre par une mesure : je sais sur
            //   quelle machine tourne la préproduction, pas ce que Maxime veut ENGAGER sur une page
            //   publique. Une réponse écrite ici sans lui serait une promesse contractuelle inventée —
            //   et c'est exactement le genre de phrase qu'un assistant cite ensuite pendant des mois.
            //   À ajouter dès qu'il l'aura donnée.
        ];
    }
}
