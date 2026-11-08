# Registre des traitements

> **Statut : version 1.0 complète au 05/11/2026 — à valider par Maxime (#344).** Constitué au titre de la décision **D114** (audit du 14/09) et de l'**article 30 RGPD**. Couvre la fiche client, les prélèvements SEPA, la carte de la ville (en préparation), le contrôle d'accès, les ventes et la facturation, les messages aux clients et le journal d'audit. Les durées de conservation sont celles proposées à chaque collectivité ; la décision finale lui revient, comme responsable de traitement.
>
> **Complété le 05/11/2026** (#344) : T4 à T7 ajoutés ; la purge à échéance, l'épuration sélective et l'export de portabilité sont désormais implémentés et décrits (section « Droits des personnes et fin de conservation ») ; les durées du fichier client sont rattachées au référentiel de la CNIL.
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
| **Durée de conservation** | Durée de la relation commerciale, puis **3 ans à compter de sa fin** — dernier achat ou dernier contact émanant du client (référentiel CNIL « gestion des activités commerciales », délibération n° 2021-131 du 23/09/2021). **Paramétrable par collectivité** (écran Données personnelles, 36 mois proposés) ; à échéance, la fiche est **anonymisée** automatiquement (`crm:rgpd:appliquer-conservation`), sauf abonnement vivant ou solde de porte-monnaie positif. Les pièces comptables liées suivent T5 |
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

## T4 — Contrôle d'accès et fréquentation

| Rubrique | Contenu |
|---|---|
| **Finalité** | Ouvrir ou refuser l'accès aux équipements (tourniquets, bornes, contrôle manuel), décompter les entrées, tenir la jauge de sécurité |
| **Base légale** (art. 6) | Exécution du contrat (6.1.b) ; pour une commune, mission de service public (6.1.e) ; obligation de sécurité pour la jauge |
| **Personnes concernées** | Porteurs d'un titre d'accès (billet, carte, badge, abonnement) |
| **Catégories de données** | Support d'accès (`identifiant`, type, statut), droit d'accès rattaché, **passages** (horodatage, espace, équipement, sens, résultat, motif de refus, agent). Aucune donnée biométrique. Entités : `App\Acces\Entity\Support`, `DroitAcces`, `Passage` |
| **Destinataires** | Personnel habilité de l'établissement (cloisonnement par établissement) ; sous-traitant Fluvia |
| **Durée de conservation** | Droits et supports : durée du titre. Passages : **12 mois** proposés (statistiques de fréquentation au-delà : données agrégées, sans identifiant de support). *(proposé, à confirmer par la collectivité)* |
| **Mesures** | Cloisonnement par établissement ; journal d'audit des actions sur la topologie et les supports ; pas de photo ni de biométrie |

## T5 — Ventes, caisse et facturation

| Rubrique | Contenu |
|---|---|
| **Finalité** | Encaisser, émettre tickets et factures, tenir la régie et la comptabilité |
| **Base légale** (art. 6) | Exécution du contrat (6.1.b) ; **obligation légale** comptable et fiscale (6.1.c) |
| **Personnes concernées** | Acheteurs ; destinataires de facture |
| **Catégories de données** | Vente (lignes, montants, moyens de paiement, rattachement au client par identifiant), facture (destinataire : nom, adresse), opérations scellées NF525. Entités : `App\Vente\Entity\Vente`, `App\Facturation\Entity\Facture`, `DestinataireFacturation` |
| **Destinataires** | Personnel habilité ; comptable public ou cabinet comptable ; administration fiscale sur contrôle ; sous-traitant Fluvia |
| **Durée de conservation** | **10 ans** (art. L123-22 C. com.) pour les pièces comptables. L'anonymisation d'une fiche client n'efface pas la vente : celle-ci ne porte qu'un identifiant, qui ne désigne plus personne |
| **Mesures** | Scellement NF525 (inaltérabilité) ; cloisonnement par établissement ; aucune clé étrangère dure de la vente vers la fiche client, ce qui permet l'anonymisation sans rompre la chaîne |

## T6 — Messages aux clients (courriels, SMS)

| Rubrique | Contenu |
|---|---|
| **Finalité** | Messages de service (confirmation, rappel, pré-notification de prélèvement) et, avec consentement, messages commerciaux |
| **Base légale** (art. 6) | Exécution du contrat (6.1.b) pour les messages de service ; **consentement** (6.1.a) pour la prospection |
| **Personnes concernées** | Clients disposant d'un courriel ou d'un téléphone |
| **Catégories de données** | Courriel, téléphone ; **consentements** par canal (état, date de recueil, source, version du texte). Entité : `App\Crm\Entity\Consentement` (append-only) |
| **Destinataires** | Prestataire d'envoi ; sous-traitant Fluvia |
| **Durée de conservation** | Consentements : durée de la relation, puis **3 ans** pour la preuve d'une opposition (référentiel CNIL précité) |
| **Mesures** | Envoi commercial filtré par le consentement exploitable (`ConsentGatedNotifier`) ; historique des consentements non modifiable |

## T7 — Journal d'audit

| Rubrique | Contenu |
|---|---|
| **Finalité** | Tracer les actions sensibles (droits, caisse, fiche client, RGPD) pour la sécurité et la preuve |
| **Base légale** (art. 6) | Intérêt légitime / obligation de sécurité (art. 32) |
| **Personnes concernées** | Utilisateurs de l'application (auteur) ; clients dont la fiche est modifiée |
| **Catégories de données** | Auteur (courriel de l'utilisateur), date, action, cible, valeurs avant et après pour les champs scalaires. Mots de passe, secrets et IBAN exclus. **Depuis le 05/11/2026, l'anonymisation d'une fiche n'y recopie plus l'identité effacée.** Entité : `App\Audit\Entity\EntreeAudit` |
| **Destinataires** | Administrateurs habilités ; éditeur sur accès de support journalisé |
| **Durée de conservation** | **À arbitrer** (voir « À valider ») : les entrées antérieures à une anonymisation peuvent contenir l'identité de la personne |
| **Mesures** | Append-only ; cloisonnement par établissement |

---

## Droits des personnes et fin de conservation

| Droit ou obligation | Mise en œuvre (écran **Données personnelles**) |
|---|---|
| Effacement (art. 17) | Demande enregistrée, délai d'un mois suivi (art. 12.3), traitement par **anonymisation** de la fiche, tracé et verrouillé |
| Accès (art. 15) et portabilité (art. 20) | **Export JSON** de toutes les données d'une personne — format « structuré, couramment utilisé et lisible par machine » (art. 20.1) ; chaque export est journalisé |
| Limitation de la conservation (art. 5.1.e) | **Durée paramétrable par collectivité** et **purge à échéance** nocturne (anonymisation, 500 fiches au plus par nuit, premier passage sous surveillance de l'éditeur — D109) |
| Épuration ponctuelle | **Épuration sélective** par critères (inactivité, date de création), **aperçu** avant tout effacement, sélection fiche par fiche, confirmation explicite ; chaque fiche anonymisée laisse une trace d'origine « épuration » signée |
| Protections | Jamais anonymisée automatiquement ni par épuration : fiche avec abonnement actif, en pause ou impayé ; fiche avec solde de porte-monnaie positif |
| Cloisonnement | Chaque épuration, purge ou export ne porte que sur la collectivité de l'établissement actif (`CustomerGroupGuard`) ; testé avec deux collectivités et une fiche témoin |

Sources vérifiées le 05/11/2026 : [référentiel CNIL « gestion des activités commerciales »](https://www.cnil.fr/sites/cnil/files/atoms/files/referentiel_traitements-donnees-caractere-personnel_gestion-activites-commerciales.pdf) (délibération n° 2021-131 du 23/09/2021) ; [RGPD, chapitre III, art. 12 à 23](https://www.cnil.fr/fr/reglement-europeen-protection-donnees/chapitre3).

---

## Durées de conservation — récapitulatif

| Donnée | Durée active | Archivage / base | Référence |
|---|---|---|---|
| Fiche client / abonné | Durée de la relation | 3 ans après la fin de la relation (paramétrable), puis anonymisation automatique | Référentiel CNIL, délibération n° 2021-131 |
| Passages au contrôle d'accès | 12 mois | Statistiques agrégées seulement | *(proposé, à confirmer)* |
| Ventes, factures, opérations scellées | Vie du contrat | 10 ans | Art. L123-22 C. com. |
| Consentements et oppositions | Durée de la relation | 3 ans après l'opposition | Référentiel CNIL, délibération n° 2021-131 |
| Journal d'audit | À arbitrer | — | Voir « À valider » |
| Mandat SEPA | Jusqu'à révocation / fin d'abonnement | 13 mois après dernier prélèvement (R-transactions) + preuve du consentement | SEPA Core Rulebook *(recommandé, à confirmer)* |
| Historique de paiement (échéances, remises) | Vie du contrat | 10 ans (pièces comptables) | Art. L123-22 C. com. *(recommandé, à confirmer)* |
| Carte de la ville / carte réseau *(en préparation)* | Durée de l'adhésion à la carte | Jusqu'à prescription (5 ans) ; paiements de la carte : 10 ans | Comme la fiche client et l'historique de paiement *(recommandé, à confirmer)* |

## À valider par Maxime

- **Qualification sous-traitant / responsable** selon le contrat client réel (DSP, régie, groupe privé) : le responsable est l'entité titulaire du groupe (08/10) ; reste à vérifier, contrat par contrat, quelle entité c'est (en DSP notamment), et si Fluvia est co-responsable sur certains traitements.
- **Base légale de la carte de la ville / carte réseau (T3)** : à arbitrer entre l'exécution du contrat d'adhésion (6.1.b) et la mission de service public pour une commune (6.1.e).
- **Durées de conservation** : les valeurs ci-dessus sont désormais **proposées** (références usuelles + reco CNIL + bases légales) — à **confirmer** (ce sont des recommandations, pas des minima imposés par un texte unique).
- **Journal d'audit (T7)** : durée de conservation, et sort des entrées antérieures à une anonymisation (elles peuvent contenir l'identité effacée). Options : les caviarder à l'anonymisation, ou les purger au-delà d'une durée.
- **Passages (T4)** : la durée de 12 mois est proposée, pas encore appliquée par une tâche.
- **Données liées non effacées par l'anonymisation** : libellé de famille (souvent un nom de famille), interlocuteurs d'une société cliente (`CustomerContact`). À traiter dans un lot suivant.

---

*Registre des traitements Fluvia — version 1.0 (D114, #344, art. 30 RGPD). Le coherence-reviewer compare ce document aux entités porteuses de données personnelles ; tenir la liste des entités à jour quand une donnée nouvelle est collectée.*
