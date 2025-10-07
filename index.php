<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cloaker Pro - Sistema Profissional de Cloaking</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        dark: {
                            DEFAULT: '#000000',
                            secondary: '#0a0a0a',
                            tertiary: '#141414',
                            card: '#1a1a1a',
                            hover: '#242424',
                        },
                        silver: {
                            DEFAULT: '#c0c0c0',
                            light: '#e8e8e8',
                            dark: '#808080',
                            muted: '#a8a8a8',
                        },
                        accent: {
                            success: '#4ade80',
                            danger: '#f87171',
                            warning: '#fbbf24',
                            info: '#60a5fa',
                        }
                    },
                    backgroundImage: {
                        'gradient-silver': 'linear-gradient(135deg, #c0c0c0 0%, #808080 100%)',
                    },
                    boxShadow: {
                        'glow': '0 0 20px rgba(192, 192, 192, 0.15)',
                        'glow-lg': '0 0 30px rgba(192, 192, 192, 0.25)',
                    }
                }
            }
        }
    </script>
    
    <style>
        .bg-animated {
            background: linear-gradient(-45deg, #000000, #0a0a0a, #141414, #0a0a0a);
            background-size: 400% 400%;
            animation: gradient 15s ease infinite;
        }
        
        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                radial-gradient(circle at 20% 50%, rgba(192, 192, 192, 0.05) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(192, 192, 192, 0.05) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }
        
        .card-shadow {
            box-shadow: 0 20px 60px rgba(0,0,0,0.8), 0 0 40px rgba(192, 192, 192, 0.1);
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }
        
        .float {
            animation: float 3s ease-in-out infinite;
        }
        
        ::-webkit-scrollbar {
            width: 10px;
        }
        
        ::-webkit-scrollbar-track {
            background: #0a0a0a;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #808080;
            border-radius: 5px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #c0c0c0;
        }
        
        .feature-card {
            transition: all 0.3s ease;
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
        }
    </style>
</head>
<body class="bg-animated min-h-screen relative">
    
    <!-- Header -->
    <header class="relative z-50 border-b border-[#2a2a2a]">
        <div class="container mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 bg-gradient-silver rounded-full flex items-center justify-center shadow-glow">
                        <i class="fas fa-shield-alt text-2xl text-dark"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-silver">Cloaker Pro</h1>
                        <p class="text-xs text-silver-dark">Powered by AutoStacker</p>
                    </div>
                </div>
                <a href="admin/login.php" class="bg-gradient-silver hover:shadow-glow text-dark font-bold py-2 px-6 rounded-lg transition-all duration-300 transform hover:scale-105">
                    <i class="fas fa-sign-in-alt mr-2"></i>Acessar Painel
                </a>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="relative z-10 py-20 px-4">
        <div class="container mx-auto text-center">
            <div class="fade-in-up">
                <div class="inline-flex items-center justify-center w-32 h-32 bg-gradient-silver rounded-full shadow-glow-lg mb-8 float">
                    <i class="fas fa-shield-alt text-6xl text-dark"></i>
                </div>
                <h2 class="text-5xl md:text-6xl font-bold text-silver mb-4">
                    Sistema Profissional<br/>de <span class="bg-gradient-silver bg-clip-text text-transparent">Cloaking</span>
                </h2>
                <p class="text-xl text-silver-dark max-w-2xl mx-auto mb-8">
                    Proteja suas campanhas de tráfego pago com a tecnologia mais avançada de detecção e filtragem. Multi-tenant, analytics em tempo real e fingerprinting de última geração.
                </p>
                
                <!-- Price Badge -->
                <div class="inline-block bg-dark-card border-2 border-accent-success rounded-2xl p-6 mb-8 card-shadow">
                    <div class="text-accent-success text-sm font-bold uppercase tracking-wider mb-2">
                        <i class="fas fa-star mr-2"></i>Oferta Especial AutoStacker
                    </div>
                    <div class="text-5xl font-bold text-silver mb-2">GRÁTIS</div>
                    <div class="text-silver-dark text-lg mb-3">na sua estrutura</div>
                    <div class="flex items-center justify-center gap-4 text-sm text-silver-muted">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-check-circle text-accent-success"></i>
                            <span>Seu Domínio</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <i class="fas fa-check-circle text-accent-success"></i>
                            <span>Seu VPS</span>
                        </div>
                    </div>
                </div>
                
                <div class="flex flex-wrap items-center justify-center gap-4">
                    <a href="#recursos" class="bg-gradient-silver hover:shadow-glow text-dark font-bold py-4 px-8 rounded-lg transition-all duration-300 transform hover:scale-105 text-lg">
                        <i class="fas fa-rocket mr-2"></i>Começar Agora
                    </a>
                    <a href="#recursos" class="bg-dark-tertiary border border-silver hover:bg-dark-hover text-silver font-bold py-4 px-8 rounded-lg transition-all duration-300">
                        <i class="fas fa-info-circle mr-2"></i>Ver Recursos
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="recursos" class="relative z-10 py-20 px-4">
        <div class="container mx-auto">
            <div class="text-center mb-16">
                <h3 class="text-4xl font-bold text-silver mb-4">Recursos Premium</h3>
                <p class="text-silver-dark text-lg max-w-2xl mx-auto">
                    Tecnologia de ponta para proteger suas campanhas e maximizar seus resultados
                </p>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Feature 1 -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-2xl p-6 card-shadow feature-card">
                    <div class="w-16 h-16 bg-gradient-to-br from-purple-600 to-purple-800 rounded-xl flex items-center justify-center mb-4">
                        <i class="fas fa-users text-3xl text-white"></i>
                    </div>
                    <h4 class="text-xl font-bold text-silver mb-3">Sistema Multi-Tenant</h4>
                    <p class="text-silver-dark mb-4">
                        Gerencie múltiplos clientes em uma única instalação. Isolamento total de dados, planos customizados e limites configuráveis.
                    </p>
                    <ul class="space-y-2 text-sm text-silver-muted">
                        <li class="flex items-center gap-2">
                            <i class="fas fa-check text-accent-success"></i>
                            Isolamento completo de dados
                        </li>
                        <li class="flex items-center gap-2">
                            <i class="fas fa-check text-accent-success"></i>
                            Planos personalizáveis
                        </li>
                        <li class="flex items-center gap-2">
                            <i class="fas fa-check text-accent-success"></i>
                            Controle de limites e quotas
                        </li>
                    </ul>
                </div>

                <!-- Feature 2 -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-2xl p-6 card-shadow feature-card">
                    <div class="w-16 h-16 bg-gradient-to-br from-blue-600 to-blue-800 rounded-xl flex items-center justify-center mb-4">
                        <i class="fas fa-fingerprint text-3xl text-white"></i>
                    </div>
                    <h4 class="text-xl font-bold text-silver mb-3">Fingerprinting Avançado</h4>
                    <p class="text-silver-dark mb-4">
                        Análise profunda de impressões digitais com 50+ atributos. Canvas, WebGL, fontes e detecção comportamental.
                    </p>
                    <ul class="space-y-2 text-sm text-silver-muted">
                        <li class="flex items-center gap-2">
                            <i class="fas fa-check text-accent-success"></i>
                            Canvas & WebGL fingerprinting
                        </li>
                        <li class="flex items-center gap-2">
                            <i class="fas fa-check text-accent-success"></i>
                            Análise comportamental
                        </li>
                        <li class="flex items-center gap-2">
                            <i class="fas fa-check text-accent-success"></i>
                            Risk scoring automático
                        </li>
                    </ul>
                </div>

                <!-- Feature 3 -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-2xl p-6 card-shadow feature-card">
                    <div class="w-16 h-16 bg-gradient-to-br from-red-600 to-red-800 rounded-xl flex items-center justify-center mb-4">
                        <i class="fas fa-shield-virus text-3xl text-white"></i>
                    </div>
                    <h4 class="text-xl font-bold text-silver mb-3">Anti-Scraping Robusto</h4>
                    <p class="text-silver-dark mb-4">
                        Proteção contra Scrapy, Selenium, Puppeteer e ferramentas de automação. Rate limiting inteligente com Redis.
                    </p>
                    <ul class="space-y-2 text-sm text-silver-muted">
                        <li class="flex items-center gap-2">
                            <i class="fas fa-check text-accent-success"></i>
                            Detecção de 15+ ferramentas
                        </li>
                        <li class="flex items-center gap-2">
                            <i class="fas fa-check text-accent-success"></i>
                            Rate limiting configurável
                        </li>
                        <li class="flex items-center gap-2">
                            <i class="fas fa-check text-accent-success"></i>
                            Whitelist/Blacklist de IPs
                        </li>
                    </ul>
                </div>

                <!-- Feature 4 -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-2xl p-6 card-shadow feature-card">
                    <div class="w-16 h-16 bg-gradient-to-br from-green-600 to-green-800 rounded-xl flex items-center justify-center mb-4">
                        <i class="fas fa-chart-line text-3xl text-white"></i>
                    </div>
                    <h4 class="text-xl font-bold text-silver mb-3">Analytics em Tempo Real</h4>
                    <p class="text-silver-dark mb-4">
                        Dashboard completo com estatísticas detalhadas, gráficos interativos e geolocalização com 28+ países.
                    </p>
                    <ul class="space-y-2 text-sm text-silver-muted">
                        <li class="flex items-center gap-2">
                            <i class="fas fa-check text-accent-success"></i>
                            Estatísticas em tempo real
                        </li>
                        <li class="flex items-center gap-2">
                            <i class="fas fa-check text-accent-success"></i>
                            Análise geográfica avançada
                        </li>
                        <li class="flex items-center gap-2">
                            <i class="fas fa-check text-accent-success"></i>
                            Dispositivos e navegadores
                        </li>
                    </ul>
                </div>

                <!-- Feature 5 -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-2xl p-6 card-shadow feature-card">
                    <div class="w-16 h-16 bg-gradient-to-br from-yellow-600 to-yellow-800 rounded-xl flex items-center justify-center mb-4">
                        <i class="fas fa-filter text-3xl text-white"></i>
                    </div>
                    <h4 class="text-xl font-bold text-silver mb-3">Filtros Inteligentes</h4>
                    <p class="text-silver-dark mb-4">
                        Detecção automática de bots, VPN, TOR, proxies e datacenters. Filtros geográficos e de dispositivos.
                    </p>
                    <ul class="space-y-2 text-sm text-silver-muted">
                        <li class="flex items-center gap-2">
                            <i class="fas fa-check text-accent-success"></i>
                            Detecção de bots e crawlers
                        </li>
                        <li class="flex items-center gap-2">
                            <i class="fas fa-check text-accent-success"></i>
                            Bloqueio VPN/TOR/Proxy
                        </li>
                        <li class="flex items-center gap-2">
                            <i class="fas fa-check text-accent-success"></i>
                            Filtros GEO avançados
                        </li>
                    </ul>
                </div>

                <!-- Feature 6 -->
                <div class="bg-dark-card border border-[#2a2a2a] rounded-2xl p-6 card-shadow feature-card">
                    <div class="w-16 h-16 bg-gradient-to-br from-indigo-600 to-indigo-800 rounded-xl flex items-center justify-center mb-4">
                        <i class="fas fa-random text-3xl text-white"></i>
                    </div>
                    <h4 class="text-xl font-bold text-silver mb-3">Modos de Cloaking</h4>
                    <p class="text-silver-dark mb-4">
                        Redirect 302, Proxy reverso ou iFrame. Escolha o melhor método para suas campanhas de tráfego pago.
                    </p>
                    <ul class="space-y-2 text-sm text-silver-muted">
                        <li class="flex items-center gap-2">
                            <i class="fas fa-check text-accent-success"></i>
                            Redirecionamento 302
                        </li>
                        <li class="flex items-center gap-2">
                            <i class="fas fa-check text-accent-success"></i>
                            Proxy reverso
                        </li>
                        <li class="flex items-center gap-2">
                            <i class="fas fa-check text-accent-success"></i>
                            iFrame incorporado
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Security Section -->
    <section class="relative z-10 py-20 px-4 bg-dark-secondary">
        <div class="container mx-auto">
            <div class="text-center mb-16">
                <h3 class="text-4xl font-bold text-silver mb-4">Segurança de Nível Empresarial</h3>
                <p class="text-silver-dark text-lg max-w-2xl mx-auto">
                    Proteção máxima para suas campanhas e dados dos clientes
                </p>
            </div>

            <div class="grid md:grid-cols-4 gap-6">
                <div class="text-center p-6">
                    <div class="w-20 h-20 bg-gradient-to-br from-accent-success to-green-700 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-lock text-3xl text-white"></i>
                    </div>
                    <h5 class="text-lg font-bold text-silver mb-2">SSL/TLS</h5>
                    <p class="text-silver-dark text-sm">Criptografia ponta a ponta</p>
                </div>
                
                <div class="text-center p-6">
                    <div class="w-20 h-20 bg-gradient-to-br from-accent-info to-blue-700 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-database text-3xl text-white"></i>
                    </div>
                    <h5 class="text-lg font-bold text-silver mb-2">Isolamento Total</h5>
                    <p class="text-silver-dark text-sm">Dados separados por tenant</p>
                </div>
                
                <div class="text-center p-6">
                    <div class="w-20 h-20 bg-gradient-to-br from-accent-warning to-orange-700 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-user-shield text-3xl text-white"></i>
                    </div>
                    <h5 class="text-lg font-bold text-silver mb-2">2FA Ready</h5>
                    <p class="text-silver-dark text-sm">Autenticação de dois fatores</p>
                </div>
                
                <div class="text-center p-6">
                    <div class="w-20 h-20 bg-gradient-to-br from-purple-600 to-purple-800 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-history text-3xl text-white"></i>
                    </div>
                    <h5 class="text-lg font-bold text-silver mb-2">Auditoria</h5>
                    <p class="text-silver-dark text-sm">Logs detalhados de ações</p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="relative z-10 py-20 px-4">
        <div class="container mx-auto">
            <div class="bg-gradient-to-br from-dark-card to-dark-tertiary border border-silver rounded-3xl p-12 text-center card-shadow">
                <h3 class="text-4xl font-bold text-silver mb-4">
                    Pronto para Proteger Suas Campanhas?
                </h3>
                <p class="text-silver-dark text-lg max-w-2xl mx-auto mb-8">
                    Comece agora mesmo gratuitamente na sua estrutura. Domínio e VPS por sua conta.
                </p>
                
                <div class="flex flex-col sm:flex-row items-center justify-center gap-4 mb-8">
                    <div class="flex items-center gap-3 text-silver">
                        <i class="fas fa-check-circle text-accent-success text-xl"></i>
                        <span>Sem mensalidades</span>
                    </div>
                    <div class="flex items-center gap-3 text-silver">
                        <i class="fas fa-check-circle text-accent-success text-xl"></i>
                        <span>Setup gratuito</span>
                    </div>
                    <div class="flex items-center gap-3 text-silver">
                        <i class="fas fa-check-circle text-accent-success text-xl"></i>
                        <span>Suporte incluído</span>
                    </div>
                </div>
                
                <a href="login.php" class="inline-block bg-gradient-silver hover:shadow-glow-lg text-dark font-bold py-4 px-12 rounded-xl transition-all duration-300 transform hover:scale-110 text-lg">
                    <i class="fas fa-rocket mr-2"></i>Começar Agora
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="relative z-10 border-t border-[#2a2a2a] py-12 px-4">
        <div class="container mx-auto">
            <div class="grid md:grid-cols-4 gap-8 mb-8">
                <div>
                    <div class="flex items-center gap-2 mb-4">
                        <div class="w-10 h-10 bg-gradient-silver rounded-full flex items-center justify-center">
                            <i class="fas fa-shield-alt text-xl text-dark"></i>
                        </div>
                        <span class="text-xl font-bold text-silver">Cloaker Pro</span>
                    </div>
                    <p class="text-silver-dark text-sm">
                        Sistema profissional de cloaking para proteção de campanhas de tráfego pago.
                    </p>
                </div>
                
                <div>
                    <h6 class="text-silver font-bold mb-4 uppercase text-sm">Produto</h6>
                    <ul class="space-y-2 text-silver-dark text-sm">
                        <li><a href="#recursos" class="hover:text-silver transition-colors">Recursos</a></li>
                        <li><a href="#" class="hover:text-silver transition-colors">Documentação</a></li>
                        <li><a href="#" class="hover:text-silver transition-colors">API</a></li>
                        <li><a href="#" class="hover:text-silver transition-colors">Changelog</a></li>
                    </ul>
                </div>
                
                <div>
                    <h6 class="text-silver font-bold mb-4 uppercase text-sm">Suporte</h6>
                    <ul class="space-y-2 text-silver-dark text-sm">
                        <li><a href="#" class="hover:text-silver transition-colors">Central de Ajuda</a></li>
                        <li><a href="#" class="hover:text-silver transition-colors">Tutoriais</a></li>
                        <li><a href="#" class="hover:text-silver transition-colors">Contato</a></li>
                        <li><a href="#" class="hover:text-silver transition-colors">Status</a></li>
                    </ul>
                </div>
                
                <div>
                    <h6 class="text-silver font-bold mb-4 uppercase text-sm">Legal</h6>
                    <ul class="space-y-2 text-silver-dark text-sm">
                        <li><a href="#" class="hover:text-silver transition-colors">Termos de Uso</a></li>
                        <li><a href="#" class="hover:text-silver transition-colors">Privacidade</a></li>
                        <li><a href="#" class="hover:text-silver transition-colors">Cookies</a></li>
                        <li><a href="#" class="hover:text-silver transition-colors">Licença</a></li>
                    </ul>
                </div>
            </div>
            
            <div class="border-t border-[#2a2a2a] pt-8 text-center">
                <p class="text-silver-dark text-sm mb-4">
                    &copy; 2025 <span class="text-silver font-semibold">Cloaker Pro</span> - Powered by <span class="text-silver-light">AutoStacker</span>
                </p>
                <div class="flex items-center justify-center gap-6">
                    <a href="#" class="text-silver-dark hover:text-silver transition-colors">
                        <i class="fab fa-github text-xl"></i>
                    </a>
                    <a href="#" class="text-silver-dark hover:text-silver transition-colors">
                        <i class="fab fa-twitter text-xl"></i>
                    </a>
                    <a href="#" class="text-silver-dark hover:text-silver transition-colors">
                        <i class="fab fa-telegram text-xl"></i>
                    </a>
                    <a href="#" class="text-silver-dark hover:text-silver transition-colors">
                        <i class="fab fa-discord text-xl"></i>
                    </a>
                </div>
            </div>
        </div>
    </footer>

    <script>
        // Smooth scroll
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Intersection Observer for fade-in animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in-up');
                }
            });
        }, observerOptions);

        document.querySelectorAll('.feature-card').forEach(card => {
            observer.observe(card);
        });
    </script>
</body>
</html>