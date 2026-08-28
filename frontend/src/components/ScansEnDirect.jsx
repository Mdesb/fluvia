import { useCallback, useEffect, useRef, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import RechercheBilletModal from './RechercheBilletModal.jsx'

// LES SCANS EN DIRECT, À LA CAISSE — PARCE QUE C'EST LÀ QUE LE CLIENT SE PLAINT.
//
// Quand un billet est refusé au tourniquet, la personne ne va pas voir l'écran de supervision : elle
// revient au guichet et dit « ça ne marche pas ». Le caissier, lui, n'a aucun moyen de savoir ce qui
// vient de se passer à trois mètres de lui. Il rouvre la vente, il téléphone, ou il laisse passer.
//
// Ce bandeau montre les derniers passages au fil de l'eau, avec le motif du refus en clair, et
// ouvre la fiche complète du billet en un clic.
//
// ─────────────────────────────────────────────────────────────────────────────────────────────
// QUATRE DÉCISIONS, TOUTES CONTRAINTES PAR CE QUE LE SERVEUR SAIT FAIRE
//
// 1. CE N'EST PAS UNE MODALE. Une fenêtre qui s'ouvre à chaque scan couperait la vente en cours —
//    et à l'ouverture des portes, ce serait plusieurs par minute. Un panneau qui s'empile dans le
//    coin se regarde quand on veut et ne prend la main jamais. Les refus s'y voient de loin ; les
//    passages acceptés restent discrets, parce qu'ils n'appellent aucun geste.
//
// 2. ON INTERROGE, ON NE REÇOIT PAS. Le projet n'a AUCUN canal temps réel : ni Mercure, ni SSE,
//    aucun `EventSource`. Un scan ne peut donc pas nous être poussé — on demande « quoi de neuf
//    depuis la dernière fois ? » toutes les quatre secondes. Ce n'est pas le montage qu'on
//    choisirait, c'est celui que le serveur permet aujourd'hui.
//
// 3. ON NE REDEMANDE PAS LA PAGE ENTIÈRE. `Passage` porte un `DateFilter` sur `horodatage` : on
//    garde l'horodatage du dernier passage vu et on demande `horodatage[strictly_after]`. En régime
//    normal la réponse est vide et ne coûte qu'un aller-retour ; sans ce filtre on retéléchargerait
//    les mêmes vingt lignes toute la journée.
//
//    ⚠ ET C'EST AUSSI CE QUI REND LA PERTE VISIBLE. Le serveur plafonne toute collection à 30 lignes
//    et ignore `itemsPerPage`. Si plus de 30 passages tombent entre deux interrogations, on en perd.
//    Avec le filtre par date on le SAIT (la page revient pleine) et on le dit ; sans lui, jamais.
//
// 4. ON S'ARRÊTE QUAND PERSONNE NE REGARDE. La caisse est l'écran qu'on laisse ouvert toute la
//    journée sur un poste partagé : à quatre secondes, cela ferait de l'ordre de vingt mille
//    requêtes par jour et par poste, dont la quasi-totalité pendant que l'onglet est en arrière-plan.
//    Le timer se met en pause sur `visibilitychange` et rattrape au retour avec le même filtre par
//    date — donc sans rien perdre.
//
// CE QUE CE BANDEAU NE PEUT PAS AFFICHER, ET POURQUOI CE N'EST PAS UN OUBLI.
//
// Le nom du produit et le tarif ne sont PAS dans la réponse : le groupe `passage:read` expose le
// support (son numéro), le droit (son identifiant et son type), l'espace, le contrôleur,
// l'équipement, le résultat et le motif — jamais le produit vendu ni son prix. Les afficher
// demanderait soit de les ajouter au groupe côté serveur, soit de croiser trois collections
// plafonnées à 30 lignes à chaque scan, ce qui donnerait un nom faux une fois sur deux.
//
// D'où le compromis : le bandeau montre ce qui est certain (numéro, lieu, résultat, pourquoi), et
// un clic ouvre la fiche du billet, qui va chercher le produit, le type et le solde. Le manque est
// nommé ici pour qu'il soit demandé au serveur plutôt que bricolé.

const RESULTAT = { valide: 'Validé', refuse: 'Refusé', compte: 'Compté' }
const RESULTAT_CLS = { valide: 'good', refuse: 'crit', compte: 'mut' }

// Même table que l'écran Topologie & passages, restreinte à ce qui sert au comptoir : ce que le
// caissier doit RÉPONDRE au client qui est devant lui.
const POURQUOI = {
  hors_marge: 'Hors créneau : trop tôt ou trop tard par rapport à son billet.',
  anti_passback: 'Badge déjà passé il y a moins que le délai anti-passback.',
  credit_epuise: 'Carte épuisée : plus d’entrée disponible.',
  support_bloque: 'Badge déclaré perdu ou volé, donc bloqué.',
  seuil_fmi: 'Jauge atteinte sur cet espace : les entrées sont refusées.',
  droit_invalide: 'Billet inconnu, non appairé, ou droit annulé.',
  sens_interdit: 'Ce lecteur n’accepte pas ce sens de passage.',
  non_nominatif: 'Comptage sans billet identifié.',
  ouverture_manuelle: 'Passage forcé par un agent.',
  federation_inactive: 'Billet d’un autre site : la reconnaissance mutuelle est inactive.',
  signature_invalide: 'Code du billet invalide ou falsifié.',
  hors_portee: 'Ce terminal n’a pas autorité sur cet équipement.',
  credit_epuise_hors_ligne_litige: 'Accepté hors ligne alors que la carte était épuisée.',
  hors_horaires_ouverture: 'Le site est fermé à cette heure.',
}

function heure(v) {
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

const CLE_ACTIF = 'billetterie.scans-en-direct'
const PERIODE_MS = 4000
const MAX_AFFICHES = 6

export default function ScansEnDirect({ droits = [], etabActif }) {
  // Sans `acces.lire`, chaque interrogation partirait pour un 403 toutes les quatre secondes. Un
  // caissier qui n'a pas le droit de lire les passages ne voit simplement pas ce bandeau.
  const peutLire = aLeDroit(droits, 'acces.lire')

  const [actif, setActif] = useState(() => {
    try {
      return localStorage.getItem(CLE_ACTIF) !== 'non'
    } catch {
      return true
    }
  })
  const [scans, setScans] = useState([])
  const [replie, setReplie] = useState(false)
  const [arrete, setArrete] = useState(null) // raison d'un arrêt : session perdue, droit refusé…
  const [trouEventuel, setTrouEventuel] = useState(false)
  const [billetOuvert, setBilletOuvert] = useState(null)
  const dernier = useRef(null) // horodatage ISO du dernier passage connu
  const amorce = useRef(false)

  useEffect(() => {
    try {
      localStorage.setItem(CLE_ACTIF, actif ? 'oui' : 'non')
    } catch {
      // Un navigateur qui refuse le stockage local ne doit pas empêcher le bandeau de fonctionner :
      // on perd la préférence, pas la fonction.
    }
  }, [actif])

  // Changer d'établissement remet le fil à zéro : les passages d'un autre site n'ont rien à faire
  // dans le bandeau, et l'horodatage de repère non plus.
  useEffect(() => {
    dernier.current = null
    amorce.current = false
    setScans([])
    setTrouEventuel(false)
    setArrete(null)
  }, [etabActif])

  const interroger = useCallback(async () => {
    try {
      const query = { 'order[horodatage]': 'desc' }
      if (dernier.current) query['horodatage[strictly_after]'] = dernier.current
      const recus = membres(await api.journalPassages(query))

      // ⚠ L'AMORÇAGE SE TERMINE MÊME QUAND LA RÉPONSE EST VIDE, ET C'EST TOUT LE POINT.
      //
      // Défaut trouvé en ouvrant l'écran, pas en le relisant : sur un site où AUCUN passage n'est
      // encore enregistré, la première lecture rend zéro ligne. Si on repart sans marquer l'amorçage,
      // le tout premier scan de la journée est pris pour de l'historique et avalé en silence — le
      // seul cas où ce bandeau devait servir, et le seul où il ne servait pas.
      const premiere = !amorce.current
      amorce.current = true

      if (recus.length === 0) return

      // Le plus récent d'abord : c'est l'ordre demandé au serveur, et c'est celui du bandeau.
      dernier.current = recus[0].horodatage

      // PREMIÈRE LECTURE NON VIDE : on prend le repère SANS annoncer les passages comme s'ils
      // venaient d'arriver. Ouvrir la caisse à 14 h et voir surgir le refus de 9 h 12 comme un
      // événement du moment ferait chercher un problème qui n'existe plus.
      if (premiere) return

      if (recus.length >= 30) setTrouEventuel(true)
      setScans((s) => [...recus, ...s].slice(0, MAX_AFFICHES))
    } catch (e) {
      // 401 : la session a expiré (le jeton vit une heure, sans rafraîchissement dans le projet).
      // On coupe le fil plutôt que de frapper toutes les quatre secondes dans le vide, et on le dit :
      // un bandeau qui se tait ressemble à un calme d'exploitation.
      if (e.status === 401) setArrete('Session expirée : le suivi des scans est arrêté.')
      else if (e.status === 403) setArrete('Ce compte n’a pas le droit de lire les passages.')
      else setArrete(e.message || 'Le suivi des scans est interrompu.')
    }
  }, [])

  useEffect(() => {
    if (!peutLire || !actif || arrete) return undefined
    let timer = null
    const battre = () => {
      if (document.visibilityState === 'visible') interroger()
    }
    battre()
    timer = setInterval(battre, PERIODE_MS)
    // Au retour d'onglet, on rattrape tout de suite : le filtre par date rend exactement ce qui
    // s'est passé pendant l'absence, sans doublon.
    const auRetour = () => {
      if (document.visibilityState === 'visible') interroger()
    }
    document.addEventListener('visibilitychange', auRetour)
    return () => {
      clearInterval(timer)
      document.removeEventListener('visibilitychange', auRetour)
    }
  }, [peutLire, actif, arrete, interroger])

  if (!peutLire) return null

  const refus = scans.filter((s) => s.resultat === 'refuse').length

  return (
    <>
      <div
        style={{
          position: 'fixed',
          right: 16,
          bottom: 16,
          width: replie ? 'auto' : 340,
          maxWidth: 'calc(100vw - 32px)',
          zIndex: 40,
        }}
      >
        <div className="card" style={{ margin: 0, boxShadow: '0 8px 28px rgba(0,0,0,.28)' }}>
          <div className="card-h" style={{ gap: 8 }}>
            <h3 style={{ fontSize: 14 }}>
              Scans en direct
              {refus > 0 && !replie ? <span className="badge crit" style={{ marginLeft: 8 }}>{refus} refus</span> : null}
            </h3>
            <div className="r" style={{ marginLeft: 'auto', display: 'flex', gap: 6 }}>
              <button
                className="btn ghost sm"
                onClick={() => setActif((v) => !v)}
                title={actif ? 'Suspendre le suivi' : 'Reprendre le suivi'}
              >
                {actif ? '⏸' : '▶'}
              </button>
              <button className="btn ghost sm" onClick={() => setReplie((v) => !v)} title={replie ? 'Déplier' : 'Replier'}>
                {replie ? '▴' : '▾'}
              </button>
            </div>
          </div>

          {!replie && (
            <div className="card-b" style={{ padding: 12 }}>
              {arrete && <div className="banner banner-error" style={{ margin: '0 0 8px' }}>{arrete}</div>}
              {!actif && !arrete && (
                <div className="hint" style={{ margin: 0 }}>
                  Suivi suspendu. Les passages continuent d’être enregistrés : ils sont dans
                  Topologie &amp; passages › Journal.
                </div>
              )}
              {trouEventuel && (
                <div className="banner" style={{ background: 'var(--warn-bg)', color: 'var(--warn)', margin: '0 0 8px' }}>
                  Beaucoup de passages d’un coup : le serveur en rend 30 au maximum, il peut en manquer
                  dans cette liste. Le journal, lui, est complet.
                </div>
              )}
              {actif && !arrete && scans.length === 0 && (
                <div className="hint" style={{ margin: 0 }}>
                  En attente d’un passage. Les scans apparaissent ici dès qu’un lecteur en signale un.
                </div>
              )}

              {scans.map((p) => {
                const refuse = p.resultat === 'refuse'
                return (
                  <div
                    key={p.id}
                    style={{
                      borderTop: '1px solid var(--line)',
                      padding: '8px 0',
                      display: 'flex',
                      flexDirection: 'column',
                      gap: 2,
                    }}
                  >
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                      <span className={`badge ${RESULTAT_CLS[p.resultat] || 'mut'}`}>
                        {RESULTAT[p.resultat] || p.resultat}
                      </span>
                      <span className="nm">{p.support?.identifiant || 'sans support'}</span>
                      <span className="mut" style={{ marginLeft: 'auto' }}>{heure(p.horodatage)}</span>
                    </div>
                    <div className="mut">
                      {p.espace?.libelle || '—'}
                      {p.equipement?.libelle ? ` · ${p.equipement.libelle}` : ''}
                    </div>
                    {/* Le pourquoi n'est affiché que quand il en faut un : sur un passage accepté, une
                        ligne de motif vide ne dit rien et pousse à chercher un problème. */}
                    {refuse && (
                      <div style={{ color: 'var(--crit)' }}>
                        {POURQUOI[p.codeMotif] || p.motif || 'Refusé, sans motif transmis.'}
                      </div>
                    )}
                    {p.support?.identifiant && (
                      <div>
                        <button
                          className="btn ghost sm"
                          onClick={() => setBilletOuvert(p.support.identifiant)}
                        >
                          Voir le billet
                        </button>
                      </div>
                    )}
                  </div>
                )
              })}
            </div>
          )}
        </div>
      </div>

      <RechercheBilletModal
        open={!!billetOuvert}
        numeroInitial={billetOuvert || ''}
        onClose={() => setBilletOuvert(null)}
      />
    </>
  )
}
