# Carte des modules

**Relevé du 26/08/2026 au soir, après l'arrêt de la flotte.**

Cette carte remplace celle qui répartissait les modules entre neuf sessions. Elle ne dit plus **qui**
travaille où — elle dit **où en est le produit**.

Deux colonnes seulement comptent :

- **Exposé** — ce que le serveur sait faire. Compté sur les déclarations d'opérations d'API.
- **Atteignable** — ce qu'un utilisateur peut réellement déclencher depuis un écran.
  `npm run mesurer-ecart`, reconstitué par préfixe d'URL, donc à une ou deux unités près.

> **L'écart entre les deux est le vrai état du chantier.**
> 1 049 opérations construites, **234 atteignables (22,3 %)**. **815 n'ont pas de porte.**

---

## Les modules qui font vivre l'exploitation

| Module | Exposé | Atteignable | État |
|---|---:|---:|---|
| **Stock** | 63 | ~30 | Ouvert le 26/08, de zéro à trois sections. ⚠ **Trois replis silencieux** — une donnée absente y fait taire un contrôle. ⚠ **Inventaire « par rayon » compte tout, « sur sélection » ne compte rien.** |
| **Vente** | 26 | ~11 | Cœur du produit. Vente sans caisse livrée, ticket reconstituable, correction de règlement. ⚠ **L'écran et le serveur ne se confrontent nulle part** — trois défauts de ce type le 26/08. |
| **Caisse** | 22 | ~6 | Clôture Z, écart de caisse expliqué. La clôture **journalière** est désormais distincte du Z et automatisée. |
| **Offre** | 48 | ~9 | Catalogue, tarifs, socle partagé + ajout local. Le prix d'une option est calculé côté serveur. |
| **OptionProduit** | 14 | 0 | ⚠ **Les options ne se vendent pas.** Le serveur sait tout faire, **aucun écran ne permet d'en choisir une en vendant**. Premier chantier. |
| **Reservation** | 51 | ~7 | ⚠ **À VÉRIFIER EN PREMIER : un compteur d'occupation entretenu à la main à côté d'une jauge qui se recalcule.** Deux sources pour la même question. |
| **Boutique** | 47 | ~4 | Vente en ligne, paniers, remboursements — ces derniers branchés le 26/08. |

## Les métiers verticaux

| Module | Exposé | Atteignable | État |
|---|---:|---:|---|
| **Musée** | 67 | ~11 | Ouvert le 26/08 : salles, jauges, dossiers scolaires. ⚠ Le seuil est affiché **sans dire ce qu'il déclenche**. |
| **Padel** | 56 | ~7 | Ouvert le 26/08 : parties ouvertes, réservations. ⚠ **Le classement d'un tournoi existe et ne s'affiche nulle part.** ⚠ La commande d'éclairage pilote **un relais physique**. |
| **Piscine** | 53 | ~4 | Ouvert le 26/08 : seuil POSS en tête d'écran, casiers en grille. ⚠ **`presents` est affiché sans que sa source ait été vérifiée** — un chiffre de sécurité. |
| **Patinoire** | 34 | 13 | Le plus complet des verticaux. Parc de patins, affûtage, cautions, liste d'attente. |
| **Sport** | 31 | ~0 | Abonnements fitness, prélèvements. Aucun écran. |
| **Lodging / Stay / Dining** | — | 0 | Hébergement : la nuitée et le tarif par nuit existent. Séjour et restauration : rien d'atteignable. |

## L'argent et la conformité

| Module | Exposé | Atteignable | État |
|---|---:|---:|---|
| **Compta** | 78 | ~7 | ⚠ **Le plus gros écart du produit.** La clôture est **irréversible** et ne vérifie pas que toutes les ventes sont comptabilisées. La génération d'écritures **saute en silence** les ventes au mapping incomplet. |
| **Finance** | 60 | ~7 | Factures fournisseur livrées. Rapprochement bancaire et notes de frais : rien. **Sa suite de tests n'a jamais rendu de verdict.** |
| **Facturation** | 26 | ~8 | Devis, bons de commande, bons de livraison avec leur filiation. ⚠ **Un devis n'expire jamais** — le mécanisme existe, rien ne l'appelle. |
| **Sepa** | 15 | ~0 | Préavis de prélèvement construit et planifié. ⚠ **Ne peut pas partir : aucun prestataire d'envoi.** ⚠ **Aucun parseur pain.002** — les rejets bancaires réels ne remontent pas. |
| **Recouvrement** | 12 | ~2 | Relances, incidents d'impayé. |
| **Caution** | 8 | ~2 | Empreintes et retenues, utilisées par la patinoire. |

## Le socle

| Module | Exposé | Atteignable | État |
|---|---:|---:|---|
| **Securite** | 31 | ~6 | Rôles, permissions, délégations, MFA. ⚠ **Un joker de module ne doit jamais contenir un droit qui dépasse ce module.** |
| **Organisation** | 19 | ~3 | Groupes, régions, établissements. **Un établissement ne se supprime plus** — il se désactive. Porte désormais son fuseau horaire. |
| **Personnel** | 42 | ~9 | Planning, qualifications, badges. ⚠ **Un agent dont la qualification est révoquée après la planification reste au planning** — vérification quotidienne posée le 26/08, **aucun écran ne l'affiche**. |
| **Acces** | 50 | ~4 | Contrôle d'accès, supports, passages, jauges FMI. |
| **Crm** | 38 | ~4 | Clients, familles, bénéficiaires, consentements, RGPD. |
| **Support** | 37 | ~2 | Tickets, base de connaissances. |
| **Reporting** | 26 | ~2 | Indicateurs, axes analytiques, agrégation. |
| **Dms** | 15 | ~2 | Documents, versions, liens publics expirants. |
| **Platform** | — | — | Bus d'événements, ordonnanceur, notifications, cloisonnement. |
| **Audit** | — | — | Journal d'audit, tracé au refus comme à l'usage. |

## L'éditeur

| Module | Exposé | Atteignable | État |
|---|---:|---:|---|
| **Subscription** | 22 | ~14 | Abonnements, plans, provisioning. ⚠ **L'accès d'assistance a son API et son port serveur, mais aucun écran** — donc en pratique l'assistance passera par une affectation permanente, ce que la règle interdit. |
| **Editeur** | — | ~14 | Administration éditeur, tunnel de souscription. |
| **Social** | 11 | ~2 | Publication réseaux, collecte de statistiques. |
| **RevenueRecovery / SmartFlow** | 20 | ~0 | Relances de revenus, créneaux libérés, listes d'attente. |

---

## Les six mécanismes construits que rien n'appelle

| Mécanisme | Ce qu'il manque |
|---|---|
| **Accès d'assistance (ED-4)** | l'écran, et l'adaptateur du port |
| **Bascule carte → prélèvement** | l'abonné — vingt lignes |
| **Expiration des devis** | une tâche planifiée |
| **Révocation d'un badge** | un écran. ⚠ **Mesure de sécurité que personne ne peut déclencher.** |
| **Validation d'un créneau de bassin** | un écran, ou son retrait |
| **Classement de tournoi padel** | un écran |

---

## Ce que ce tableau ne dit pas

**Aucun des vingt écrans livrés le 26/08 n'a jamais tourné contre un vrai serveur.** Compilation verte,
garde-fous verts, **zéro exécution**. Le premier qui les ouvre trouvera des choses.

Et le reste — les doutes non levés, les endroits où l'API ment sur ce qu'elle rend, les pièges de
nommage à effet silencieux — est dans `COORDINATION/PASSATION.md`.

---

# Relevé du 27/08/2026 au soir — ce qui a changé en un jour

**Le chiffre du haut n'est plus le bon, et l'écart s'est déplacé.**

|  | 26/08 au soir | 27/08 au soir |
|---|---:|---:|
| Opérations exposées | 1 049 | **1 081** |
| Appels depuis les écrans | ~234 | **343** |
| Entrées de menu condamnées (`absent: true`) | 7 | **3, toutes délibérées** |

Les trois entrées restantes — **SEPA, recouvrement, cautions** — ne sont pas des manques : elles
vivent dans les onglets de `Comptabilité`, là où l'exploitant les cherche. Leur ouvrir une porte
propre créerait deux chemins vers la même liste, et personne ne saurait lequel fait foi. Le drapeau
est conservé **à dessein**, avec l'explication dans `AppShell.jsx`.

## Ce qui a été ouvert

| Module | Ce qui manquait | Ce qui a été fait |
|---|---|---|
| **Support** | 18 opérations, **aucun écran** | Tickets, escalade N1→N2, notes internes, base de connaissances |
| **Autorisation** | 13 opérations, aucun écran | File des demandes, **triée par ce qui expire** |
| **Dms** | 15 opérations, aucun écran | Bibliothèque versionnée, dépôt multipart, téléchargement authentifié |
| **Social** | 11 opérations, aucun écran | Comptes, composition, **détail par compte** d'un envoi partiel |
| **Sport** | `EvenementSOS` listable mais jamais listé | Appels d'urgence en tête, présences isolées, abonnements |
| **Legal** | *n'existait pas* | Six documents composés depuis une fiche de faits |
| **Project** | *n'existait pas* | Projets et tâches, avancement compté, retard déduit |
| **Crm** | pas de contacts, pas de pipeline | Contacts d'une société, pipeline dont trois étapes se lisent du devis |
| **Reservation** | un seul modèle de réservation | Placement libre, horaires et absences, vue semaine |

## Les défauts que cette journée a fait sortir

Ils ne se ressemblent pas, et c'est ce qui les rend instructifs.

**Deux fuites de cloisonnement.** Le catalogue de Piscine A montrait le produit de Patinoire B et
cachait les quatorze produits du socle — et un produit d'un autre site a été **encaissé**, entrant
dans une chaîne de scellement NF525 tenue par point de vente. Puis `CustomerContact`, exposée sans
qu'aucune extension ne puisse la filtrer.

> **Une fuite de cloisonnement ne produit pas d'erreur : elle produit des lignes en trop, et des
> lignes en trop ne se remarquent que si on les compte.**

**Un bug d'argent.** Un abonnement souscrit chez Patinoire B par un compte né chez Piscine A entrait
dans les comptes de Piscine A — abonnement, mandat SEPA et panier.

**Les deux boutiques en ligne n'avaient jamais rien eu à vendre.** Les sept produits publiés et
vendables en ligne ont tous un établissement nul ; la jointure interne les excluait tous. La boutique
affichait *« Aucun billet en vente »* — une phrase exacte, qui ne ressemblait pas à un défaut.

**Et un défaut qui ne venait pas de nous.** Le préfixe CSS `pub-` (pour « public ») est lu comme
« publicité » par les listes de filtres françaises : `#pub-main` était masqué, et la boutique
apparaissait vide à tout visiteur muni d'un bloqueur. Renommé `bq-` sur 299 occurrences.

## Cinq champs déclarés et inertes, trouvés le même jour

`TypeProduit::$defauts`, `Activite::$competenceExigee`, `Ressource::$competenceRequise`,
`DisponibiliteRessource` et `IndisponibiliteRessource` (exposées en CRUD complet, front muet),
`CommercialDocument` (chaîne devis → facture, écran existant mais jamais nommée dans la carte).

> **Ce qui manque à ce produit n'est presque jamais la règle métier : c'est le chemin qui y mène.**

C'est la leçon la plus rentable de la journée, et elle a changé deux décisions : la « verticale salon
de massage » et le « CRM B2B » n'ont **pas** été écrits comme des modules neufs — ils existaient sous
d'autres noms, et il ne manquait qu'un écran et deux briques.

## Ce qui reste, et qui n'est pas du développement

- **Cloisonnement des comptes clients** : ils sont cloisonnés par boutique d'**inscription**, donc un
  exploitant voit ceux nés chez lui et pas ceux qui achètent chez lui. Faux dans les deux sens.
  Décision de produit — voir `COMPTE-CLIENT-FINAL.md`.
- **Activités commerciales** : étendre `Support` ou séparer. Un module écrit à côté ferait deux boîtes
  de suivi. Voir `CRM-B2B.md`.
- **`frame-ancestors`** : absent, la boutique est encapsulable par n'importe qui. Configuration nginx.
  Voir `INTEGRATION-IFRAME.md`.
- **La suite complète de tests n'a jamais été menée à son terme** : 835 tests qui recréent un schéma
  de ~300 tables par classe, arrêtés après six heures. Les modules touchés sont validés un par un.
