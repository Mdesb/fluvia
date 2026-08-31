# Deux impayés sur le même client ouvriraient deux campagnes de relance

**Statut : défaut mesuré, LATENT, correction en attente d'une décision de Maxime.**
Trouvé par `allaccess-c2` le 30/08 en cherchant si le modèle des incidents avait une conséquence
pour quelqu'un — pas en cherchant ce défaut-là.

---

## Le mécanisme

`RecoveryEngine::handle()` dédoublonne ses campagnes sur `subjectType` + `subjectRef`. Et le sujet
publié par `LegacyEventBridge` est :

    new EventSubject('PaymentIncident', $incident->getId())

**La clé est donc l'incident, alors qu'une relance s'adresse à un redevable.** Deux incidents du même
client — un rejet de mars, un rejet d'avril — produisent deux `subjectRef` distincts, donc deux
`RecoveryCase` parallèles : deux campagnes sur la même personne.

⚠ Ce n'est **pas** le même sujet que le doublon d'incidents lui-même, qui a été instruit et tranché :
un incident par échéance rejetée est **requis**, pas seulement défendable — l'entité porte
`referenceEcheanceOrigine` et `rejetOrigine`, et la représentation bancaire se fait par montant. Un
test échoue désormais si quelqu'un « corrige » en fusionnant (`beea83a`).

## Pourquoi rien ne part aujourd'hui

Deux raisons **séparées**, chacune suffisante, **chacune révocable par un geste ordinaire** :

1. aucune `RecoverySequence` n'est semée, et elles sont inactives par défaut (RG-RR-02) — il suffit
   qu'un exploitant en active une ;
2. `SendDueRecoveryAttemptsCommand` n'est déclenchée par rien. Vérifié sur le VPS : ni crontab, ni
   timer. C'est la même absence que les vingt-deux commandes cataloguées sans déclencheur.

**Latent n'est pas inoffensif.** Le jour où l'ordonnanceur démarre et où quelqu'un active une
séquence, les deux verrous tombent ensemble.

## Ce qui n'a pas été mesuré, et pourquoi

⚠ **On n'a pas compté ce qui arrive dans une boîte aux lettres.** `MAILER_DSN` est nul sur cette
instance : on aurait compté zéro pour une raison sans rapport avec la question, et conclu « pas de
double relance » alors que la vraie réponse est « aucune relance du tout ».

Ce qui a été compté, ce sont les **points de décision** — l'ouverture du `RecoveryCase`. C'est ce
déplacement qui a mené à la clé plutôt qu'à un chiffre creux.

## La décision qui appartient à Maxime

**Deux impayés distincts justifient-ils deux relances ?**

- **Si oui** — parce qu'elles disent des montants et des échéances différents — le comportement
  actuel est correct et il n'y a rien à faire, sauf peut-être espacer les envois.
- **Si non** — parce qu'un client qui reçoit deux courriers le même jour lit surtout qu'on ne sait
  pas à qui l'on parle — alors il faut dédoublonner **sur le redevable** pour les déclencheurs
  d'impayé, en gardant l'incident comme *motif* de la campagne plutôt que comme son *identité*.

C'est une question produit autant que technique. La forme proposée par c2 est la seconde ; elle n'est
pas appliquée.

## Ce qu'il faudra vérifier le jour où on le corrigera

Le test qui compte n'est pas « un seul `RecoveryCase` existe » : c'est **combien de tentatives
d'envoi sont décidées** pour un client portant deux incidents. Le premier serait vrai d'un
dédoublonnage qui perd le second motif — et l'exploitant ne saurait plus lequel des deux impayés la
relance concerne.
