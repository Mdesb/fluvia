import { useCallback, useEffect, useRef, useState } from 'react'
import Qr from '../components/Qr.jsx'
import { printThermal } from '../components/thermalPrint.js'
import { issuerLines, passesToPrint, printedDateTime, vatRows } from '../api/receiptPrint.js'
import { deviceStore, kioskApi, newKey } from './kioskApi.js'
import {
  COLLECT_RETURN_SECONDS,
  STAFF_HOLD_MS,
  STEPS,
  STEP_LABELS,
  canRecharge,
  cartCount,
  cartItems,
  cartTotalCents,
  changeQuantity,
  euros,
  isBlockedKey,
  labelOf,
  normalizeCardCode,
  offersFor,
  paymentOutcome,
} from './kioskFlow.js'

// LA BORNE AUTOMATIQUE DE VENTE (#341) — l'écran de l'usager, servi sous `/borne`.
//
// ── UNE APPLICATION À PART, ET C'EST LE PREMIER VERROU ─────────────────────────────────────────
//
// `Root.jsx` charge cette branche SEULE : le paquet de la borne ne contient ni le back-office, ni
// ses écrans, ni son client d'API. Aucun lien n'en sort. Et le jeton de l'appareil n'ouvre que
// `/api/kiosk/…` (pare-feu dédié) : même un navigateur dont on sortirait ne lirait rien d'autre.
//
// ── LES VERROUS DE LA PAGE ──────────────────────────────────────────────────────────────────
//
// Plein écran redemandé au premier toucher et à chaque sortie, menu contextuel coupé, touches de
// sortie neutralisées (`isBlockedKey`), retour arrière piégé. Ce que la page ne peut pas fermer
// (Alt+F4, Ctrl+Alt+Suppr) se ferme au système : navigateur en mode kiosque et session à accès
// attribué — c'est dans l'aide d'installation, pas promis ici.
//
// ── LA SORTIE DU PERSONNEL ──────────────────────────────────────────────────────────────────
//
// Appui long de trois secondes dans le coin haut gauche, puis le code de sortie — vérifié par le
// SERVEUR (un code comparé dans la page se lirait dans son source), verrouillé après cinq échecs.

export default function KioskApp() {
  const [paired, setPaired] = useState(() => Boolean(deviceStore.get()))
  return paired
    ? <KioskScreen onUnpaired={() => setPaired(false)} />
    : <PairingScreen onPaired={() => setPaired(true)} />
}

// ── L'appairage : un agent saisit le code affiché dans Paramètres › Caisse › Bornes ─────────────
function PairingScreen({ onPaired }) {
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  async function pair(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const res = await kioskApi.pair(code)
      deviceStore.set(res.token)
      onPaired()
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <main className="kiosk kiosk-pairing">
      <h1 className="kiosk-title">Mise en service de la borne</h1>
      <p className="kiosk-lead">
        Saisissez le code d’appairage affiché dans <b>Paramètres › Caisse › Bornes</b>. Il est valable
        un quart d’heure et ne sert qu’une fois.
      </p>
      <form className="kiosk-pair-form" onSubmit={pair}>
        <input
          className="kiosk-input"
          value={code}
          onChange={(e) => setCode(e.target.value.toUpperCase())}
          placeholder="XXXX-XXXX"
          autoComplete="off"
          aria-label="Code d’appairage"
        />
        <button type="submit" className="kiosk-btn primary" disabled={busy || code.trim().length < 8}>
          {busy ? 'Appairage…' : 'Appairer cette borne'}
        </button>
      </form>
      {error && <div className="kiosk-alert" role="alert">{error}</div>}
    </main>
  )
}

function KioskScreen({ onUnpaired }) {
  const [config, setConfig] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [mode, setMode] = useState(null) // null = accueil · 'buy' · 'recharge'
  const [step, setStep] = useState('choose')
  const [cart, setCart] = useState({})
  const [cardCode, setCardCode] = useState('')
  const [order, setOrder] = useState(null)
  const [payment, setPayment] = useState(null) // { status, attempt, message }
  const [receipt, setReceipt] = useState(null)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [staff, setStaff] = useState(null) // null · 'keypad' · 'menu'
  const [maintenance, setMaintenance] = useState(false)

  const lastActivity = useRef(Date.now())
  const orderRef = useRef(null)
  orderRef.current = order

  const handleError = useCallback((err) => {
    if (err?.status === 401) {
      onUnpaired()
      return
    }
    setError(err?.message || 'Une erreur est survenue.')
  }, [onUnpaired])

  const loadConfig = useCallback(async () => {
    try {
      setConfig(await kioskApi.config())
      setLoadError(null)
    } catch (err) {
      if (err?.status === 401) onUnpaired()
      else setLoadError(err.message)
    }
  }, [onUnpaired])

  useEffect(() => { loadConfig() }, [loadConfig])

  // L'offre se relit à l'accueil, toutes les cinq minutes : un prix changé au back-office y arrive.
  useEffect(() => {
    if (mode !== null) return undefined
    const t = setInterval(loadConfig, 5 * 60 * 1000)
    return () => clearInterval(t)
  }, [mode, loadConfig])

  useKioskLock(!maintenance)

  const resetToHome = useCallback(async () => {
    const pending = orderRef.current
    setMode(null)
    setStep('choose')
    setCart({})
    setCardCode('')
    setOrder(null)
    setPayment(null)
    setReceipt(null)
    setError(null)
    setBusy(false)
    // Une commande ouverte et non payée se vide ; une commande payée, elle, est déjà délivrée ou
    // tenue par le terminal — le serveur refuse alors l'abandon, et c'est juste.
    if (pending && !pending.completed) {
      try {
        await kioskApi.abandon(pending.order)
      } catch {
        // Refusé (paiement en cours) ou réseau coupé : la commande reste ouverte, rien n'est délivré.
      }
    }
  }, [])

  // Inactivité : retour à l'accueil — sauf pendant que le terminal attend la carte de l'usager.
  useEffect(() => {
    const touch = () => { lastActivity.current = Date.now() }
    window.addEventListener('pointerdown', touch)
    window.addEventListener('keydown', touch)
    const idleMs = (config?.idleSeconds || 60) * 1000
    const t = setInterval(() => {
      if (mode === null || staff) return
      if (step === 'pay' && (busy || payment?.status === 'waiting')) return
      if (step === 'collect') return
      if (Date.now() - lastActivity.current > idleMs) resetToHome()
    }, 1000)
    return () => {
      window.removeEventListener('pointerdown', touch)
      window.removeEventListener('keydown', touch)
      clearInterval(t)
    }
  }, [config, mode, step, busy, payment, staff, resetToHome])

  // Après la remise, l'écran revient seul à l'accueil.
  useEffect(() => {
    if (step !== 'collect') return undefined
    const t = setTimeout(resetToHome, COLLECT_RETURN_SECONDS * 1000)
    return () => clearTimeout(t)
  }, [step, resetToHome])

  function start(nextMode) {
    lastActivity.current = Date.now()
    setError(null)
    setMode(nextMode)
    setStep('choose')
    setCart({})
    setCardCode('')
  }

  async function complete(current) {
    try {
      const res = await kioskApi.complete(current.order)
      setOrder({ ...current, completed: true })
      setReceipt(res)
      setStep('collect')
    } catch (err) {
      handleError(err)
    }
  }

  async function poll(current, attempt) {
    for (;;) {
      await new Promise((r) => setTimeout(r, 1500))
      let res
      try {
        res = await kioskApi.payment(current.order, attempt)
      } catch (err) {
        if (err?.status === 0) continue // réseau : on réessaie, l'issue est sur le serveur
        handleError(err)
        return
      }
      if (res.status !== 'waiting') {
        setPayment({ status: res.status, attempt })
        if (res.status === 'accepted') await complete(current)
        return
      }
    }
  }

  async function pay() {
    setBusy(true)
    setError(null)
    setStep('pay')
    try {
      let current = order
      if (!current) {
        current = await kioskApi.open(cartItems(cart), mode === 'recharge' ? normalizeCardCode(cardCode) : null)
        setOrder(current)
      }
      setPayment({ status: 'waiting' })
      const res = await kioskApi.pay(current.order, newKey())
      if (res.status === 'accepted') {
        setPayment({ status: 'accepted' })
        await complete(current)
      } else if (res.status === 'waiting') {
        setPayment({ status: 'waiting', attempt: res.attempt?.id })
        await poll(current, res.attempt?.id)
      } else {
        setPayment({ status: res.status })
      }
    } catch (err) {
      setPayment(null)
      if (!order) setStep('choose')
      handleError(err)
    } finally {
      setBusy(false)
    }
  }

  async function cancelPayment() {
    if (!order || !payment?.attempt) return
    try {
      const res = await kioskApi.cancelPayment(order.order, payment.attempt)
      setPayment({ status: res.status, attempt: payment.attempt })
    } catch (err) {
      handleError(err)
    }
  }

  if (!config) {
    return (
      <main className="kiosk kiosk-center">
        {loadError ? <div className="kiosk-alert">{loadError}</div> : <div className="spinner" />}
        <StaffCorner onOpen={() => setStaff('keypad')} />
        {staff && <StaffPanel staff={staff} setStaff={setStaff} setMaintenance={setMaintenance} onUnpaired={onUnpaired} />}
      </main>
    )
  }

  const offers = offersFor(mode, config.offers)
  const totalCents = cartTotalCents(cart, offers)

  return (
    <main className="kiosk">
      <StaffCorner onOpen={() => setStaff('keypad')} />
      <header className="kiosk-head">
        <div className="kiosk-brand">{config.establishment}</div>
        {mode !== null && <Steps current={step} />}
      </header>

      {mode === null && (
        <Home config={config} onBuy={() => start('buy')} onRecharge={() => start('recharge')} />
      )}

      {mode !== null && step === 'choose' && (
        <section className="kiosk-body">
          {mode === 'recharge' && (
            <CardReader value={cardCode} onChange={setCardCode} />
          )}
          <h2 className="kiosk-h2">{mode === 'recharge' ? 'Choisissez votre recharge' : 'Choisissez vos entrées'}</h2>
          <div className="kiosk-offers">
            {offers.map((o) => (
              <OfferTile
                key={o.offer}
                offer={o}
                quantity={cart[o.offer] || 0}
                single={mode === 'recharge'}
                onChange={(delta) => setCart(mode === 'recharge'
                  ? (delta > 0 ? { [o.offer]: 1 } : {})
                  : changeQuantity(cart, o.offer, delta))}
              />
            ))}
          </div>
          {error && <div className="kiosk-alert" role="alert">{error}</div>}
          <footer className="kiosk-foot">
            <button type="button" className="kiosk-btn ghost" onClick={resetToHome}>Retour</button>
            <button
              type="button"
              className="kiosk-btn primary big"
              disabled={busy || cartCount(cart) === 0 || (mode === 'recharge' && normalizeCardCode(cardCode) === '')}
              onClick={pay}
            >
              Payer {euros(totalCents, { cents: true })}
            </button>
          </footer>
        </section>
      )}

      {mode !== null && step === 'pay' && (
        <PayStep
          order={order}
          payment={payment}
          busy={busy}
          error={error}
          onCancel={cancelPayment}
          onRetry={pay}
          onAbandon={resetToHome}
        />
      )}

      {mode !== null && step === 'collect' && receipt && (
        <CollectStep receipt={receipt} recharge={mode === 'recharge'} onDone={resetToHome} />
      )}

      {staff && <StaffPanel staff={staff} setStaff={setStaff} setMaintenance={setMaintenance} onUnpaired={onUnpaired} />}
      {maintenance && (
        <div className="kiosk-maintenance">
          Mode maintenance : la borne n’est plus verrouillée.
          <button type="button" className="kiosk-btn primary" onClick={() => { setMaintenance(false); requestFullscreen() }}>
            Reprendre la borne
          </button>
        </div>
      )}
    </main>
  )
}

function Steps({ current }) {
  const index = STEPS.indexOf(current)
  return (
    <ol className="kiosk-steps" aria-label="Étapes">
      {STEPS.map((s, i) => (
        <li key={s} className={i === index ? 'on' : i < index ? 'done' : undefined} aria-current={i === index ? 'step' : undefined}>
          <span className="kiosk-step-n">{i + 1}</span> {STEP_LABELS[s]}
        </li>
      ))}
    </ol>
  )
}

function Home({ config, onBuy, onRecharge }) {
  if (!config.terminalReady) {
    return (
      <section className="kiosk-body kiosk-center">
        <h1 className="kiosk-title">Borne hors service</h1>
        <p className="kiosk-lead">Le paiement par carte n’est pas disponible. Adressez-vous à l’accueil.</p>
      </section>
    )
  }
  const recharge = canRecharge(config)
  return (
    <section className="kiosk-body kiosk-center">
      <h1 className="kiosk-title">Bienvenue</h1>
      <p className="kiosk-lead">Touchez ce que vous voulez faire. Paiement par carte bancaire.</p>
      <div className="kiosk-home">
        <button type="button" className="kiosk-choice" onClick={onBuy} disabled={config.offers.length === 0}>
          <span className="kiosk-choice-icon" aria-hidden="true">🎟</span>
          Acheter une entrée
        </button>
        {recharge && (
          <button type="button" className="kiosk-choice" onClick={onRecharge}>
            <span className="kiosk-choice-icon" aria-hidden="true">💳</span>
            Recharger ma carte
          </button>
        )}
      </div>
    </section>
  )
}

function OfferTile({ offer, quantity, single, onChange }) {
  return (
    <div className={`kiosk-offer${quantity > 0 ? ' on' : ''}`}>
      <div className="kiosk-offer-name">{offer.label}</div>
      <div className="kiosk-offer-tariff">{offer.tariffLabel}</div>
      <div className="kiosk-offer-price">{euros(offer.price)}</div>
      {single ? (
        <button type="button" className={`kiosk-btn ${quantity > 0 ? 'primary' : 'ghost'}`} onClick={() => onChange(quantity > 0 ? -1 : 1)}>
          {quantity > 0 ? 'Choisie' : 'Choisir'}
        </button>
      ) : (
        <div className="kiosk-qty">
          <button type="button" className="kiosk-qty-btn" onClick={() => onChange(-1)} disabled={quantity === 0} aria-label={`Retirer une ${offer.label}`}>−</button>
          <span className="kiosk-qty-n" aria-live="polite">{quantity}</span>
          <button type="button" className="kiosk-qty-btn" onClick={() => onChange(1)} aria-label={`Ajouter une ${offer.label}`}>+</button>
        </div>
      )}
    </div>
  )
}

// Le lecteur de carte (douchette ou lecteur de badge) se comporte comme un clavier : il « tape » le
// numéro puis Entrée. Le champ garde le focus pour le recevoir.
function CardReader({ value, onChange }) {
  const input = useRef(null)
  useEffect(() => { input.current?.focus() }, [])
  return (
    <div className="kiosk-card-reader">
      <h2 className="kiosk-h2">Présentez votre carte devant le lecteur</h2>
      <input
        ref={input}
        className="kiosk-input"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        onKeyDown={(e) => { if (e.key === 'Enter') e.preventDefault() }}
        placeholder="Numéro de la carte"
        autoComplete="off"
        aria-label="Numéro de la carte"
      />
      {normalizeCardCode(value) !== '' && <div className="kiosk-ok">Carte lue ✓</div>}
    </div>
  )
}

function PayStep({ order, payment, busy, error, onCancel, onRetry, onAbandon }) {
  const outcome = payment ? paymentOutcome(payment.status) : null
  return (
    <section className="kiosk-body kiosk-center">
      {order && <div className="kiosk-amount">{euros(order.total)}</div>}
      {(!payment || payment.status === 'waiting' || payment.status === 'accepted') && !error && (
        <>
          <h1 className="kiosk-title">Payez sur le terminal</h1>
          <p className="kiosk-lead">Insérez ou approchez votre carte bancaire du terminal, puis suivez ses instructions.</p>
          <div className="spinner" />
          {payment?.attempt && payment.status === 'waiting' && (
            <button type="button" className="kiosk-btn ghost" onClick={onCancel}>Annuler le paiement</button>
          )}
        </>
      )}
      {outcome && outcome.kind !== 'ok' && outcome.kind !== 'wait' && (
        <>
          <div className={outcome.kind === 'help' ? 'kiosk-alert' : 'kiosk-warn'} role="alert">{outcome.message}</div>
          {outcome.kind === 'help' && order && <div className="kiosk-ref">Vente n° {order.number}</div>}
          <div className="kiosk-foot">
            {outcome.kind === 'retry' && (
              <>
                <button type="button" className="kiosk-btn ghost" onClick={onAbandon} disabled={busy}>Abandonner</button>
                <button type="button" className="kiosk-btn primary big" onClick={onRetry} disabled={busy}>Réessayer</button>
              </>
            )}
            {outcome.kind === 'help' && (
              <button type="button" className="kiosk-btn primary" onClick={onAbandon}>Terminer</button>
            )}
          </div>
        </>
      )}
      {error && (
        <>
          <div className="kiosk-alert" role="alert">{error}</div>
          <button type="button" className="kiosk-btn primary" onClick={onAbandon}>Revenir à l’accueil</button>
        </>
      )}
    </section>
  )
}

function CollectStep({ receipt, recharge, onDone }) {
  const papers = useRef([])
  const ticket = receipt.ticket
  const lines = (ticket.lignes || []).map((l) => ({ ...l, libelle: labelOf(l.libelle) }))
  const passes = passesToPrint({ ...ticket, lignes: lines })
  const [qrReady, setQrReady] = useState(0)
  const printed = useRef(false)

  // On imprime quand tous les QR sont dessinés : un ticket imprimé avant porterait un carré vide.
  useEffect(() => {
    if (!receipt.printTicket || printed.current || qrReady < passes.length) return
    printed.current = true
    printThermal(papers.current.filter(Boolean), { titre: `Ticket ${ticket.numero}` })
  }, [receipt, qrReady, passes.length, ticket.numero])

  const badge = receipt.badge
  return (
    <section className="kiosk-body kiosk-center">
      <div className="kiosk-done" aria-hidden="true">✓</div>
      <h1 className="kiosk-title">
        {recharge ? 'Votre carte est rechargée' : badge?.status === 'issued' ? 'Prenez votre badge' : 'Prenez votre ticket'}
      </h1>
      {badge && badge.status !== 'issued' && badge.message && <p className="kiosk-lead">{badge.message}</p>}
      {receipt.printTicket && <p className="kiosk-lead">Le ticket sort de l’imprimante, sous l’écran.</p>}
      <button type="button" className="kiosk-btn primary big" onClick={onDone}>Terminer</button>

      {/* Le papier, hors écran : recopié seul sur le rouleau par `printThermal`. */}
      <div className="kiosk-paper" aria-hidden="true">
        <ReceiptPaper ticket={ticket} lines={lines} refFn={(el) => { papers.current[0] = el }} />
        {passes.map((p, i) => (
          <div key={p.code} className="ticket doc-imprimer" ref={(el) => { papers.current[i + 1] = el }}>
            <div className="tb">
              {p.lignes.map((l, j) => <div className="trow" key={j}><span>{l.quantite} × {l.libelle}</span></div>)}
              <div className="tqr">
                <Qr value={p.code} size={84} title={`QR billet ${ticket.numero}`} onReady={() => setQrReady((n) => n + 1)} />
                <div className="t-code mono">{p.code}</div>
              </div>
            </div>
          </div>
        ))}
      </div>
    </section>
  )
}

function ReceiptPaper({ ticket, lines, refFn }) {
  const issuer = issuerLines(ticket.emetteur)
  const vat = vatRows(ticket.ventilationTva)
  return (
    <div className="ticket doc-imprimer" ref={refFn}>
      <div className="tb">
        {issuer.length > 0 && (
          <div className="t-emetteur">{issuer.map((l, i) => <div key={i} className={i === 0 ? 't-etab' : undefined}>{l}</div>)}</div>
        )}
        <div className="t-date">{printedDateTime(ticket.date)}</div>
        <div className="tnum">Ticket {ticket.numero} · borne</div>
        {lines.map((l) => (
          <div className="trow" key={l.id}>
            <span>{l.quantite} × {l.libelle}</span>
            <span className="num">{euros(l.montantLigne)}</span>
          </div>
        ))}
        {ticket.duplicata && <div className="trow"><span><b>DUPLICATA</b> — ce ticket a déjà été édité.</span></div>}
        <div className="trow tt"><span>Total TTC</span><span className="num">{euros(ticket.total)}</span></div>
        {vat.length > 0 && (
          <div className="t-tva">
            {vat.map((r, i) => (
              <div className="trow" key={i}><span>{r.libelle}</span><span className="num">HT {euros(r.ht)} · TVA {euros(r.tva)}</span></div>
            ))}
          </div>
        )}
        <div className="trow"><span>Règlement · Carte bancaire</span><span className="num">{euros(ticket.total)}</span></div>
        {ticket.ticketClientTerminal && <div className="t-terminal">{ticket.ticketClientTerminal}</div>}
      </div>
    </div>
  )
}

// ── Le coin du personnel : appui long, puis code vérifié par le serveur ──────────────────────
function StaffCorner({ onOpen }) {
  const timer = useRef(null)
  const startHold = () => { timer.current = setTimeout(onOpen, STAFF_HOLD_MS) }
  const stopHold = () => clearTimeout(timer.current)
  return (
    <div
      className="kiosk-staff-corner"
      onPointerDown={startHold}
      onPointerUp={stopHold}
      onPointerLeave={stopHold}
      aria-hidden="true"
    />
  )
}

function StaffPanel({ staff, setStaff, setMaintenance, onUnpaired }) {
  const [code, setCode] = useState('')
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  async function unlock() {
    setBusy(true)
    setError(null)
    try {
      await kioskApi.unlock(code)
      setStaff('menu')
    } catch (err) {
      if (err?.status === 401) onUnpaired()
      setError(err.message)
      setCode('')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="kiosk-overlay" role="dialog" aria-modal="true" aria-label="Accès du personnel">
      <div className="kiosk-panel">
        {staff === 'keypad' ? (
          <>
            <h2 className="kiosk-h2">Code du personnel</h2>
            <div className="kiosk-code" aria-live="polite">{'•'.repeat(code.length) || ' '}</div>
            <div className="kiosk-keypad">
              {['1', '2', '3', '4', '5', '6', '7', '8', '9', '⌫', '0', 'OK'].map((k) => (
                <button
                  key={k}
                  type="button"
                  className="kiosk-key"
                  disabled={busy || (k === 'OK' && code.length < 4)}
                  onClick={() => {
                    if (k === '⌫') setCode(code.slice(0, -1))
                    else if (k === 'OK') unlock()
                    else if (code.length < 8) setCode(code + k)
                  }}
                >
                  {k}
                </button>
              ))}
            </div>
            {error && <div className="kiosk-alert" role="alert">{error}</div>}
            <button type="button" className="kiosk-btn ghost" onClick={() => setStaff(null)}>Fermer</button>
          </>
        ) : (
          <>
            <h2 className="kiosk-h2">Maintenance de la borne</h2>
            <button type="button" className="kiosk-btn primary" onClick={() => setStaff(null)}>Reprendre la vente</button>
            <button
              type="button"
              className="kiosk-btn ghost"
              onClick={() => { setStaff(null); setMaintenance(true); if (document.fullscreenElement) document.exitFullscreen().catch(() => {}) }}
            >
              Quitter le plein écran
            </button>
            <button
              type="button"
              className="kiosk-btn danger"
              onClick={() => { deviceStore.clear(); setStaff(null); onUnpaired() }}
            >
              Oublier cet appareil
            </button>
          </>
        )}
      </div>
    </div>
  )
}

function requestFullscreen() {
  const el = document.documentElement
  if (!document.fullscreenElement && el.requestFullscreen) el.requestFullscreen().catch(() => {})
}

/** Les verrous de la page, actifs hors maintenance. */
function useKioskLock(active) {
  useEffect(() => {
    if (!active) return undefined
    const onKey = (e) => {
      const inField = e.target instanceof HTMLInputElement
      if (isBlockedKey(e, inField)) {
        e.preventDefault()
        e.stopPropagation()
      }
    }
    const prevent = (e) => e.preventDefault()
    // Le retour arrière du navigateur (geste, bouton de souris) : on reste sur la borne.
    const trap = () => window.history.pushState({ kiosk: true }, '', window.location.href)
    // Le plein écran ne se demande que sur un geste de l'usager : le premier toucher le relance.
    const onTouch = () => requestFullscreen()

    trap()
    window.addEventListener('keydown', onKey, true)
    window.addEventListener('contextmenu', prevent)
    window.addEventListener('dragstart', prevent)
    window.addEventListener('popstate', trap)
    window.addEventListener('pointerdown', onTouch)
    return () => {
      window.removeEventListener('keydown', onKey, true)
      window.removeEventListener('contextmenu', prevent)
      window.removeEventListener('dragstart', prevent)
      window.removeEventListener('popstate', trap)
      window.removeEventListener('pointerdown', onTouch)
    }
  }, [active])
}
