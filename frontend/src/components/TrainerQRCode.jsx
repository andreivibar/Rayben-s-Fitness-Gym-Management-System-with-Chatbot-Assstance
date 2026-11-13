import { Link } from 'react-router-dom'
import { FaCamera, FaQrcode } from 'react-icons/fa'

export default function TrainerQRCode() {
  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="flex items-center gap-3">
        <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-100 text-violet-700"><FaQrcode /></span>
        <div>
          <p className="text-xs uppercase tracking-[0.24em] text-slate-500">Trainer attendance</p>
          <h2 className="mt-1 font-semibold text-slate-900">Use the shared gym QR code</h2>
        </div>
      </div>
      <p className="mt-4 text-sm leading-6 text-slate-600">
        Scan the current code displayed at the gym with your phone camera. Your signed-in trainer account is used to
        record attendance.
      </p>
      <Link to="/trainer/attendance/scan" className="mt-4 inline-flex min-h-[44px] items-center gap-2 rounded-xl bg-violet-700 px-4 py-2 text-sm font-medium text-white hover:bg-violet-800">
        <FaCamera /> Open QR scanner
      </Link>
    </section>
  )
}
