/**
 * Cloaker Pro - Fingerprinting System v2.1
 * Client-side fingerprint detection
 * 
 * CORREÇÕES v2.1:
 * - Bug de detecção de fontes corrigido
 * - Endpoint correto para API
 * - Melhor tratamento de erros
 * - Compatível com Engine v2.1
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
                hardwareConcurrency: navigator.hardwareConcurrency || 0,
                deviceMemory: navigator.deviceMemory || 0,
                
                // Tela
                screen: {
                    width: screen.width,
                    height: screen.height,
                    availWidth: screen.availWidth,
                    availHeight: screen.availHeight,
                    colorDepth: screen.colorDepth,
                    pixelDepth: screen.pixelDepth,
                    pixelRatio: window.devicePixelRatio || 1
                },
                
                // Viewport
                viewport: {
                    width: window.innerWidth,
                    height: window.innerHeight
                },
                
                // Timezone
                timezone: {
                    offset: new Date().getTimezoneOffset(),
                    name: this.getTimezoneName()
                },
                
                // Plugins
                plugins: this.getPlugins(),
                
                // Canvas
                canvas: await this.getCanvasFingerprint(),
                
                // WebGL
                webgl: this.getWebGLFingerprint(),
                
                // Fontes (CORRIGIDO)
                fonts: await this.detectFonts(),
                
                // Touch support
                touchSupport: this.getTouchSupport(),
                
                // Cookies e Storage
                cookies: navigator.cookieEnabled,
                localStorage: this.checkLocalStorage(),
                sessionStorage: this.checkSessionStorage(),
                
                // Audio
                audio: await this.getAudioFingerprint(),
                
                // Media Devices
                mediaDevices: await this.getMediaDevices(),
                
                // Battery (se disponível)
                battery: await this.getBatteryInfo(),
                
                // Comportamento inicial
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
         * Obter timezone name com fallback
         */
        getTimezoneName() {
            try {
                return Intl.DateTimeFormat().resolvedOptions().timeZone;
            } catch (e) {
                return 'Unknown';
            }
        },
        
        /**
         * Obter plugins
         */
        getPlugins() {
            try {
                const plugins = [];
                for (let i = 0; i < navigator.plugins.length; i++) {
                    plugins.push(navigator.plugins[i].name);
                }
                return plugins.sort().join(',');
            } catch (e) {
                return '';
            }
        },
        
        /**
         * Canvas fingerprinting
         */
        async getCanvasFingerprint() {
            try {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                
                if (!ctx) return 'not_supported';
                
                canvas.width = 200;
                canvas.height = 50;
                
                // Desenhar texto com várias técnicas
                ctx.textBaseline = 'top';
                ctx.font = '14px Arial';
                ctx.textBaseline = 'alphabetic';
                ctx.fillStyle = '#f60';
                ctx.fillRect(125, 1, 62, 20);
                ctx.fillStyle = '#069';
                ctx.fillText('Cloaker Pro 🔒', 2, 15);
                ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
                ctx.fillText('Fingerprint Test', 4, 17);
                
                // Gerar hash do canvas
                const dataURL = canvas.toDataURL();
                return await this.simpleHash(dataURL);
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
                    vendor: gl.getParameter(gl.VENDOR) || '',
                    renderer: gl.getParameter(gl.RENDERER) || '',
                    version: gl.getParameter(gl.VERSION) || '',
                    shadingLanguageVersion: gl.getParameter(gl.SHADING_LANGUAGE_VERSION) || '',
                    unmaskedVendor: debugInfo ? gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL) : '',
                    unmaskedRenderer: debugInfo ? gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) : ''
                };
            } catch (e) {
                return 'error';
            }
        },
        
        /**
         * Detectar fontes instaladas - CORRIGIDO
         */
        async detectFonts() {
            try {
                const baseFonts = ['monospace', 'sans-serif', 'serif'];
                const testFonts = [
                    'Arial', 'Verdana', 'Times New Roman', 'Courier New', 
                    'Georgia', 'Palatino', 'Garamond', 'Bookman',
                    'Comic Sans MS', 'Trebuchet MS', 'Impact'
                ];
                
                const detectedFonts = []; // CORRIGIDO: variável renomeada
                const testString = "mmmmmmmmmmlli";
                const testSize = '72px';
                
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                
                if (!ctx) return '';
                
                // Medir largura com fonte base
                const baseWidths = {};
                baseFonts.forEach(baseFont => {
                    ctx.font = testSize + ' ' + baseFont;
                    baseWidths[baseFont] = ctx.measureText(testString).width;
                });
                
                // Testar cada fonte
                for (const font of testFonts) {
                    let isDetected = false; // CORRIGIDO: variável renomeada
                    for (const baseFont of baseFonts) {
                        ctx.font = testSize + ' ' + font + ', ' + baseFont;
                        const width = ctx.measureText(testString).width;
                        if (width !== baseWidths[baseFont]) {
                            isDetected = true;
                            break;
                        }
                    }
                    if (isDetected) {
                        detectedFonts.push(font);
                    }
                }
                
                return detectedFonts.join(',');
            } catch (e) {
                return 'error';
            }
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
                
                // Fechar contexto de forma assíncrona
                setTimeout(() => context.close(), 100);
                
                return Array.from(data.slice(0, 30)).reduce((a, b) => a + b, 0).toFixed(2);
            } catch (e) {
                return 'error';
            }
        },
        
        /**
         * NOVO: Obter Media Devices
         */
        async getMediaDevices() {
            try {
                if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
                    return 'not_supported';
                }
                
                const devices = await navigator.mediaDevices.enumerateDevices();
                return {
                    audioInput: devices.filter(d => d.kind === 'audioinput').length,
                    audioOutput: devices.filter(d => d.kind === 'audiooutput').length,
                    videoInput: devices.filter(d => d.kind === 'videoinput').length
                };
            } catch (e) {
                return 'error';
            }
        },
        
        /**
         * NOVO: Obter informações de bateria
         */
        async getBatteryInfo() {
            try {
                if (!navigator.getBattery) return 'not_supported';
                
                const battery = await navigator.getBattery();
                return {
                    charging: battery.charging,
                    level: Math.round(battery.level * 100)
                };
            } catch (e) {
                return 'not_supported';
            }
        },
        
        /**
         * Gerar hash SHA-256 do fingerprint
         */
        async hashFingerprint(data) {
            try {
                const str = JSON.stringify(data);
                const buffer = new TextEncoder().encode(str);
                const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
                const hashArray = Array.from(new Uint8Array(hashBuffer));
                return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
            } catch (e) {
                // Fallback: hash simples
                return this.simpleHash(JSON.stringify(data));
            }
        },
        
        /**
         * Hash simples como fallback
         */
        simpleHash(str) {
            let hash = 0;
            for (let i = 0; i < str.length; i++) {
                const char = str.charCodeAt(i);
                hash = ((hash << 5) - hash) + char;
                hash = hash & hash;
            }
            return Math.abs(hash).toString(16);
        },
        
        /**
         * Monitorar comportamento
         */
        startBehaviorTracking() {
            let mouseMovements = 0;
            let clicks = 0;
            let keyPresses = 0;
            let scrolls = 0;
            
            const throttle = (func, delay) => {
                let lastCall = 0;
                return function(...args) {
                    const now = Date.now();
                    if (now - lastCall < delay) return;
                    lastCall = now;
                    return func(...args);
                };
            };
            
            document.addEventListener('mousemove', throttle(() => mouseMovements++, 100));
            document.addEventListener('click', () => clicks++);
            document.addEventListener('keypress', () => keyPresses++);
            window.addEventListener('scroll', throttle(() => scrolls++, 200));
            
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
         * Enviar fingerprint para servidor - CORRIGIDO
         */
        async send(fingerprint) {
            try {
                // CORRIGIDO: endpoint correto
                const baseUrl = window.location.origin;
                const response = await fetch(baseUrl + '/api/fingerprint.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(fingerprint),
                    credentials: 'same-origin'
                });
                
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                
                return await response.json();
            } catch (e) {
                console.error('Fingerprint send error:', e);
                return null;
            }
        },
        
        /**
         * Inicializar sistema
         */
        async init() {
            try {
                // 1. Marcar JS como habilitado
                document.cookie = '_js=1; path=/; max-age=3600; SameSite=Lax';
                
                // 2. Gerar fingerprint
                const fp = await this.generate();
                
                // 3. Salvar em cookie
                document.cookie = `_fp=${fp.fingerprint}; path=/; max-age=86400; SameSite=Lax`;
                
                // 4. Enviar para servidor
                const response = await this.send(fp);
                
                // Se servidor retornou um ID, salvar
                if (response && response.id) {
                    document.cookie = `_fp_id=${response.id}; path=/; max-age=86400; SameSite=Lax`;
                }
                
                // 5. Iniciar tracking de comportamento
                const behaviorTracker = this.startBehaviorTracking();
                
                // 6. Atualizar comportamento periodicamente (reduzido para 60s)
                const updateInterval = setInterval(async () => {
                    const behavior = behaviorTracker.getBehavior();
                    
                    // Só enviar se houve atividade
                    if (behavior.mouseMovements > 0 || behavior.clicks > 0 || 
                        behavior.keyPresses > 0 || behavior.scrolls > 0) {
                        
                        fp.data.behavior = behavior;
                        
                        // Re-hash com novo comportamento
                        fp.fingerprint = await this.hashFingerprint(fp.data);
                        
                        // Enviar atualização (silenciosamente)
                        await this.send({
                            fingerprint: fp.fingerprint,
                            behavior: behavior,
                            update: true
                        });
                    }
                }, 60000); // A cada 60 segundos
                
                // Limpar interval quando página descarregar
                window.addEventListener('beforeunload', () => {
                    clearInterval(updateInterval);
                });
                
                return fp;
            } catch (e) {
                console.error('Fingerprint init error:', e);
                return null;
            }
        }
    };
    
    // Auto-inicializar quando DOM estiver pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            CloakerFingerprint.init();
        });
    } else {
        // DOM já está pronto
        CloakerFingerprint.init();
    }
    
    // Expor globalmente (opcional - para debug)
    if (typeof window !== 'undefined') {
        window.CloakerFingerprint = CloakerFingerprint;
    }
    
})();