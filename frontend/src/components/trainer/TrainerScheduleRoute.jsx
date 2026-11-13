import { getStoredUser } from '../../utils/authState'
import TrainerBookingSchedule from '../../pages/TrainerBookingSchedule.jsx'
import TrainerSchedule from '../../pages/TrainerSchedule.jsx'

export default function TrainerScheduleRoute() {
  const role = String(getStoredUser()?.role || '').toLowerCase().trim()
  return role === 'trainer' ? <TrainerBookingSchedule /> : <TrainerSchedule />
}
