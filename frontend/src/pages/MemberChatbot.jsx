import { useCallback, useEffect, useRef, useState } from 'react'
import { FaPlus, FaRobot, FaPaperPlane, FaTrash, FaUser } from 'react-icons/fa'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import api from '../services/api'
import ConfirmationModal from '../components/ui/ConfirmationModal.jsx'
import { useFeedback } from '../components/ui/FeedbackContext.js'

const suggestedQuestions = [
  'What are the gym operating hours?',
  'What monthly membership plans are available?',
  'How can I book a session?',
  'Suggest a beginner-friendly workout.',
]

const CHATBOT_REQUEST_TIMEOUT_MS = 45_000
const CHATBOT_HISTORY_TIMEOUT_MS = 10_000

function getErrorMessage(error, fallback) {
  return error?.response?.data?.message || fallback
}

function isRequestTimeout(error) {
  return error?.code === 'ECONNABORTED' || error?.code === 'ETIMEDOUT' || error?.name === 'TimeoutError'
}

function isNewMatchingUserMessage(messages, content, previousIds) {
  return messages.some((message) =>
    message.role === 'user'
    && message.content === content
    && !previousIds.has(String(message.id))
  )
}

function getSendErrorMessage(error) {
  if (isRequestTimeout(error)) {
    return 'The request timed out. We will check whether your message was received before you retry.'
  }
  if (!error?.response) {
    return 'Unable to reach the gym assistant. Check your connection and try again.'
  }
  return getErrorMessage(error, 'Unable to send your message. Please try again.')
}

function AssistantMarkdown({ content }) {
  return (
    <div className="break-words text-sm leading-6 [&_table]:my-2 [&_table]:min-w-full [&_table]:border-collapse [&_th]:border [&_th]:border-slate-300 [&_th]:bg-slate-100 [&_th]:p-2 [&_td]:border [&_td]:border-slate-300 [&_td]:p-2">
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        components={{
          p: ({ children }) => <p className="mb-2 last:mb-0">{children}</p>,
          h1: ({ children }) => <h1 className="mb-2 mt-3 text-lg font-bold first:mt-0">{children}</h1>,
          h2: ({ children }) => <h2 className="mb-2 mt-3 text-base font-bold first:mt-0">{children}</h2>,
          h3: ({ children }) => <h3 className="mb-1 mt-2 font-bold first:mt-0">{children}</h3>,
          ul: ({ children }) => <ul className="mb-2 list-disc space-y-1 pl-5 last:mb-0">{children}</ul>,
          ol: ({ children }) => <ol className="mb-2 list-decimal space-y-1 pl-5 last:mb-0">{children}</ol>,
          li: ({ children }) => <li className="pl-1">{children}</li>,
          a: ({ href, children }) => (
            <a href={href} target="_blank" rel="noreferrer" className="text-cyan-800 underline underline-offset-2">
              {children}
            </a>
          ),
          blockquote: ({ children }) => (
            <blockquote className="my-2 border-l-2 border-cyan-400 pl-3 text-slate-600">{children}</blockquote>
          ),
          pre: ({ children }) => (
            <pre className="my-2 overflow-x-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100">{children}</pre>
          ),
          code: ({ children, className }) => (
            <code className={className || 'rounded bg-slate-200/70 px-1 py-0.5 text-[0.9em]'}>{children}</code>
          ),
          hr: () => <hr className="my-3 border-slate-200" />,
        }}
      >
        {content}
      </ReactMarkdown>
    </div>
  )
}

export default function MemberChatbot() {
  const { showSuccess, showRequestError } = useFeedback()
  const [conversations, setConversations] = useState([])
  const [activeConversationId, setActiveConversationId] = useState(null)
  const [messages, setMessages] = useState([])
  const [draft, setDraft] = useState('')
  const [loading, setLoading] = useState(true)
  const [sending, setSending] = useState(false)
  const [reconciling, setReconciling] = useState(false)
  const [deleteConfirmation, setDeleteConfirmation] = useState(null)
  const [deletingConversationId, setDeletingConversationId] = useState(null)
  const [error, setError] = useState('')
  const [accessDenied, setAccessDenied] = useState(false)
  const messageListRef = useRef(null)
  const messageInputRef = useRef(null)
  const requestId = useRef(0)
  const sendingRef = useRef(false)
  const uncertainRetryRef = useRef(null)

  const loadConversations = useCallback(async () => {
    const response = await api.get('/member_chatbot.php', {
      params: { action: 'conversations' },
      timeout: CHATBOT_HISTORY_TIMEOUT_MS,
    })
    return response.data.conversations || []
  }, [])

  useEffect(() => {
    let active = true
    loadConversations()
      .then((items) => {
        if (active) setConversations(items)
      })
      .catch((requestError) => {
        if (!active) return
        if (requestError?.response?.status === 403) {
          setAccessDenied(true)
        } else {
          setError(getErrorMessage(requestError, 'Unable to load your conversations.'))
        }
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => {
      active = false
    }
  }, [loadConversations])

  useEffect(() => {
    if (messageListRef.current) {
      messageListRef.current.scrollTop = messageListRef.current.scrollHeight
    }
  }, [messages, sending])

  const selectConversation = async (conversationId) => {
    setActiveConversationId(conversationId)
    setMessages([])
    setError('')
    const currentRequestId = ++requestId.current
    try {
      const response = await api.get('/member_chatbot.php', {
        params: { action: 'messages', conversation_id: conversationId },
        timeout: CHATBOT_HISTORY_TIMEOUT_MS,
      })
      if (currentRequestId === requestId.current) setMessages(response.data.messages || [])
    } catch (requestError) {
      if (currentRequestId === requestId.current) {
        setError(getErrorMessage(requestError, 'Unable to load this conversation.'))
      }
    }
  }

  const createConversation = async () => {
    setError('')
    try {
      const response = await api.post('/member_chatbot.php', { action: 'create' }, {
        timeout: CHATBOT_REQUEST_TIMEOUT_MS,
      })
      const conversation = response.data.conversation
      setConversations((items) => [conversation, ...items])
      setActiveConversationId(conversation.id)
      setMessages([])
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Unable to create a conversation.'))
    }
  }

  const deleteConversation = async (conversationId) => {
    if (deletingConversationId !== null) return
    setDeletingConversationId(conversationId)
    setError('')
    try {
      const response = await api.delete('/member_chatbot.php', {
        params: { action: 'delete', conversation_id: conversationId },
        timeout: CHATBOT_REQUEST_TIMEOUT_MS,
      })
      if (!response.data?.success) throw new Error('Conversation deletion was not confirmed.')
      setConversations((items) => items.filter((item) => Number(item.id) !== Number(conversationId)))
      if (Number(activeConversationId) === Number(conversationId)) {
        requestId.current += 1
        setActiveConversationId(null)
        setMessages([])
      }
      setDeleteConfirmation(null)
      showSuccess(response.data.message || 'Conversation deleted.')
    } catch (requestError) {
      showRequestError(requestError, 'Unable to delete this conversation. Try again after refreshing the conversation list.', 'Delete failed')
    } finally {
      setDeletingConversationId(null)
    }
  }

  const sendMessage = async (event) => {
    event.preventDefault()
    const content = draft.trim()
    if (!content || sendingRef.current) return

    sendingRef.current = true
    setSending(true)
    setReconciling(false)
    setError('')
    let conversationId = activeConversationId
    let previousIds = new Set(messages.map((message) => String(message.id)))
    let sent = false

    try {
      const uncertainRetry = uncertainRetryRef.current
      if (
        uncertainRetry?.content === content
        && uncertainRetry.conversationId
        && Number(conversationId) === Number(uncertainRetry.conversationId)
      ) {
        setSending(false)
        setReconciling(true)
        try {
          const history = await api.get('/member_chatbot.php', {
            params: { action: 'messages', conversation_id: uncertainRetry.conversationId },
            timeout: CHATBOT_HISTORY_TIMEOUT_MS,
          })
          const historyMessages = history.data.messages || []
          setMessages(historyMessages)
          if (isNewMatchingUserMessage(historyMessages, content, uncertainRetry.previousIds)) {
            setDraft('')
            setError('Your message was saved, but the assistant could not complete a reply. It appears above; send a new message to continue.')
            uncertainRetryRef.current = null
            return
          }
          previousIds = new Set(historyMessages.map((message) => String(message.id)))
          uncertainRetryRef.current = null
        } catch {
          setError('We could not confirm whether your message was received. Your draft is preserved; try again when conversation history is available.')
          return
        } finally {
          setReconciling(false)
        }
        setSending(true)
      }

      if (!conversationId) {
        const created = await api.post('/member_chatbot.php', { action: 'create' }, {
          timeout: CHATBOT_REQUEST_TIMEOUT_MS,
        })
        conversationId = created.data.conversation.id
        setActiveConversationId(conversationId)
        setConversations((items) => [created.data.conversation, ...items])
      }

      const response = await api.post('/member_chatbot.php', {
        action: 'send',
        conversation_id: conversationId,
        message: content,
      }, { timeout: CHATBOT_REQUEST_TIMEOUT_MS })
      setMessages((items) => [...items, ...response.data.messages])
      setDraft('')
      uncertainRetryRef.current = null
      sent = true
    } catch (requestError) {
      setSending(false)
      setReconciling(true)
      setDraft(content)
      setError(getSendErrorMessage(requestError))
      if (conversationId) {
        try {
          const response = await api.get('/member_chatbot.php', {
            params: { action: 'messages', conversation_id: conversationId },
            timeout: CHATBOT_HISTORY_TIMEOUT_MS,
          })
          const historyMessages = response.data.messages || []
          setMessages(historyMessages)
          if (isNewMatchingUserMessage(historyMessages, content, previousIds)) {
            setDraft('')
            setError('Your message was saved, but the assistant could not complete a reply. It appears above; send a new message to continue.')
            uncertainRetryRef.current = null
          } else {
            setError(`${getSendErrorMessage(requestError)} Your draft is available to retry.`)
            uncertainRetryRef.current = null
          }
        } catch {
          uncertainRetryRef.current = { conversationId, content, previousIds }
          setError('We could not confirm whether your message was received. Your draft is preserved; retrying it will first check history to prevent a duplicate.')
        }
      }
    } finally {
      setSending(false)
      setReconciling(false)
      sendingRef.current = false
    }

    if (sent) {
      sendingRef.current = true
      setReconciling(true)
      try {
        setConversations(await loadConversations())
      } catch {
        setError('Your message was sent, but the conversation list could not be refreshed.')
      } finally {
        setReconciling(false)
        sendingRef.current = false
      }
    }
  }

  const chooseSuggestedQuestion = (question) => {
    setDraft(question)
    messageInputRef.current?.focus()
  }

  if (loading) {
    return (
      <div className="flex min-h-[50vh] items-center justify-center text-sm text-slate-500">
        Loading gym assistant…
      </div>
    )
  }

  if (accessDenied) {
    return (
      <section className="mx-auto max-w-3xl rounded-2xl border border-amber-200 bg-white p-6 shadow-sm sm:p-8">
        <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-amber-50 text-amber-700">
          <FaRobot aria-hidden="true" className="text-xl" />
        </div>
        <h1 className="mt-4 text-2xl font-bold text-slate-900">Gym assistant</h1>
        <p className="mt-2 text-sm leading-6 text-slate-600">
          The assistant and saved conversations are available to members with an active, verified Monthly Subscription.
          Pending or unverified payments do not qualify.           Your saved conversations will remain in your account and will be available
          again if your subscription becomes eligible.
        </p>
      </section>
    )
  }

  return (
    <section className="flex min-h-[calc(100vh-7rem)] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm lg:h-[calc(100vh-3rem)] lg:min-h-[36rem]">
      <header className="flex items-center gap-3 border-b border-slate-200 px-4 py-4 sm:px-6">
        <div className="flex h-11 w-11 items-center justify-center rounded-full border border-cyan-100 bg-cyan-50 text-cyan-800">
          <FaRobot aria-hidden="true" className="text-xl" />
        </div>
        <div>
          <h1 className="text-lg font-bold text-slate-900">Gym assistant</h1>
          <p className="text-xs text-slate-500">Gym information and general fitness guidance</p>
        </div>
      </header>

      <div className="flex min-h-0 flex-1 flex-col lg:flex-row">
        <aside className="flex max-h-52 shrink-0 flex-col border-b border-slate-200 bg-slate-50 p-3 lg:max-h-none lg:w-64 lg:border-b-0 lg:border-r">
          <button
            type="button"
            onClick={createConversation}
            disabled={sending || reconciling}
            className="flex min-h-11 items-center justify-center gap-2 rounded-xl bg-cyan-700 px-3 text-sm font-semibold text-white transition hover:bg-cyan-800 disabled:cursor-not-allowed disabled:opacity-60"
          >
            <FaPlus aria-hidden="true" />
            New conversation
          </button>
          <div className="mt-3 min-h-0 space-y-1 overflow-y-auto" aria-label="Saved conversations">
            {conversations.map((conversation) => (
              <div
                key={conversation.id}
                className={`group flex items-center gap-2 rounded-xl ${
                  Number(activeConversationId) === Number(conversation.id) ? 'bg-cyan-100' : 'hover:bg-white'
                }`}
              >
                <button
                  type="button"
                  onClick={() => selectConversation(conversation.id)}
                  disabled={sending || reconciling}
                  className="min-h-11 min-w-0 flex-1 truncate px-3 text-left text-sm text-slate-700"
                  aria-current={Number(activeConversationId) === Number(conversation.id) ? 'page' : undefined}
                >
                  {conversation.title}
                </button>
                <button
                  type="button"
                  onClick={() => setDeleteConfirmation(conversation)}
                  disabled={sending || reconciling || deletingConversationId !== null}
                  aria-label={`Delete ${conversation.title}`}
                  className="mr-2 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  <FaTrash aria-hidden="true" className="text-xs" />
                </button>
              </div>
            ))}
            {conversations.length === 0 && (
              <p className="px-3 py-3 text-xs leading-5 text-slate-500">Your conversations will appear here.</p>
            )}
          </div>
        </aside>

        <div className="flex min-h-0 flex-1 flex-col">
          <div ref={messageListRef} className="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-5 sm:px-6">
            {messages.length === 0 && !sending && (
              <div className="mx-auto flex min-h-full max-w-2xl flex-col items-center justify-center py-8 text-center">
                <div className="flex h-16 w-16 items-center justify-center rounded-full border border-cyan-100 bg-cyan-50 text-cyan-800 shadow-sm">
                  <FaRobot aria-hidden="true" className="text-2xl" />
                </div>
                <h2 className="mt-5 text-xl font-bold text-slate-900">Welcome to your gym assistant</h2>
                <p className="mt-2 max-w-lg text-sm leading-6 text-slate-500">
                  Ask about verified gym information or get general fitness guidance. Choose a question to get started.
                </p>
                <div className="mt-6 grid w-full gap-2 text-left sm:grid-cols-2">
                  {suggestedQuestions.map((question) => (
                    <button
                      key={question}
                      type="button"
                      onClick={() => chooseSuggestedQuestion(question)}
                      className="min-h-12 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 transition hover:border-cyan-300 hover:bg-cyan-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-600"
                    >
                      {question}
                    </button>
                  ))}
                </div>
              </div>
            )}
            {messages.map((message) => (
              <div
                key={message.id}
                className={`flex items-end gap-2 ${message.role === 'user' ? 'justify-end' : 'justify-start'}`}
              >
                {message.role !== 'user' && (
                  <div
                    aria-label="Gym assistant"
                    className="mb-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-cyan-100 bg-cyan-50 text-cyan-800"
                  >
                    <FaRobot aria-hidden="true" className="text-sm" />
                  </div>
                )}
                <div className={`max-w-[85%] rounded-2xl px-4 py-3 sm:max-w-[75%] ${
                  message.role === 'user'
                    ? 'bg-cyan-700 text-white'
                    : 'border border-slate-200 bg-slate-50 text-slate-800'
                }`}>
                  {message.role === 'user'
                    ? <p className="whitespace-pre-wrap break-words text-sm leading-6">{message.content}</p>
                    : <AssistantMarkdown content={message.content} />}
                </div>
                {message.role === 'user' && (
                  <div
                    aria-label="You"
                    className="mb-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-slate-100 text-slate-600"
                  >
                    <FaUser aria-hidden="true" className="text-xs" />
                  </div>
                )}
              </div>
            ))}
            {sending && (
              <div className="flex items-end gap-2" role="status" aria-label="Gym assistant is typing">
                <div className="mb-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-cyan-100 bg-cyan-50 text-cyan-800">
                  <FaRobot aria-hidden="true" className="text-sm" />
                </div>
                <div className="flex items-center gap-1 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                  <span className="sr-only">Gym assistant is typing</span>
                  <span className="h-2 w-2 animate-bounce rounded-full bg-cyan-700 [animation-delay:-0.3s]" />
                  <span className="h-2 w-2 animate-bounce rounded-full bg-cyan-700 [animation-delay:-0.15s]" />
                  <span className="h-2 w-2 animate-bounce rounded-full bg-cyan-700" />
                </div>
              </div>
            )}
          </div>

          <div className="border-t border-slate-200 p-3 sm:p-4">
            {error && <p role="alert" className="mb-2 px-1 text-sm text-rose-700">{error}</p>}
            <form onSubmit={sendMessage} className="flex items-end gap-2">
              <label className="sr-only" htmlFor="gym-chat-message">Message the gym assistant</label>
              <textarea
                id="gym-chat-message"
                ref={messageInputRef}
                disabled={sending || reconciling}
                rows="2"
                maxLength={4000}
                value={draft}
                onChange={(event) => setDraft(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault()
                    event.currentTarget.form?.requestSubmit()
                  }
                }}
                placeholder="Ask a gym or fitness question…"
                className="min-h-12 flex-1 resize-y rounded-xl border border-slate-300 px-3 py-2.5 text-sm text-slate-800 outline-none transition focus:border-cyan-600 focus:ring-2 focus:ring-cyan-100"
              />
              <button
                type="submit"
                disabled={sending || reconciling || !draft.trim()}
                aria-label="Send message"
                className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-cyan-700 text-white transition hover:bg-cyan-800 disabled:cursor-not-allowed disabled:bg-slate-300"
              >
                <FaPaperPlane aria-hidden="true" />
              </button>
            </form>
            <p className="mt-2 px-1 text-[11px] leading-4 text-slate-500">
              Powered by Rayben&apos;s Fitness Gym AI Assistant. General fitness information only; consult a qualified
              health professional for medical concerns. Do not send passwords or payment credentials.
            </p>
          </div>
        </div>
      </div>
      <ConfirmationModal
        open={Boolean(deleteConfirmation)}
        title="Delete this conversation?"
        message="This permanently deletes the conversation and its messages. This action cannot be undone."
        confirmText="Delete Conversation"
        variant="danger"
        loading={deletingConversationId !== null}
        loadingText="Deleting…"
        onConfirm={() => deleteConversation(deleteConfirmation.id)}
        onCancel={() => setDeleteConfirmation(null)}
      />
    </section>
  )
}
