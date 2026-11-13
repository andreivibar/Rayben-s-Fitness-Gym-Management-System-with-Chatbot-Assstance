import axios from 'axios';
import { clearAuthState } from '../utils/authState'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || (import.meta.env.DEV ? '/api' : '/Gym-Management-System/backend/api/'),
});

api.interceptors.request.use((config) => {
  let user = null;
  const storages = [localStorage, sessionStorage];
  for (const store of storages) {
    try {
      const raw = store.getItem('user');
      if (raw) {
        user = JSON.parse(raw);
        if (user?.auth_token) break;
      }
    } catch {
      // try next storage
    }
  }
  if (user) {
    if (user?.auth_token) {
      config.headers.Authorization = `Bearer ${user.auth_token}`;
      config.headers['X-Auth-Token'] = user.auth_token;
    }
    if (user?.email) {
      config.headers['X-User-Email'] = user.email;
    }
  }
  return config;
}, (error) => Promise.reject(error));

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error?.response?.status === 401) {
      clearAuthState()
      window.dispatchEvent(new Event('gym:auth-invalidated'))
    }
    return Promise.reject(error);
  }
);

export default api;
