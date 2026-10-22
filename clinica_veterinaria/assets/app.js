// VetSystem — utilitários globais compartilhados entre as páginas

const API = 'api/index.php';
let csrfTokenValue = '';

async function getCsrfToken() {
  if (!csrfTokenValue) {
    const res = await fetch(API + '/csrf');
    const data = await res.json();
    csrfTokenValue = data.csrf_token;
  }
  return csrfTokenValue;
}

// autenticação
function getUsuario() {
  try { return JSON.parse(sessionStorage.getItem('usuario')); }
  catch { return null; }
}
function isAdmin() {
  return getUsuario()?.perfil === 'admin';
}
function requireAuth() {
  if (!getUsuario()) window.location.href = 'login.html';
}
function logout() {
  getCsrfToken().then(token => fetch(API + '/login', { method: 'DELETE', headers: { 'X-CSRF-Token': token } })).finally(() => {
    sessionStorage.removeItem('usuario');
    window.location.href = 'login.html';
  });
}

async function api(path, options = {}) {
  const method = (options.method || 'GET').toUpperCase();
  const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
  if (['POST', 'PUT', 'DELETE'].includes(method)) headers['X-CSRF-Token'] = await getCsrfToken();
  const res = await fetch(API + path, { headers, ...options });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.erro || `Erro ${res.status}`);
  return data;
}

function toast(msg, type = 'success') {
  let container = document.querySelector('.toast-container');
  if (!container) {
    container = document.createElement('div');
    container.className = 'toast-container';
    document.body.appendChild(container);
  }
  const t = document.createElement('div');
  t.className = `toast ${type}`;
  const icon = type === 'success' ? '✔' : type === 'error' ? '✖' : 'ℹ';
  t.innerHTML = `<span>${icon}</span> ${msg}`;
  container.appendChild(t);
  setTimeout(() => t.remove(), 3500);
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[char]));
}
function escapeJsAttr(value) {
  return escapeHtml(JSON.stringify(String(value ?? '')));
}

function openModal(id) {
  document.getElementById(id).classList.add('open');
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-overlay')) e.target.classList.remove('open');
});

// ícones — svg inline, sem depender de fonte de ícone externa
const ICONS = {
  dashboard: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>',
  clientes: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.3"/><path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6"/></svg>',
  pacientes: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="7" cy="6.5" r="1.6"/><circle cx="12" cy="4.5" r="1.6"/><circle cx="17" cy="6.5" r="1.6"/><circle cx="19.2" cy="11" r="1.6"/><path d="M12 10.3c-3.2 0-6.6 2.4-6.6 5.9 0 2 1.6 3.2 3.5 3.2 1.1 0 1.9-.5 3.1-.5s2 .5 3.1.5c1.9 0 3.5-1.2 3.5-3.2 0-3.5-3.4-5.9-6.6-5.9z"/></svg>',
  veterinarios: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3v6a3 3 0 0 0 6 0V3"/><path d="M18 3v4"/><circle cx="18" cy="10" r="2"/><path d="M9 9v3a6 6 0 0 0 6 6"/></svg>',
  agendamentos: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="1.5"/><path d="M3 9.5h18"/><path d="M8 3v4M16 3v4"/></svg>',
  consultas: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="3.5" width="14" height="17" rx="1.5"/><path d="M9 3.3v-.1A1.6 1.6 0 0 1 10.6 1.6h2.8A1.6 1.6 0 0 1 15 3.2v.1"/><path d="M8.5 11h7M8.5 14.5h7M8.5 18h4"/></svg>',
  search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="10.5" cy="10.5" r="6.5"/><path d="M20 20l-4.6-4.6"/></svg>',
  edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
  trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16"/><path d="M9 7V4.5A1.5 1.5 0 0 1 10.5 3h3A1.5 1.5 0 0 1 15 4.5V7"/><path d="M6 7l1 12.5A1.5 1.5 0 0 0 8.5 21h7a1.5 1.5 0 0 0 1.5-1.5L18 7"/></svg>',
  history: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v4h4"/><path d="M12 8v4l3 2"/></svg>',
  menu: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>',
};

function toggleSidebar() {
  document.getElementById('sidebar')?.classList.toggle('open');
  document.getElementById('sidebarBackdrop')?.classList.toggle('open');
}
function closeSidebar() {
  document.getElementById('sidebar')?.classList.remove('open');
  document.getElementById('sidebarBackdrop')?.classList.remove('open');
}

function initNav(active) {
  const menuBtn = document.getElementById('menuToggleBtn');
  if (menuBtn) menuBtn.innerHTML = ICONS.menu;
  const u = getUsuario();
  if (u) {
    const el = document.getElementById('sidebarUser');
    if (el) el.innerHTML = `<strong>${u.nome}</strong>${u.perfil}`;
  }
  document.querySelectorAll('.nav-item').forEach(a => {
    a.classList.remove('active');
    if (a.dataset.page === active) a.classList.add('active');
  });
  document.getElementById('logoutBtn')?.addEventListener('click', logout);
}

function renderSidebar(active) {
  const links = [
    { page: 'dashboard',    icon: ICONS.dashboard,    label: 'Dashboard',    href: 'index.html' },
    { page: 'clientes',     icon: ICONS.clientes,     label: 'Clientes',      href: 'clientes.html' },
    { page: 'pacientes',    icon: ICONS.pacientes,    label: 'Pacientes',     href: 'pacientes.html' },
    { page: 'veterinarios', icon: ICONS.veterinarios, label: 'Veterinários',  href: 'veterinarios.html' },
    { page: 'agendamentos', icon: ICONS.agendamentos, label: 'Agendamentos',  href: 'agendamentos.html' },
    { page: 'consultas',    icon: ICONS.consultas,    label: 'Consultas',     href: 'consultas.html' },
  ];
  return `
  <div class="sidebar-logo">
    <div class="logo-icon">${ICONS.pacientes}</div>
    <span>VetSystem<small>Clínica Veterinária</small></span>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-label">Menu</div>
    ${links.map(l => `
      <a href="${l.href}" class="nav-item${l.page === active ? ' active' : ''}" data-page="${l.page}">
        <span class="nav-icon">${l.icon}</span>${l.label}
      </a>`).join('')}
  </nav>
  <div class="sidebar-footer">
    <div id="sidebarUser"></div>
    <button id="logoutBtn" class="btn btn-outline btn-sm mt-2" style="width:100%;justify-content:center">
      Sair
    </button>
  </div>`;
}

function fmtDate(s) {
  if (!s) return '—';
  const d = new Date(s.includes('T') ? s : s + 'T00:00:00');
  return d.toLocaleDateString('pt-BR');
}
function fmtDateTime(s) {
  if (!s) return '—';
  const d = new Date(s.replace(' ', 'T'));
  return d.toLocaleString('pt-BR', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
}
function fmtCPF(s) {
  if (!s) return '—';
  return s.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
}
function fmtTelefone(s) {
  if (!s) return '—';
  const digits = s.replace(/\D/g, '');
  if (digits.length === 11) return digits.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
  if (digits.length === 10) return digits.replace(/(\d{2})(\d{4})(\d{4})/, '($1) $2-$3');
  return s;
}
function cpfValido(value) {
  const cpf = value.replace(/\D/g, '');
  if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
  for (let index = 9; index < 11; index++) {
    let total = 0;
    for (let position = 0; position < index; position++) total += Number(cpf[position]) * (index + 1 - position);
    const digit = (total * 10) % 11 % 10;
    if (digit !== Number(cpf[index])) return false;
  }
  return true;
}

const STATUS_BADGE = {
  agendado:   '<span class="badge badge-primary">Agendado</span>',
  confirmado: '<span class="badge badge-success">Confirmado</span>',
  cancelado:  '<span class="badge badge-danger">Cancelado</span>',
  realizado:  '<span class="badge badge-gray">Realizado</span>',
};
