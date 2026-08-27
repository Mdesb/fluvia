# TASKS — tableau de claim

Avant de démarrer un chantier, ajoute une ligne ici avec ton instance + statut. Statuts :
`CLAIM` (réservé) · `WIP` (en cours) · `REVIEW` (en revue de cohérence) · `DONE` · `BLOCKED`.
Ne touche pas un chemin déjà en `WIP` par une autre instance.

| # | Tâche | Chemins | Instance | Statut | Maj |
|---|---|---|---|---|---|
| C1 | Contrat de plateforme v0 (noyau, manifeste, événements) | `COORDINATION/CONTRACT/**` | **claude-A** | DONE | 20/08 |
| C2 | Échafaudage de coordination (ce dossier) | `COORDINATION/**` | claude (billetterie) | DONE | 19/08 |
| C3 | Spec SDD Suite Finance & Compta | `specs/finance/**` | claude (billetterie) | DONE | 19/08 |
| C6 | Suite Finance FIN-0 (OCR) + FIN-1 (Compta) | `app/src/{Ocr,Compta}/**` | **claude-B** | DONE | 21/08 |
| C4 | Garde-fous CI : cloisonnement, nommage, secrets — i18n et CSRF sans objet | `bin/` | **claude-C** | DONE | 22/08 |
| C5 | Bus d'événements + registre de modules (impl core) | `app/src/Platform/**` | **claude-A** | DONE | 20/08 |
| C7 | Harnais de test executable (stack isolee, DDL hors mapping) | `infra/test-stack.sh`, `app/tests/DdlHorsMapping.php` | **claude-A** | DONE | 19/08 |
| C8 | CA-11 Support : l agent ne voit pas les 2 messages du fil (TicketSupportApiTest:109) | `app/src/Support/State/MessageTicketProvider.php` | **claude-A** | DONE | 19/08 |
| C9 | Decouverte des ressources API : identifier le mecanisme reel, rendre la configuration explicite, PUIS exclure les entites des services | `app/config/{services,packages/api_platform}.yaml` | **claude-A** | BLOCKED | 20/08 |
| C10 | Suite Finance FIN-2, FIN-3, FIN-4 - bloc complet | `app/src/Finance/**` | **claude-B** | DONE | 21/08 |

| C11 | Tests de non-regression IDOR Caisse/SEPA (etendre CaisseClotureRoleFixtures : caisse.mouvement absente des fixtures) | `app/tests/Caisse`, `app/src/Caisse/DataFixtures` | **claude-B** | DONE | 24/08 |
| C12 | Porter letablissement sur AccesRedevableChangeEvent pour quil soit pontable (RG-PLAT-03) | `app/src/Recouvrement/Event` | **claude-B** | DONE | 24/08 |
| C13 | Retirer LegacyEventBridge quand chaque module publiera lui-meme son DomainEvent | `app/src/Platform/Event/Legacy` | *a assigner* | CLAIM | 20/08 |
| ED-0 | Spec administration editeur + tunnel de souscription (SDD) | `specs/editeur/**` | **claude-A** | REVIEW | 20/08 |
| ED-1 | Catalogue doffres : Plan, PlanOption adossees aux capabilities | `app/src/Editeur/**` | **claude-A** | DONE | 20/08 |
| ED-2 | Abonnement : cycle de vie, prorata, mecanisme de suspension | `app/src/Subscription/**` | **claude-A** | DONE | 20/08 |
| ED-3 | Tunnel de souscription SEPA + provisioning idempotent | `app/src/Editeur/**` | **claude-A** | WIP | 20/08 |
| ED-4 | Acces dassistance borne et audite (RG-ED-07) | `app/src/Editeur/**`, `app/src/Audit/**` | *a assigner* | CLAIM | 20/08 |
| C14 | Declarer au mapping ORM les index ecrits en SQL brut, pour que migrations:diff cesse de proposer leur suppression | `app/src/*/Entity`, `app/migrations` | *a assigner* | CLAIM | 20/08 |
| C15 | NF525 : cle de scellement en dur dans ScellementEcritureHandler (conformite legale) | `app/src/Compta/Nf525` | **claude-B** | DONE | 20/08 |
| C16 | Declencheur : hook pre-receive installe sur le bare, garde-fous actifs | `hooks/` | **claude-C** | DONE | 22/08 |
| D7-bis | Asynchrone : messenger + transport Doctrine, worker systemd, transport dechec | `app/config`, `infra/` | **claude-A** | DONE | 21/08 |
| SOC-0 | Spec SDD du module de publication sociale | `specs/social/**` | **claude-A** | CLAIM | 21/08 |
| SOC-1 | Modele post/publications + coffre a jetons chiffre et cloisonne | `app/src/Social/**` | **claude-H** | DONE | 21/08 |
| SOC-2 | Adaptateur reseau ouvert (Mastodon/Bluesky) + file, reprises, quotas | `app/src/Social/**` | **claude-H** | CLAIM | 21/08 |
| SOC-3 | Collecte planifiee des statistiques : instantanes + charge brute conservee | `app/src/Social/**` | **claude-H** | CLAIM | 21/08 |
| SOC-4 | Adaptateurs Meta (Page + Instagram), apres verification dentreprise et revue applicative | `app/src/Social/**` | *a assigner* | EXTERNE | 21/08 |
| DMS-0 | GED : spec SDD (cloisonnement, URL signees expirantes, retention legale, versionnement) | `specs/dms/**` | **claude-B** | DONE | 21/08 |
| DMS-1 | GED : implementation du stockage, des versions et des acces — **selon spec arbitree D18** | `app/src/Dms/**` | **claude-B** | DONE | 24/08 |
| C17 | NF525 : troisieme chaine (Facturation) — cle obligatoire depuis lenvironnement | `app/src/Facturation/Nf525` | **claude-A** | DONE | 22/08 |
| ACT-0 | Spec SDD : composition dactivites, format de paquet verticale, remplacement de Metier | `specs/activites/**` | **claude-A** | CLAIM | 22/08 |
| ACT-1 | Reservation : quantite consommee, reservation par type, quota de second niveau | `app/src/Reservation/**` | *a assigner* | CLAIM | 22/08 |
| ACT-2 | Module hebergement : nuitee, calendrier doccupation, arrivee et depart | `app/src/Lodging/**` | *a assigner* | CLAIM | 22/08 |
| ACT-3 | Concept de sejour : compte unique sur place, regle une fois au depart | `app/src/Stay/**` | *a assigner* | CLAIM | 22/08 |
| ACT-4 | Module restauration : service a table, addition, envoi cuisine | `app/src/Dining/**` | *a assigner* | CLAIM | 22/08 |
| ACC-0 | Declaration de capacites des pilotes dacces (decision, revocation, encodage, passages) | `app/src/Acces/Port/**` | **claude-A** | DONE | 22/08 |
| ACC-1 | Echec explicite sur operation non declaree + restitution a lexploitant | `app/src/Acces/**` | *libre — deblocage ACC-0* | CLAIM | 22/08 |
| ACC-2 | Second port : encodage dune autorisation sur un medium (distinct de lappairage) | `app/src/Acces/Port/**` | *a assigner* | CLAIM | 22/08 |
| ACC-3 | Projection reelle : une reservation ouvre un acces (remplace le no-op documente) | `app/src/Reservation/ProjectionAcces**` | *a assigner* | CLAIM | 22/08 |
| C18 | Tests : afficher le detail des 5 notices PHPUnit (config actuelle ne donne que le compte) | `app/phpunit.dist.xml` | **claude-C** | DONE | 22/08 |
| C19 | Garde-fou : detecter les find()/findOneBy() directs en Processor non confrontes au perimetre (angle mort revele par lIDOR dappairage) | `bin/garde-fou-cloisonnement.php` | **claude-C** | DONE | 22/08 |
| C20 | IDOR appairage corrige — test de non-regression a ecrire (agent scope B vs droit de A = 404) | `app/tests/Acces/**` | **claude-B** (verif) | DONE | 24/08 |
| C21 | Performance : verifier US-L3-03 et RG-ACC-01 hors suite fonctionnelle (materiel representatif, a chaud, percentile) | `infra/**` | *a assigner* | CLAIM | 22/08 |
| C22 | OCR mode degrade : assertion sur labsence dappel reseau plutot que sur le chronometre | `app/tests/Ocr/**` | **claude-B** (verif) | DONE | 24/08 |
| C23 | Tests : remplacer les createMock() sans attente par createStub() (5 notices, meme famille) | `app/tests/**` | **claude-C** | DONE | 22/08 |
| RR-1 | **PREALABLE** — emettre les evenements declencheurs manquants (panier abandonne, facture echue, devis expire, client inactif) | `app/src/{Boutique,Facturation,Crm}/**` | **claude-E** | CLAIM | 23/08 |
| SF-1 | **PREALABLE** — emettre booking.cancelled, booking.no_show, access.recorded | `app/src/{Reservation,Acces}/**` | **claude-E** | CLAIM | 23/08 |
| RR-0 | Spec SDD Revenue Recovery — trancher dabord : etendre Recouvrement ou module neuf | `specs/revenue-recovery/**` | **claude-E** | CLAIM | 23/08 |
| SF-0 | Spec SDD Smart Flow — retards, creneaux liberes, liste dattente, affluence | `specs/smart-flow/**` | **claude-E** | REVIEW | 24/08 |
| RR-2 | Moteur de relance pilote par evenements (apres RR-0 et RR-1) | `app/src/RevenueRecovery/**` | **claude-E** | CLAIM | 23/08 |
| SF-2 | Creneau libere, liste dattente, revente du creneau (apres SF-0 et SF-1) | `app/src/SmartFlow/**` | **claude-E** | CLAIM | 23/08 |
| CQ-0 | **PREALABLE** — rattacher un DroitAcces a un porteur (facultatif : la carte au porteur reste possible) | `app/src/{Acces,Crm}/**` | **claude-C** | CLAIM | 23/08 |
| CQ-1 | Recharge dune carte multi-entrees : increment du droit existant, bascule versionMaj, vente rattachee | `app/src/Acces/**` | **claude-B** | DONE | 24/08 |
| CQ-2 | Consultation du solde en lecture seule (ne consomme rien) + modale de caisse avec ajout rapide | `app/src/Acces/**` | **claude-A** | CLAIM | 23/08 |
| CQ-3 | Carte de N reservations : ouvrir creditRestant sur les droits de type Booking | `app/src/Reservation/**` | *a assigner* | CLAIM | 23/08 |
| CQ-4 | propositionRecharge : ne designer que des canaux reellement implementes | `app/src/Acces/State/PassageIngestionProcessor.php` | **claude-B** | DONE | 24/08 |
| CQ-5 | No-show : issue sur le credit (decompte / restitue / restitue avec report), orthogonale a la facturation | `app/src/Reservation/**` | **claude-B** | DONE | 24/08 |
| CQ-6 | Carte de seances nominative : quota de STOCK, distinct du quota periodique des formules | `app/src/{Reservation,Acces}/**` | *a assigner* | CLAIM | 23/08 |
| CQ-7 | Parametres de recharge : la validite apres recharge se configure (conserver / prolonger) | `app/src/Offre/**` | **claude-G** | DONE | 24/08 |
| ACC-4 | Resolution du pilote dacces **par etablissement** — lalias DI unique rend les capacites globales et vide D17 dune partie de son sens | `app/src/Acces/**` | *a assigner* | CLAIM | 23/08 |
| CQ-8 | **ARGENT** — vendre N cartes en une ligne facture N et nemet quune seule chargee (defaut preexistant, revele par CQ-1) | `app/src/Vente/Service/ValiderVenteService.php` | **claude-B** | REVIEW | 24/08 |
| C24 | Le hook installe compare son contenu a la version poussee et avertit sil est perime (D28) | `hooks/pre-receive` | **claude-C** | CLAIM | 24/08 |
<!-- Ajouter les nouvelles tâches au-dessus de cette ligne. -->

| CLI-0 | Spec SDD de l'application client final — web et native (D38) | `specs/client/**` | **claude-A** | CLAIM | 25/08 |
| CLI-1 | Authentification et espace personnel du client : inscription, connexion, « mon compte » | `app/src/Boutique/**` | *a assigner* | CLAIM | 25/08 |
| CLI-2 | Mes reservations, mes billets, mes commandes — lecture cloisonnee par les droits `_soi` | `client/**` | *a assigner* | CLAIM | 25/08 |
| CLI-3 | Ma carte et mon solde : porte-monnaie, cartes multi-entrees, recharge | `client/**` | *a assigner* | CLAIM | 25/08 |
| CLI-4 | Coquille native (iOS/Android) — **non publiable avant immatriculation, cf. SOC-4** | `client-natif/**` | *a assigner* | EXTERNE | 25/08 |

| UI-1 | **Modifier un prix** — `GrilleTarifaire` expose Post et Patch, le front ne fait que lire. Plainte directe de Maxime | `frontend/**` | **claude-H** | CLAIM | 25/08 |
| UI-2 | Options produit : le chantier entier — creation, valeurs, rattachement, apercu caisse | `frontend/**`, `app/src/OptionProduit/**` | *a assigner* | CLAIM | 25/08 |
| UI-3 | Vue rapide du billet : produit, type, entrees restantes, ou dates si abonnement | `frontend/**` | *a assigner* | CLAIM | 25/08 |
| UI-4 | Vue calendrier type agenda : ajout et suppression rapides d evenements | `frontend/**` | *a assigner* | CLAIM | 25/08 |
| UI-5 | Informations client : champs supplementaires, dont moyen de paiement prefere (**ajout serveur**, nexiste pas) | `app/src/Crm/**`, `frontend/**` | *a assigner* | CLAIM | 25/08 |
| ACT-5 | Categories automatiques par verticale : un produit daffutage doit tomber dans les bons axes sans saisie | `app/src/Offre/**` | **claude-A** | FAIT 27/08 | 25/08 |
| ACT-6 | Jauge propre aux cours (padel, tennis) — le modele la portait deja (`Ressource.capacitePropre`), elle etait juste modifiable NULLE PART | `app/src/Reservation/**` | **claude-A** | FAIT 27/08 | 25/08 |
| VTE-1 | `Vente` : ajouter OrderFilter sur la date, DateFilter, SearchFilter sur le client — bloque lhistorique des ventes | `app/src/Vente/**` | *a assigner* | CLAIM | 25/08 |
| PER-1 | `personnel:traiter-echeances-sortie` exige un agentEmail : decider quelle identite porte un traitement automatique dans laudit | `app/src/Personnel/**` | *a assigner* | CLAIM | 25/08 |

| CMP-0 | Spec SDD du module de campagnes — frontiere avec Revenue Recovery, audience, consentement, attribution | `specs/campagnes/**` | **claude-A** | REVIEW | 25/08 |
| CMP-1 | **PREALABLE** — remonter `ClientNotificationInterface` de SmartFlow vers Platform (trois modules en dependent) | `app/src/Platform/**`, `app/src/SmartFlow/**` | **claude-A** | CLAIM | 25/08 |
| CMP-2 | Audience : definition dun segment sur les donnees de comportement, previsualisation du nombre de personnes touchees | `app/src/Campagne/**` | *a assigner* | CLAIM | 25/08 |
| CMP-3 | Message et canaux, **avec le consentement rendu incontournable a lenvoi** — pas verifie, impossible a contourner | `app/src/Campagne/**` | *a assigner* | CLAIM | 25/08 |
| CMP-4 | Planification : ponctuelle, recurrente, ou declenchee par un evenement de domaine (anniversaire, abonnement a echeance, carte a une entree) | `app/src/Campagne/**` | *a assigner* | CLAIM | 25/08 |
| CMP-5 | **ATTRIBUTION** — qui est revenu, ce quil a achete, combien ca a rapporte. Cest le seul avantage quun outil generaliste ne peut pas copier | `app/src/Campagne/**` | *a assigner* | CLAIM | 25/08 |
| CMP-6 | Adaptateur denvoi reel (courriel, SMS) — **EXTERNE : exige un prestataire, donc un contrat, donc limmatriculation** | `app/src/Campagne/**` | *a assigner* | EXTERNE | 25/08 |

| PAY-0 | Spec du parcours de paiement : recueil conjoint carte + mandat, bascule sur rejet, explication au client | `specs/paiement/**` | **claude-A** | CLAIM | 25/08 |
| PAY-1 | Recueil conjoint au guichet : carte par le terminal (**jamais de numero saisi dans lapplication**), mandat signe | `app/src/{Vente,Sepa}/**` | *a assigner* | CLAIM | 25/08 |
| PAY-2 | Bascule automatique carte -> prelevement sur rejet, avec preavis au client avant tout prelevement | `app/src/{Sepa,Facturation}/**` | *a assigner* | CLAIM | 25/08 |
| PAY-3 | Rejet CARTE : lentite nexiste pas, seul le rejet SEPA est modelise | `app/src/Vente/**` | *a assigner* | CLAIM | 25/08 |
| PAY-4 | Explication du double recueil dans le parcours client — une phrase avant la saisie, pas une note de bas de page | `frontend/**`, `vitrine/**` | *a assigner* | CLAIM | 25/08 |
| PAY-5 | Prestataire bancaire : jeton recurrent, champs heberges en ligne, lecture des retours pain.002 | `app/src/{Vente,Sepa}/**` | *a assigner* | EXTERNE | 25/08 |

| UI-6 | **Choix du tarif au guichet** — lAPI laccepte deja, lecran choisit tout seul. Cause probable de la proliferation de produits (D44) | `frontend/**` | **claude-H** | CLAIM | 25/08 |
| VTE-2 | Vente directe sans session de caisse, **sans especes**, ouverte par une PERMISSION (tranche par Maxime, D45-bis) | `app/src/{Vente,Caisse}/**` | *a assigner* | CLAIM | 25/08 |

| VTE-3 | Correction de reglement : ecriture compensatoire datee du jour du geste, rattachee a la vente, scellee (D45) | `app/src/{Vente,Caisse}/**` | *a assigner* | CLAIM | 25/08 |
| VTE-4 | Permission dediee a la correction de reglement, distincte de caisse.gerer, tracee a laudit | `app/src/Securite/**` | **claude-A** | CLAIM | 25/08 |
| FAC-1 | Devis, bon de commande, bon de livraison : la chaine complete jusqua la facture existante | `app/src/Facturation/**` | *a assigner* | CLAIM | 25/08 |

| VTE-5 | Attente de paiement **et debiteur** portes par le CANAL, exiges a la construction — six canaux, dont OTA ou le debiteur nest pas le client (D46-bis) | `app/src/{Offre,Vente}/**` | *a assigner* | CLAIM | 25/08 |
| VTE-6 | Date de modification sur vente et facture, et **affichage en ecart** et non en dates brutes | `app/src/{Vente,Facturation}/**`, `frontend/**` | *a assigner* | CLAIM | 25/08 |
| CAI-1 | Une correction de reglement peut pointer lAlerteEcartCaisse quelle explique — un ecart explique cesse detre un ecart | `app/src/Caisse/**` | *a assigner* | CLAIM | 25/08 |
