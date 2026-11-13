import QRCode from 'react-qr-code'

export default function MemberQRCode({ qrValue, size = 128 }) {
  // Keep the component simple and focused. Accepts `qrValue` so we can later
  // swap it for a secure token without changing call sites.
  const value = (qrValue || '').toString()

  return (
    <div className="inline-block rounded bg-white p-2">
      {/* react-qr-code renders an SVG; size prop controls width/height */}
      <div style={{ width: size, height: size }}>
        <QRCode value={value} size={size} />
      </div>
    </div>
  )
}
