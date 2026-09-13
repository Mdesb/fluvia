# Dépannage : annulation d'une vente (pour le support)

Pour chaque symptôme, dans l'ordre : ce que dit l'utilisateur, la cause la plus fréquente d'abord, le geste, la vérification.

## « Le bouton Annuler n'apparaît pas »

1. Cause la plus fréquente : la vente est déjà annulée. Le bouton **Annuler cette vente** ne s'affiche pas deux fois pour la même vente.
   Geste : demander à l'utilisateur d'ouvrir l'historique et de lire l'état de la vente. S'il voit **Rembourser** à la place, la vente n'est plus annulable.
   Vérification : le numéro d'avoir correspondant existe dans l'historique.
2. Cause suivante : la vente ne fait pas partie de la caisse en cours (caisse d'un autre jour ou d'un autre poste).
   Geste : orienter vers **Rembourser** si le client veut son argent. Pour une vente après clôture de la journée, ne rien promettre : ce cas n'a pas été vérifié, remonter en interne.
   Vérification : l'utilisateur confirme la date et le poste de la vente.
3. Cause rare : la vente appartient à un autre établissement. Elle est alors introuvable.
   Geste : vérifier l'établissement sur lequel l'utilisateur est connecté.
   Vérification : la vente apparaît une fois connecté sur le bon établissement.

## « Il me demande le régisseur »

1. Cause unique connue : l'établissement exige la validation du régisseur pour toute annulation. Le message est **Demandez au régisseur de valider. Rien n'est annulé pour l'instant.**
   Geste : rassurer, rien n'est perdu et rien n'est annulé. La demande est déjà transmise, le régisseur la voit sur son écran. Ne pas refaire la demande.
   Vérification : le régisseur confirme qu'il voit la demande. Après validation, l'utilisateur voit la vente marquée annulée dans l'historique.
2. Si le régisseur ne voit rien : noter le numéro de vente, l'heure et le poste, remonter en interne.

## « J'ai annulé mais le client veut son argent »

1. Cause : confusion normale. Annuler une vente crée un avoir et bloque le billet, mais ne rend pas l'argent. Le remboursement est un geste séparé.
   Geste : dans l'historique, sur la vente annulée, l'utilisateur clique sur **Rembourser** (il apparaît quand la vente n'est plus annulable) et suit les étapes de remboursement.
   Vérification : le remboursement apparaît dans l'historique et le client a été remboursé selon son moyen de paiement.
2. Rappel utile : rien n'est imprimé automatiquement à l'annulation. Si le client veut une trace, l'utilisateur peut lui donner le numéro d'avoir affiché dans **Vente annulée. Avoir n° X émis.**

## Ce qui n'a pas été vérifié

Le comportement d'une annulation après la clôture de la journée. Ne pas donner de consigne à l'utilisateur sur ce point, remonter en interne.
