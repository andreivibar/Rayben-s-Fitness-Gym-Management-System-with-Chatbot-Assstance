import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaSearch, FaPlus, FaClipboardList } from 'react-icons/fa'

function Attendance() {
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [records, setRecords] = useState([])

  const filteredRecords = records.filter((record) => {
    if (!search) return true
    return (
      (record.name || '')
        .toLowerCase()
        .includes(search.toLowerCase()) ||
      (record.status || '')
        .toLowerCase()
        .includes(search.toLowerCase())
    )
  })

  const handleCheckIn = () => {
    const nextId = records.length + 1
    const newRecord = {
      id: nextId,
      name: '',
      status: 'Pending',
      time: 'TBD',
    }
    setRecords([newRecord, ...records])
  }

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      <header className="border-b border-slate-200 bg-white/90 backdrop-blur sticky top-0 z-20">
        <div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
          <div>
            <p className="text-sm uppercase tracking-[0.24em] text-slate-500">
              Gym Management System
            </p>
            <h1 className="text-2xl font-semibold text-slate-900">Attendance</h1>
          </div>
          <div className="flex items-center gap-4">
            <button
              onClick={() => navigate('/dashboard')}
              className="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
            >
              Back to Dashboard
            </button>
            <button
              onClick={handleCheckIn}
              className="rounded-2xl bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800"
            >
              <FaPlus className="mr-2 inline-block" /> Add check-in
            </button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-7xl px-6 py-8">
        <section className="grid gap-6 lg:grid-cols-[1.4fr_0.6fr]">
          <div className="rounded-3xl bg-white p-6 shadow-sm">
            <div className="flex flex-col gap-6 md:flex-row md:items-center md:justify-between">
              <div>
                <p className="text-sm font-medium uppercase tracking-[0.24em] text-slate-500">
                  Attendance overview
                </p>
                <h2 className="mt-3 text-3xl font-semibold text-slate-900">Daily check-ins</h2>
              </div>
              <div className="flex flex-col gap-3 sm:flex-row">
                <div className="relative">
                  <FaSearch className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                  <input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search members"
                    className="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3 pl-10 pr-4 text-sm text-slate-900 shadow-sm focus:border-slate-300 focus:outline-none"
                  />
                </div>
                <div className="rounded-3xl bg-slate-50 p-4 text-sm text-slate-600 shadow-sm">
                  <p className="font-semibold text-slate-900">Total today</p>
                  <p className="mt-2 text-3xl font-semibold text-slate-900">{records.length}</p>
                </div>
              </div>
            </div>

            <div className="mt-8 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
              <div className="grid grid-cols-[2fr_1fr_1fr_0.8fr] gap-4 bg-slate-50 px-6 py-4 text-xs uppercase tracking-[0.3em] text-slate-500">
                <span>Member</span>
                <span>Status</span>
                <span>Check-in Time</span>
                <span className="text-right">Actions</span>
              </div>
              <div className="divide-y divide-slate-200">
                {filteredRecords.length > 0 ? (
                  filteredRecords.map((record) => (
                    <div key={record.id} className="grid grid-cols-[2fr_1fr_1fr_0.8fr] gap-4 px-6 py-5 text-sm text-slate-700">
                      <div>
                        <p className="font-semibold text-slate-900">{record.name || 'Unassigned member'}</p>
                        <p className="text-slate-500">Member ID {record.id}</p>
                      </div>
                      <div>
                        <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${record.status === 'Checked in' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'}`}>
                          {record.status}
                        </span>
                      </div>
                      <div>{record.time}</div>
                      <div className="flex items-center justify-end gap-2">
                        <button className="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100">
                          View
                        </button>
                        <button className="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100">
                          Edit
                        </button>
                      </div>
                    </div>
                  ))
                ) : (
                  <div className="px-6 py-12 text-center text-sm text-slate-500">
                    No attendance records yet. Connect this page to your database to load names.
                  </div>
                )}
              </div>
            </div>
          </div>

          <aside className="space-y-6">
            <div className="rounded-3xl bg-white p-6 shadow-sm">
              <div className="flex items-start justify-between gap-4">
                <div>
                  <p className="text-sm font-medium uppercase tracking-[0.24em] text-slate-500">Attendance stats</p>
                  <h2 className="mt-3 text-2xl font-semibold text-slate-900">Overview</h2>
                </div>
                <div className="rounded-2xl bg-slate-900 px-4 py-2 text-sm font-medium text-white">Live</div>
              </div>

              <div className="mt-6 space-y-4">
                <div className="rounded-3xl border border-slate-200 bg-slate-50 p-4">
                  <p className="text-sm text-slate-500">Checked in</p>
                  <p className="mt-2 text-3xl font-semibold text-slate-900">{records.filter((record) => record.status === 'Checked in').length}</p>
                </div>
                <div className="rounded-3xl border border-slate-200 bg-slate-50 p-4">
                  <p className="text-sm text-slate-500">Pending</p>
                  <p className="mt-2 text-3xl font-semibold text-slate-900">{records.filter((record) => record.status === 'Pending').length}</p>
                </div>
              </div>
            </div>

            <div className="rounded-3xl bg-white p-6 shadow-sm">
              <div className="flex items-center gap-3">
                <div className="rounded-2xl bg-slate-900 p-3 text-white">
                  <FaClipboardList />
                </div>
                <div>
                  <p className="text-sm font-medium uppercase tracking-[0.24em] text-slate-500">Today’s log</p>
                  <p className="mt-2 text-lg font-semibold text-slate-900">{records.length} records</p>
                </div>
              </div>
              <div className="mt-6 space-y-3 text-sm text-slate-600">
                <p>Manage daily attendance with the search and check-in actions.</p>
                <p>Use the action buttons to view or edit each entry.</p>
              </div>
            </div>
          </aside>
        </section>
      </main>
    </div>
  )
}

export default Attendance
