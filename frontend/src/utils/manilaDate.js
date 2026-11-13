export function getManilaDateString(date = new Date()) {
  return date.toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' })
}
