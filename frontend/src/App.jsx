import './App.css'
import { lazy, Suspense } from 'react'
import { BrowserRouter, Route, Routes, Navigate } from 'react-router-dom'
import ProtectedRoute, { GuestOnlyRoute } from './components/ProtectedRoute.jsx'
import FeedbackProvider from './components/ui/FeedbackProvider.jsx'

const appBasename = '/Gym-Management-System/frontend/dist'

const AdminDashboard = lazy(() => import('./pages/AdminDashboard.jsx'))
const AdminProfile = lazy(() => import('./pages/AdminProfile.jsx'))
const AdminSettings = lazy(() => import('./pages/AdminSettings.jsx'))
const FeedbackManagement = lazy(() => import('./pages/FeedbackManagement.jsx'))
const Attendance = lazy(() => import('./pages/Attendance.jsx'))
const AttendanceScanner = lazy(() => import('./pages/AttendanceScanner.jsx'))
const AttendanceQrDisplay = lazy(() => import('./pages/AttendanceQrDisplay.jsx'))
const AttendanceRecords = lazy(() => import('./pages/AttendanceRecords.jsx'))
const CheckedInToday = lazy(() => import('./pages/CheckedInToday.jsx'))
const Login = lazy(() => import('./pages/Login.jsx'))
const ForgotPassword = lazy(() => import('./pages/ForgotPassword.jsx'))
const MemberDashboard = lazy(() => import('./pages/MemberDashboard.jsx'))
const MemberPendingApproval = lazy(() => import('./pages/MemberPendingApproval.jsx'))
const MemberFeedbackPage = lazy(() => import('./pages/MemberFeedbackPage.jsx'))
const MemberAttendanceScan = lazy(() => import('./pages/MemberAttendanceScan.jsx'))
const MemberAttendancePage = lazy(() => import('./pages/MemberAttendancePage.jsx'))
const MemberPortalLayout = lazy(() => import('./components/MemberPortalLayout.jsx'))
const TrainerManagement = lazy(() => import('./pages/TrainerManagement.jsx'))
const TrainerDashboard = lazy(() => import('./pages/TrainerDashboard.jsx'))
const TrainerSchedule = lazy(() => import('./pages/TrainerSchedule.jsx'))
const TrainerPortalRoute = lazy(() => import('./components/trainer/TrainerPortalRoute.jsx'))
const TrainerScheduleRoute = lazy(() => import('./components/trainer/TrainerScheduleRoute.jsx'))
const Members = lazy(() => import('./pages/Members.jsx'))
const MemberRegistration = lazy(() => import('./pages/MemberRegistration.jsx'))
const Bookings = lazy(() => import('./pages/Bookings.jsx'))
const Payments = lazy(() => import('./pages/Payments.jsx'))
const DailyPasses = lazy(() => import('./pages/DailyPasses.jsx'))
const MemberProfile = lazy(() => import('./pages/MemberProfile.jsx'))
const MemberChatbot = lazy(() => import('./pages/MemberChatbot.jsx'))
const TrainerProfile = lazy(() => import('./pages/TrainerProfile.jsx'))
const TrainerAttendance = lazy(() => import('./pages/TrainerAttendance.jsx'))
const AdminTrainerAttendance = lazy(() => import('./pages/AdminTrainerAttendance.jsx'))
const LandingPage = lazy(() => import('./pages/LandingPage.jsx'))

function App() {
  return (
    <FeedbackProvider>
      <BrowserRouter basename={appBasename}>
      <Suspense
        fallback={
          <div className="flex min-h-screen items-center justify-center bg-slate-100 text-sm text-slate-600">
            Loading page…
          </div>
        }
      >
        <Routes>
          {/* Public Routes */}
          <Route path="/" element={<GuestOnlyRoute><LandingPage /></GuestOnlyRoute>} />
          <Route path="/login" element={<GuestOnlyRoute><Login /></GuestOnlyRoute>} />
          <Route path="/forgot-password" element={<GuestOnlyRoute><ForgotPassword /></GuestOnlyRoute>} />
          <Route path="/register" element={<GuestOnlyRoute><MemberRegistration /></GuestOnlyRoute>} />
          <Route path="/day-pass" element={<Navigate to="/register?membership=daily_pass" replace />} />

          {/* Admin Protected Routes */}
          <Route
            path="/dashboard"
            element={
              <ProtectedRoute allowedRoles={['admin', 'administrator']}>
                <AdminDashboard />
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/profile"
            element={
              <ProtectedRoute allowedRoles={['admin', 'administrator']}>
                <AdminProfile />
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/settings"
            element={
              <ProtectedRoute allowedRoles={['admin', 'administrator']}>
                <AdminSettings />
              </ProtectedRoute>
            }
          />
          <Route
            path="/feedback"
            element={
              <ProtectedRoute allowedRoles={['admin', 'administrator']}>
                <FeedbackManagement />
              </ProtectedRoute>
            }
          />
          <Route
            path="/members"
            element={
              <ProtectedRoute allowedRoles={['admin', 'administrator']}>
                <Members />
              </ProtectedRoute>
            }
          />
          <Route
            path="/trainer/manage"
            element={
              <ProtectedRoute allowedRoles={['admin', 'administrator']}>
                <TrainerManagement />
              </ProtectedRoute>
            }
          />
          <Route
            path="/attendance"
            element={
              <ProtectedRoute allowedRoles={['admin', 'administrator']}>
                <Attendance />
              </ProtectedRoute>
            }
          />
          <Route
            path="/attendance/scanner"
            element={
              <ProtectedRoute allowedRoles={['admin', 'administrator']}>
                <AttendanceScanner />
              </ProtectedRoute>
            }
          />
          <Route
            path="/attendance/qr-display"
            element={
              <ProtectedRoute allowedRoles={['admin', 'administrator']}>
                <AttendanceQrDisplay />
              </ProtectedRoute>
            }
          />
          <Route
            path="/attendance/records"
            element={
              <ProtectedRoute allowedRoles={['admin', 'administrator']}>
                <AttendanceRecords />
              </ProtectedRoute>
            }
          />
          <Route
            path="/attendance/checked-in"
            element={
              <ProtectedRoute allowedRoles={['admin', 'administrator']}>
                <CheckedInToday />
              </ProtectedRoute>
            }
          />
          <Route
            path="/trainer/attendance/records"
            element={
              <ProtectedRoute allowedRoles={['admin', 'administrator']}>
                <AdminTrainerAttendance />
              </ProtectedRoute>
            }
          />
          <Route
            path="/bookings"
            element={
              <ProtectedRoute allowedRoles={['admin', 'administrator']}>
                <Bookings />
              </ProtectedRoute>
            }
          />
          <Route
            path="/payments"
            element={
              <ProtectedRoute allowedRoles={['admin', 'administrator', 'staff', 'member']}>
                <Payments />
              </ProtectedRoute>
            }
          />
          <Route
            path="/daily-passes"
            element={
              <ProtectedRoute allowedRoles={['admin', 'administrator', 'staff']}>
                <DailyPasses />
              </ProtectedRoute>
            }
          />

          {/* Trainer Protected Routes */}
          <Route
            path="/trainer"
            element={
              <ProtectedRoute allowedRoles={['trainer', 'admin', 'administrator']}>
                <TrainerPortalRoute />
              </ProtectedRoute>
            }
          >
            <Route index element={<ProtectedRoute allowedRoles={['trainer']}><TrainerDashboard /></ProtectedRoute>} />
            <Route path="profile" element={<ProtectedRoute allowedRoles={['trainer']}><TrainerProfile /></ProtectedRoute>} />
            <Route path="attendance" element={<ProtectedRoute allowedRoles={['trainer']}><TrainerAttendance /></ProtectedRoute>} />
            <Route path="attendance/scan" element={<ProtectedRoute allowedRoles={['trainer']}><MemberAttendanceScan mode="trainer" /></ProtectedRoute>} />
            <Route path="availability" element={<ProtectedRoute allowedRoles={['trainer']}><TrainerSchedule /></ProtectedRoute>} />
            <Route path="schedule" element={<ProtectedRoute allowedRoles={['trainer', 'admin', 'administrator']}><TrainerScheduleRoute /></ProtectedRoute>} />
          </Route>

          <Route
            path="/member/pending-approval"
            element={
              <ProtectedRoute allowedRoles={['member']}>
                <MemberPendingApproval />
              </ProtectedRoute>
            }
          />

          {/* Member Protected Routes */}
          <Route
            element={
              <ProtectedRoute allowedRoles={['member']}>
                <MemberPortalLayout />
              </ProtectedRoute>
            }
          >
            <Route path="/member/dashboard" element={<MemberDashboard />} />
            <Route path="/member/membership" element={<MemberDashboard />} />
            <Route path="/member/bookings" element={<MemberDashboard />} />
            <Route path="/member/workout-plan" element={<MemberDashboard />} />
            <Route path="/member/progress" element={<MemberDashboard />} />
            <Route path="/member/attendance" element={<MemberAttendancePage />} />
            <Route path="/member/profile" element={<MemberProfile />} />
            <Route path="/member/payments" element={<Payments />} />
            <Route path="/member/feedback" element={<MemberFeedbackPage />} />
            <Route path="/member/chatbot" element={<MemberChatbot />} />
          </Route>
          <Route path="/member" element={<Navigate to="/member/dashboard" replace />} />

          {/* Fallback */}
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </Suspense>
      </BrowserRouter>
    </FeedbackProvider>
  )
}

export default App
