import { useEffect, useRef, useState } from 'react'

// Pad de signature manuscrite : capture au doigt/stylet/souris sur un canvas, rend le PNG encodé en
// base64 (sans le préfixe `data:`) via `onChange` à la fin de chaque trait. `onChange(null)` quand on
// efface. C'est cette image que le backend scelle (App\Signature) : elle lie la signature au geste.
//
// ⚠ `touchAction: none` sur le canvas : sans lui, un trait au doigt fait défiler la page au lieu de
// dessiner. Les évènements Pointer couvrent souris ET tactile d'un seul jeu de handlers.
export default function SignaturePad({ label, onChange }) {
  const canvasRef = useRef(null)
  const cbRef = useRef(onChange)
  cbRef.current = onChange
  const [signe, setSigne] = useState(false)

  useEffect(() => {
    const canvas = canvasRef.current
    if (!canvas) return undefined
    const ctx = canvas.getContext('2d')
    ctx.lineWidth = 2
    ctx.lineCap = 'round'
    ctx.lineJoin = 'round'
    ctx.strokeStyle = '#111'
    let dessine = false
    const point = (e) => {
      const r = canvas.getBoundingClientRect()
      // Le canvas est dessiné à sa taille interne (width/height) mais affiché à une autre (CSS) :
      // on rapporte la position à l'échelle interne, sinon le trait dérive de la pointe.
      return [((e.clientX - r.left) * canvas.width) / r.width, ((e.clientY - r.top) * canvas.height) / r.height]
    }
    const down = (e) => {
      dessine = true
      const [x, y] = point(e)
      ctx.beginPath()
      ctx.moveTo(x, y)
      try { canvas.setPointerCapture(e.pointerId) } catch { /* pas de capture : sans effet */ }
    }
    const move = (e) => {
      if (!dessine) return
      const [x, y] = point(e)
      ctx.lineTo(x, y)
      ctx.stroke()
    }
    const up = () => {
      if (!dessine) return
      dessine = false
      setSigne(true)
      cbRef.current?.(canvas.toDataURL('image/png').split(',')[1] || null)
    }
    canvas.addEventListener('pointerdown', down)
    canvas.addEventListener('pointermove', move)
    canvas.addEventListener('pointerup', up)
    return () => {
      canvas.removeEventListener('pointerdown', down)
      canvas.removeEventListener('pointermove', move)
      canvas.removeEventListener('pointerup', up)
    }
  }, [])

  const effacer = () => {
    const canvas = canvasRef.current
    if (canvas) canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height)
    setSigne(false)
    cbRef.current?.(null)
  }

  return (
    <div className="field" style={{ margin: 0 }}>
      <span className="sub">{label}</span>
      <canvas
        ref={canvasRef}
        width={480}
        height={160}
        style={{
          border: '1px solid var(--line)',
          borderRadius: '8px',
          touchAction: 'none',
          width: '100%',
          maxWidth: 480,
          background: '#fff',
          cursor: 'crosshair',
        }}
      />
      <div className="row" style={{ gap: 'var(--esp-serre)', alignItems: 'center' }}>
        <button type="button" className="btn ghost sm" onClick={effacer}>
          Effacer
        </button>
        <small className={signe ? 'sub' : 'crit'}>{signe ? 'Signé' : 'Non signé'}</small>
      </div>
    </div>
  )
}
