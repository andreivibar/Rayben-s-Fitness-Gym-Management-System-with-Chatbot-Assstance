import { createContext, useContext } from 'react'

export const FeedbackContext = createContext(null)

export function getSafeErrorMessage(error, fallback) {
  const status = Number(error?.response?.status || 0)
  const apiMessage = error?.response?.data?.message?.trim?.()
  const sensitiveOrTechnicalDetails = /(?:SQLSTATE|PDOException|mysqli|stack trace|fatal error|password|secret|api[_ -]?key|credential|token\s*[=:]|(?:[A-Z]:\\|\/(?:var|home|etc|xampp)\/))/i
  if (
    status >= 400
    && status < 500
    && typeof apiMessage === 'string'
    && apiMessage.length <= 300
    && !/[\r\n]/.test(apiMessage)
    && !sensitiveOrTechnicalDetails.test(apiMessage)
  ) {
    return apiMessage
  }
  return fallback
}

export function useFeedback() {
  const context = useContext(FeedbackContext)
  if (!context) throw new Error('useFeedback must be used within FeedbackProvider.')
  return context
}
