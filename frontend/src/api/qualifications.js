// LES TYPES DE QUALIFICATION, EN UN SEUL ENDROIT.
//
// Deux ecrans les proposent : la saisie d'une qualification (onglet « Qualifications ») et le
// creneau de travail, qui declare celle qu'il EXIGE. Deux listes du meme enum finiraient par
// diverger -- et le jour ou l'une gagne un type que l'autre ignore, le creneau exige un brevet que
// personne ne peut saisir. Ce depot connait ce defaut par coeur.
//
// ⚠ La source de verite est `App\Personnel\Enum\TypeQualification` : six cas, pas un de plus.
// Un type absent de l'enum part en 422.
export const TYPES_QUALIFICATION = [
  ['MNS', 'MNS — maitre-nageur sauveteur'],
  ['BNSSA', 'BNSSA — surveillant de baignade'],
  ['BEESAN', 'BEESAN'],
  ['BAFA', 'BAFA'],
  ['BPJEPS', 'BPJEPS'],
  ['autre', 'Autre (preciser le libelle)'],
]
