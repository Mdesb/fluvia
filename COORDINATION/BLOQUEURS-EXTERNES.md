# Bloqueurs externes — ce qui attend un tiers

> **D19** — Rien ici n'arrête un développement. Chaque ligne est consignée, la tâche correspondante est
> en statut `EXTERNE`, et le travail continue ailleurs. Ce registre remplace la répétition de ces
> points dans les rapports horaires : on l'ouvre quand une action extérieure aboutit, pas chaque heure.
>
> Ne pas confondre avec un bloqueur **interne** (statut `BLOCKED`), qui reste dans les rapports parce
> qu'il dépend de nous.

| # | Sujet | Attend quoi | De qui | Contournement en place | Impact réel |
|---|---|---|---|---|---|
| E-1 | Adaptateurs Meta (Page, Instagram) — SOC-4 | Vérification d'entreprise puis revue applicative | Maxime : immatriculation de la société d'abord | SOC-2 vise Mastodon et Bluesky, réseaux ouverts sans vérification | **Aucun** sur SOC-0 à SOC-3 |
| E-2 | Transmission SEPA réelle (EBICS/SFTP) | Contrat bancaire et identifiant créancier (ICS) réel | Maxime : banque | `CollecteurSepaStubAdapter` derrière `CollecteurSepaInterface` ; fixtures en ICS fictif | **Aucun** sur mandats, remises, rejets, génération pain.008 |
| E-3 | Encaissement carte et empreinte bancaire | Choix puis contrat d'un prestataire de paiement | Maxime | D10 : le tunnel démarre en SEPA seul | Repousse la cible CHR/hôtellerie, où l'empreinte est attendue |
| E-4 | Adaptateurs de contrôle d'accès réels (Itbox, SmartAccess) | Identifiants, matériel ou bac à sable fournisseur | Fournisseurs, via Maxime | `SimulateurAccesAdapter` derrière `PiloteAcces` | **Aucun** sur le domaine ; ACC-0 à ACC-3 se développent sur le simulateur |
| E-5 | Cartes en portefeuille Apple/Google | Agrément de programme, obtenu via les fabricants de serrures | Non engagé | `TypeSupport::Wallet` existe comme valeur, rien derrière | Aucun aujourd'hui — à rouvrir seulement si un client le demande |
| E-6 | Lecteurs OSDP plutôt que Wiegand | Exigence à porter dans les cahiers des charges | Maxime, à l'écrit, dès maintenant | — | Aucun sur l'existant ; conditionne l'indépendance matérielle future (D17) |

| E-7 | Authenticité des rappels PayFiP (`POST /compta/payfip/retour`) | Le schéma de signature de la DGFiP — inconnu de nous | DGFiP, via Maxime | Route fermée par la permission `compta.valider` et cloisonnée par la vente d'origine (23/08) | **Aucun aujourd'hui** : Maxime a confirmé le 23/08 que rien n'appelle cette route |
## Ce qui débloque quoi

- **E-1** part dès que la société est immatriculée. C'est le seul de la liste dont la date dépend
  d'une démarche déjà engagée.
- **E-2** et **E-3** sont indépendants l'un de l'autre : SEPA suffit au lancement (D10), la carte ne
  devient nécessaire qu'en visant l'hôtellerie.
- **E-4** ne se débloque pas par une démarche mais par un **premier client réel** : c'est son matériel
  installé qui décidera du premier adaptateur, pas notre préférence.
- **E-6** ne coûte rien et n'attend personne d'autre que nous : c'est une phrase à ajouter aux
  cahiers des charges, aujourd'hui.

- **E-7** n'est pas un blocage de développement mais une **limite de ce qui est corrigé**. Un rappel de
  prestataire n'est pas un utilisateur connecté : la permission posée le 23/08 est un repli, valable
  tant que la route n'est appelée par personne. Le jour où l'intégration devient réelle, la garde doit
  devenir une **preuve d'authenticité du message** — signature, empreinte ou liste d'adresses — et la
  permission n'aura plus de sens. À rouvrir **avant** le premier rappel réel, pas après.
