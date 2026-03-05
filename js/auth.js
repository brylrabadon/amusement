// Auth works with localStorage so login is instant (no server required).
// Seed admin on first load for demo. Replace with API calls when backend auth is ready.
const Auth = (() => {
  const USERS_KEY = 'amusepark_users';
  const SESSION_KEY = 'amusepark_session';

  function getUsers() {
    try {
      return JSON.parse(localStorage.getItem(USERS_KEY) || '[]');
    } catch (_) {
      return [];
    }
  }

  function saveUsers(users) {
    localStorage.setItem(USERS_KEY, JSON.stringify(users));
  }

  function getSession() {
    try {
      return JSON.parse(localStorage.getItem(SESSION_KEY) || 'null');
    } catch (_) {
      return null;
    }
  }

  function setSession(data) {
    localStorage.setItem(SESSION_KEY, JSON.stringify(data));
  }

  function clearSession() {
    localStorage.removeItem(SESSION_KEY);
  }

  // Seed default admin so login works without server
  (function seedAdmin() {
    const users = getUsers();
    if (!users.find(u => u.email === 'admin@amusepark.com')) {
      users.push({
        id: 1,
        full_name: 'Admin',
        email: 'admin@amusepark.com',
        password: 'Admin1234',
        phone: '',
        role: 'admin',
        created_at: new Date().toISOString()
      });
      saveUsers(users);
    }
  })();

  function register({ full_name, email, phone, password, role = 'customer' }) {
    const users = getUsers();
    if (users.find(u => u.email === email)) {
      return { success: false, message: 'Email is already registered.' };
    }
    const user = {
      id: Date.now(),
      full_name,
      email,
      phone: phone || '',
      password,
      role,
      created_at: new Date().toISOString()
    };
    users.push(user);
    saveUsers(users);
    const safe = { id: user.id, full_name, email, phone: user.phone, role, created_at: user.created_at };
    setSession(safe);
    return { success: true, user: safe };
  }

  function login(email, password) {
    const users = getUsers();
    const user = users.find(u => u.email === email && u.password === password);
    if (!user) return { success: false, message: 'Invalid email or password.' };
    const safe = { id: user.id, full_name: user.full_name, email: user.email, phone: user.phone || '', role: user.role, created_at: user.created_at };
    setSession(safe);
    return { success: true, user: safe };
  }

  function logout(redirect) {
    clearSession();
    window.location.href = redirect || 'login.html';
  }

  function currentUser() {
    return getSession();
  }

  function authHeader() {
    return {};
  }

  function requireAuth(role) {
    const session = getSession();
    if (!session) { window.location.href = '../login.html'; return null; }
    if (role && session.role !== role) { window.location.href = '../index.html'; return null; }
    return session;
  }

  function requireAuthPublic(role) {
    const session = getSession();
    if (!session) { window.location.href = 'login.html'; return null; }
    if (role && session.role !== role) { window.location.href = 'index.html'; return null; }
    return session;
  }

  return { register, login, logout, currentUser, requireAuth, requireAuthPublic, authHeader };
})();
