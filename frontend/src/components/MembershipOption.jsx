import { FaCheck, FaDumbbell, FaWalking } from 'react-icons/fa'

function MembershipOption({
  option,
  selected,
  onSelect,
}) {
  const { title, description, value, tag, features = [] } = option
  const isMonthly = value === 'monthly'

  return (
    <button
      type="button"
      onClick={() => onSelect(value)}
      className={`group relative flex flex-col justify-between rounded-3xl border-2 p-5 sm:p-6 text-left transition-all duration-200 focus:outline-none cursor-pointer ${
        selected
          ? 'border-violet-600 bg-violet-50/60 shadow-md shadow-violet-100 ring-4 ring-violet-500/10'
          : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50/50'
      }`}
    >
      {/* Top Tag & Selection Indicator */}
      <div className="flex items-start justify-between gap-3 w-full">
        <div className="flex items-center gap-3">
          <div
            className={`flex h-11 w-11 items-center justify-center rounded-2xl transition-colors ${
              selected ? 'bg-violet-600 text-white' : 'bg-slate-100 text-slate-600 group-hover:bg-slate-200'
            }`}
          >
            {isMonthly ? <FaDumbbell className="text-lg" /> : <FaWalking className="text-lg" />}
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h3 className="text-base sm:text-lg font-bold text-slate-900">{title}</h3>
              {tag && (
                <span
                  className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wider ${
                    selected ? 'bg-violet-200/80 text-violet-800' : 'bg-slate-100 text-slate-600'
                  }`}
                >
                  {tag}
                </span>
              )}
            </div>
          </div>
        </div>

        {/* Custom Radio Button Indicator */}
        <div
          className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full border-2 transition-all ${
            selected ? 'border-violet-600 bg-violet-600 text-white' : 'border-slate-300 bg-white group-hover:border-slate-400'
          }`}
        >
          {selected && <FaCheck className="text-[10px]" />}
        </div>
      </div>

      {/* Description */}
      <p className="mt-3 text-xs sm:text-sm leading-relaxed text-slate-600">{description}</p>

      {/* Feature Bullet points */}
      {features.length > 0 && (
        <ul className="mt-4 space-y-1.5 border-t border-slate-200/60 pt-3 text-xs text-slate-600">
          {features.map((feat, idx) => (
            <li key={idx} className="flex items-center gap-2">
              <span className={`h-1.5 w-1.5 rounded-full ${selected ? 'bg-violet-600' : 'bg-slate-400'}`} />
              <span>{feat}</span>
            </li>
          ))}
        </ul>
      )}
    </button>
  )
}

export default MembershipOption
