import { useState, useEffect, useRef } from "react";
import { Link, useNavigate } from "react-router-dom";
import api from "../services/api";
import { getStoredUser } from "../utils/authState";


function Login() {
    const navigate = useNavigate();

    const [username, setUsername] = useState("");
    const [password, setPassword] = useState("");
    const [error, setError] = useState("");
    const [showPassword, setShowPassword] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const isSubmittingRef = useRef(false);

    useEffect(() => {
        const showLogoutWarning = () => {
            setError('You are signed out on this device, but the server could not invalidate the session. Try again when the connection is restored.');
            try {
                sessionStorage.removeItem('gym-logout-server-warning');
            } catch {
                // The warning is also delivered by the in-page event.
            }
        };
        try {
            if (sessionStorage.getItem('gym-logout-server-warning')) showLogoutWarning();
        } catch {
            // Continue with the login page if session storage is unavailable.
        }
        window.addEventListener('gym:logout-server-unavailable', showLogoutWarning);

        const user = getStoredUser();
        const role = (user?.role || '').toString().toLowerCase().trim();
        if (role === 'admin' || role === 'administrator') navigate('/dashboard', { replace: true });
        else if (role === 'trainer') navigate('/trainer', { replace: true });
        else if (role === 'member') {
            const approvalStatus = user?.initial_approval?.status;
            navigate(approvalStatus && approvalStatus !== 'approved' ? '/member/pending-approval' : '/member/dashboard', { replace: true });
        }
        return () => window.removeEventListener('gym:logout-server-unavailable', showLogoutWarning);
    }, [navigate]);

    const handleLogin = async (e) => {
        e.preventDefault();
        if (isSubmittingRef.current) return;
        setError("");

        const trimmedUsername = username.trim();
        const trimmedPassword = password.trim();
        const usernameRegex = /^[A-Za-z0-9_]{3,30}$/;
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        if (!trimmedUsername || !trimmedPassword) {
            setError("Please enter both your username and password.");
            return;
        }

        if (!usernameRegex.test(trimmedUsername) && !emailRegex.test(trimmedUsername)) {
            setError("Enter a valid username or email address.");
            return;
        }

        if (trimmedPassword.length < 6) {
            setError("Password must be at least 6 characters long.");
            return;
        }

        isSubmittingRef.current = true;
        setIsSubmitting(true);
        try {
            const response = await api.post('/login.php', {
                username: trimmedUsername,
                password: trimmedPassword,
            });

            if (response?.data?.success) {
                const role = (response.data.role || '').toString().toLowerCase().trim();
                const authToken = response.data.auth_token || '';
                const uData = response.data.user || {};
                if (!authToken || !['admin', 'administrator', 'trainer', 'member'].includes(role)) {
                    setError("The server returned an invalid account session. Please try again.");
                    return;
                }
                // persist user info & token
                try {
                    localStorage.setItem('user', JSON.stringify({
                        id: uData.id,
                        first_name: uData.first_name || '',
                        middle_name: uData.middle_name || '',
                        last_name: uData.last_name || '',
                        full_name: uData.full_name || '',
                        username: uData.username || trimmedUsername,
                        email: uData.email || '',
                        role,
                        initial_approval: uData.initial_approval || null,
                        auth_token: authToken
                    }));
                    sessionStorage.removeItem('user');
                } catch {
                    setError('Unable to save your login session in this browser. Check storage permissions and try again.');
                    return;
                }
                if (role === 'admin' || role === 'administrator') {
                    navigate('/dashboard', { replace: true });
                } else if (role === 'trainer') {
                    navigate('/trainer', { replace: true });
                } else if (role === 'member') {
                    const approvalStatus = uData.initial_approval?.status;
                    navigate(approvalStatus && approvalStatus !== 'approved' ? '/member/pending-approval' : '/member/dashboard', { replace: true });
                } else {
                    navigate('/login', { replace: true });
                }
            } else {
                setError(response?.data?.message || "Incorrect username or password.");
            }
        } catch (err) {
            const message = err?.response?.status === 429
                ? "Sign-in is temporarily unavailable after repeated attempts. Please wait a bit before trying again."
                : err?.response?.data?.message ||
                    err?.message ||
                    "Login failed. Please check your connection and try again.";
            setError(message);
        } finally {
            isSubmittingRef.current = false;
            setIsSubmitting(false);
        }
    };

        return (
        <div className="min-h-screen bg-slate-100 text-slate-900">
            <div className="mx-auto flex min-h-screen max-w-md items-center justify-center px-4 py-10 sm:px-6">
                <form
                    onSubmit={handleLogin}
                    className="w-full rounded-[32px] border border-slate-200 bg-white p-8 shadow-xl shadow-slate-200/60 sm:p-10"
                >
                    <div className="mb-8 text-center">
                        <img
                            src={`${import.meta.env.BASE_URL}images/gym-logo.png`}
                            alt="Gym logo"
                            onError={(event) => { event.currentTarget.style.visibility = 'hidden' }}
                            className="mx-auto mb-5 h-25 w-37 object-contain sm:h-30 sm:w-45"
                        />
                        <p className="text-sm uppercase tracking-[0.3em] text-violet-600">Welcome back</p>
                        <h1 className="mt-3 text-3xl font-semibold text-slate-900">Login</h1>
                        <p className="mt-3 text-sm leading-6 text-slate-600">
                            Sign in to your account to continue.
                        </p>
                    </div>

                    <div className="space-y-5">
                        <label className="block text-sm text-slate-700">
                            Username
                            <input
                                type="text"
                                name="username"
                                value={username}
                                placeholder="Enter your username"
                                className="mt-3 w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-violet-400 focus:bg-white"
                                onChange={(e) => setUsername(e.target.value)}
                            />
                        </label>

                        <label className="block text-sm text-slate-700">
                            Password
                            <div className="relative mt-3">
                                <input
                                    type={showPassword ? 'text' : 'password'}
                                    name="password"
                                    value={password}
                                    placeholder="Enter your password"
                                    className="w-full rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-violet-400 focus:bg-white"
                                    onChange={(e) => setPassword(e.target.value)}
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowPassword((s) => !s)}
                                    className="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-slate-500"
                                >
                                    {showPassword ? 'Hide' : 'Show'}
                                </button>
                            </div>
                        </label>
                    </div>

                    {error && (
                        <p className="mt-6 rounded-3xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                            {error}
                        </p>
                    )}

                    <button
                        type="submit"
                        disabled={isSubmitting}
                        aria-busy={isSubmitting}
                        className="mt-6 inline-flex w-full items-center justify-center rounded-3xl bg-violet-700 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-violet-300/30 transition hover:bg-violet-800 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {isSubmitting ? "Logging in..." : "Login"}
                    </button>

                    <div className="mt-4 text-center text-sm">
                        <Link to="/forgot-password" className="font-semibold text-violet-600 transition hover:text-violet-800">
                            Forgot password?
                        </Link>
                    </div>

                    <div className="mt-6 flex items-center justify-between text-sm text-slate-600">
                        <p>Don’t have an account?</p>
                        <Link to="/register" className="font-semibold text-violet-600 transition hover:text-violet-800">
                            Register
                        </Link>
                    </div>
                </form>
            </div>
        </div>
    );
}

export default Login;