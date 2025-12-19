const TOKEN_KEY = 'finhub_token';
const PROFILE_KEY = 'finhub_profile';

export const authStore = {
  getToken() {
    return localStorage.getItem(TOKEN_KEY);
  },
  setToken(token) {
    localStorage.setItem(TOKEN_KEY, token);
  },
  clearToken() {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(PROFILE_KEY);
  },
  setProfile(profile) {
    localStorage.setItem(PROFILE_KEY, JSON.stringify(profile));
  },
  getProfile() {
    const value = localStorage.getItem(PROFILE_KEY);
    return value ? JSON.parse(value) : null;
  },
};
