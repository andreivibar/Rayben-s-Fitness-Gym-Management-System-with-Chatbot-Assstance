import { useState, useRef, useEffect } from 'react'
import { FaEllipsisV } from 'react-icons/fa'
import { createPortal } from 'react-dom'

export default function ActionMenu({ actions = [] }) {
  const [isOpen, setIsOpen] = useState(false)
  const [position, setPosition] = useState({ top: 0, left: 0, above: false })
  const buttonRef = useRef(null)
  const menuRef = useRef(null)

  // Calculate menu position when opened or when window resizes
  useEffect(() => {
    if (!isOpen || !buttonRef.current) return

    const updatePosition = () => {
      const button = buttonRef.current
      if (!button) return

      const rect = button.getBoundingClientRect()
      const menuHeight = 160 // approximate height of menu
      const viewportHeight = window.innerHeight

      // Check if there's enough space below, otherwise show above
      const spaceBelow = viewportHeight - rect.bottom
      const showAbove = spaceBelow < menuHeight && rect.top > menuHeight

      const top = showAbove ? rect.top - menuHeight - 8 : rect.bottom + 8
      const left = rect.right - 200 // menu width is 200px, align to right edge

      setPosition({
        top,
        left: Math.max(8, Math.min(left, window.innerWidth - 208)), // Keep 8px margin
        above: showAbove,
      })
    }

    // Small delay to ensure DOM is ready
    const timer = setTimeout(updatePosition, 0)
    window.addEventListener('resize', updatePosition)
    window.addEventListener('scroll', updatePosition, true)

    return () => {
      clearTimeout(timer)
      window.removeEventListener('resize', updatePosition)
      window.removeEventListener('scroll', updatePosition, true)
    }
  }, [isOpen])

  // Handle click outside
  useEffect(() => {
    const handleClickOutside = (e) => {
      if (buttonRef.current && !buttonRef.current.contains(e.target) && menuRef.current && !menuRef.current.contains(e.target)) {
        setIsOpen(false)
      }
    }

    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside)
      return () => document.removeEventListener('mousedown', handleClickOutside)
    }
  }, [isOpen])

  return (
    <>
      <button
        ref={buttonRef}
        onClick={() => setIsOpen(!isOpen)}
        className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-2.5 py-1.5 text-slate-600 transition hover:bg-slate-50 hover:text-slate-900 relative"
        title="More actions"
      >
        <FaEllipsisV className="text-xs" />
      </button>

      {isOpen &&
        createPortal(
          <div
            ref={menuRef}
            className="fixed w-48 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl"
            style={{
              top: `${position.top}px`,
              left: `${position.left}px`,
              zIndex: 9999,
            }}
          >
            {actions.map((action, idx) => (
              <button
                key={idx}
                onClick={() => {
                  action.onClick?.()
                  setIsOpen(false)
                }}
                disabled={action.disabled}
                className={`flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm transition ${
                  action.variant === 'danger'
                    ? 'text-rose-700 hover:bg-rose-50 disabled:text-rose-300'
                    : action.variant === 'warning'
                    ? 'text-amber-700 hover:bg-amber-50 disabled:text-amber-300'
                    : 'text-slate-700 hover:bg-slate-50 disabled:text-slate-300'
                } disabled:cursor-not-allowed`}
              >
                {action.icon && <span className="flex-shrink-0">{action.icon}</span>}
                <span>{action.label}</span>
              </button>
            ))}
          </div>,
          document.body
        )}
    </>
  )
}
