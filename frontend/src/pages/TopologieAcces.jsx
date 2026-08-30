import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import Modal from '../components/Modal.jsx'
import Tabs from '../components/Tabs.jsx'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import {
  ETAT_CLS,
  ETAT_CONTROLEUR,
  MODE_RECALAGE,
  MODE_SEUIL,
  MOTIF_REFUS,
  RESULTAT_CLS,
  RESULTAT_PASSAGE,
  SENS_EQUIPEMENT,
  SENS_PASSAGE,
  TYPE_EQUIPEMENT,
  depuis,
  duree,
  signeDeVie,
} from '../api/acces.js'

// TOPOLOGIE & PASSAGES — CONFIGURER LE CONTRÔLE D'ACCÈS, ET RELIRE CE QU'IL A FAIT.
//
// L'écran « Supervision » montre l'instant : les jauges, les incidents, les vingt derniers
// passages. L'écran « Badges & terminaux » gère les porteurs et le matériel. Il manquait les deux
// bouts : L'INSTALLATION (quels espaces, quels contrôleurs, quels équipements, avec quels seuils et
// quelles tolérances) et LA MÉMOIRE (le journal complet, filtrable et exportable).
//
// Quatre entités exposaient chacune GetCollection + Get + Post + Patch — EspaceAcces, Controleur,
// Equipement, SousReseau — et AUCUN écran ne les atteignait. Concrètement : sur une installation
// neuve, on ne pouvait pas déclarer un tourniquet. Le seuil de jauge d'un espace, le mode au
// dépassement, le délai d'anti-passback, les marges d'avance et de retard existaient en base et se
// réglaient à la main, dans la base, par quelqu'un qui savait où regarder.
//
// LE JOURNAL EST LE MÊME SUJET, PAS UN AUTRE. On ne règle pas une marge de retard dans l'abstrait :
// on la règle parce qu'on a vu passer trente refus « hors créneau » à 9 h 02. Le journal est
// l'instrument qui dit si la configuration est juste — d'où les deux dans le même écran.
//
// ─────────────────────────────────────────────────────────────────────────────────────────────
// CE QUE CET ÉCRAN REFUSE DE FAIRE, ET POURQUOI
//
// 1. IL NE CRÉE PAS DE LIEU. Un « espace d'accès » n'est pas un lieu : c'est le RÉGLAGE D'ACCÈS
//    d'un espace du socle (Paramètres › Établissement & entités › Espaces). Le serveur l'impose
//    (`espaceSocle` non nul, RG-SOCLE-01) et l'écran le dit, sinon l'exploitant se retrouve devant
//    deux listes de lieux qui se ressemblent sans savoir laquelle remplir.
//
// 2. IL NE RÈGLE PAS LES HORAIRES. Un passage peut être refusé hors des heures d'ouverture, mais
//    la case qui l'active vit dans Paramètres › Horaires d'ouverture, par établissement. Deux endroits
//    qui prétendent décider quand la porte s'ouvre finissent par se contredire, et personne ne sait
//    lequel a gagné. On renvoie vers celui qui existe.
//
// 3. IL NE PROPOSE PAS DE SUPPRESSION. Le serveur n'expose aucun DELETE sur ces quatre entités, et
//    c'est heureux : supprimer un espace d'accès emporterait en cascade les plages d'ouverture qui
//    lui sont rattachées (`OpeningSlot.space`, onDelete: CASCADE) — et une plage sans espace vaut
//    pour tout l'établissement, donc l'espace supprimé n'aurait pas moins d'horaires, il hériterait
//    de ceux du site. Un bouton « Supprimer » qui fait ça sans le dire n'a pas sa place ici.
//
// 4. IL NE CONFOND PAS « VIDE » ET « CASSÉ ». `X-Etablissement` est obligatoire : une collection
//    demandée sans établissement actif rend une LISTE VIDE, pas une erreur. Sur un écran de
//    configuration, « aucun espace configuré » pousse quelqu'un à tout recréer. Les trois cas — pas
//    d'établissement actif, collection vide, appel en échec — se disent donc avec trois phrases
//    différentes.
//
// ⚠ LE SERVEUR NE SAIT PAS TRIER LES PASSAGES, ET IL NE LE DIT PAS.
//
// `Passage` déclare un `SearchFilter` et un `DateFilter` — mais AUCUN `OrderFilter`. Le paramètre
// `order[horodatage]=desc` que tout le front envoie depuis l'origine est donc **silencieusement
// ignoré** : la collection sort dans l'ordre d'insertion, c'est-à-dire du plus ANCIEN au plus
// récent. Vérifié le 29/08 contre la préprod, en comparant l'ordre demandé et l'ordre reçu.
//
// Conjugué à la pagination, cela veut dire qu'une liste de passages montre les PREMIERS passages
// de l'histoire du site, jamais les derniers. On trie donc ce qu'on a reçu, faute de pouvoir
// choisir ce qu'on reçoit — et on le dit là où ça se voit.
//
// LE VOCABULAIRE GLOBAL NE SERT PAS ICI. `mot('valide')` rend « Accepté », qui qualifie le résultat
// d'un passage ; `mot('caisse')` rend « Espèces au guichet ». Aucun de ces sens n'est celui des
// énumérations de la topologie. D'où `api/acces.js` — les tables du module, partagées par les quatre
// écrans qui en parlent, et à ne pas « simplifier » en les renvoyant vers `vocabulaire.js`.

const ONGLETS = [
  ['plan', 'Plan du site'],
  ['lecteurs', 'Lecteurs'],
  ['reseaux', 'Sous-réseaux'],
  ['journal', 'Journal des passages'],
]

// L'identifiant d'une relation, qu'elle arrive en objet (`{ '@id', id, … }`) ou en IRI nue
// (`/api/espace_acces/…`). Les deux formes cohabitent DANS LA MÊME RÉPONSE selon les groupes de
// sérialisation : `Controleur.espace` est un objet dans `controleur:read`, mais la même relation vue
// depuis un équipement n'est qu'une IRI, parce que `EspaceAcces` ne déclare rien dans
// `equipement:read`. Comparer des `id` plutôt que des formes, c'est ce qui rend ce croisement sûr.
function idDe(v) {
  if (!v) return null
  if (typeof v === 'object') return v.id || (v['@id'] ? v['@id'].split('/').pop() : null)
  return String(v).split('/').pop()
}

// Les zones qu'un contrôleur dessert EN PLUS de son emplacement. La collection arrive en objets
// (`EspaceAcces` expose son libellé dans `controleur:read`), mais on croise quand même par
// identifiant : le jour où le groupe change, la colonne dirait « — » au lieu d'inventer.
function zonesDesservies(controleur, espaces) {
  return (controleur?.servedSpaces || [])
    .map((e) => (typeof e === 'object' && e.libelle) || espaces.find((x) => x.id === idDe(e))?.libelle)
    .filter(Boolean)
}

function texteOuTiret(v) {
  return v === null || v === undefined || v === '' ? '—' : v
}

function horodate(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('fr-FR')
}

// Une réponse Hydra dit combien d'éléments existent VRAIMENT, et c'est la seule chose sur laquelle
// s'appuyer : le serveur pagine, une collection plus longue que la page arrive coupée sans rien
// dire. Sur un plan de site, une liste tronquée n'est pas incomplète, elle est FAUSSE — on croit
// voir l'installation entière.
//
// ⚠ NE PAS REMPLACER CETTE COMPARAISON PAR UN NOMBRE. Elle a survécu au passage du plafond de 30 à
// 100 le 29/08 ; les commentaires qui citaient « 30 » ne l'ont pas fait, et six bandeaux ont
// annoncé pendant un jour des données manquantes qui ne manquaient plus.
function totalReel(reponse, recus) {
  const total = reponse?.totalItems ?? reponse?.['hydra:totalItems']
  return typeof total === 'number' && recus < total ? total : null
}

// ─────────────────────────────────────────────────────────────────────────────────────────────
// Formulaire générique de la topologie.
//
// L'ERREUR EST RENDUE DANS LA MODALE, ET C'EST LE POINT DE CE COMPOSANT. Le réflexe est de poser le
// message dans le bandeau de l'écran : il atterrit alors DERRIÈRE la fenêtre restée ouverte. On
// clique « Enregistrer », rien ne bouge, et l'explication est cachée dessous. `Controleur` et
// `Equipement` portent des validations serveur (`TopologieCoherente`, contrôleur obligatoire, sens
// obligatoire) : un 422 est le cas NORMAL ici, pas l'exception.
function FormulaireTopologie({ open, titre, champs, valeurs, setValeurs, onSubmit, onClose, erreur, enCours, aide }) {
  return (
    <Modal open={open} onClose={onClose} titre={titre}>
      <form onSubmit={onSubmit}>
        {aide && <p className="hint" style={{ marginTop: 0 }}>{aide}</p>}
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {champs.map((c) => {
          if (c.visible && !c.visible(valeurs)) return null
          const id = `topo-${c.nom}`
          const maj = (v) => setValeurs((s) => ({ ...s, [c.nom]: v }))
          return (
            <div className="field" key={c.nom}>
              <label htmlFor={id}>
                {c.libelle}
                {c.requis && ' *'}
              </label>
              {c.type === 'choix' ? (
                <select id={id} className="select" value={valeurs[c.nom] ?? ''} onChange={(e) => maj(e.target.value)} required={c.requis}>
                  {c.options.map((o) => (
                    <option key={o.valeur} value={o.valeur}>{o.libelle}</option>
                  ))}
                </select>
              ) : c.type === 'bool' ? (
                <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontWeight: 400 }}>
                  <input id={id} type="checkbox" checked={!!valeurs[c.nom]} onChange={(e) => maj(e.target.checked)} />
                  {c.libelleCase || c.libelle}
                </label>
              ) : c.type === 'cases' ? (
                <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap' }}>
                  {c.options.length === 0 && <span className="mut">{c.siVide || 'Aucun choix disponible.'}</span>}
                  {c.options.map((o) => (
                    <label key={o.valeur} style={{ display: 'flex', alignItems: 'center', gap: 6, fontWeight: 400 }}>
                      <input
                        type="checkbox"
                        aria-label={o.libelle}
                        checked={(valeurs[c.nom] || []).includes(o.valeur)}
                        onChange={(e) =>
                          setValeurs((s) => {
                            const actuel = s[c.nom] || []
                            return {
                              ...s,
                              [c.nom]: e.target.checked ? [...actuel, o.valeur] : actuel.filter((x) => x !== o.valeur),
                            }
                          })
                        }
                      />
                      {o.libelle}
                    </label>
                  ))}
                </div>
              ) : (
                <input
                  id={id}
                  className="input"
                  type={c.type === 'nombre' ? 'number' : 'text'}
                  min={c.min}
                  max={c.max}
                  value={valeurs[c.nom] ?? ''}
                  placeholder={c.exemple}
                  required={c.requis}
                  onChange={(e) => maj(e.target.value)}
                />
              )}
              {c.aide && <div className="hint" style={{ marginTop: 4 }}>{c.aide}</div>}
            </div>
          )
        })}
        <div className="row" style={{ justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button className="btn" type="button" onClick={onClose} disabled={enCours}>Annuler</button>
          <button className="btn primary" type="submit" disabled={enCours}>
            {enCours ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

// Un nombre facultatif part à `null` et non à `0` : `preAlertePct` vide ne veut pas dire « alerter
// à 0 % », et `antiPassbackDelai` vide sur un équipement veut dire « hériter de l'espace ».
function nombreOuNul(v) {
  if (v === '' || v === null || v === undefined) return null
  const n = Number(v)
  return Number.isFinite(n) ? n : null
}

export default function TopologieAcces({ etabActif, droits, onNav }) {
  const [onglet, setOnglet] = useState('plan')
  // ALLER DU MATÉRIEL À SES PASSAGES SANS REFAIRE LA RECHERCHE.
  //
  // On ne se demande jamais « quels passages ce mois-ci » dans l'abstrait : on se le demande
  // parce qu'un lecteur précis refuse du monde, ou qu'une zone se remplit trop vite. Le plan et
  // le journal parlent des mêmes objets ; passer de l'un à l'autre ne devrait pas obliger à
  // retrouver son nom dans une liste déroulante.
  const [cibleJournal, setCibleJournal] = useState(null) // { espace|equipement, etab }
  const [avertissement, setAvertissement] = useState(null)
  const [espaces, setEspaces] = useState([])
  const [controleurs, setControleurs] = useState([])
  const [equipements, setEquipements] = useState([])
  const [reseaux, setReseaux] = useState([])
  const [espacesSocle, setEspacesSocle] = useState([])
  const [tronques, setTronques] = useState([])
  const [echecs, setEchecs] = useState([])
  const [chargement, setChargement] = useState(true)
  const [statutErreur, setStatutErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [maj, setMaj] = useState(null)
  const [auto, setAuto] = useState(false)
  const [sessionPerdue, setSessionPerdue] = useState(false)
  const timer = useRef(null)

  // Édition : { genre: 'espace'|'controleur'|'equipement', ligne: objet|null, valeurs, erreur, enCours }
  const [edition, setEdition] = useState(null)

  const peutGerer = aLeDroit(droits, 'acces.gerer')

  const charger = useCallback(async (silencieux = false) => {
    if (!silencieux) setChargement(true)
    const resultats = await Promise.allSettled([
      api.espacesAcces(),
      api.controleursAcces(),
      api.equipementsAcces(),
      api.sousReseauxAcces(),
      api.espaces(),
    ])
    const noms = ['Espaces d’accès', 'Contrôleurs', 'Équipements', 'Sous-réseaux', 'Espaces du site']
    const poseurs = [setEspaces, setControleurs, setEquipements, setReseaux, setEspacesSocle]
    const coupees = []
    const rates = []
    let vu401 = false
    resultats.forEach((r, i) => {
      if (r.status === 'fulfilled') {
        const lignes = membres(r.value)
        poseurs[i](lignes)
        const total = totalReel(r.value, lignes.length)
        if (total !== null) coupees.push(`${noms[i]} : ${lignes.length} sur ${total}`)
      } else {
        // Une liste en échec ne vide pas les autres : un agent qui n'a pas `organisation.lire` peut
        // parfaitement lire la topologie, il verra seulement les espaces du socle en moins.
        poseurs[i]([])
        if (r.reason?.status === 401) vu401 = true
        rates.push({ nom: noms[i], message: r.reason?.message || 'chargement impossible', statut: r.reason?.status || null })
      }
    })
    setTronques(coupees)
    setEchecs(rates)
    setStatutErreur(rates[0]?.statut ?? null)
    setSessionPerdue(vu401)
    setMaj(new Date())
    if (!silencieux) setChargement(false)
  }, [])

  useEffect(() => {
    charger()
  }, [etabActif, charger])

  // RAFRAÎCHISSEMENT AUTOMATIQUE, ET LA RAISON POUR LAQUELLE IL S'ARRÊTE TOUT SEUL.
  //
  // Le jeton d'authentification expire au bout d'une heure (valeur par défaut de Lexik : aucun
  // `token_ttl` n'est déclaré) et le projet n'a AUCUN mécanisme de rafraîchissement. Un écran laissé
  // ouvert devant soi — c'est exactement l'usage d'un plan de site pendant une installation — se
  // fait donc éjecter en silence. On ne peut pas réparer ça d'ici : c'est le socle. Ce qu'on peut
  // faire, c'est ne pas continuer à taper toutes les douze secondes dans le vide, et DIRE pourquoi
  // l'écran s'est figé, au lieu de laisser croire à des données à jour.
  useEffect(() => {
    if (!auto || sessionPerdue) return undefined
    timer.current = setInterval(() => charger(true), 12000)
    return () => clearInterval(timer.current)
  }, [auto, sessionPerdue, charger])

  useEffect(() => {
    if (sessionPerdue) setAuto(false)
  }, [sessionPerdue])

  const parEspace = useMemo(() => {
    const carte = new Map()
    espaces.forEach((e) => carte.set(e.id, { espace: e, controleurs: [] }))
    const orphelins = []
    controleurs.forEach((c) => {
      const cible = carte.get(idDe(c.espace))
      const equipementsDuControleur = equipements.filter((q) => idDe(q.controleur) === c.id)
      const noeud = { controleur: c, equipements: equipementsDuControleur }
      if (cible) cible.controleurs.push(noeud)
      else orphelins.push(noeud)
    })
    return { noeuds: [...carte.values()], orphelins }
  }, [espaces, controleurs, equipements])

  const nomEspaceSocle = useCallback(
    (espaceAcces) => {
      // `EspaceAcces.espaceSocle` arrive en IRI NUE : `Espace` (socle) ne déclare aucune propriété
      // dans le groupe `espace_acces:read`. Écrire `e.espaceSocle.nom` donnerait `undefined` sur
      // toutes les lignes — une colonne vide qui se lit « pas de lieu ». On croise donc par
      // identifiant avec la liste des espaces du site.
      const id = idDe(espaceAcces.espaceSocle)
      const trouve = espacesSocle.find((s) => s.id === id)
      if (trouve) return trouve.type ? `${trouve.nom} (${trouve.type})` : trouve.nom
      return id ? 'lieu non chargé' : '—'
    },
    [espacesSocle],
  )

  const nomReseau = useCallback(
    (espaceAcces) => {
      // Même famille : `SousReseau` n'expose que son `id` dans `espace_acces:read`, jamais son
      // libellé.
      const id = idDe(espaceAcces.sousReseau)
      if (!id) return null
      return reseaux.find((r) => r.id === id)?.libelle || 'sous-réseau non chargé'
    },
    [reseaux],
  )

  // ── Ouverture des formulaires ───────────────────────────────────────────────────────────────

  function ouvrirEspace(ligne = null) {
    setSucces(null)
    setEdition({
      genre: 'espace',
      ligne,
      erreur: null,
      enCours: false,
      valeurs: ligne
        ? {
            libelle: ligne.libelle || '',
            espaceSocle: idDe(ligne.espaceSocle) || '',
            seuilFmi: ligne.seuilFmi ?? 0,
            modeSeuil: ligne.modeSeuil || 'blocage',
            preAlertePct: ligne.preAlertePct ?? '',
            antiPassbackActif: ligne.antiPassbackActif !== false,
            antiPassbackDelai: ligne.antiPassbackDelai ?? 300,
            recalageOuverture: ligne.recalageOuverture || 'remise_a_zero',
            sousReseau: idDe(ligne.sousReseau) || '',
          }
        : {
            libelle: '',
            espaceSocle: espacesSocle[0]?.id || '',
            seuilFmi: 0,
            modeSeuil: 'blocage',
            preAlertePct: '',
            antiPassbackActif: true,
            antiPassbackDelai: 300,
            recalageOuverture: 'remise_a_zero',
            sousReseau: '',
          },
    })
  }

  function ouvrirControleur(ligne = null, espaceId = null) {
    setSucces(null)
    setEdition({
      genre: 'controleur',
      ligne,
      erreur: null,
      enCours: false,
      valeurs: ligne
        ? {
            libelle: ligne.libelle || '',
            espace: idDe(ligne.espace) || '',
            servedSpaces: (ligne.servedSpaces || []).map((e) => idDe(e)).filter(Boolean),
            itboxRef: ligne.itboxRef || '',
            etat: ligne.etat || 'en_ligne',
          }
        : {
            libelle: '',
            espace: espaceId || espaces[0]?.id || '',
            servedSpaces: [],
            itboxRef: '',
            etat: 'en_ligne',
          },
    })
  }

  function ouvrirEquipement(ligne = null, controleurId = null) {
    setSucces(null)
    setEdition({
      genre: 'equipement',
      ligne,
      erreur: null,
      enCours: false,
      valeurs: ligne
        ? {
            libelle: ligne.libelle || '',
            controleur: idDe(ligne.controleur) || '',
            type: ligne.type || 'tourniquet',
            sens: ligne.sens || 'entree',
            // Trois états et non deux : `null` veut dire « hérite de l'espace », et c'est le
            // réglage le plus courant. Un booléen l'aurait écrasé en « désactivé ».
            antiPassback: ligne.antiPassbackActif === null || ligne.antiPassbackActif === undefined
              ? 'herite'
              : ligne.antiPassbackActif ? 'actif' : 'inactif',
            antiPassbackDelai: ligne.antiPassbackDelai ?? '',
            margeAvance: ligne.margeAvance ?? 0,
            margeRetard: ligne.margeRetard ?? 0,
          }
        : {
            libelle: '',
            controleur: controleurId || controleurs[0]?.id || '',
            type: 'tourniquet',
            sens: 'entree',
            antiPassback: 'herite',
            antiPassbackDelai: '',
            margeAvance: 0,
            margeRetard: 0,
          },
    })
  }

  const setValeurs = useCallback((fn) => {
    setEdition((s) => (s ? { ...s, valeurs: typeof fn === 'function' ? fn(s.valeurs) : fn } : s))
  }, [])

  async function enregistrer(e) {
    e.preventDefault()
    const { genre, ligne, valeurs } = edition
    setEdition((s) => ({ ...s, erreur: null, enCours: true }))
    try {
      if (genre === 'espace') {
        const corps = {
          libelle: valeurs.libelle,
          espaceSocle: `/api/espaces/${valeurs.espaceSocle}`,
          seuilFmi: Number(valeurs.seuilFmi) || 0,
          modeSeuil: valeurs.modeSeuil,
          preAlertePct: nombreOuNul(valeurs.preAlertePct),
          antiPassbackActif: !!valeurs.antiPassbackActif,
          antiPassbackDelai: Number(valeurs.antiPassbackDelai) || 300,
          recalageOuverture: valeurs.recalageOuverture,
          sousReseau: valeurs.sousReseau ? `/api/sous_reseaus/${valeurs.sousReseau}` : null,
        }
        if (ligne) await api.majEspaceAcces(ligne.id, corps)
        else await api.creerEspaceAcces(corps)
      } else if (genre === 'controleur') {
        const corps = {
          libelle: valeurs.libelle,
          espace: `/api/espace_acces/${valeurs.espace}`,
          // L'emplacement ne se répète pas dans les zones desservies : il est déjà ouvert, et l'y
          // remettre ferait lire « ouvre aussi sa propre zone », ce qui n'apprend rien.
          servedSpaces: (valeurs.servedSpaces || [])
            .filter((id) => id !== valeurs.espace)
            .map((id) => `/api/espace_acces/${id}`),
          itboxRef: valeurs.itboxRef,
          etat: valeurs.etat,
        }
        const enregistre = ligne ? await api.majControleur(ligne.id, corps) : await api.creerControleur(corps)

        // ⚠ CE CONTRÔLE RESTE, ALORS MÊME QUE LE DÉFAUT QUI L'A MOTIVÉ EST CORRIGÉ.
        //
        // Le 29/08, `PATCH /api/controleurs/{id}` avec les zones desservies répondait **200** et
        // rendait la collection **vide**. Cause trouvée en interrogeant l'inflecteur de Symfony, pas
        // en relisant le code : il ne singularise que le DERNIER mot, tirait `espacesDesservi` de
        // `servedSpaces`, et cherchait donc `addEspacesDesservi` quand l'entité déclarait
        // `addEspaceDesservi`. Les deux accesseurs existaient — c'est la rencontre des noms qui
        // manquait, et elle se produit dans une bibliothèque qu'on ne lit pas.
        //
        // La propriété s'appelle désormais `servedSpaces` : l'inflection en tire `servedSpace`, et
        // les noms se rencontrent. Un test passe par l'API — PATCH, relecture, puis retrait.
        //
        // ⚠ ON GARDE LE CONTRÔLE QUAND MÊME. C'est lui qui a rendu ce défaut visible au lieu de le
        // laisser passer pour un caprice, et rien ne garantit qu'un autre champ ne le refera pas :
        // un exploitant qui croit avoir ouvert le tourniquet aux abonnés de la salle laisserait des
        // gens devant une porte. Comparer ce qu'on a demandé à ce que le serveur rend coûte trois
        // lignes.
        const voulues = (corps.servedSpaces || []).map((iri) => iri.split('/').pop()).sort()
        const retenues = (enregistre?.servedSpaces || []).map((e) => idDe(e)).filter(Boolean).sort()
        if (voulues.length !== retenues.length || voulues.some((v, i) => v !== retenues[i])) {
          setAvertissement(
            'Le serveur a accepté le contrôleur mais n’a pas retenu les zones de « Ouvre aussi » : '
            + 'ce lecteur n’ouvre donc que son emplacement. C’est un défaut serveur connu, signalé — '
            + 'le reste de la fiche, lui, est bien enregistré.',
          )
        } else {
          setAvertissement(null)
        }
      } else {
        const corps = {
          libelle: valeurs.libelle,
          controleur: `/api/controleurs/${valeurs.controleur}`,
          type: valeurs.type,
          sens: valeurs.sens,
          antiPassbackActif: valeurs.antiPassback === 'herite' ? null : valeurs.antiPassback === 'actif',
          antiPassbackDelai: valeurs.antiPassback === 'actif' ? nombreOuNul(valeurs.antiPassbackDelai) : null,
          margeAvance: Number(valeurs.margeAvance) || 0,
          margeRetard: Number(valeurs.margeRetard) || 0,
        }
        if (ligne) await api.majEquipement(ligne.id, corps)
        else await api.creerEquipement(corps)
      }
      setEdition(null)
      setSucces(ligne ? 'Modification enregistrée.' : 'Ajout enregistré.')
      await charger(true)
    } catch (err) {
      setEdition((s) => ({ ...s, erreur: err.message || "L'enregistrement n'a pas abouti.", enCours: false }))
    }
  }

  const champsEspace = [
    {
      nom: 'libelle',
      libelle: 'Nom du point de contrôle',
      requis: true,
      exemple: 'Entrée principale',
      aide: 'Le nom que verront vos agents en supervision et dans le journal.',
    },
    {
      nom: 'espaceSocle',
      libelle: 'Lieu contrôlé',
      type: 'choix',
      requis: true,
      options: espacesSocle.map((s) => ({ valeur: s.id, libelle: s.type ? `${s.nom} — ${s.type}` : s.nom })),
      aide:
        'Un espace d’accès n’est pas un lieu : c’est le réglage d’accès d’un lieu existant. '
        + 'Les lieux se créent dans Paramètres › Établissement & entités › Espaces.',
    },
    {
      nom: 'seuilFmi',
      libelle: 'Seuil de fréquentation (FMI)',
      type: 'nombre',
      min: 0,
      aide: 'Nombre maximum de personnes présentes. 0 = aucun seuil suivi.',
    },
    {
      nom: 'modeSeuil',
      libelle: 'Au dépassement du seuil',
      type: 'choix',
      options: Object.entries(MODE_SEUIL).map(([valeur, libelle]) => ({ valeur, libelle })),
      aide: 'Blocage : les entrées sont refusées. Alerte seule : on laisse entrer et on signale.',
    },
    {
      nom: 'preAlertePct',
      libelle: 'Pré-alerte (% du seuil)',
      type: 'nombre',
      min: 0,
      max: 100,
      aide: 'Laisser vide pour ne pas être prévenu avant le seuil.',
    },
    {
      nom: 'antiPassbackActif',
      libelle: 'Anti-passback',
      type: 'bool',
      libelleCase: 'Refuser un second passage du même support avant le délai',
      aide: 'Empêche le prêt de badge : on repasse la carte par-dessus la barrière au suivant.',
    },
    {
      nom: 'antiPassbackDelai',
      libelle: 'Délai d’anti-passback (secondes)',
      type: 'nombre',
      min: 1,
      visible: (v) => !!v.antiPassbackActif,
      aide: '300 s = 5 minutes.',
    },
    {
      nom: 'recalageOuverture',
      libelle: 'À l’ouverture du site',
      type: 'choix',
      options: Object.entries(MODE_RECALAGE).map(([valeur, libelle]) => ({ valeur, libelle })),
      aide: 'Remise à zéro : le compteur de présence repart de 0 chaque jour. Report : on garde le résiduel.',
    },
    {
      nom: 'sousReseau',
      libelle: 'Sous-réseau',
      type: 'choix',
      options: [{ valeur: '', libelle: '— aucun —' }, ...reseaux.map((r) => ({ valeur: r.id, libelle: r.libelle }))],
      aide: 'Pour mutualiser une jauge ou un anti-passback entre plusieurs espaces.',
    },
  ]

  const champsControleur = [
    { nom: 'libelle', libelle: 'Nom du contrôleur', requis: true, exemple: 'Portique nord' },
    // OÙ IL EST, PUIS CE QU'IL OUVRE — DEUX QUESTIONS, DEUX CHAMPS, ET C'EST LE VOCABULAIRE QUI
    // DOIT LES SÉPARER.
    //
    // Le serveur porte les deux notions et elles ne se remplacent pas : l'emplacement est la porte
    // PHYSIQUE — c'est sa jauge qui se décrémente, son anti-passback qui s'applique, et c'est lui
    // qui s'inscrit sur le passage, même quand le titre est accepté au titre d'une autre zone. Les
    // zones desservies n'élargissent que la DÉCISION.
    //
    // « Emplacement » et « Ouvre aussi » disent cette différence sans paragraphe : le premier
    // répond à « où est ce lecteur », le second à « qui peut y passer ». Un exploitant qui veut
    // deux jauges distinctes a besoin de deux lecteurs, et l'aide du second champ le dit.
    {
      nom: 'espace',
      libelle: 'Emplacement — la porte physique',
      type: 'choix',
      requis: true,
      options: espaces.map((e) => ({ valeur: e.id, libelle: e.libelle })),
      aide:
        'La zone où se trouve ce matériel. C’est SA jauge qui se décrémente et c’est elle qui '
        + 'figure au journal, quel que soit le titre présenté. Un contrôleur sans emplacement est '
        + 'refusé par le serveur.',
    },
    {
      nom: 'servedSpaces',
      libelle: 'Ouvre aussi',
      type: 'cases',
      options: espaces
        .filter((e) => e.id !== (edition?.valeurs?.espace ?? ''))
        .map((e) => ({ valeur: e.id, libelle: e.libelle })),
      siVide: 'Aucune autre zone d’accès à desservir sur ce site.',
      aide:
        'Les titres de ces zones-là sont acceptés ici — le cas type est le tourniquet placé entre '
        + 'la piscine et la salle de sport. Cela n’ajoute aucune jauge : la fréquentation reste '
        + 'comptée sur l’emplacement. Pour deux jauges distinctes, il faut deux lecteurs.',
    },
    {
      nom: 'itboxRef',
      libelle: 'Référence ITBOX',
      requis: true,
      exemple: 'ITBOX-01',
      aide:
        'La référence du concentrateur qui pilote ce contrôleur. C’est elle qui relie le matériel '
        + 'au terminal enrôlé — plusieurs contrôleurs peuvent partager le même ITBOX.',
    },
    {
      nom: 'etat',
      libelle: 'État',
      type: 'choix',
      options: Object.entries(ETAT_CONTROLEUR).map(([valeur, libelle]) => ({ valeur, libelle })),
      aide:
        'En ligne et hors ligne se règlent tout seuls au fil des synchronisations. « Hors service » '
        + 'est le seul état qui se décide ici : c’est le matériel qu’on retire du jeu.',
    },
  ]

  const champsEquipement = [
    { nom: 'libelle', libelle: 'Nom de l’équipement', requis: true, exemple: 'Tourniquet A' },
    {
      nom: 'controleur',
      libelle: 'Contrôleur',
      type: 'choix',
      requis: true,
      options: controleurs.map((c) => ({ valeur: c.id, libelle: c.libelle })),
      aide: 'Un équipement orphelin est refusé par le serveur.',
    },
    {
      nom: 'type',
      libelle: 'Type',
      type: 'choix',
      options: Object.entries(TYPE_EQUIPEMENT).map(([valeur, libelle]) => ({ valeur, libelle })),
    },
    {
      nom: 'sens',
      libelle: 'Sens',
      type: 'choix',
      options: Object.entries(SENS_EQUIPEMENT).map(([valeur, libelle]) => ({ valeur, libelle })),
      aide: 'Le sens décide de l’effet sur la jauge : une entrée incrémente, une sortie décrémente.',
    },
    {
      nom: 'antiPassback',
      libelle: 'Anti-passback',
      type: 'choix',
      options: [
        { valeur: 'herite', libelle: 'Hériter de l’espace' },
        { valeur: 'actif', libelle: 'Forcer actif ici' },
        { valeur: 'inactif', libelle: 'Désactiver ici' },
      ],
      aide: 'Sur une sortie de secours, on désactive souvent ce que l’espace impose.',
    },
    {
      nom: 'antiPassbackDelai',
      libelle: 'Délai local (secondes)',
      type: 'nombre',
      min: 1,
      visible: (v) => v.antiPassback === 'actif',
      aide: 'Laisser vide pour garder le délai de l’espace.',
    },
    {
      nom: 'margeAvance',
      libelle: 'Tolérance d’avance (minutes)',
      type: 'nombre',
      min: 0,
      aide: 'Combien de temps avant son créneau un porteur est accepté à cet équipement.',
    },
    {
      nom: 'margeRetard',
      libelle: 'Tolérance de retard (minutes)',
      type: 'nombre',
      min: 0,
      aide: 'La tolérance de l’équipement borne localement ; la fenêtre du droit reste la référence.',
    },
  ]

  const champsCourants =
    edition?.genre === 'espace' ? champsEspace : edition?.genre === 'controleur' ? champsControleur : champsEquipement

  const titreForm = edition
    ? `${edition.ligne ? 'Modifier' : 'Ajouter'} — ${
        edition.genre === 'espace' ? 'espace d’accès' : edition.genre === 'controleur' ? 'contrôleur' : 'équipement'
      }`
    : ''

  const enLigne = controleurs.filter((c) => c.etat === 'en_ligne').length
  const horsService = controleurs.filter((c) => c.etat === 'hors_service').length
  // « 1 sur 1 en ligne » est un chiffre rassurant qui peut être entièrement faux : l'état est
  // déclaré, le signe de vie est constaté. Quand les deux divergent, le compteur doit le dire.
  const muets = controleurs.filter((c) => signeDeVie(c).suspect).length

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Topologie &amp; passages</h1>
          <p>Le plan du contrôle d’accès — espaces, contrôleurs, équipements — et le journal complet</p>
        </div>
        <div className="actions">
          <span className="hint" style={{ margin: 0 }}>{maj ? `Actualisé à ${maj.toLocaleTimeString('fr-FR')}` : ''}</span>
          <button className={`btn${auto ? ' primary' : ''}`} onClick={() => setAuto((v) => !v)} disabled={sessionPerdue}>
            {auto ? '⏸ Auto' : '▶ Auto'}
          </button>
          <button className="btn" onClick={() => charger()}>↻ Rafraîchir</button>
        </div>
      </div>

      {/* Les trois cas se disent avec trois phrases différentes — voir l'en-tête du fichier. */}
      {!etabActif && (
        <div className="banner banner-error">
          Aucun établissement actif. Choisissez un site en haut de l’écran : sans lui, le serveur rend
          une liste <strong>vide</strong> et non une erreur, et cet écran aurait l’air d’une installation neuve.
        </div>
      )}

      {sessionPerdue && (
        <div className="banner banner-error">
          Votre session a expiré : l’actualisation automatique est arrêtée et les données affichées
          datent de {maj ? maj.toLocaleTimeString('fr-FR') : 'la dernière lecture'}. Reconnectez-vous
          pour retrouver l’écran à jour.
        </div>
      )}

      {echecs.length > 0 && !sessionPerdue && (
        <div className="banner banner-error">
          {echecs.map((e) => (
            <div key={e.nom}>
              <strong>{e.nom}</strong> : {e.statut === 403
                ? 'droits insuffisants sur cet établissement — cette partie reste vide.'
                : e.message}
            </div>
          ))}
        </div>
      )}

      {tronques.length > 0 && (
        <div className="banner" style={{ background: 'var(--warn-bg)', color: 'var(--warn)' }}>
          Listes reçues incomplètes :{' '}
          {tronques.join(' · ')}. Le plan ci-dessous est donc incomplet.
        </div>
      )}

      {succes && <div className="banner banner-ok">{succes}</div>}
      {avertissement && (
        <div className="banner" style={{ background: 'var(--warn-bg)', color: 'var(--warn)' }}>
          {avertissement}
        </div>
      )}

      <Tabs onglets={ONGLETS} actif={onglet} onChange={setOnglet} />

      {onglet === 'plan' && (
        <>
          <div className="grid g4" style={{ marginBottom: 16 }}>
            <div className="kpi">
              <div className="lbl">Espaces d’accès</div>
              <div className="val">{espaces.length}</div>
            </div>
            <div className="kpi">
              <div className="lbl">Contrôleurs en ligne</div>
              <div className="val">
                {enLigne}
                <span style={{ fontSize: 15, color: 'var(--ink-faint)' }}> / {controleurs.length}</span>
              </div>
              {muets > 0 && (
                <div style={{ color: 'var(--warn)' }}>
                  dont {muets} sans signe de vie récent
                </div>
              )}
            </div>
            <div className="kpi">
              <div className="lbl">Hors service</div>
              <div className="val" style={{ color: horsService ? 'var(--crit)' : undefined }}>{horsService}</div>
            </div>
            <div className="kpi">
              <div className="lbl">Équipements</div>
              <div className="val">{equipements.length}</div>
            </div>
          </div>

          <section className="card">
            <div className="card-h">
              <h3>Plan du site</h3>
              <div className="r" style={{ marginLeft: 'auto' }}>
                {peutGerer && (
                  <button className="btn primary sm" onClick={() => ouvrirEspace(null)} disabled={espacesSocle.length === 0}>
                    ＋ Espace d’accès
                  </button>
                )}
              </div>
            </div>
            <div className="card-b">
              <p className="hint" style={{ marginTop: 0 }}>
                Trois niveaux : un <strong>espace d’accès</strong> porte le seuil de fréquentation et
                l’anti-passback ; un <strong>contrôleur</strong> est le boîtier qui décide ; un{' '}
                <strong>équipement</strong> est le tourniquet ou le lecteur qu’on franchit.{' '}
                Les heures d’ouverture, elles, se règlent{' '}
                {/* Un renvoi qu'on ne peut pas suivre est une devinette : l'écran nomme la
                    destination ET y emmène. Sans `onNav`, la phrase reste, sans le lien. */}
                {onNav ? (
                  <button className="lnk" type="button" onClick={() => onNav('parametres')}>
                    dans Paramètres › Horaires d’ouverture
                  </button>
                ) : (
                  'dans Paramètres › Horaires d’ouverture'
                )}{' '}
                — un passage peut y être refusé sans que rien ici ne le dise.
              </p>

              {chargement ? (
                <div className="center" style={{ minHeight: 160 }}><div className="spinner" /></div>
              ) : espaces.length === 0 ? (
                <div className="empty" style={{ padding: 18 }}>
                  <div style={{ marginBottom: 10 }}>
                    {etabActif
                      ? 'Aucun espace d’accès configuré sur ce site. Tant qu’il n’y en a pas, aucun tourniquet ne peut être déclaré et aucun passage ne peut être rattaché à un lieu.'
                      : 'Sélectionnez un établissement pour voir sa topologie.'}
                    {espacesSocle.length === 0 && etabActif && (
                      <div style={{ marginTop: 8 }}>
                        Commencez par créer un lieu dans <strong>Paramètres › Établissement &amp; entités ›
                        Espaces</strong> : un espace d’accès se rattache toujours à un lieu existant.
                      </div>
                    )}
                  </div>
                  {peutGerer && espacesSocle.length > 0 && etabActif && (
                    <button className="btn primary sm" onClick={() => ouvrirEspace(null)}>＋ Créer le premier</button>
                  )}
                </div>
              ) : (
                parEspace.noeuds.map(({ espace, controleurs: liste }) => (
                  <div key={espace.id} className="card" style={{ marginBottom: 14 }}>
                    <div className="card-h">
                      <h3>{espace.libelle}</h3>
                      <span className="sub">{nomEspaceSocle(espace)}</span>
                      <div className="r" style={{ marginLeft: 'auto', display: 'flex', gap: 6 }}>
                        <button
                          className="btn ghost sm"
                          onClick={() => {
                            setCibleJournal({ espace: espace.id, etab: etabActif })
                            setOnglet('journal')
                          }}
                        >
                          Passages
                        </button>
                        {peutGerer && (
                          <>
                            <button className="btn ghost sm" onClick={() => ouvrirEspace(espace)}>Modifier</button>
                            <button className="btn ghost sm" onClick={() => ouvrirControleur(null, espace.id)}>
                              ＋ Contrôleur
                            </button>
                          </>
                        )}
                      </div>
                    </div>
                    <div className="card-b">
                      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 10 }}>
                        <span className={`badge ${espace.seuilFmi > 0 ? 'warn' : 'mut'}`}>
                          {espace.seuilFmi > 0
                            ? `Seuil ${espace.seuilFmi} · ${MODE_SEUIL[espace.modeSeuil] || espace.modeSeuil}`
                            : 'Aucun seuil de fréquentation'}
                        </span>
                        {espace.preAlertePct ? <span className="badge mut">Pré-alerte à {espace.preAlertePct} %</span> : null}
                        <span className={`badge ${espace.antiPassbackActif ? 'good' : 'mut'}`}>
                          {espace.antiPassbackActif
                            ? `Anti-passback ${duree(espace.antiPassbackDelai)}`
                            : 'Anti-passback désactivé'}
                        </span>
                        <span className="badge mut">
                          Ouverture : {MODE_RECALAGE[espace.recalageOuverture] || espace.recalageOuverture}
                        </span>
                        {nomReseau(espace) && <span className="badge mut">Sous-réseau : {nomReseau(espace)}</span>}
                      </div>

                      {liste.length === 0 ? (
                        <div className="hint">
                          Aucun contrôleur sur cet espace : rien n’y décide encore d’un passage.
                        </div>
                      ) : (
                        liste.map(({ controleur, equipements: eqs }) => (
                          <div key={controleur.id} style={{ borderTop: '1px solid var(--line)', paddingTop: 10, marginTop: 10 }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                              <strong className="nm">{controleur.libelle}</strong>
                              <span className={`badge ${ETAT_CLS[controleur.etat] || 'mut'}`}>
                                {ETAT_CONTROLEUR[controleur.etat] || controleur.etat}
                              </span>
                              <span className="mut">ITBOX {texteOuTiret(controleur.itboxRef)}</span>
                              {zonesDesservies(controleur, espaces).length > 0 && (
                                <span
                                  className="badge mut"
                                  title="Les titres de ces zones sont acceptés ici. La fréquentation, elle, reste comptée sur l’emplacement."
                                >
                                  ouvre aussi : {zonesDesservies(controleur, espaces).join(', ')}
                                </span>
                              )}
                              {(() => {
                                const vie = signeDeVie(controleur)
                                return vie.suspect ? (
                                  <span className={`badge ${vie.cls}`} title={horodate(controleur.dernierHeartbeat)}>
                                    {vie.texte}
                                  </span>
                                ) : (
                                  <span className="mut" title={horodate(controleur.dernierHeartbeat)}>· {vie.texte}</span>
                                )
                              })()}
                              <span className="mut" title="Version de la liste de révocation embarquée par ce contrôleur">
                                · révocations v{controleur.versionRevocation ?? 0}
                              </span>
                              {peutGerer && (
                                <span style={{ marginLeft: 'auto', display: 'flex', gap: 6 }}>
                                  <button className="btn ghost sm" onClick={() => ouvrirControleur(controleur)}>Modifier</button>
                                  <button className="btn ghost sm" onClick={() => ouvrirEquipement(null, controleur.id)}>
                                    ＋ Équipement
                                  </button>
                                </span>
                              )}
                            </div>

                            {eqs.length === 0 ? (
                              <div className="hint" style={{ marginTop: 6 }}>
                                Aucun équipement : ce contrôleur ne pilote encore aucun passage physique.
                              </div>
                            ) : (
                              <table className="tbl" style={{ marginTop: 8 }}>
                                <thead>
                                  <tr>
                                    <th>Équipement</th>
                                    <th>Type</th>
                                    <th>Sens</th>
                                    <th>Anti-passback</th>
                                    <th className="num">Avance</th>
                                    <th className="num">Retard</th>
                                    {peutGerer && <th />}
                                  </tr>
                                </thead>
                                <tbody>
                                  {eqs.map((q) => (
                                    <tr key={q.id}>
                                      <td className="nm">{q.libelle}</td>
                                      <td>{TYPE_EQUIPEMENT[q.type] || q.type}</td>
                                      <td>{SENS_EQUIPEMENT[q.sens] || q.sens}</td>
                                      <td>
                                        {q.antiPassbackActif === null || q.antiPassbackActif === undefined ? (
                                          <span className="mut">hérité de l’espace</span>
                                        ) : q.antiPassbackActif ? (
                                          `forcé ${duree(q.antiPassbackDelai ?? espace.antiPassbackDelai)}`
                                        ) : (
                                          <span className="mut">désactivé ici</span>
                                        )}
                                      </td>
                                      <td className="num">{q.margeAvance ?? 0} min</td>
                                      <td className="num">{q.margeRetard ?? 0} min</td>
                                      {peutGerer && (
                                        <td className="num">
                                          <button className="btn ghost sm" onClick={() => ouvrirEquipement(q)}>Modifier</button>
                                        </td>
                                      )}
                                    </tr>
                                  ))}
                                </tbody>
                              </table>
                            )}
                          </div>
                        ))
                      )}
                    </div>
                  </div>
                ))
              )}

              {/* Un contrôleur dont l'espace n'est pas dans la liste chargée n'est pas un contrôleur
                  sans espace : c'est presque toujours une liste arrivée coupée. Le cacher ferait
                  disparaître du matériel réel du plan. */}
              {parEspace.orphelins.length > 0 && (
                <div className="banner" style={{ background: 'var(--warn-bg)', color: 'var(--warn)' }}>
                  {parEspace.orphelins.length} contrôleur(s) rattachés à un espace absent de cette page :{' '}
                  {parEspace.orphelins.map((o) => o.controleur.libelle).join(', ')}. Probablement l’effet
                  de la troncature ci-dessus, pas une topologie cassée.
                </div>
              )}
            </div>
          </section>
        </>
      )}

      {onglet === 'lecteurs' && (
        <Lecteurs
          equipements={equipements}
          controleurs={controleurs}
          espaces={espaces}
          peutGerer={peutGerer}
          chargement={chargement}
          onEditer={(q) => ouvrirEquipement(q)}
          onAjouter={() => ouvrirEquipement(null)}
          onVoirPassages={(q) => {
            setCibleJournal({ equipement: q.id, etab: etabActif })
            setOnglet('journal')
          }}
        />
      )}

      {onglet === 'reseaux' && (
        <SousReseaux
          reseaux={reseaux}
          espaces={espaces}
          peutGerer={peutGerer}
          chargement={chargement}
          onChange={() => charger(true)}
        />
      )}

      {onglet === 'journal' && (
        <JournalPassages
          // ⚠ UNE REMISE À ZÉRO PAR EFFET NE SUFFISAIT PAS, ET L'ÉCRAN L'A MONTRÉ.
          //
          // Premier essai : `key={etabActif}` pour remonter le journal, plus un effet qui vidait la
          // cible au changement de site. Le filtre « Lecteur » restait pourtant posé sur un
          // tourniquet de l'autre site — parce que l'effet s'exécute APRÈS le rendu : le journal
          // remontait d'abord avec l'ancienne cible, la reposait, et le vidage arrivait ensuite,
          // sans plus rien à vider.
          //
          // La cible porte donc son établissement, et n'est lue que si c'est encore le bon. Une
          // dérivation n'a pas d'ordre d'exécution : elle est vraie au moment du rendu.
          key={etabActif}
          espaces={espaces}
          equipements={equipements}
          etabActif={etabActif}
          cible={cibleJournal?.etab === etabActif ? cibleJournal : null}
        />
      )}

      {edition && (
        <FormulaireTopologie
          open
          titre={titreForm}
          champs={champsCourants}
          valeurs={edition.valeurs}
          setValeurs={setValeurs}
          onSubmit={enregistrer}
          onClose={() => setEdition(null)}
          erreur={edition.erreur}
          enCours={edition.enCours}
        />
      )}
    </div>
  )
}

// ─────────────────────────────────────────────────────────────────────────────────────────────
// LECTEURS — LA MÊME INSTALLATION, LUE PAR LE MATÉRIEL.
//
// Le plan du site part des zones, parce que c'est là que se règlent les seuils. Mais quand on
// installe, quand on dépanne, ou quand on reçoit un ticket « le lecteur 3 ne passe plus », on ne
// pense pas en zones : on pense en lecteurs. Les deux vues montrent les mêmes objets ; c'est la
// question posée qui change.
//
// CE QUE CETTE VUE REND VISIBLE ET QUE L'ARBRE CACHE :
//
//  — la référence ITBOX de chaque lecteur, qui est ce qu'on lit sur le matériel et dans les
//    échanges avec l'installateur. Elle n'est PAS portée par l'équipement : elle vit sur son
//    contrôleur, et le serveur ne l'embarque pas dans `equipement:read` (les groupes de
//    `Controleur` n'exposent que l'identifiant et le libellé). On la recroise donc côté client.
//  — l'anti-passback EFFECTIF, c'est-à-dire ce qui s'appliquera vraiment : la surcharge de
//    l'équipement quand elle existe, la valeur de la zone sinon. Lire « hérité » dans une colonne
//    ne dit pas ce qui va se passer ; lire « 5 min (de la zone) », si.
//
// ⚠ UN LECTEUR N'APPARTIENT AUJOURD'HUI QU'À UNE SEULE ZONE, et ce n'est pas un choix d'écran :
// c'est le modèle. Un `Equipement` a un `Controleur`, un `Controleur` a un `EspaceAcces` — la zone
// se déduit par transitivité, elle ne se choisit pas. La colonne le dit plutôt que de laisser croire
// à une liste. Rattacher un lecteur à plusieurs zones demande une relation qui n'existe pas encore.
function Lecteurs({ equipements, controleurs, espaces, peutGerer, chargement, onEditer, onAjouter, onVoirPassages }) {
  const [recherche, setRecherche] = useState('')

  const lignes = useMemo(() => {
    const parId = new Map(controleurs.map((c) => [c.id, c]))
    const zonesParId = new Map(espaces.map((e) => [e.id, e]))
    return equipements.map((q) => {
      const controleur = parId.get(idDe(q.controleur)) || null
      const zone = controleur ? zonesParId.get(idDe(controleur.espace)) || null : null
      return { equipement: q, controleur, zone }
    })
  }, [equipements, controleurs, espaces])

  const filtrees = useMemo(() => {
    const q = recherche.trim().toLowerCase()
    if (!q) return lignes
    return lignes.filter((l) =>
      [l.equipement.libelle, l.controleur?.libelle, l.controleur?.itboxRef, l.zone?.libelle]
        .filter(Boolean)
        .some((v) => String(v).toLowerCase().includes(q)),
    )
  }, [lignes, recherche])

  return (
    <section className="card">
      <div className="card-h">
        <h3>Lecteurs</h3>
        <div className="r" style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
          <input
            className="input"
            style={{ maxWidth: 260 }}
            placeholder="Nom, ITBOX, zone…"
            value={recherche}
            onChange={(e) => setRecherche(e.target.value)}
            aria-label="Rechercher un lecteur"
          />
          {peutGerer && (
            <button className="btn primary sm" onClick={onAjouter} disabled={controleurs.length === 0}>＋ Lecteur</button>
          )}
        </div>
      </div>
      <div className="card-b">
        <p className="hint" style={{ marginTop: 0 }}>
          Chaque lecteur est piloté par un contrôleur, lui-même rattaché à un ITBOX et à une zone.
          L’état affiché est celui du contrôleur : c’est lui qui parle au réseau, pas le lecteur.
        </p>

        {chargement ? (
          <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
        ) : lignes.length === 0 ? (
          <div className="empty" style={{ padding: 18 }}>
            <div style={{ marginBottom: 10 }}>
              {controleurs.length === 0
                ? 'Aucun contrôleur déclaré : un lecteur se rattache toujours à un contrôleur, commencez par le plan du site.'
                : 'Aucun lecteur déclaré. Tant qu’il n’y en a pas, l’ITBOX n’a rien à qui rapporter un scan.'}
            </div>
            {peutGerer && controleurs.length > 0 && (
              <button className="btn primary sm" onClick={onAjouter}>＋ Déclarer le premier</button>
            )}
          </div>
        ) : filtrees.length === 0 ? (
          <div className="empty" style={{ padding: 18 }}>Aucun lecteur ne correspond à « {recherche} ».</div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Lecteur</th>
                  <th>Type</th>
                  <th title="La porte physique : c’est sa jauge qui se décrémente.">Emplacement</th>
                  <th title="Les titres de ces zones sont acceptés ici, sans changer la jauge.">Ouvre aussi</th>
                  <th>Contrôleur · ITBOX</th>
                  <th>État</th>
                  <th>Sens</th>
                  <th>Anti-passback effectif</th>
                  <th className="num">Tolérances</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {filtrees.map(({ equipement: q, controleur, zone }) => {
                  const surcharge = q.antiPassbackActif !== null && q.antiPassbackActif !== undefined
                  const actif = surcharge ? q.antiPassbackActif : zone?.antiPassbackActif
                  const delai = surcharge ? (q.antiPassbackDelai ?? zone?.antiPassbackDelai) : zone?.antiPassbackDelai
                  return (
                    <tr key={q.id}>
                      <td className="nm">{q.libelle}</td>
                      <td>{TYPE_EQUIPEMENT[q.type] || q.type}</td>
                      <td>{zone ? zone.libelle : <span className="mut">zone hors de cette page</span>}</td>
                      <td>
                        {/* « — » veut dire « rien d'autre », pas « rien » : le lecteur ouvre
                            toujours son emplacement, colonne d'à côté. */}
                        {zonesDesservies(controleur, espaces).join(', ') || <span className="mut">—</span>}
                      </td>
                      <td>
                        {controleur ? (
                          <>
                            {controleur.libelle}
                            <span className="mut"> · {texteOuTiret(controleur.itboxRef)}</span>
                          </>
                        ) : (
                          <span className="mut">contrôleur hors de cette page</span>
                        )}
                      </td>
                      <td>
                        {controleur ? (
                          <>
                            <span
                              className={`badge ${ETAT_CLS[controleur.etat] || 'mut'}`}
                              title={horodate(controleur.dernierHeartbeat)}
                            >
                              {ETAT_CONTROLEUR[controleur.etat] || controleur.etat}
                            </span>
                            {/* L'état déclaré et le signe de vie sont deux informations distinctes :
                                affichées ensemble, elles se contredisent au lieu de se confirmer, et
                                c'est cette contradiction qui doit sauter aux yeux. */}
                            <div className={signeDeVie(controleur).suspect ? undefined : 'mut'}>
                              {signeDeVie(controleur).texte}
                            </div>
                          </>
                        ) : (
                          '—'
                        )}
                      </td>
                      <td>{SENS_EQUIPEMENT[q.sens] || q.sens}</td>
                      <td>
                        {actif === undefined ? (
                          <span className="mut">—</span>
                        ) : actif ? (
                          <>
                            {duree(delai)}
                            <span className="mut">{surcharge ? ' (propre au lecteur)' : ' (de la zone)'}</span>
                          </>
                        ) : (
                          <span className="mut">désactivé{surcharge ? ' ici' : ' sur la zone'}</span>
                        )}
                      </td>
                      <td className="num">
                        +{q.margeAvance ?? 0} / −{q.margeRetard ?? 0} min
                      </td>
                      <td className="num">
                        <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                          <button className="btn ghost sm" onClick={() => onVoirPassages(q)}>Passages</button>
                          {peutGerer && (
                            <button className="btn ghost sm" onClick={() => onEditer(q)}>Modifier</button>
                          )}
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </section>
  )
}

// ─────────────────────────────────────────────────────────────────────────────────────────────
// SOUS-RÉSEAUX — la reconnaissance mutuelle entre espaces.
//
// Un sous-réseau réunit plusieurs espaces sous une même jauge agrégée et un même anti-passback : le
// cas type est le complexe aquatique dont le bassin et l'espace bien-être partagent une capacité
// réglementaire. Sans écran, le champ existait et ne se réglait nulle part.
function SousReseaux({ reseaux, espaces, peutGerer, chargement, onChange }) {
  const [edition, setEdition] = useState(null)
  const [avertissement, setAvertissement] = useState(null)

  function ouvrir(ligne = null) {
    setEdition({
      ligne,
      erreur: null,
      enCours: false,
      valeurs: ligne
        ? {
            libelle: ligne.libelle || '',
            actif: !!ligne.actif,
            espaces: (ligne.espaces || []).map((e) => idDe(e)).filter(Boolean),
            seuilFmiAgrege: ligne.seuilFmiAgrege ?? '',
            antiPassbackDelai: ligne.antiPassbackDelai ?? '',
          }
        : { libelle: '', actif: false, espaces: [], seuilFmiAgrege: '', antiPassbackDelai: '' },
    })
  }

  async function enregistrer(e) {
    e.preventDefault()
    const { ligne, valeurs } = edition
    setEdition((s) => ({ ...s, erreur: null, enCours: true }))
    try {
      const corps = {
        libelle: valeurs.libelle,
        actif: !!valeurs.actif,
        espaces: (valeurs.espaces || []).map((id) => `/api/espace_acces/${id}`),
        seuilFmiAgrege: nombreOuNul(valeurs.seuilFmiAgrege),
        antiPassbackDelai: nombreOuNul(valeurs.antiPassbackDelai),
      }
      const enregistre = ligne ? await api.majSousReseau(ligne.id, corps) : await api.creerSousReseau(corps)

      // ⚠ LE SERVEUR ACCEPTE LES ESPACES RÉUNIS ET NE LES GARDE PAS. VÉRIFIÉ, PAS SUPPOSÉ.
      //
      // `PATCH /api/sous_reseaus/{id}` avec `espaces: [IRI, IRI]` répond **200** et rend
      // `espaces: []`. Cause : `SousReseau` déclare `addEspace()` mais AUCUN `removeEspace()`, et le
      // sérialiseur de Symfony ne considère une collection comme modifiable que si l'ajout ET le
      // retrait existent. Sans les deux, il ignore la propriété — sans erreur, sans avertissement.
      //
      // C'est le pire des cas pour un écran : le geste réussit, le message dit « enregistré », et la
      // configuration ne change pas. Un sous-réseau sans espaces ne fait rien du tout ; l'exploitant
      // croirait avoir mutualisé une jauge qui ne l'est pas. On compare donc ce qu'on a demandé à ce
      // que le serveur rend, et on le dit. Le jour où `removeEspace()` existe, ce contrôle devient
      // silencieux tout seul — il n'y aura rien à retirer.
      const voulus = (valeurs.espaces || []).map(String).sort()
      const retenus = (enregistre?.espaces || []).map((e) => idDe(e)).filter(Boolean).sort()
      const perdus = voulus.length !== retenus.length || voulus.some((v, i) => v !== retenus[i])

      setEdition(null)
      await onChange()
      if (perdus) {
        setAvertissement(
          voulus.length === 0
            ? null
            : 'Le serveur a accepté l’enregistrement mais n’a pas retenu les espaces réunis : le '
              + 'sous-réseau reste donc sans effet. C’est un défaut serveur (la collection n’est pas '
              + 'modifiable faute de méthode de retrait), il est signalé — le reste de la fiche, lui, '
              + 'est bien enregistré.',
        )
      } else {
        setAvertissement(null)
      }
    } catch (err) {
      setEdition((s) => ({ ...s, erreur: err.message || "L'enregistrement n'a pas abouti.", enCours: false }))
    }
  }

  const champs = [
    { nom: 'libelle', libelle: 'Nom du sous-réseau', requis: true, exemple: 'Complexe aquatique' },
    {
      nom: 'actif',
      libelle: 'Actif',
      type: 'bool',
      libelleCase: 'Appliquer la jauge et l’anti-passback communs',
      aide: 'Tant qu’il est inactif, le sous-réseau est une simple étiquette : chaque espace garde ses propres règles.',
    },
    {
      nom: 'espaces',
      libelle: 'Espaces réunis',
      type: 'cases',
      options: espaces.map((e) => ({ valeur: e.id, libelle: e.libelle })),
      siVide: 'Aucun espace d’accès à réunir : commencez par le plan du site.',
    },
    {
      nom: 'seuilFmiAgrege',
      libelle: 'Jauge commune',
      type: 'nombre',
      min: 0,
      aide: 'Nombre maximum de personnes pour l’ensemble des espaces réunis. Vide = chacun garde la sienne.',
    },
    {
      nom: 'antiPassbackDelai',
      libelle: 'Anti-passback commun (secondes)',
      type: 'nombre',
      min: 1,
      aide: 'Empêche de ressortir d’un espace pour rentrer aussitôt dans un autre du même réseau. Vide = pas de règle commune.',
    },
  ]

  return (
    <section className="card">
      <div className="card-h">
        <h3>Sous-réseaux</h3>
        {peutGerer && (
          <div className="r" style={{ marginLeft: 'auto' }}>
            <button className="btn primary sm" onClick={() => ouvrir(null)}>＋ Sous-réseau</button>
          </div>
        )}
      </div>
      <div className="card-b">
        <p className="hint" style={{ marginTop: 0 }}>
          Un sous-réseau réunit plusieurs espaces sous une même jauge et un même anti-passback — le cas
          type est le complexe où le bassin et l’espace bien-être partagent une capacité réglementaire.
        </p>
        {avertissement && (
          <div className="banner" style={{ background: 'var(--warn-bg)', color: 'var(--warn)' }}>
            {avertissement}
          </div>
        )}
        {chargement ? (
          <div className="center" style={{ minHeight: 100 }}><div className="spinner" /></div>
        ) : reseaux.length === 0 ? (
          <div className="empty" style={{ padding: 18 }}>
            <div style={{ marginBottom: 10 }}>
              Aucun sous-réseau. Ce n’est pas un manque : tant que chaque espace se suffit à lui-même,
              on n’en a pas besoin.
            </div>
            {peutGerer && espaces.length > 0 && (
              <button className="btn primary sm" onClick={() => ouvrir(null)}>＋ Créer le premier</button>
            )}
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Sous-réseau</th>
                  <th>État</th>
                  <th>Espaces réunis</th>
                  <th className="num">Jauge commune</th>
                  <th className="num">Anti-passback</th>
                  {peutGerer && <th />}
                </tr>
              </thead>
              <tbody>
                {reseaux.map((r) => {
                  const ids = (r.espaces || []).map((e) => idDe(e))
                  const noms = ids.map((id) => espaces.find((e) => e.id === id)?.libelle).filter(Boolean)
                  return (
                    <tr key={r.id}>
                      <td className="nm">{r.libelle}</td>
                      <td>
                        <span className={`badge ${r.actif ? 'good' : 'mut'}`}>{r.actif ? 'Actif' : 'Inactif'}</span>
                      </td>
                      <td>
                        {ids.length === 0
                          ? '—'
                          : noms.length === ids.length
                            ? noms.join(', ')
                            : `${noms.join(', ')}${noms.length ? ' · ' : ''}${ids.length - noms.length} hors de cette page`}
                      </td>
                      <td className="num">{r.seuilFmiAgrege ?? '—'}</td>
                      <td className="num">{duree(r.antiPassbackDelai)}</td>
                      {peutGerer && (
                        <td className="num">
                          <button className="btn ghost sm" onClick={() => ouvrir(r)}>Modifier</button>
                        </td>
                      )}
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {edition && (
        <FormulaireTopologie
          open
          titre={edition.ligne ? 'Modifier — sous-réseau' : 'Ajouter — sous-réseau'}
          champs={champs}
          valeurs={edition.valeurs}
          setValeurs={(fn) =>
            setEdition((s) => ({ ...s, valeurs: typeof fn === 'function' ? fn(s.valeurs) : fn }))
          }
          onSubmit={enregistrer}
          onClose={() => setEdition(null)}
          erreur={edition.erreur}
          enCours={edition.enCours}
        />
      )}
    </section>
  )
}

// ─────────────────────────────────────────────────────────────────────────────────────────────
// JOURNAL DES PASSAGES (A-05) — ce que le contrôle d'accès a fait, et pourquoi.
//
// La supervision montre les vingt derniers passages, sans filtre et sans mémoire. Le serveur, lui,
// porte des filtres (espace, contrôleur, équipement, résultat, période) et une opération d'export
// que personne n'atteignait. Un refus se comprend en regardant les vingt qui l'entourent, pas
// l'instant.
function JournalPassages({ espaces, equipements, etabActif, cible }) {
  const [filtres, setFiltres] = useState({ depuis: '', jusqua: '', espace: '', equipement: '', resultat: '', billet: '' })
  const [lignes, setLignes] = useState([])
  const [total, setTotal] = useState(null)
  const [chargement, setChargement] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [info, setInfo] = useState(null)
  const [exportEnCours, setExportEnCours] = useState(false)

  const charger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const query = { itemsPerPage: 100, 'order[horodatage]': 'desc' }
      if (filtres.depuis) query['horodatage[after]'] = filtres.depuis
      if (filtres.jusqua) query['horodatage[before]'] = `${filtres.jusqua}T23:59:59`
      if (filtres.espace) query.espace = filtres.espace
      if (filtres.equipement) query.equipement = filtres.equipement
      if (filtres.resultat) query.resultat = filtres.resultat
      // ⚠ RECHERCHE PAR NUMÉRO DE BILLET : LE FILTRE EST `exact`, PAS UNE RECHERCHE PARTIELLE.
      //
      // `#[ApiFilter(SearchFilter::class, properties: ['support.identifiant' => 'exact'])]` — un
      // numéro tronqué ne rend donc RIEN, et rien se lit « ce billet n'est jamais passé », qui est
      // la pire réponse possible à un client qui affirme le contraire. D'où le libellé du champ et
      // la phrase du résultat vide.
      if (filtres.billet.trim()) query['support.identifiant'] = filtres.billet.trim()
      const reponse = await api.journalPassages(query)
      // Voir la note en tête de fichier : le tri demandé au serveur est ignoré, on trie ce qu'on a.
      const recus = membres(reponse).sort((a, b) => (a.horodatage < b.horodatage ? 1 : -1))
      setLignes(recus)
      setTotal(reponse?.totalItems ?? reponse?.['hydra:totalItems'] ?? null)
    } catch (e) {
      setErreur(e.message || 'Journal indisponible.')
      setLignes([])
      setTotal(null)
    } finally {
      setChargement(false)
    }
  }, [filtres])

  // Une cible venue du plan ou de la liste des lecteurs pose le filtre correspondant. Elle ne
  // remplace pas les filtres de période déjà saisis : on vient voir CE lecteur-là sur la fenêtre
  // qu'on regardait, pas repartir de zéro.
  useEffect(() => {
    if (!cible) return
    setFiltres((f) => ({ ...f, espace: cible.espace || '', equipement: cible.equipement || '' }))
  }, [cible])

  useEffect(() => {
    charger()
  }, [etabActif, charger])

  async function exporter() {
    setExportEnCours(true)
    setErreur(null)
    setInfo(null)
    try {
      // L'export a SES PROPRES NOMS DE PARAMÈTRES (`depuis`/`jusqua`), différents de ceux de la
      // collection filtrée (`horodatage[after]`/`[before]`) : c'est un provider écrit à la main, pas
      // le filtre standard. Réutiliser les noms de l'écran de liste rendrait un export non filtré
      // qui a l'air filtré.
      const query = {}
      if (filtres.depuis) query.depuis = filtres.depuis
      if (filtres.jusqua) query.jusqua = `${filtres.jusqua} 23:59:59`
      if (filtres.espace) query.espace = filtres.espace
      if (filtres.equipement) query.equipement = filtres.equipement
      if (filtres.resultat) query.resultat = filtres.resultat
      const reponse = await api.exportPassages(query)
      const tout = membres(reponse)

      // ⚠ CE FILTRE N'EST PLUS UN PANSEMENT, C'EST UN TÉMOIN DE RÉGRESSION — ET LA DIFFÉRENCE A
      // UNE DATE.
      //
      // Il a été écrit le 28/08 parce que `PassageExportProvider` construisait son propre
      // QueryBuilder : le cloisonnement (`PerimetreAccesExtension`) ne s'applique qu'aux collections
      // servies par le provider standard, donc l'export rendait les passages de TOUS les sites. La
      // borne serveur est arrivée le 29/08 (`b2b5acc`) ; ce provider porte désormais son
      // `IDENTITY(p.etablissement) = :export_etablissement`.
      //
      // Le filtre écarte donc zéro ligne à chaque appel, et un contournement mort se fait retirer
      // par le prochain lecteur qui croit nettoyer — ou garder sans que personne ne sache pourquoi.
      // On le garde pour une raison qui, elle, vaut au présent : **il mesure que la borne tient.**
      // Si `ecartes` repasse au-dessus de zéro, c'est que quelqu'un a défait `b2b5acc`, et cet écran
      // est le seul endroit du produit qui le verrait.
      //
      // ⚠ ET LE ZÉRO DOIT ÊTRE UN ZÉRO MESURÉ, PAS UN ZÉRO PAR CONSTRUCTION. La version précédente
      // écrivait `etabActif ? filtrer : tout` : sans établissement actif, elle ne comparait rien et
      // rendait « 0 écartée », c'est-à-dire un satisfecit obtenu en ne regardant pas. Même chose si
      // le serveur cesse d'exposer `etablissement` sur les lignes. On exige donc de pouvoir
      // comparer, et on distingue « rien à signaler » de « je n'ai pas pu vérifier ».
      const peutComparer =
        Boolean(etabActif) && tout.length > 0 && tout.every((p) => idDe(p.etablissement))
      const aNous = peutComparer ? tout.filter((p) => idDe(p.etablissement) === etabActif) : tout
      const ecartes = peutComparer ? tout.length - aNous.length : null

      // Le provider d'export ne connaît PAS le numéro de billet : il lit `depuis`, `jusqua`,
      // `espace`, `equipement`, `resultat`, et rien d'autre. Envoyer le filtre du journal produirait
      // un fichier de TOUS les passages sous un nom qui promet un billet précis. On coupe donc ici,
      // sur la donnée déjà reçue.
      const filtrees = filtres.billet.trim()
        ? aNous.filter((p) => p.support?.identifiant === filtres.billet.trim())
        : aNous

      if (filtrees.length === 0) {
        setInfo('Aucun passage à exporter pour ces filtres.')
        return
      }
      telechargerCsv(filtrees, espaces, equipements)
      setInfo(
        ecartes === null
          ? `${filtrees.length} passage(s) exportés. Le cloisonnement de l’export n’a PAS pu être vérifié ici (établissement actif ou champ « etablissement » absent des lignes) : ce n’est pas un satisfecit.`
          : ecartes > 0
          ? `⚠ ${filtrees.length} passage(s) exportés, mais ${ecartes} ligne(s) rendues par le serveur appartiennent à un AUTRE établissement et ont été écartées ici. La borne de cloisonnement de l’export a régressé côté serveur — signalez-le, le fichier remis serait autrement celui du site voisin.`
          : `${filtrees.length} passage(s) exportés.`,
      )
    } catch (e) {
      setErreur(e.message || "L'export n'a pas abouti.")
    } finally {
      setExportEnCours(false)
    }
  }

  const majFiltre = (nom) => (e) => setFiltres((s) => ({ ...s, [nom]: e.target.value }))

  return (
    <section className="card">
      <div className="card-h">
        <h3>Journal des passages</h3>
        {total !== null && lignes.length < total && (
          <span
            className="badge warn"
            title="Cette liste est arrivée incomplète, et le serveur ne sait pas la trier : ce sont les plus anciennes."
          >
            {lignes.length} sur {total}
          </span>
        )}
        <div className="r" style={{ marginLeft: 'auto', display: 'flex', gap: 6 }}>
          <button className="btn ghost sm" onClick={charger} disabled={chargement}>↻</button>
          <button className="btn sm" onClick={exporter} disabled={exportEnCours}>
            {exportEnCours ? 'Export…' : '⤓ Exporter (CSV)'}
          </button>
        </div>
      </div>
      <div className="card-b">
        <p className="hint" style={{ marginTop: 0 }}>
          Le journal est en lecture seule : un passage ne se corrige pas, il se relit. L’export porte
          sur <strong>tous</strong> les passages qui répondent aux filtres, pas seulement sur les
          lignes affichées.
        </p>

        <div className="grid g4" style={{ gap: 10, marginBottom: 12 }}>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="j-depuis">Du</label>
            <input id="j-depuis" className="input" type="date" value={filtres.depuis} onChange={majFiltre('depuis')} />
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="j-jusqua">Au</label>
            <input id="j-jusqua" className="input" type="date" value={filtres.jusqua} onChange={majFiltre('jusqua')} />
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="j-espace">Espace</label>
            <select id="j-espace" className="select" value={filtres.espace} onChange={majFiltre('espace')}>
              <option value="">Tous</option>
              {espaces.map((e) => (
                <option key={e.id} value={e.id}>{e.libelle}</option>
              ))}
            </select>
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="j-resultat">Résultat</label>
            <select id="j-resultat" className="select" value={filtres.resultat} onChange={majFiltre('resultat')}>
              <option value="">Tous</option>
              {Object.entries(RESULTAT_PASSAGE).map(([v, l]) => (
                <option key={v} value={v}>{l}</option>
              ))}
            </select>
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="j-billet">N° de billet ou de badge (exact)</label>
            <input
              id="j-billet"
              className="input"
              value={filtres.billet}
              onChange={majFiltre('billet')}
              placeholder="Le numéro complet"
            />
          </div>
        </div>

        {/* UN FILTRE QU'ON NE VOIT PAS EST UN PIÈGE : sans cette étiquette, on lit « aucun passage »
            en croyant regarder tout le site alors qu'on ne regarde qu'un tourniquet. Le sélecteur
            d'espace est visible juste au-dessus ; celui d'équipement n'existe pas, d'où l'étiquette. */}
        {filtres.equipement && (
          <div className="row" style={{ gap: 8, marginBottom: 10, flexWrap: 'wrap' }}>
            <span className="badge mut">
              Lecteur : {equipements.find((q) => q.id === filtres.equipement)?.libelle || 'sélectionné'}
            </span>
            <button
              className="btn ghost sm"
              type="button"
              onClick={() => setFiltres((f) => ({ ...f, equipement: '' }))}
            >
              Retirer ce filtre
            </button>
          </div>
        )}

        {erreur && <div className="banner banner-error">{erreur}</div>}
        {info && <div className="banner banner-ok">{info}</div>}
        {total !== null && lignes.length < total && (
          <div className="banner" style={{ background: 'var(--warn-bg)', color: 'var(--warn)' }}>
            Cette liste est arrivée incomplète, et le serveur ne sait pas la trier : ce sont les{' '}
            <strong>{lignes.length} plus anciennes</strong> des {total} qui répondent à ces filtres, et
            non les plus récentes. Restreignez la période pour voir ce qui vous intéresse — l’export,
            lui, porte bien sur la totalité.
          </div>
        )}

        {chargement ? (
          <div className="center" style={{ minHeight: 140 }}><div className="spinner" /></div>
        ) : lignes.length === 0 ? (
          <div className="empty" style={{ padding: 18 }}>
            {filtres.billet.trim() ? (
              <>
                Aucun passage pour le numéro « {filtres.billet.trim()} ». La recherche porte sur le
                numéro <strong>complet</strong> : un numéro tronqué ou approché ne rend rien, ce qui
                ne veut pas dire que ce billet n’est jamais passé. Vérifiez le numéro avant de
                répondre au client.
              </>
            ) : filtres.depuis || filtres.jusqua || filtres.espace || filtres.resultat || filtres.equipement ? (
              'Aucun passage ne répond à ces filtres.'
            ) : (
              'Aucun passage enregistré sur ce site. Le journal se remplit tout seul dès qu’un équipement lit un support.'
            )}
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Horodatage</th>
                  <th>Espace</th>
                  <th>Équipement</th>
                  <th>Sens</th>
                  <th>Résultat</th>
                  <th>Support</th>
                  <th>Pourquoi</th>
                </tr>
              </thead>
              <tbody>
                {lignes.map((p) => (
                  <tr key={p.id}>
                    <td>{horodate(p.horodatage)}</td>
                    <td>{p.espace?.libelle || '—'}</td>
                    <td>
                      {p.equipement?.libelle || <span className="mut">sans équipement</span>}
                      {p.controleur?.libelle ? <span className="mut"> · {p.controleur.libelle}</span> : null}
                    </td>
                    <td>{SENS_PASSAGE[p.sens] || p.sens}</td>
                    <td>
                      <span className={`badge ${RESULTAT_CLS[p.resultat] || 'mut'}`}>
                        {RESULTAT_PASSAGE[p.resultat] || p.resultat}
                      </span>
                      {p.origineHorsLigne && <span className="badge mut" title="Enregistré hors ligne puis synchronisé">hors ligne</span>}
                      {p.enConflit && <span className="badge crit" title="Conflit détecté à la réconciliation">conflit</span>}
                    </td>
                    <td>{p.support?.identifiant || <span className="mut">non nominatif</span>}</td>
                    {/* `codeMotif` est le code machine, `motif` la phrase saisie ou calculée par le
                        moteur. On affiche LA PHRASE DE L'EXPLOITANT quand le code est connu, la
                        phrase du serveur en dessous, et le code brut en dernier recours : un code
                        qu'on n'a pas traduit ici vaut mieux affiché tel quel qu'escamoté. */}
                    <td>{cellulePourquoi(p)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </section>
  )
}

// Ce qu'on met dans la colonne « Pourquoi » d'un passage.
//
// Trois sources, dans cet ordre : la traduction de `codeMotif` (une phrase pour l'exploitant, plus
// le geste à faire), puis `motif` (la phrase du moteur, souvent plus précise sur le cas), puis le
// code brut si on ne le connaît pas encore. Un code non traduit s'affiche tel quel : le masquer
// ferait disparaître le seul indice d'un refus qu'on n'a pas prévu.
function cellulePourquoi(p) {
  const connu = p.codeMotif ? MOTIF_REFUS[p.codeMotif] : null
  if (!connu && !p.motif && !p.codeMotif) return '—'
  return (
    <>
      {connu && (
        <div title={connu.geste ? `${connu.quoi}\n\nQue faire : ${connu.geste}` : connu.quoi}>
          <strong>{connu.libelle}</strong>
        </div>
      )}
      {connu ? <div className="mut">{connu.quoi}</div> : null}
      {p.motif && p.motif !== connu?.quoi ? <div className="mut">{p.motif}</div> : null}
      {!connu && p.codeMotif ? <div className="mut">code : {p.codeMotif}</div> : null}
      {connu?.geste ? <div className="hint" style={{ margin: 0 }}>{connu.geste}</div> : null}
    </>
  )
}

// Le point-virgule et le BOM ne sont pas des détails : sans eux, le fichier s'ouvre en une seule
// colonne dans un tableur français et les accents sortent en mojibake — l'export a l'air cassé alors
// que la donnée est juste.
function telechargerCsv(passages, espaces, equipements) {
  const enTetes = ['Horodatage', 'Espace', 'Controleur', 'Equipement', 'Sens', 'Resultat', 'Support', 'Motif', 'Hors ligne', 'En conflit']
  const echappe = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`
  const nomEspace = (p) => p.espace?.libelle || espaces.find((e) => e.id === idDe(p.espace))?.libelle || ''
  const nomEquipement = (p) => p.equipement?.libelle || equipements.find((e) => e.id === idDe(p.equipement))?.libelle || ''
  const lignes = passages.map((p) =>
    [
      horodate(p.horodatage),
      nomEspace(p),
      p.controleur?.libelle || '',
      nomEquipement(p),
      SENS_PASSAGE[p.sens] || p.sens || '',
      RESULTAT_PASSAGE[p.resultat] || p.resultat || '',
      p.support?.identifiant || '',
      // Le fichier porte la phrase de l'exploitant, pas le code machine : un CSV se relit loin de
      // l'application, par quelqu'un qui n'a pas la table sous les yeux.
      [MOTIF_REFUS[p.codeMotif]?.libelle, p.motif || (MOTIF_REFUS[p.codeMotif] ? '' : p.codeMotif)]
        .filter(Boolean)
        .join(' — '),
      p.origineHorsLigne ? 'oui' : 'non',
      p.enConflit ? 'oui' : 'non',
    ]
      .map(echappe)
      .join(';'),
  )
  const contenu = `﻿${enTetes.map(echappe).join(';')}\n${lignes.join('\n')}\n`
  const url = URL.createObjectURL(new Blob([contenu], { type: 'text/csv;charset=utf-8' }))
  const a = document.createElement('a')
  a.href = url
  a.download = `passages-${new Date().toISOString().slice(0, 10)}.csv`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}
