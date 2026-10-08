# Registre des traitements — données d'abonnement

> **Statut : brouillon à valider (Maxime).** Constitué au titre de la décision **D114** (audit du 14/09) et de l'**article 30 RGPD**. Première tranche : les traitements liés à l'abonnement (fiche client, mandats et prélèvements SEPA, historique de paiement), et la carte de la ville / carte réseau, en préparation. À étendre ensuite aux autres traitements (contrôle d'accès, facturation, notifications).
>
> **Corrigé le 08/10/2026** (décision de Maxime) : le fichier client est **commun au groupe**, pas cloisonné par établissement comme l'écrivait la version précédente. Le responsable de traitement, les destinataires et les mesures de T1 sont réécrits en conséquence ; T3 est ajouté.

## Cadre

- **Fluvia (Onefitness Services)** agit comme **sous-traitant** (art. 28 RGPD) : la plateforme héberge et traite les données **pour le compte** de son client. Le **responsable de traitement** est l'**entité titulaire du groupe** d'établissements : la commune pour une mairie, la société pour un groupe privé. Ce registre est celui du sous-traitant (art. 30.2), qui recense les traitements effectués pour le compte des responsables.
- **Cloisonnement à deux niveaux.** Le **fichier client** (fiche `Client`, porte-monnaie virtuel, historique d'achats) est **commun au groupe** : tous les sites d'un groupe partagent la même fiche, et aucun groupe ne voit les clients d'un autre (`CustomerScope`, `PerimetreCrmExtension`). Les autres données restent **cloisonnées par établissement** : chaque établissement ne voit que les siennes (extensions Doctrine `PerimetreSepaExtension`, `UserScopeExtension`…). Dans les deux cas, c'est un invariant serveur, pas un filtre d'écran.
- **Hébergement** : en France (VPS OVH).

## Mesures techniques et organisationnelles transverses

| Mesure | Mise en œuvre |
|---|---|
| Chiffrement de l'IBAN au repos | `ChiffreurIban` — libsodium `crypto_secretbox` (réversible, clé au déploiement) |
| Tokenisation de l'IBAN | `TokenisationIbanHmacAdapter` — jeton HMAC non réversible (recherche/rapprochement sans exposer l'IBAN) |
| Exposition minimale | Seuls les **4 derniers** chiffres de l'IBAN sont lisibles en API ; l'IBAN en clair et le token chiffré ne portent **aucun** groupe de sérialisation |
| Cloisonnement | Périmètre appliqué au niveau Doctrine (invariant serveur) : **par groupe** pour le fichier client (`CustomerScope`), **par établissement** pour les autres données (SEPA…) |
| Contrôle d'accès applicatif | Permissions (`sepa.lire`, `sepa.gerer`, `compta.*`…) ; accès de support ouvert/refermé et journalisé |
| Traçabilité | Journal d'audit ; opérations de caisse scellées (NF525) |

> **Point de droit soumis à validation.** D114 demande que « l'IBAN soit traité comme donnée sensible ». Au sens strict de l'**art. 9 RGPD**, l'IBAN n'est **pas** une donnée sensible (santé, opinions, biométrie…). C'est une **donnée bancaire**, dont la CNIL recommande le chiffrement. Le registre applique donc à l'IBAN le **niveau de protection** d'une donnée sensible (chiffrement au repos + tokenisation), ce qui satisfait l'intention de D114 sans erreur de qualification.

---

## T1 — Gestion des abonnés (fiche client)

| Rubrique | Contenu |
|---|---|
| **Finalité** | Gérer les clients et abonnés : souscription, suivi de la relation, contact, contrôle d'accès rattaché. Une seule fiche par client pour tout le groupe, sans doublon d'un site à l'autre |
| **Base légale** (art. 6) | Exécution du contrat d'abonnement (6.1.b) ; intérêt légitime pour la gestion de la relation client |
| **Personnes concernées** | Clients, abonnés, bénéficiaires rattachés (dont mineurs via la famille) |
| **Catégories de données** | Identité (civilité, `nom`, `prenom`, `raisonSociale`/`siret` pour les personnes morales), **date de naissance**, contact (`email`, `telephone`, `adresse`), rattachements (`groupe`, `famille`, `beneficiaire`), dernière visite. **Porte-monnaie virtuel** (un par client, commun au groupe) : solde, échéance, mouvements (montant, date, site, canal). **Historique d'achats** de tous les sites du groupe, affiché sur la fiche 360. Champs entités : `App\Crm\Entity\Client`, `App\Crm\Entity\Beneficiaire`, `App\Crm\Entity\PorteMonnaieVirtuel`, `App\Crm\Entity\MouvementPmv` |
| **Destinataires** | Personnel habilité (selon permissions) de **tous les sites du groupe** : la fiche est commune au groupe ; sous-traitant Fluvia (hébergement) |
| **Durée de conservation** | Durée de la relation ; **prospection : 3 ans** après le dernier contact (reco CNIL) ; archivage intermédiaire jusqu'à la **prescription** (5 ans, art. 2224 C. civ.), puis suppression ou anonymisation. *(recommandé, à confirmer)* |
| **Mesures** | Cloisonnement **par groupe** (fiche commune à tous les sites du groupe, invisible des autres groupes) ; accès par permissions ; pas de donnée sensible (art. 9) collectée dans la fiche. **Aujourd'hui**, l'historique d'achats et le solde du porte-monnaie sont visibles de tous les sites du groupe. **Mesure prévue** (évolution « B », sans date) : les réserver aux rôles de direction du groupe |

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

## T3 — Carte de la ville / carte réseau *(en préparation)*

> **Traitement en préparation** : aucun code ni aucune entité à ce jour. Cette fiche sera complétée quand la fonctionnalité sera spécifiée.

| Rubrique | Contenu |
|---|---|
| **Finalité** | Délivrer et gérer une carte nominative valable sur tous les sites du groupe — carte de la ville pour une commune, carte réseau pour un groupe privé — et la reconnaître en caisse et au contrôle d'accès. Avantages attachés à la carte : à préciser par la spec |
| **Base légale** (art. 6) | **À arbitrer** : soit l'**exécution du contrat d'adhésion à la carte** (6.1.b), soit, pour une commune, l'**exécution d'une mission de service public** (6.1.e) |
| **Personnes concernées** | Titulaires de la carte (clients du groupe) ; bénéficiaires rattachés (dont mineurs via la famille) si la carte leur est ouverte *(à préciser)* |
| **Catégories de données** | Celles de la fiche client (T1) à laquelle la carte se rattache : identité, date de naissance, contact. Propres à la carte : numéro de carte, dates de validité, statut. Éventuellement un justificatif de résidence (carte de la ville réservée aux habitants) ou une photo (carte qui en porte une) : à préciser par la spec, au minimum nécessaire |
| **Destinataires** | Personnel habilité (selon permissions) de **tous les sites du groupe** ; sous-traitant Fluvia (hébergement) |
| **Durée de conservation** | Alignée sur T1 et T2 : durée de l'adhésion à la carte, puis archivage intermédiaire jusqu'à la **prescription** (5 ans, art. 2224 C. civ.), puis suppression ou anonymisation ; si la carte est payante, pièces comptables **10 ans** (art. L123-22 C. com.), comme l'historique de paiement. *(recommandé, à confirmer)* |
| **Mesures** | Celles de T1 : cloisonnement par groupe, accès par permissions. À compléter avec la spec |

---

## Durées de conservation — récapitulatif

| Donnée | Durée active | Archivage / base | Référence |
|---|---|---|---|
| Fiche client / abonné | Durée de la relation | Jusqu'à prescription (5 ans) | Art. 2224 C. civ. *(recommandé, à confirmer)* |
| Mandat SEPA | Jusqu'à révocation / fin d'abonnement | 13 mois après dernier prélèvement (R-transactions) + preuve du consentement | SEPA Core Rulebook *(recommandé, à confirmer)* |
| Historique de paiement (échéances, remises) | Vie du contrat | 10 ans (pièces comptables) | Art. L123-22 C. com. *(recommandé, à confirmer)* |
| Carte de la ville / carte réseau *(en préparation)* | Durée de l'adhésion à la carte | Jusqu'à prescription (5 ans) ; paiements de la carte : 10 ans | Comme la fiche client et l'historique de paiement *(recommandé, à confirmer)* |

## À valider par Maxime

- **Qualification sous-traitant / responsable** selon le contrat client réel (DSP, régie, groupe privé) : le responsable est l'entité titulaire du groupe (08/10) ; reste à vérifier, contrat par contrat, quelle entité c'est (en DSP notamment), et si Fluvia est co-responsable sur certains traitements.
- **Base légale de la carte de la ville / carte réseau (T3)** : à arbitrer entre l'exécution du contrat d'adhésion (6.1.b) et la mission de service public pour une commune (6.1.e).
- **Durées de conservation** : les valeurs ci-dessus sont désormais **proposées** (références usuelles + reco CNIL + bases légales) — à **confirmer** (ce sont des recommandations, pas des minima imposés par un texte unique).
- **Traitements à ajouter** au registre : contrôle d'accès / fréquentation (badges, passages), facturation électronique, notifications e-mail / SMS, journal d'audit.
- La **purge / anonymisation automatique** en fin de durée (non implémentée — chantier à ouvrir une fois les durées arrêtées).

---

*Registre des traitements Fluvia — première tranche « données d'abonnement » (D114, art. 30 RGPD). Le coherence-reviewer compare ce document aux entités porteuses de données personnelles ; tenir la liste des entités à jour quand une donnée nouvelle est collectée.*
