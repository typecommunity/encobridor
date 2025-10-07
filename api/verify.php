/**
 * Cloaker Pro - Fingerprinting System Integration
 * Cliente-side fingerprint detection + API integration
 */

(function() {
    'use strict';
    
    const CloakerFingerprint = {
        
        /**
         * Gerar fingerprint completo
         */
        async generate() {
            const fp = {
                timestamp: Date.now(),
                
                // Básico
                userAgent: navigator.userAgent,
                language: navigator.language,
                languages: navigator.languages?.join(',') || '',
                platform: navigator.platform,
                hardwareConcurrency: navigator.hardwareConcurrency,
                deviceMemory: navigator.deviceMemory,
                
                // Tela
                screen: {
                    width: screen.width,
                    height: screen.height,
                    availWidth: screen.availWidth,
                    availHeight: screen.availHeight,
                    colorDepth: screen.colorDepth,
                    pixelDepth: screen.pixelDepth
                },
                
                // Viewport
                viewport: {
                    width: window.innerWidth,
                    height: window.innerHeight
                },
                
                // Timezone
                timezone: {
                    offset: new Date().getTimezoneOffset(),
                    name: Intl.DateTimeFormat().resolvedOptions().timeZone
                },
                
                // Plugins
                plugins: this.getPlugins(),
                
                // Canvas
                canvas: await this.getCanvasFingerprint(),
                
                // WebGL
                webgl: this.getWebGLFingerprint(),
                
                // Fontes
                fonts: await this.detectFonts(),
                
                // Touch support
                touchSupport: this.getTouchSupport(),
                
                // Cookies e Storage
                cookies: navigator.cookieEnabled,
                localStorage: this.checkLocalStorage(),
                sessionStorage: this.checkSessionStorage(),
                
                // Audio
                audio: await this.getAudioFingerprint(),
                
                // Comportamento
                behavior: {
                    mouseMovements: 0,
                    clicks: 0,
                    keyPresses: 0,
                    scrolls: 0
                }
            };
            
            // Gerar hash
            const hash = await this.hashFingerprint(fp);
            
            return {
                fingerprint: hash,
                data: fp
            };
        },
        
        /**
         * Obter plugins
         */
        getPlugins() {
            const plugins = [];
            for (let i = 0; i < navigator.plugins.length; i++) {
                plugins.push(navigator.plugins[i].name);
            }
            return plugins.sort().join(',');
        },
        
        /**
         * Canvas fingerprinting
         */
        async getCanvasFingerprint() {
            try {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                
                canvas.width = 200;
                canvas.height = 50;
                
                // Desenhar texto
                ctx.textBaseline = 'top';
                ctx.font = '14px Arial';
                ctx.textBaseline = 'alphabetic';
                ctx.fillStyle = '#f60';
                ctx.fillRect(125, 1, 62, 20);
                ctx.fillStyle = '#069';
                ctx.fillText('Cloaker Pro 🔒', 2, 15);
                ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
                ctx.fillText('Fingerprint Test', 4, 17);
                
                return canvas.toDataURL();
            } catch (e) {
                return 'error';
            }
        },
        
        /**
         * WebGL fingerprinting
         */
        getWebGLFingerprint() {
            try {
                const canvas = document.createElement('canvas');
                const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
                
                if (!gl) return 'not_supported';
                
                const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
                
                return {
                    vendor: gl.getParameter(gl.VENDOR),
                    renderer: gl.getParameter(gl.RENDERER),
                    version: gl.getParameter(gl.VERSION),
                    shadingLanguageVersion: gl.getParameter(gl.SHADING_LANGUAGE_VERSION),
                    unmaskedVendor: debugInfo ? gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL) : '',
                    unmaskedRenderer: debugInfo ? gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) : ''
                };
            } catch (e) {
                return 'error';
            }
        },
        
        /**
         * Detectar fontes instaladas
         */
        async detectFonts() {
            const baseFonts = ['monospace', 'sans-serif', 'serif'];
            const testFonts = [
                'Arial', 'Verdana', 'Times New Roman', 'Courier New', 
                'Georgia', 'Palatino', 'Garamond', 'Bookman',
                'Comic Sans MS', 'Trebuchet MS', 'Impact'
            ];
            
            const detected = [];
            const testString = "mmmmmmmmmmlli";
            const testSize = '72px';
            
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            
            // Medir largura com fonte base
            const baseWidths = {};
            baseFonts.forEach(baseFont => {
                ctx.font = testSize + ' ' + baseFont;
                baseWidths[baseFont] = ctx.measureText(testString).width;
            });
            
            // Testar cada fonte
            for (const font of testFonts) {
                let isDetected = false;
                for (const baseFont of baseFonts) {
                    ctx.font = testSize + ' ' + font + ', ' + baseFont;
                    const width = ctx.measureText(testString).width;
                    if (width !== baseWidths[baseFont]) {
                        isDetected = true;
                        break;
                    }
                }
                if (isDetected) {
                    detected.push(font);
                }
            }
            
            return detected.join(',');
        },
        
        /**
         * Touch support
         */
        getTouchSupport() {
            return {
                maxTouchPoints: navigator.maxTouchPoints || 0,
                touchEvent: 'ontouchstart' in window,
                touchStart: 'ontouchstart' in window
            };
        },
        
        /**
         * Verificar localStorage
         */
        checkLocalStorage() {
            try {
                localStorage.setItem('_test', '1');
                localStorage.removeItem('_test');
                return true;
            } catch (e) {
                return false;
            }
        },
        
        /**
         * Verificar sessionStorage
         */
        checkSessionStorage() {
            try {
                sessionStorage.setItem('_test', '1');
                sessionStorage.removeItem('_test');
                return true;
            } catch (e) {
                return false;
            }
        },
        
        /**
         * Audio fingerprinting
         */
        async getAudioFingerprint() {
            try {
                const AudioContext = window.AudioContext || window.webkitAudioContext;
                if (!AudioContext) return 'not_supported';
                
                const context = new AudioContext();
                const oscillator = context.createOscillator();
                const analyser = context.createAnalyser();
                const gainNode = context.createGain();
                const scriptProcessor = context.createScriptProcessor(4096, 1, 1);
                
                gainNode.gain.value = 0;
                oscillator.type = 'triangle';
                oscillator.connect(analyser);
                analyser.connect(scriptProcessor);
                scriptProcessor.connect(gainNode);
                gainNode.connect(context.destination);
                oscillator.start(0);
                
                const data = new Float32Array(analyser.frequencyBinCount);
                analyser.getFloatFrequencyData(data);
                
                oscillator.stop();
                context.close();
                
                return Array.from(data.slice(0, 30)).reduce((a, b) => a + b, 0);
            } catch (e) {
                return 'error';
            }
        },
        
        /**
         * Gerar hash do fingerprint
         */
        async hashFingerprint(data) {
            const str = JSON.stringify(data);
            const buffer = new TextEncoder().encode(str);
            const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
            const hashArray = Array.from(new Uint8Array(hashBuffer));
            return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
        },
        
        /**
         * Monitorar comportamento
         */
        startBehaviorTracking() {
            let mouseMovements = 0;
            let clicks = 0;
            let keyPresses = 0;
            let scrolls = 0;
            
            document.addEventListener('mousemove', () => mouseMovements++);
            document.addEventListener('click', () => clicks++);
            document.addEventListener('keypress', () => keyPresses++);
            window.addEventListener('scroll', () => scrolls++);
            
            return {
                getBehavior: () => ({
                    mouseMovements,
                    clicks,
                    keyPresses,
                    scrolls
                })
            };
        },
        
        /**
         * Enviar fingerprint para servidor (INTEGRAÇÃO COM API)
         */
        async sendToAPI(fingerprintData, campaignSlug) {
            try {
                const response = await fetch('/api/verify.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Fingerprint': fingerprintData.fingerprint
                    },
                    body: JSON.stringify({
                        campaign: campaignSlug,
                        fingerprint_data: fingerprintData,
                        include_details: true
                    })
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const result = await response.json();
                
                console.log('✅ Fingerprint enviado com sucesso:', result);
                
                // Processar resposta da API
                this.handleAPIResponse(result);
                
                return result;
                
            } catch (e) {
                console.error('❌ Erro ao enviar fingerprint:', e);
                return null;
            }
        },
        
        /**
         * Processar resposta da API
         */
        handleAPIResponse(response) {
            if (!response.success) {
                console.warn('⚠️ API retornou erro:', response.error);
                return;
            }
            
            // Verificar decisão
            if (response.decision === 'money') {
                console.log('💰 Visitante aprovado para página MONEY');
                
                // Se modo cloak, executar código de cloaking
                if (response.mode === 'cloak' && response.cloak_code) {
                    console.log('🎭 Aplicando cloaking...');
                    eval(response.cloak_code);
                }
                // Se modo redirect, redirecionar
                else if (response.mode === 'redirect' && response.redirect_url) {
                    console.log('↗️ Redirecionando para:', response.redirect_url);
                    window.location.href = response.redirect_url;
                }
            } else {
                console.log('🛡️ Visitante direcionado para página SAFE');
            }
            
            // Log de detalhes se disponível
            if (response.details) {
                console.log('📊 Detalhes da análise:', response.details);
            }
            
            // Log de fingerprint
            if (response.fingerprint) {
                console.log('🔐 Fingerprint:', response.fingerprint);
                
                if (response.fingerprint.suspicious) {
                    console.warn('⚠️ Fingerprint marcado como suspeito!');
                    console.warn('Risk Score:', response.fingerprint.risk_score);
                }
            }
        },
        
        /**
         * Inicializar sistema COM INTEGRAÇÃO
         */
        async init(options = {}) {
            const {
                campaignSlug = null,
                autoSend = true,
                behaviorUpdateInterval = 30000
            } = options;
            
            try {
                // Marcar JS como habilitado
                document.cookie = '_js_check=1; path=/; max-age=3600; SameSite=Lax';
                
                console.log('🔒 Inicializando Cloaker Pro Fingerprint...');
                
                // Gerar fingerprint
                const fp = await this.generate();
                
                console.log('✅ Fingerprint gerado:', fp.fingerprint.substring(0, 16) + '...');
                
                // Salvar em cookie
                document.cookie = `_fp=${fp.fingerprint}; path=/; max-age=86400; SameSite=Lax`;
                
                // Enviar para API se autoSend estiver ativado e tiver campaignSlug
                if (autoSend && campaignSlug) {
                    console.log('📤 Enviando para API...');
                    await this.sendToAPI(fp, campaignSlug);
                }
                
                // Iniciar tracking de comportamento
                const behaviorTracker = this.startBehaviorTracking();
                
                // Atualizar comportamento periodicamente
                setInterval(async () => {
                    const behavior = behaviorTracker.getBehavior();
                    fp.data.behavior = behavior;
                    
                    // Re-hash com novo comportamento
                    fp.fingerprint = await this.hashFingerprint(fp.data);
                    
                    // Enviar atualização se tiver campaignSlug
                    if (campaignSlug) {
                        await this.sendToAPI(fp, campaignSlug);
                    }
                }, behaviorUpdateInterval);
                
                return fp;
                
            } catch (error) {
                console.error('❌ Erro ao inicializar fingerprint:', error);
                return null;
            }
        }
    };
    
    // Expor globalmente
    window.CloakerFingerprint = CloakerFingerprint;
    
    // Auto-inicializar se tiver data-campaign no script tag
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            const scriptTag = document.querySelector('script[data-campaign]');
            if (scriptTag) {
                const campaignSlug = scriptTag.getAttribute('data-campaign');
                CloakerFingerprint.init({ campaignSlug });
            }
        });
    } else {
        const scriptTag = document.querySelector('script[data-campaign]');
        if (scriptTag) {
            const campaignSlug = scriptTag.getAttribute('data-campaign');
            CloakerFingerprint.init({ campaignSlug });
        }
    }
    
})();