# Registre des traitements — données d'abonnement

> **Statut : brouillon à valider (Maxime).** Constitué au titre de la décision **D114** (audit du 14/09) et de l'**article 30 RGPD**. Première tranche : les traitements liés à l'abonnement (fiche client, mandats et prélèvements SEPA, historique de paiement). À étendre ensuite aux autres traitements (contrôle d'accès, facturation, notifications).

## Cadre

- **Fluvia (Onefitness Services)** agit comme **sous-traitant** (art. 28 RGPD) : la plateforme héberge et traite les données **pour le compte** de l'établissement exploitant, qui est le **responsable de traitement**. Ce registre est celui du sous-traitant (art. 30.2), qui recense les traitements effectués pour le compte des responsables.
- **Multi-établissement** : chaque établissement ne voit que ses propres données. Le cloisonnement est un invariant serveur (extensions Doctrine `PerimetreSepaExtension`, `UserScopeExtension`…), pas un filtre d'écran.
- **Hébergement** : en France (VPS OVH).

## Mesures techniques et organisationnelles transverses

| Mesure | Mise en œuvre |
|---|---|
| Chiffrement de l'IBAN au repos | `ChiffreurIban` — libsodium `crypto_secretbox` (réversible, clé au déploiement) |
| Tokenisation de l'IBAN | `TokenisationIbanHmacAdapter` — jeton HMAC non réversible (recherche/rapprochement sans exposer l'IBAN) |
| Exposition minimale | Seuls les **4 derniers** chiffres de l'IBAN sont lisibles en API ; l'IBAN en clair et le token chiffré ne portent **aucun** groupe de sérialisation |
| Cloisonnement | Périmètre par établissement appliqué au niveau Doctrine (invariant serveur) |
| Contrôle d'accès applicatif | Permissions (`sepa.lire`, `sepa.gerer`, `compta.*`…) ; accès de support ouvert/refermé et journalisé |
| Traçabilité | Journal d'audit ; opérations de caisse scellées (NF525) |

> **Point de droit soumis à validation.** D114 demande que « l'IBAN soit traité comme donnée sensible ». Au sens strict de l'**art. 9 RGPD**, l'IBAN n'est **pas** une donnée sensible (santé, opinions, biométrie…). C'est une **donnée bancaire**, dont la CNIL recommande le chiffrement. Le registre applique donc à l'IBAN le **niveau de protection** d'une donnée sensible (chiffrement au repos + tokenisation), ce qui satisfait l'intention de D114 sans erreur de qualification.

---

## T1 — Gestion des abonnés (fiche client)

| Rubrique | Contenu |
|---|---|
| **Finalité** | Gérer les clients et abonnés : souscription, suivi de la relation, contact, contrôle d'accès rattaché |
| **Base légale** (art. 6) | Exécution du contrat d'abonnement (6.1.b) ; intérêt légitime pour la gestion de la relation client |
| **Personnes concernées** | Clients, abonnés, bénéficiaires rattachés (dont mineurs via la famille) |
| **Catégories de données** | Identité (civilité, `nom`, `prenom`, `raisonSociale`/`siret` pour les personnes morales), **date de naissance**, contact (`email`, `telephone`, `adresse`), rattachements (`groupe`, `famille`, `beneficiaire`), dernière visite. Champs entités : `App\Crm\Entity\Client`, `App\Crm\Entity\Beneficiaire` |
| **Destinataires** | Personnel habilité de l'établissement (selon permissions) ; sous-traitant Fluvia (hébergement) |
| **Durée de conservation** | Durée de la relation ; **prospection : 3 ans** après le dernier contact (reco CNIL) ; archivage intermédiaire jusqu'à la **prescription** (5 ans, art. 2224 C. civ.), puis suppression ou anonymisation. *(recommandé, à confirmer)* |
| **Mesures** | Cloisonnement par établissement ; accès par permissions ; pas de donnée sensible (art. 9) collectée dans la fiche |

## T2 — Mandats et prélèvements SEPA

| Rubrique | Contenu |
|---|---|
| **Finalité** | Encaisser les abonnements par prélèvement SEPA : mandat, échéancier, remises, rejets, pré-notifications, relances |
| **Base légale** (art. 6) | Exécution du contrat (6.1.b) ; **obligation légale** comptable pour la conservation des pièces (6.1.c) |
| **Personnes concernées** | Débiteurs (payeurs) des abonnements |
| **Catégories de données** | **Coordonnées bancaires** : IBAN (chiffré + tokenisé, 4 derniers en clair), `bicDebiteur`, `debiteurNom`, `dateSignature` du mandat, RUM, statut. **Historique de paiement** : échéances (`dateProgrammee`, `montantCentimes`, `statut`, `dateExecutionReelle`, motifs d'annulation/réduction), remises, rejets. Entités : `App\Sepa\Entity\MandatSepa`, `App\Membership\Entity\EcheanceSepa`, `RemiseSepa`, `RejetSepa`, `DebitPreNotification` |
| **Destinataires** | Personnel habilité (`sepa.gerer`) ; banque du créancier (remise pain.008) ; sous-traitant Fluvia ; le collecteur SEPA une fois branché *(aujourd'hui bouchon — voir #104)* |
| **Durée de conservation** | **Mandat** : 13 mois après le dernier prélèvement (gestion des R-transactions SEPA), puis comme preuve du consentement jusqu'à la prescription ; **pièces comptables** (échéances, remises) : **10 ans** (art. L123-22 Code de commerce). *(recommandé, à confirmer)* |
| **Mesures** | IBAN chiffré (libsodium) + tokenisé (HMAC) ; 4 derniers chiffres seuls exposés ; cloisonnement par établissement ; **préavis avant prélèvement ≥ 14 jours** sauf clause contractuelle de préavis réduit (voir #98) |

---

## Durées de conservation — récapitulatif

| Donnée | Durée active | Archivage / base | Référence |
|---|---|---|---|
| Fiche client / abonné | Durée de la relation | Jusqu'à prescription (5 ans) | Art. 2224 C. civ. *(recommandé, à confirmer)* |
| Mandat SEPA | Jusqu'à révocation / fin d'abonnement | 13 mois après dernier prélèvement (R-transactions) + preuve du consentement | SEPA Core Rulebook *(recommandé, à confirmer)* |
| Historique de paiement (échéances, remises) | Vie du contrat | 10 ans (pièces comptables) | Art. L123-22 C. com. *(recommandé, à confirmer)* |

## À valider par Maxime

- **Qualification sous-traitant / responsable** selon le contrat client réel (DSP, régie, groupe privé) : le responsable est-il toujours l'établissement, ou Fluvia co-responsable sur certains traitements ?
- **Durées de conservation** : les valeurs ci-dessus sont désormais **proposées** (références usuelles + reco CNIL + bases légales) — à **confirmer** (ce sont des recommandations, pas des minima imposés par un texte unique).
- **Traitements à ajouter** au registre : contrôle d'accès / fréquentation (badges, passages), facturation électronique, notifications e-mail / SMS, journal d'audit.
- La **purge / anonymisation automatique** en fin de durée (non implémentée — chantier à ouvrir une fois les durées arrêtées).

---

*Registre des traitements Fluvia — première tranche « données d'abonnement » (D114, art. 30 RGPD). Le coherence-reviewer compare ce document aux entités porteuses de données personnelles ; tenir la liste des entités à jour quand une donnée nouvelle est collectée.*
