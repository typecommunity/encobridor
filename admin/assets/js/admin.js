/**
 * Cloaker Pro - Admin JavaScript
 * Scripts principais do painel administrativo
 */

// Configuração global
const CONFIG = {
    apiUrl: '../ajax/',
    refreshInterval: 30000, // 30 segundos
    toastDuration: 3000,
    chartColors: {
        primary: 'rgb(139, 92, 246)',
        success: 'rgb(16, 185, 129)',
        danger: 'rgb(239, 68, 68)',
        warning: 'rgb(251, 146, 60)',
        info: 'rgb(59, 130, 246)'
    }
};

// Gerenciador de CSRF Token
class CSRFManager {
    static getToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }
    
    static addToForm(form) {
        const token = this.getToken();
        if (token) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'csrf_token';
            input.value = token;
            form.appendChild(input);
        }
    }
    
    static addToHeaders(headers = {}) {
        const token = this.getToken();
        if (token) {
            headers['X-CSRF-Token'] = token;
        }
        return headers;
    }
}

// Sistema de Notificações
class Toast {
    static show(message, type = 'info', duration = CONFIG.toastDuration) {
        const toast = document.createElement('div');
        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };
        
        const colors = {
            success: 'bg-green-600',
            error: 'bg-red-600',
            warning: 'bg-yellow-600',
            info: 'bg-blue-600'
        };
        
        toast.className = `fixed bottom-4 right-4 ${colors[type]} text-white px-6 py-3 rounded-lg shadow-lg z-50 flex items-center gap-3 animate-slide-in`;
        toast.innerHTML = `
            <i class="fas ${icons[type]} text-lg"></i>
            <span>${message}</span>
        `;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.classList.add('animate-fade-out');
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }
    
    static success(message) { this.show(message, 'success'); }
    static error(message) { this.show(message, 'error'); }
    static warning(message) { this.show(message, 'warning'); }
    static info(message) { this.show(message, 'info'); }
}

// Gerenciador de Campanhas
class CampaignManager {
    constructor() {
        this.campaigns = [];
        this.currentPage = 1;
        this.perPage = 10;
    }
    
    async loadCampaigns(page = 1, filters = {}) {
        try {
            const params = new URLSearchParams({
                page,
                per_page: this.perPage,
                ...filters
            });
            
            const response = await fetch(`${CONFIG.apiUrl}get-campaigns.php?${params}`);
            const data = await response.json();
            
            if (data.success) {
                this.campaigns = data.campaigns;
                this.render();
            }
        } catch (error) {
            console.error('Error loading campaigns:', error);
            Toast.error('Erro ao carregar campanhas');
        }
    }
    
    async saveCampaign(formData) {
        try {
            const headers = CSRFManager.addToHeaders({
                'X-Requested-With': 'XMLHttpRequest'
            });
            
            const response = await fetch(`${CONFIG.apiUrl}save-campaign.php`, {
                method: 'POST',
                headers,
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                Toast.success(data.message);
                if (data.redirect) {
                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 1000);
                }
            } else {
                Toast.error(data.error || 'Erro ao salvar campanha');
            }
            
            return data;
        } catch (error) {
            console.error('Error saving campaign:', error);
            Toast.error('Erro ao salvar campanha');
            return { success: false };
        }
    }
    
    async deleteCampaign(campaignId) {
        if (!confirm('Tem certeza que deseja excluir esta campanha?')) {
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('id', campaignId);
            formData.append('csrf_token', CSRFManager.getToken());
            
            const response = await fetch(`${CONFIG.apiUrl}delete-campaign.php`, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                Toast.success('Campanha excluída com sucesso!');
                this.loadCampaigns(this.currentPage);
            } else {
                Toast.error(data.error || 'Erro ao excluir campanha');
            }
        } catch (error) {
            console.error('Error deleting campaign:', error);
            Toast.error('Erro ao excluir campanha');
        }
    }
    
    async duplicateCampaign(campaignId) {
        try {
            const formData = new FormData();
            formData.append('action', 'duplicate');
            formData.append('id', campaignId);
            formData.append('csrf_token', CSRFManager.getToken());
            
            const response = await fetch(`${CONFIG.apiUrl}campaign-action.php`, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                Toast.success('Campanha duplicada com sucesso!');
                this.loadCampaigns(this.currentPage);
            } else {
                Toast.error(data.error || 'Erro ao duplicar campanha');
            }
        } catch (error) {
            console.error('Error duplicating campaign:', error);
            Toast.error('Erro ao duplicar campanha');
        }
    }
    
    copyLink(slug) {
        const url = `${window.location.origin}/?${slug}`;
        
        if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(() => {
                Toast.success('Link copiado!');
            }).catch(() => {
                this.fallbackCopy(url);
            });
        } else {
            this.fallbackCopy(url);
        }
    }
    
    fallbackCopy(text) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        
        try {
            document.execCommand('copy');
            Toast.success('Link copiado!');
        } catch (err) {
            Toast.error('Erro ao copiar link');
        }
        
        document.body.removeChild(textarea);
    }
    
    render() {
        // Implementar renderização da tabela de campanhas
    }
}

// Gerenciador de Estatísticas
class StatsManager {
    constructor() {
        this.charts = {};
        this.autoRefresh = null;
    }
    
    async loadStats(type = 'dashboard', period = 'today', campaignId = null) {
        try {
            const params = new URLSearchParams({
                type,
                period,
                campaign_id: campaignId
            });
            
            const response = await fetch(`${CONFIG.apiUrl}get-stats.php?${params}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            const data = await response.json();
            
            if (data.success || data.data) {
                this.updateStats(data.data || data);
                this.updateCharts(data.data || data);
            }
        } catch (error) {
            console.error('Error loading stats:', error);
        }
    }
    
    updateStats(data) {
        // Atualizar cards de estatísticas
        Object.keys(data).forEach(key => {
            const element = document.querySelector(`[data-stat="${key}"]`);
            if (element) {
                const value = data[key];
                if (typeof value === 'number') {
                    element.textContent = this.formatNumber(value);
                } else {
                    element.textContent = value;
                }
            }
        });
    }
    
    updateCharts(data) {
        // Atualizar gráfico de tráfego por hora
        if (data.hourly_traffic && this.charts.traffic) {
            this.charts.traffic.data.labels = data.hourly_traffic.map(d => d.hour);
            this.charts.traffic.data.datasets[0].data = data.hourly_traffic.map(d => d.visitors);
            this.charts.traffic.update();
        }
        
        // Atualizar gráfico de dispositivos
        if (data.device_breakdown && this.charts.devices) {
            this.charts.devices.data.labels = data.device_breakdown.map(d => d.type);
            this.charts.devices.data.datasets[0].data = data.device_breakdown.map(d => d.count);
            this.charts.devices.update();
        }
    }
    
    initCharts() {
        // Gráfico de Tráfego
        const trafficCanvas = document.getElementById('trafficChart');
        if (trafficCanvas) {
            this.charts.traffic = new Chart(trafficCanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Visitantes',
                        data: [],
                        borderColor: CONFIG.chartColors.primary,
                        backgroundColor: CONFIG.chartColors.primary + '20',
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        }
        
        // Gráfico de Dispositivos
        const devicesCanvas = document.getElementById('devicesChart');
        if (devicesCanvas) {
            this.charts.devices = new Chart(devicesCanvas.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: [],
                    datasets: [{
                        data: [],
                        backgroundColor: [
                            CONFIG.chartColors.primary,
                            CONFIG.chartColors.info,
                            CONFIG.chartColors.success,
                            CONFIG.chartColors.warning
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'right' }
                    }
                }
            });
        }
    }
    
    startAutoRefresh(interval = CONFIG.refreshInterval) {
        this.stopAutoRefresh();
        this.autoRefresh = setInterval(() => {
            this.loadStats();
        }, interval);
    }
    
    stopAutoRefresh() {
        if (this.autoRefresh) {
            clearInterval(this.autoRefresh);
            this.autoRefresh = null;
        }
    }
    
    formatNumber(num) {
        if (num >= 1000000) {
            return (num / 1000000).toFixed(1) + 'M';
        } else if (num >= 1000) {
            return (num / 1000).toFixed(1) + 'K';
        }
        return num.toLocaleString();
    }
}

// Validador de Formulários
class FormValidator {
    static validateUrl(url) {
        try {
            new URL(url);
            return true;
        } catch {
            return false;
        }
    }
    
    static validateEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }
    
    static validateCampaignForm(form) {
        const errors = [];
        
        const name = form.querySelector('[name="name"]');
        if (!name || !name.value.trim()) {
            errors.push('Nome da campanha é obrigatório');
        }
        
        const safePage = form.querySelector('[name="safe_page"]');
        if (!safePage || !this.validateUrl(safePage.value)) {
            errors.push('URL da Safe Page é inválida');
        }
        
        const moneyPage = form.querySelector('[name="money_page"]');
        if (!moneyPage || !this.validateUrl(moneyPage.value)) {
            errors.push('URL da Money Page é inválida');
        }
        
        if (errors.length > 0) {
            errors.forEach(error => Toast.error(error));
            return false;
        }
        
        return true;
    }
}

// Inicialização
document.addEventListener('DOMContentLoaded', function() {
    // Inicializar gerenciadores
    window.campaignManager = new CampaignManager();
    window.statsManager = new StatsManager();
    
    // Inicializar gráficos se existirem
    if (typeof Chart !== 'undefined') {
        statsManager.initCharts();
    }
    
    // Carregar estatísticas iniciais
    if (document.querySelector('[data-stat]')) {
        statsManager.loadStats();
        statsManager.startAutoRefresh();
    }
    
    // Adicionar CSRF token a todos os formulários
    document.querySelectorAll('form').forEach(form => {
        CSRFManager.addToForm(form);
    });
    
    // Handler para formulário de campanha
    const campaignForm = document.getElementById('campaignForm');
    if (campaignForm) {
        campaignForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            if (!FormValidator.validateCampaignForm(this)) {
                return;
            }
            
            const submitBtn = this.querySelector('[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Salvando...';
            submitBtn.disabled = true;
            
            const formData = new FormData(this);
            const result = await campaignManager.saveCampaign(formData);
            
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    }
    
    // Handlers para ações de campanha
    document.addEventListener('click', function(e) {
        // Deletar campanha
        if (e.target.closest('[data-action="delete-campaign"]')) {
            const campaignId = e.target.closest('[data-campaign-id]').dataset.campaignId;
            campaignManager.deleteCampaign(campaignId);
        }
        
        // Duplicar campanha
        if (e.target.closest('[data-action="duplicate-campaign"]')) {
            const campaignId = e.target.closest('[data-campaign-id]').dataset.campaignId;
            campaignManager.duplicateCampaign(campaignId);
        }
        
        // Copiar link
        if (e.target.closest('[data-action="copy-link"]')) {
            const slug = e.target.closest('[data-slug]').dataset.slug;
            campaignManager.copyLink(slug);
        }
    });
    
    // Toggle password visibility
    document.querySelectorAll('[data-toggle-password]').forEach(button => {
        button.addEventListener('click', function() {
            const targetId = this.dataset.togglePassword;
            const input = document.getElementById(targetId);
            const icon = this.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    });
    
    // Filtros em tempo real
    document.querySelectorAll('[data-filter]').forEach(filter => {
        filter.addEventListener('change', function() {
            const filters = {};
            document.querySelectorAll('[data-filter]').forEach(f => {
                if (f.value) {
                    filters[f.dataset.filter] = f.value;
                }
            });
            campaignManager.loadCampaigns(1, filters);
        });
    });
    
    // Período de estatísticas
    const periodSelector = document.getElementById('statsPeriod');
    if (periodSelector) {
        periodSelector.addEventListener('change', function() {
            statsManager.loadStats('dashboard', this.value);
        });
    }
    
    // Cleanup ao sair da página
    window.addEventListener('beforeunload', function() {
        statsManager.stopAutoRefresh();
    });
    
    // Atalhos de teclado
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + S para salvar
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            const form = document.querySelector('form:not([data-no-shortcut])');
            if (form) {
                form.dispatchEvent(new Event('submit'));
            }
        }
        
        // Ctrl/Cmd + N para nova campanha
        if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
            e.preventDefault();
            if (window.location.pathname.includes('campaigns.php')) {
                window.location.href = 'campaign-create.php';
            }
        }
        
        // ESC para fechar modais
        if (e.key === 'Escape') {
            const modal = document.querySelector('.modal.active');
            if (modal) {
                modal.classList.remove('active');
            }
        }
    });
    
    // Modo escuro (se preferência do sistema)
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.classList.add('dark-mode');
    }
});

// Funções auxiliares globais
function confirmDelete(message = 'Tem certeza que deseja excluir?') {
    return confirm(message);
}

function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
}

function exportData(type, format = 'csv') {
    const url = `${CONFIG.apiUrl}export.php?type=${type}&format=${format}`;
    window.location.href = url;
}

// Auto-logout por inatividade
let inactivityTimer;
const INACTIVITY_TIMEOUT = 30 * 60 * 1000; // 30 minutos

function resetInactivityTimer() {
    clearTimeout(inactivityTimer);
    inactivityTimer = setTimeout(() => {
        alert('Sessão expirada por inatividade. Você será desconectado.');
        window.location.href = 'logout.php';
    }, INACTIVITY_TIMEOUT);
}

// Monitorar atividade do usuário
['mousedown', 'keypress', 'scroll', 'touchstart'].forEach(event => {
    document.addEventListener(event, resetInactivityTimer);
});

resetInactivityTimer();