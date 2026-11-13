import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  FaBars,
  FaBolt,
  FaCheck,
  FaChevronRight,
  FaClock,
  FaDumbbell,
  FaHeartbeat,
  FaMapMarkerAlt,
  FaTimes,
} from 'react-icons/fa'

const GYM_IMAGES = {
  hero: `${import.meta.env.BASE_URL}images/gym/gym-image7.jpg`,
  about: `${import.meta.env.BASE_URL}images/gym/gym-image1.jpg`,
  weights: `${import.meta.env.BASE_URL}images/gym/gym-image5.jpg`,
  cardio: `${import.meta.env.BASE_URL}images/gym/gym-image6.jpg`,
  strength: `${import.meta.env.BASE_URL}images/gym/gym-image4.jpg`,
  interior: `${import.meta.env.BASE_URL}images/gym/gym-image3.jpg`,
  boxing: `${import.meta.env.BASE_URL}images/gym/gym-promotion3.jpg`,
  community: `${import.meta.env.BASE_URL}images/gym/gym-image7.jpg`,
}

const services = [
  { icon: FaDumbbell, title: 'Personal Training', text: 'Focused sessions with available trainers who keep your goals moving.' },
  { icon: FaHeartbeat, title: 'Monthly Membership', text: 'Consistent access, practical guidance, and a plan you can sustain.' },
  { icon: FaBolt, title: 'Boxing', text: 'Build conditioning, coordination, and confidence one round at a time.' },
]

const classes = ['Fitness', 'Aerobics', 'Dance Fitness', 'HIIT', 'Zumba', 'Tabata']

const gallery = [
  { src: GYM_IMAGES.weights, label: 'Weight and equipment area' },
  { src: GYM_IMAGES.cardio, label: 'Treadmills and cardio area' },
  { src: GYM_IMAGES.strength, label: 'Strength equipment' },
  { src: GYM_IMAGES.interior, label: 'Rayben’s gym interior' },
  { src: `${import.meta.env.BASE_URL}images/gym/gym-image2.jpg`, label: 'Machine Equipments' },
]

function Photo({ src, alt, className = '', priority = false }) {
  const [missing, setMissing] = useState(false)
  if (missing) return <div className={`flex items-end bg-[#101a22] p-5 ${className}`}><span className="max-w-[16rem] text-xs font-semibold uppercase tracking-[0.2em] text-cyan-300">Add your gym photo here</span></div>
  return <img src={src} alt={alt} loading={priority ? 'eager' : 'lazy'} onError={() => setMissing(true)} className={`object-cover ${className}`} />
}

function GalleryLightbox({ item, onClose }) {
  useEffect(() => {
    if (!item) return undefined
    const handleKey = (event) => { if (event.key === 'Escape') onClose() }
    document.addEventListener('keydown', handleKey)
    return () => document.removeEventListener('keydown', handleKey)
  }, [item, onClose])

  if (!item) return null
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4" onClick={onClose} role="presentation">
      <div className="relative max-h-[92vh] w-full max-w-5xl" onClick={(event) => event.stopPropagation()} role="dialog" aria-modal="true" aria-label={item.label}>
        <button type="button" onClick={onClose} className="absolute right-3 top-3 z-10 flex h-11 w-11 items-center justify-center rounded-full bg-black/60 text-white hover:bg-cyan-500" aria-label="Close photo preview"><FaTimes /></button>
        <Photo src={item.src} alt={item.label} className="max-h-[82vh] w-full rounded-2xl object-contain" />
        <p className="mt-3 text-center text-sm font-semibold text-white">{item.label}</p>
      </div>
    </div>
  )
}

function LandingPage() {
  const [menuOpen, setMenuOpen] = useState(false)
  const [lightboxItem, setLightboxItem] = useState(null)

  const closeMenu = () => setMenuOpen(false)

  return (
    <div className="min-h-screen bg-[#071017] font-sans text-white selection:bg-cyan-300 selection:text-[#071017]">
      <header className="sticky top-0 z-40 border-b border-white/10 bg-[#071017]/95 backdrop-blur">
        <div className="mx-auto flex max-w-7xl items-center justify-between px-5 py-4 lg:px-8">
          <a href="#home" onClick={closeMenu} className="flex min-w-0 items-center gap-3">
            <img src={`${import.meta.env.BASE_URL}images/gym-logo.png`} alt="Rayben's Fitness Gym" className="h-11 w-11 shrink-0 object-contain" />
            <span className="truncate text-sm font-black uppercase tracking-[0.12em] text-white sm:text-base">Rayben&apos;s Fitness Gym</span>
          </a>
          <nav className="hidden items-center gap-6 lg:flex" aria-label="Main navigation">
            {['Home', 'About', 'Services', 'Membership', 'Inside the Gym', 'Contact'].map((item) => <a key={item} href={`#${item.toLowerCase().replaceAll(' ', '-')}`} className="text-xs font-bold uppercase tracking-[0.14em] text-slate-300 transition hover:text-cyan-300">{item}</a>)}
          </nav>
          <div className="hidden items-center gap-3 sm:flex">
            <Link to="/login" className="rounded-full border border-white/25 px-4 py-2 text-xs font-bold uppercase tracking-wider text-white hover:border-cyan-300 hover:text-cyan-300">Log In</Link>
            <Link to="/register" className="rounded-full bg-cyan-300 px-4 py-2 text-xs font-black uppercase tracking-wider text-[#071017] hover:bg-white">Join Now</Link>
          </div>
          <button type="button" onClick={() => setMenuOpen(!menuOpen)} className="flex h-11 w-11 items-center justify-center rounded-full border border-white/20 text-white sm:hidden" aria-label="Toggle navigation">{menuOpen ? <FaTimes /> : <FaBars />}</button>
        </div>
        {menuOpen && <div className="border-t border-white/10 bg-[#0b1821] px-5 py-5 sm:hidden"><nav className="grid gap-4">{['Home', 'About', 'Services', 'Membership', 'Inside the Gym', 'Contact'].map((item) => <a key={item} href={`#${item.toLowerCase().replaceAll(' ', '-')}`} onClick={closeMenu} className="text-sm font-bold uppercase tracking-wider text-slate-200">{item}</a>)}<div className="mt-2 flex gap-3"><Link to="/login" onClick={closeMenu} className="flex-1 rounded-full border border-white/25 px-4 py-3 text-center text-xs font-bold uppercase">Log In</Link><Link to="/register" onClick={closeMenu} className="flex-1 rounded-full bg-cyan-300 px-4 py-3 text-center text-xs font-black uppercase text-[#071017]">Join Now</Link></div></nav></div>}
      </header>

      <main>
        <section id="home" className="relative isolate flex min-h-[clamp(620px,82vh,820px)] items-end overflow-hidden">
          <Photo src={GYM_IMAGES.hero} alt="Rayben's Fitness Gym interior" priority className="absolute inset-0 -z-20 h-full w-full" />
          <div className="absolute inset-0 -z-10 bg-[linear-gradient(90deg,rgba(4,12,17,.94),rgba(4,12,17,.62),rgba(4,12,17,.28))]" />
          <div className="absolute bottom-0 left-0 right-0 -z-10 h-40 bg-gradient-to-t from-[#071017] to-transparent" />
          <div className="mx-auto w-full max-w-7xl px-5 pb-20 pt-28 lg:px-8 lg:pb-28">
            <div className="max-w-3xl"><p className="mb-5 inline-flex items-center gap-2 border-l-4 border-cyan-300 pl-3 text-xs font-black uppercase tracking-[0.3em] text-cyan-300"><FaBolt /> Building confidence. Building fitness.</p><h1 className="max-w-3xl text-5xl font-black uppercase leading-[0.9] tracking-[-0.04em] text-white sm:text-7xl lg:text-8xl">Rayben&apos;s<br /><span className="text-cyan-300">Fitness Gym</span></h1><p className="mt-6 max-w-xl text-lg leading-8 text-slate-200 sm:text-xl">Your space to train stronger, move better, and stay consistent.</p><div className="mt-8 flex flex-col gap-3 sm:flex-row"><Link to="/register" className="inline-flex items-center justify-center gap-2 rounded-full bg-cyan-300 px-6 py-4 text-sm font-black uppercase tracking-wider text-[#071017] hover:bg-white">Join Now <FaChevronRight /></Link><a href="#inside-the-gym" className="inline-flex items-center justify-center rounded-full border border-white/35 px-6 py-4 text-sm font-black uppercase tracking-wider text-white hover:border-cyan-300 hover:text-cyan-300">Explore the Gym</a></div></div>
          </div>
        </section>

        <section className="border-y border-white/10 bg-[#0d1b24]" aria-label="Gym information">
          <div className="mx-auto grid max-w-7xl divide-y divide-white/10 px-5 sm:grid-cols-2 sm:divide-x sm:divide-y-0 lg:grid-cols-4 lg:px-8"><div className="px-0 py-6 sm:px-6"><p className="text-2xl font-black text-cyan-300">₱800 <span className="text-xs text-white">/ MONTH</span></p><p className="mt-1 text-xs font-bold uppercase tracking-widest text-slate-400">Monthly Membership</p></div><div className="px-0 py-6 sm:px-6"><p className="text-2xl font-black text-cyan-300">₱100 <span className="text-xs text-white">/ DAY</span></p><p className="mt-1 text-xs font-bold uppercase tracking-widest text-slate-400">1 Day Pass</p></div><div className="px-0 py-6 sm:px-6"><p className="text-2xl font-black text-white">5:00 AM – 10:00 PM</p><p className="mt-1 text-xs font-bold uppercase tracking-widest text-slate-400">Monday–Saturday</p></div><div className="px-0 py-6 sm:px-6"><p className="text-2xl font-black text-white">6:00 AM – 6:00 PM</p><p className="mt-1 text-xs font-bold uppercase tracking-widest text-slate-400">Sunday</p></div></div>
        </section>

        <section id="about" className="mx-auto grid max-w-7xl gap-10 px-5 py-24 lg:grid-cols-[.85fr_1.15fr] lg:items-center lg:px-8"><div><p className="text-xs font-black uppercase tracking-[0.3em] text-cyan-300">About Rayben&apos;s</p><h2 className="mt-4 text-4xl font-black uppercase leading-none sm:text-6xl">Train with<br /><span className="text-cyan-300">purpose.</span></h2><p className="mt-6 max-w-lg text-base leading-8 text-slate-300">Rayben&apos;s Fitness Gym is a place for strength training, personal training, boxing, and group fitness. Come in, find your rhythm, and build a stronger everyday life.</p><Link to="/register" className="mt-8 inline-flex items-center gap-2 border-b-2 border-cyan-300 pb-2 text-sm font-black uppercase tracking-wider text-cyan-300 hover:text-white">Start your journey <FaChevronRight /></Link></div><div className="relative min-h-[360px] overflow-hidden rounded-2xl border border-white/10"><Photo src={GYM_IMAGES.about} alt="Inside Rayben's Fitness Gym" className="absolute inset-0 h-full w-full" /><div className="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-[#071017] p-6 pt-24"><p className="text-xs font-bold uppercase tracking-[0.2em] text-cyan-300">A place to get stronger</p></div></div></section>

        <section id="inside-the-gym" className="bg-[#0d1b24] px-5 py-24 lg:px-8"><div className="mx-auto max-w-7xl"><div className="flex flex-col justify-between gap-5 sm:flex-row sm:items-end"><div><p className="text-xs font-black uppercase tracking-[0.3em] text-cyan-300">Inside the Gym</p><h2 className="mt-4 text-4xl font-black uppercase leading-none sm:text-6xl">Step inside<br /><span className="text-cyan-300">Rayben&apos;s.</span></h2></div><p className="max-w-sm text-sm leading-7 text-slate-400">A look at the space where your fitness journey begins. Tap any image to take a closer look.</p></div><div className="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">{gallery.map((item, index) => <button type="button" key={item.src} onClick={() => setLightboxItem(item)} className={`group relative min-h-72 overflow-hidden rounded-2xl border border-white/10 text-left ${index === 0 ? 'lg:col-span-2 lg:row-span-2 lg:min-h-[584px]' : ''}`} aria-label={`View ${item.label}`}><Photo src={item.src} alt={item.label} className="absolute inset-0 h-full w-full transition duration-500 group-hover:scale-105" /><div className="absolute inset-0 bg-gradient-to-t from-black/80 via-transparent to-transparent" /><span className="absolute bottom-5 left-5 text-sm font-black uppercase tracking-wider text-white">{item.label}</span></button>)}</div></div></section>

        <section className="mx-auto max-w-7xl px-5 py-24 lg:px-8"><p className="text-xs font-black uppercase tracking-[0.3em] text-cyan-300">Why choose Rayben&apos;s</p><h2 className="mt-4 max-w-2xl text-4xl font-black uppercase leading-none sm:text-6xl">Built for your<br /><span className="text-cyan-300">next level.</span></h2><div className="mt-10 grid gap-px overflow-hidden border border-white/10 bg-white/10 sm:grid-cols-2 lg:grid-cols-4">{[{ title: 'Quality Equipment', text: 'Modern equipment for strength and cardio training.' }, { title: 'Personal Training', text: 'Book sessions with available trainers.' }, { title: 'Group Fitness', text: 'Join group fitness activities with the community.' }, { title: 'Convenient Management', text: 'Manage memberships, bookings, attendance, and payments.' }].map(({ title, text }) => <article key={title} className="bg-[#071017] p-7"><FaCheck className="text-cyan-300" /><h3 className="mt-8 text-lg font-black uppercase">{title}</h3><p className="mt-3 text-sm leading-7 text-slate-400">{text}</p></article>)}</div></section>

        <section id="services" className="bg-cyan-300 px-5 py-24 text-[#071017] lg:px-8"><div className="mx-auto max-w-7xl"><p className="text-xs font-black uppercase tracking-[0.3em]">What we do</p><h2 className="mt-4 text-4xl font-black uppercase leading-none sm:text-6xl">Services that<br />keep you moving.</h2><div className="mt-10 grid gap-4 lg:grid-cols-3">{services.map(({ icon: Icon, title, text }) => <article key={title} className="bg-[#071017] p-7 text-white"><Icon className="text-3xl text-cyan-300" /><h3 className="mt-8 text-xl font-black uppercase">{title}</h3><p className="mt-3 text-sm leading-7 text-slate-400">{text}</p></article>)}</div><div className="mt-10 flex flex-wrap gap-3">{classes.map((item) => <span key={item} className="border border-[#071017]/40 px-4 py-2 text-xs font-black uppercase tracking-wider">{item}</span>)}</div></div></section>

        <section id="membership" className="mx-auto max-w-7xl px-5 py-24 lg:px-8">
          <div className="grid gap-5 lg:grid-cols-3">
            <article className="border border-cyan-300 bg-cyan-300 p-8 text-[#071017] sm:p-10">
              <p className="text-xs font-black uppercase tracking-[0.3em]">Monthly Membership</p>
              <p className="mt-5 text-6xl font-black">₱800<span className="text-base"> / month</span></p>
              <ul className="mt-7 space-y-3 text-sm font-semibold">
                {['Free 2 sessions of Personal Training', 'Health assessment', 'Fitness check', 'Monthly workout plan'].map((item) => <li key={item} className="flex items-center gap-2"><FaCheck />{item}</li>)}
              </ul>
              <Link to="/register" className="mt-8 inline-flex rounded-full bg-[#071017] px-6 py-3 text-sm font-black uppercase tracking-wider text-white hover:bg-white hover:text-[#071017]">Join Now</Link>
            </article>

            <article className="relative overflow-hidden border border-cyan-300/50 bg-[#0d1b24] p-8 sm:p-10">
              <div className="absolute right-0 top-0 bg-cyan-300 px-3 py-1 text-[10px] font-black uppercase tracking-wider text-[#071017] rounded-bl-lg">Promo</div>
              <p className="text-xs font-black uppercase tracking-[0.3em] text-cyan-300">Student/PWD/Senior</p>
              <p className="mt-5 text-6xl font-black text-white">₱700<span className="text-base text-slate-400"> / month</span></p>
              <ul className="mt-7 space-y-3 text-sm font-semibold text-slate-300">
                {['Valid ID required', 'Free 2 sessions of Personal Training', 'Health assessment', 'Monthly workout plan'].map((item) => <li key={item} className="flex items-center gap-2"><FaCheck className="text-cyan-300" />{item}</li>)}
              </ul>
              <Link to="/register" className="mt-8 inline-flex rounded-full border border-cyan-300 px-6 py-3 text-sm font-black uppercase tracking-wider text-cyan-300 hover:bg-cyan-300 hover:text-[#071017]">Join Now</Link>
            </article>

            <article className="border border-white/15 bg-[#0d1b24] p-8 sm:p-10">
              <p className="text-xs font-black uppercase tracking-[0.3em] text-cyan-300">1 Day Pass</p>
              <p className="mt-5 text-6xl font-black">₱100<span className="text-base text-slate-400"> / day</span></p>
              <p className="mt-7 max-w-sm text-sm leading-7 text-slate-400">Drop in, train at your pace, and get a feel for the Rayben&apos;s community.</p>
              <Link to="/register" className="mt-8 inline-flex rounded-full border border-white/25 px-6 py-3 text-sm font-black uppercase tracking-wider text-white hover:border-cyan-300 hover:text-cyan-300">Get Started</Link>
            </article>
          </div>
        </section>

        <section className="relative isolate min-h-[460px] overflow-hidden"><Photo src={GYM_IMAGES.boxing} alt="Boxing training at Rayben's Fitness Gym" className="absolute inset-0 -z-20 h-full w-full" /><div className="absolute inset-0 -z-10 bg-[#071017]/75" /><div className="mx-auto flex min-h-[460px] max-w-7xl items-center px-5 py-20 lg:px-8"><div><p className="text-xs font-black uppercase tracking-[0.3em] text-cyan-300">Train with purpose</p><h2 className="mt-4 max-w-2xl text-5xl font-black uppercase leading-none sm:text-7xl">Build strength.<br />Stay disciplined.</h2><p className="mt-6 max-w-lg text-base leading-7 text-slate-200">Keep pushing forward with focused training and a space that makes showing up count.</p><Link to="/login" className="mt-8 inline-flex rounded-full bg-cyan-300 px-6 py-4 text-sm font-black uppercase tracking-wider text-[#071017] hover:bg-white">Book a Session</Link></div></div></section>

        <section className="relative isolate overflow-hidden"><Photo src={GYM_IMAGES.community} alt="Group fitness community" className="absolute inset-0 -z-20 h-full w-full" /><div className="absolute inset-0 -z-10 bg-[#071017]/75" /><div className="mx-auto flex min-h-[440px] max-w-7xl items-end px-5 py-20 lg:px-8"><div className="max-w-2xl"><p className="text-xs font-black uppercase tracking-[0.3em] text-cyan-300">Community</p><h2 className="mt-4 text-4xl font-black uppercase leading-none sm:text-6xl">More than training.<br /><span className="text-cyan-300">Build a community.</span></h2><p className="mt-6 max-w-lg text-base leading-7 text-slate-200">Train alongside people who show up, support each other, and keep the energy moving.</p></div></div></section>

        <section id="contact" className="mx-auto grid max-w-7xl gap-10 px-5 py-24 lg:grid-cols-2 lg:px-8"><div><p className="text-xs font-black uppercase tracking-[0.3em] text-cyan-300">Find us</p><h2 className="mt-4 text-4xl font-black uppercase leading-none sm:text-6xl">Make the move.</h2><p className="mt-6 flex max-w-md gap-3 text-base leading-7 text-slate-300"><FaMapMarkerAlt className="mt-1 shrink-0 text-cyan-300" />2F Rayben&apos;s Place Bldg., Brgy. 7, Camalig, Albay</p><a href="https://www.google.com/maps/search/?api=1&query=Rayben%27s%20Place%20Bldg%20Camalig%20Albay" target="_blank" rel="noreferrer" className="mt-8 inline-flex rounded-full border border-cyan-300 px-5 py-3 text-xs font-black uppercase tracking-wider text-cyan-300 hover:bg-cyan-300 hover:text-[#071017]">Get Directions</a></div><div className="border-l border-white/15 pl-6 lg:pl-10"><p className="text-xs font-black uppercase tracking-[0.3em] text-cyan-300">Business Hours</p><div className="mt-6 space-y-5"><p className="flex gap-3 text-lg font-bold"><FaClock className="mt-1 text-cyan-300" /> Monday–Saturday <span className="ml-auto text-right text-slate-300">5:00 AM – 10:00 PM</span></p><p className="flex gap-3 text-lg font-bold"><FaClock className="mt-1 text-cyan-300" /> Sunday <span className="ml-auto text-right text-slate-300">6:00 AM – 6:00 PM</span></p></div></div></section>

        <section className="bg-cyan-300 px-5 py-20 text-center text-[#071017] lg:px-8"><h2 className="text-4xl font-black uppercase leading-none sm:text-6xl">Ready to start your<br />fitness journey?</h2><p className="mx-auto mt-5 max-w-lg text-base font-medium">Take the first step toward a stronger and healthier you.</p><div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row"><Link to="/register" className="rounded-full bg-[#071017] px-7 py-4 text-sm font-black uppercase tracking-wider text-white hover:bg-white hover:text-[#071017]">Join Now</Link><Link to="/login" className="rounded-full border-2 border-[#071017] px-7 py-4 text-sm font-black uppercase tracking-wider hover:bg-[#071017] hover:text-white">Log In</Link></div></section>
      </main>

      <footer className="border-t border-white/10 bg-[#040a0e] px-5 py-12 lg:px-8">
        <div className="mx-auto grid max-w-7xl gap-10 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <img src={`${import.meta.env.BASE_URL}images/gym-logo.png`} alt="Rayben's Fitness Gym" className="h-16 w-24 object-contain" />
            <p className="mt-4 text-sm leading-6 text-slate-400">Building confidence. Building fitness.</p>
          </div>
          <div>
            <p className="text-xs font-black uppercase tracking-widest text-cyan-300">Navigate</p>
            <div className="mt-4 grid gap-3 text-sm text-slate-400">
              {['About', 'Services', 'Membership', 'Inside the Gym', 'Contact'].map((item) => (
                <a key={item} href={`#${item.toLowerCase().replaceAll(' ', '-')}`} className="hover:text-white">{item}</a>
              ))}
            </div>
          </div>
          <div>
            <p className="text-xs font-black uppercase tracking-widest text-cyan-300">Access</p>
            <div className="mt-4 grid gap-3 text-sm text-slate-400">
              <Link to="/login" className="hover:text-white">Login</Link>
              <Link to="/register" className="hover:text-white">Register</Link>
              <Link to="/register" className="hover:text-white">Membership</Link>
            </div>
          </div>
          <div>
            <p className="text-xs font-black uppercase tracking-widest text-cyan-300">Visit</p>
            <p className="mt-4 text-sm leading-6 text-slate-400">2F Rayben&apos;s Place Bldg.<br />Brgy. 7, Camalig, Albay</p>
            <p className="mt-3 text-sm text-slate-400">Mon–Sat 5:00 AM–10:00 PM<br />Sun 6:00 AM–6:00 PM</p>
          </div>
        </div>
        <div className="mx-auto mt-10 max-w-7xl border-t border-white/10 pt-5 text-xs text-slate-500">
          © {new Date().getFullYear()} Rayben&apos;s Fitness Gym. All rights reserved.
        </div>
      </footer>
      <GalleryLightbox item={lightboxItem} onClose={() => setLightboxItem(null)} />
    </div>
  )
}

export default LandingPage
