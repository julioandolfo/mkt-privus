<script setup>
import { ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import axios from 'axios';

const props = defineProps({
    htmlContent: { type: String, default: '' },
    mjmlContent: { type: String, default: '' },
    jsonContent: { type: [Object, null], default: null },
});

const emit = defineEmits(['update:htmlContent', 'update:mjmlContent', 'update:jsonContent', 'save']);

const editorContainer = ref(null);
let editor = null;

// Guard contra loop infinito de eventos
let isEmitting = false;
let emitTimer = null;

// State
const activePanel = ref('blocks');
const savedBlocks = ref([]);
const wooProducts = ref([]);
const wooSearch = ref('');
const wooLoading = ref(false);
const aiPrompt = ref('');
const aiType = ref('full_template');
const aiLoading = ref(false);
const aiResult = ref(null);

onMounted(async () => {
    await nextTick();
    await initEditor();
});

onBeforeUnmount(() => {
    if (emitTimer) clearTimeout(emitTimer);
    if (editor) {
        editor.destroy();
    }
});

async function initEditor() {
    const grapesjs = (await import('grapesjs')).default;

    // Importar CSS do GrapesJS
    if (!document.getElementById('grapesjs-css')) {
        const link = document.createElement('link');
        link.id = 'grapesjs-css';
        link.rel = 'stylesheet';
        link.href = 'https://unpkg.com/grapesjs/dist/css/grapes.min.css';
        document.head.appendChild(link);
    }

    editor = grapesjs.init({
        container: editorContainer.value,
        fromElement: false,
        height: '100%',
        width: 'auto',
        storageManager: false,
        noticeOnUnload: false,

        // CRUCIAL: Forçar estilos inline (não CSS classes).
        // Em GrapesJS 0.22+ o default mudou para true (usa classes).
        // Para email marketing, PRECISA ser inline pois clientes de email
        // não suportam <style> tags.
        avoidInlineStyle: false,

        // Canvas
        canvas: {
            styles: [
                'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap',
            ],
        },

        // Paineis simplificados
        panels: { defaults: [] },

        // Dispositivos
        deviceManager: {
            devices: [
                { name: 'Desktop', width: '' },
                { name: 'Tablet', width: '768px', widthMedia: '992px' },
                { name: 'Mobile', width: '375px', widthMedia: '480px' },
            ],
        },

        // Blocos padrão de email
        blockManager: {
            appendTo: '#gjs-blocks-container',
            blocks: getDefaultBlocks(),
        },

        // Style manager
        styleManager: {
            appendTo: '#gjs-styles-container',
            sectors: [
                {
                    name: 'Dimensão',
                    open: true,
                    properties: ['width', 'max-width', 'height', 'min-height', 'margin', 'padding'],
                },
                {
                    name: 'Tipografia',
                    open: false,
                    properties: ['font-family', 'font-size', 'font-weight', 'letter-spacing', 'color', 'line-height', 'text-align', 'text-decoration'],
                },
                {
                    name: 'Fundo',
                    open: false,
                    properties: [
                        'background-color',
                        'background-image',
                        'background-repeat',
                        'background-position',
                        'background-size',
                    ],
                },
                {
                    name: 'Borda',
                    open: false,
                    properties: ['border-radius', 'border', 'border-color', 'box-shadow'],
                },
            ],
        },

        // Plugins e config
        plugins: [],
        pluginsOpts: {},
    });

    // Configurar Asset Manager para upload
    editor.AssetManager.addType('image', {
        view: {
            onRender() {
                // Custom render se necessário
            },
        },
    });

    // Upload handler
    editor.on('asset:upload:start', () => {});
    editor.on('asset:upload:end', () => {});
    editor.on('asset:upload:response', (response) => {
        if (response?.data?.src) {
            editor.AssetManager.add({ src: response.data.src });
        }
    });

    // Carregar assets existentes
    loadExistingAssets();

    // Aplicar conteudo inicial
    if (props.jsonContent) {
        editor.loadProjectData(props.jsonContent);
    } else if (props.htmlContent) {
        editor.setComponents(props.htmlContent);
    }

    // Emitir mudanças com debounce para evitar loops e travamentos
    editor.on('component:update', debouncedEmitChanges);
    editor.on('component:add', debouncedEmitChanges);
    editor.on('component:remove', debouncedEmitChanges);
    editor.on('component:styleUpdate', debouncedEmitChanges);

    // Customizar tema escuro do editor + correcoes
    applyDarkTheme();

    // Adicionar bordas visuais nos componentes do canvas
    injectCanvasStyles();

    // Carregar blocos salvos
    loadSavedBlocks();
}

function getDefaultBlocks() {
    return [
        {
            id: 'section',
            label: 'Seção',
            category: 'Layout',
            media: `<svg viewBox="0 0 24 24" width="38" height="38"><rect x="2" y="4" width="20" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/><line x1="2" y1="10" x2="22" y2="10" stroke="currentColor" stroke-width="1" opacity="0.3"/></svg>`,
            content: `<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;border:1px dashed #e5e7eb;"><tr><td style="padding:20px;">
                <p style="margin:0;">Conteúdo da seção</p>
            </td></tr></table>`,
            attributes: { class: 'gjs-block-section' },
        },
        {
            id: 'columns-2',
            label: '2 Colunas',
            category: 'Layout',
            media: `<svg viewBox="0 0 24 24" width="38" height="38"><rect x="2" y="4" width="9" height="16" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="13" y="4" width="9" height="16" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>`,
            content: `<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;"><tr>
                <td width="50%" style="padding:10px;border:1px dashed #e5e7eb;" valign="top"><p>Coluna 1</p></td>
                <td width="50%" style="padding:10px;border:1px dashed #e5e7eb;" valign="top"><p>Coluna 2</p></td>
            </tr></table>`,
        },
        {
            id: 'columns-3',
            label: '3 Colunas',
            category: 'Layout',
            media: `<svg viewBox="0 0 24 24" width="38" height="38"><rect x="1" y="4" width="6" height="16" rx="1" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="9" y="4" width="6" height="16" rx="1" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="17" y="4" width="6" height="16" rx="1" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>`,
            content: `<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;"><tr>
                <td width="33%" style="padding:10px;border:1px dashed #e5e7eb;" valign="top"><p>Col 1</p></td>
                <td width="34%" style="padding:10px;border:1px dashed #e5e7eb;" valign="top"><p>Col 2</p></td>
                <td width="33%" style="padding:10px;border:1px dashed #e5e7eb;" valign="top"><p>Col 3</p></td>
            </tr></table>`,
        },
        {
            id: 'heading',
            label: 'Título',
            category: 'Texto',
            media: `<svg viewBox="0 0 24 24" width="38" height="38"><text x="4" y="18" font-size="18" font-weight="bold" fill="currentColor" font-family="sans-serif">H1</text></svg>`,
            content: '<h1 style="color:#333;font-family:Inter,Arial,sans-serif;font-size:28px;font-weight:700;margin:0 0 10px;">Título do Email</h1>',
        },
        {
            id: 'text-block',
            label: 'Texto',
            category: 'Texto',
            media: `<svg viewBox="0 0 24 24" width="38" height="38"><line x1="3" y1="7" x2="21" y2="7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><line x1="3" y1="12" x2="18" y2="12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><line x1="3" y1="17" x2="15" y2="17" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>`,
            content: '<p style="color:#555;font-family:Inter,Arial,sans-serif;font-size:16px;line-height:1.6;margin:0 0 15px;">Seu texto aqui. Clique para editar.</p>',
        },
        {
            id: 'image-block',
            label: 'Imagem',
            category: 'Mídia',
            media: `<svg viewBox="0 0 24 24" width="38" height="38"><rect x="3" y="3" width="18" height="18" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="8.5" cy="8.5" r="1.5" fill="currentColor"/><path d="M21 15l-5-5L5 21" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round"/></svg>`,
            content: '<img src="https://placehold.co/600x300/6366f1/ffffff?text=Sua+Imagem" style="width:100%;max-width:600px;height:auto;display:block;" alt="Imagem" />',
        },
        {
            id: 'button-cta',
            label: 'Botão CTA',
            category: 'Ação',
            media: `<svg viewBox="0 0 24 24" width="38" height="38"><rect x="3" y="7" width="18" height="10" rx="5" fill="currentColor" opacity="0.15"/><rect x="3" y="7" width="18" height="10" rx="5" fill="none" stroke="currentColor" stroke-width="1.5"/><line x1="8" y1="12" x2="16" y2="12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>`,
            content: `<table cellpadding="0" cellspacing="0" style="margin:20px auto;"><tr><td>
                <a href="#" style="display:inline-block;padding:14px 32px;background:#6366f1;color:#ffffff;font-family:Inter,Arial,sans-serif;font-size:16px;font-weight:600;text-decoration:none;border-radius:8px;">Saiba Mais</a>
            </td></tr></table>`,
        },
        {
            id: 'divider',
            label: 'Separador',
            category: 'Layout',
            media: `<svg viewBox="0 0 24 24" width="38" height="38"><line x1="3" y1="12" x2="21" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>`,
            content: '<hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0;" />',
        },
        {
            id: 'spacer',
            label: 'Espaçador',
            category: 'Layout',
            media: `<svg viewBox="0 0 24 24" width="38" height="38"><line x1="12" y1="4" x2="12" y2="20" stroke="currentColor" stroke-width="1.5" stroke-dasharray="2 2"/><line x1="6" y1="4" x2="18" y2="4" stroke="currentColor" stroke-width="1" opacity="0.5"/><line x1="6" y1="20" x2="18" y2="20" stroke="currentColor" stroke-width="1" opacity="0.5"/></svg>`,
            content: '<div style="height:30px;"></div>',
        },
        {
            id: 'hero-section',
            label: 'Hero',
            category: 'Seções',
            media: `<svg viewBox="0 0 24 24" width="38" height="38"><rect x="2" y="3" width="20" height="18" rx="2" fill="currentColor" opacity="0.1"/><rect x="2" y="3" width="20" height="18" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/><line x1="6" y1="8" x2="18" y2="8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="7" y1="12" x2="17" y2="12" stroke="currentColor" stroke-width="1" stroke-linecap="round" opacity="0.5"/><rect x="8" y="15" width="8" height="3" rx="1.5" fill="currentColor" opacity="0.3"/></svg>`,
            content: `<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#6366f1;border-radius:12px;">
                <tr><td style="padding:40px;text-align:center;">
                    <h1 style="color:#fff;font-family:Inter,Arial,sans-serif;font-size:32px;margin:0 0 15px;">Título Destaque</h1>
                    <p style="color:#c7d2fe;font-family:Inter,Arial,sans-serif;font-size:18px;margin:0 0 25px;">Subtítulo ou descrição breve do conteúdo.</p>
                    <a href="#" style="display:inline-block;padding:14px 32px;background:#fff;color:#6366f1;font-size:16px;font-weight:600;text-decoration:none;border-radius:8px;">Ação Principal</a>
                </td></tr>
            </table>`,
        },
        {
            id: 'product-card',
            label: 'Card Produto',
            category: 'E-commerce',
            media: `<svg viewBox="0 0 24 24" width="38" height="38"><rect x="4" y="2" width="16" height="20" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="6" y="4" width="12" height="8" rx="1" fill="currentColor" opacity="0.1"/><line x1="6" y1="15" x2="14" y2="15" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><line x1="6" y1="18" x2="11" y2="18" stroke="currentColor" stroke-width="1" stroke-linecap="round" opacity="0.5"/></svg>`,
            content: `<table width="100%" cellpadding="0" cellspacing="0" style="max-width:280px;margin:10px;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
                <tr><td><img src="https://placehold.co/280x280/f3f4f6/9ca3af?text=Produto" style="width:100%;display:block;" /></td></tr>
                <tr><td style="padding:15px;">
                    <h3 style="color:#111;font-family:Inter,Arial,sans-serif;font-size:16px;margin:0 0 8px;">Nome do Produto</h3>
                    <p style="color:#6366f1;font-family:Inter,Arial,sans-serif;font-size:20px;font-weight:700;margin:0 0 12px;">R$ 99,90</p>
                    <a href="#" style="display:block;text-align:center;padding:10px;background:#6366f1;color:#fff;font-size:14px;font-weight:600;text-decoration:none;border-radius:6px;">Comprar</a>
                </td></tr>
            </table>`,
        },
        {
            id: 'header-block',
            label: 'Cabeçalho',
            category: 'Seções',
            media: `<svg viewBox="0 0 24 24" width="38" height="38"><rect x="2" y="4" width="20" height="6" rx="1.5" fill="currentColor" opacity="0.1"/><rect x="2" y="4" width="20" height="6" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="8" y="6" width="8" height="2" rx="1" fill="currentColor" opacity="0.4"/></svg>`,
            content: `<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#1f2937;"><tr>
                <td style="padding:20px;text-align:center;">
                    <img src="https://placehold.co/150x50/6366f1/ffffff?text=LOGO" style="height:40px;" alt="Logo" />
                </td>
            </tr></table>`,
        },
        {
            id: 'footer-block',
            label: 'Rodapé',
            category: 'Seções',
            media: `<svg viewBox="0 0 24 24" width="38" height="38"><rect x="2" y="14" width="20" height="6" rx="1.5" fill="currentColor" opacity="0.1"/><rect x="2" y="14" width="20" height="6" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5"/><line x1="6" y1="17" x2="18" y2="17" stroke="currentColor" stroke-width="1" stroke-linecap="round" opacity="0.4"/></svg>`,
            content: `<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#f9fafb;"><tr>
                <td style="padding:25px;text-align:center;">
                    <p style="color:#9ca3af;font-family:Inter,Arial,sans-serif;font-size:12px;margin:0 0 10px;">© 2026 Sua Empresa. Todos os direitos reservados.</p>
                    <p style="color:#9ca3af;font-family:Inter,Arial,sans-serif;font-size:12px;margin:0;">
                        <a href="#" style="color:#6366f1;text-decoration:underline;">Cancelar inscrição</a>
                    </p>
                </td>
            </tr></table>`,
        },
        {
            id: 'social-icons',
            label: 'Redes Sociais',
            category: 'Seções',
            media: `<svg viewBox="0 0 24 24" width="38" height="38"><circle cx="6" cy="12" r="2.5" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="12" cy="12" r="2.5" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="18" cy="12" r="2.5" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>`,
            content: `<table cellpadding="0" cellspacing="0" style="margin:15px auto;"><tr>
                <td style="padding:0 8px;"><a href="#" style="color:#6366f1;font-size:14px;text-decoration:none;">Instagram</a></td>
                <td style="padding:0 8px;"><a href="#" style="color:#6366f1;font-size:14px;text-decoration:none;">Facebook</a></td>
                <td style="padding:0 8px;"><a href="#" style="color:#6366f1;font-size:14px;text-decoration:none;">LinkedIn</a></td>
            </tr></table>`,
        },
        {
            id: 'merge-name',
            label: 'Merge: Nome',
            category: 'Merge Tags',
            media: `<svg viewBox="0 0 24 24" width="38" height="38"><text x="3" y="16" font-size="11" fill="currentColor" font-family="monospace">{"}</text></svg>`,
            content: '<span>{{first_name}}</span>',
        },
        {
            id: 'merge-email',
            label: 'Merge: Email',
            category: 'Merge Tags',
            media: `<svg viewBox="0 0 24 24" width="38" height="38"><path d="M4 6h16v12H4z" fill="none" stroke="currentColor" stroke-width="1.5" rx="1"/><path d="M4 6l8 7 8-7" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>`,
            content: '<span>{{email}}</span>',
        },
        {
            id: 'merge-fullname',
            label: 'Merge: Nome Completo',
            category: 'Merge Tags',
            media: `<svg viewBox="0 0 24 24" width="38" height="38"><circle cx="12" cy="8" r="3" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M6 20c0-3.3 2.7-6 6-6s6 2.7 6 6" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>`,
            content: '<span>{{full_name}}</span>',
        },
    ];
}

function debouncedEmitChanges() {
    // Não re-entrar se já estamos emitindo (evita loop infinito)
    if (isEmitting) return;
    // Debounce de 500ms — agrupar mudanças rápidas
    if (emitTimer) clearTimeout(emitTimer);
    emitTimer = setTimeout(emitChanges, 500);
}

function emitChanges() {
    if (!editor || isEmitting) return;
    isEmitting = true;
    try {
        const html = editor.getHtml();
        const css = editor.getCss();
        const fullHtml = `<!DOCTYPE html><html><head><style>${css}</style></head><body>${html}</body></html>`;

        emit('update:htmlContent', fullHtml);
        emit('update:jsonContent', editor.getProjectData());
    } catch (e) {
        console.warn('[GrapesEditor] Erro ao emitir mudanças:', e);
    } finally {
        // Liberar guard apos microtask para evitar re-trigger sincrono
        setTimeout(() => { isEmitting = false; }, 100);
    }
}

function applyDarkTheme() {
    const style = document.createElement('style');
    style.id = 'gjs-dark-theme';
    // Remove estilos anteriores se existirem
    const old = document.getElementById('gjs-dark-theme');
    if (old) old.remove();

    style.textContent = `
        /* === Base === */
        .gjs-one-bg { background-color: #1f2937 !important; }
        .gjs-two-color { color: #e5e7eb !important; }
        .gjs-three-bg { background-color: #374151 !important; }
        .gjs-four-color, .gjs-four-color-h:hover { color: #6366f1 !important; }
        .gjs-cv-canvas { background-color: #374151 !important; }

        /* === Blocos com preview visual === */
        .gjs-block {
            color: #d1d5db;
            background-color: #111827;
            border: 1px solid #374151;
            border-radius: 10px;
            padding: 12px 8px 10px;
            min-height: 76px;
            width: 100% !important;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.2s ease;
            cursor: grab;
            box-sizing: border-box;
        }
        .gjs-block:hover {
            border-color: #6366f1;
            background-color: #1e1b4b;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.15);
        }
        .gjs-block svg {
            color: #818cf8;
            opacity: 0.85;
        }
        .gjs-block:hover svg {
            color: #a5b4fc;
            opacity: 1;
        }
        .gjs-block__media {
            display: flex !important;
            align-items: center;
            justify-content: center;
            margin-bottom: 0;
        }
        .gjs-block-label {
            font-size: 10px !important;
            font-weight: 500;
            color: #9ca3af;
            text-align: center;
            line-height: 1.2;
        }
        .gjs-block:hover .gjs-block-label {
            color: #e0e7ff;
        }

        /* === Bloco Grid (2 por linha) com espaçamento adequado === */
        .gjs-blocks-c {
            display: grid !important;
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 10px !important;
            padding: 8px !important;
        }

        /* === Categorias === */
        .gjs-block-category {
            border-bottom: none !important;
            margin-bottom: 4px !important;
        }
        .gjs-block-category .gjs-blocks-c {
            padding: 6px 8px 10px !important;
        }
        .gjs-title {
            background-color: #0f172a !important;
            color: #d1d5db !important;
            border-bottom: none !important;
            border-top: 1px solid #1e293b !important;
            padding: 10px 14px !important;
            font-size: 10px !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.08em !important;
        }
        .gjs-title:hover {
            background-color: #1e293b !important;
        }

        /* === Style Manager === */
        .gjs-category-title, .gjs-layer-title, .gjs-sm-sector-title {
            background-color: #111827 !important;
            color: #e5e7eb !important;
        }
        .gjs-sm-sector .gjs-sm-sector-title {
            border-bottom: 1px solid #1f2937 !important;
        }
        .gjs-field, .gjs-field input, .gjs-field select, .gjs-field textarea {
            background-color: #1f2937 !important;
            color: #e5e7eb !important;
            border-color: #374151 !important;
        }
        .gjs-pn-panel { background-color: #111827 !important; border-color: #1f2937 !important; }
        .gjs-sm-property { color: #9ca3af !important; }
        .gjs-sm-property .gjs-sm-label { color: #d1d5db !important; }

        /* === Color Picker Fix - Z-INDEX ALTO === */
        .gjs-field-color-picker,
        .sp-container,
        .sp-container.sp-light {
            z-index: 99999 !important;
            position: absolute !important;
        }
        .sp-container {
            background-color: #1f2937 !important;
            border-color: #374151 !important;
            border-radius: 8px !important;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5) !important;
        }
        .sp-container .sp-input {
            background: #111827 !important;
            color: #e5e7eb !important;
            border-color: #374151 !important;
        }
        .sp-container button {
            background: #6366f1 !important;
            color: white !important;
            border: none !important;
            border-radius: 4px !important;
        }
        .sp-container button:hover {
            background: #4f46e5 !important;
        }
        .sp-replacer {
            border-color: #374151 !important;
            background: #1f2937 !important;
            border-radius: 4px !important;
        }
        /* Forcar picker a abrir acima quando perto do fundo */
        .sp-container.sp-hidden { display: none !important; }

        /* === Trait Manager === */
        .gjs-trt-trait {
            background: transparent !important;
            color: #d1d5db !important;
        }
        .gjs-trt-trait .gjs-label { color: #9ca3af !important; }

        /* === Layers === */
        .gjs-layer { background: #111827 !important; }
        .gjs-layer:hover { background: #1f2937 !important; }
        .gjs-layer-name { color: #d1d5db !important; }

        /* === Scrollbar customizada === */
        .gjs-blocks-c::-webkit-scrollbar,
        #gjs-blocks-container::-webkit-scrollbar,
        #gjs-styles-container::-webkit-scrollbar {
            width: 4px;
        }
        .gjs-blocks-c::-webkit-scrollbar-track,
        #gjs-blocks-container::-webkit-scrollbar-track,
        #gjs-styles-container::-webkit-scrollbar-track {
            background: transparent;
        }
        .gjs-blocks-c::-webkit-scrollbar-thumb,
        #gjs-blocks-container::-webkit-scrollbar-thumb,
        #gjs-styles-container::-webkit-scrollbar-thumb {
            background: #374151;
            border-radius: 2px;
        }
    `;
    document.head.appendChild(style);
}

function injectCanvasStyles() {
    // Injetar CSS dentro do iframe do canvas para mostrar bordas nos componentes
    if (!editor) return;

    const addFrameStyles = () => {
        try {
            const frame = editor.Canvas.getFrameEl();
            if (!frame || !frame.contentDocument) return;
            const doc = frame.contentDocument;

            // Verifica se já injetou
            if (doc.getElementById('gjs-canvas-helpers')) return;

            const style = doc.createElement('style');
            style.id = 'gjs-canvas-helpers';
            style.textContent = `
                /* Bordas visuais nos elementos de layout — SEM !important para nao sobrescrever estilos do usuario */
                [data-gjs-type] {
                    outline: 1px dashed rgba(99, 102, 241, 0.15);
                    outline-offset: -1px;
                    transition: outline 0.15s ease;
                }
                /* Destacar ao passar mouse */
                [data-gjs-type]:hover {
                    outline: 2px dashed rgba(99, 102, 241, 0.45);
                    outline-offset: -1px;
                }
                /* Componente selecionado */
                [data-gjs-type].gjs-selected {
                    outline: 2px solid #6366f1 !important;
                    outline-offset: 0;
                }
                /* Body do canvas — cor base clara SEM !important para permitir mudancas */
                body {
                    background: #f8fafc;
                    padding: 20px;
                    min-height: 100vh;
                }
            `;
            doc.head.appendChild(style);
        } catch (e) {
            // Frame pode não estar pronto ainda
        }
    };

    // Tentar injetar imediatamente e apos carregamento do frame
    setTimeout(addFrameStyles, 500);
    setTimeout(addFrameStyles, 1500);
    editor.on('canvas:frame:load', addFrameStyles);
}

async function loadExistingAssets() {
    try {
        const resp = await axios.get(route('email.editor.assets'));
        if (resp.data?.data) {
            resp.data.data.forEach(a => {
                editor.AssetManager.add({ src: a.src, name: a.name });
            });
        }
    } catch (e) {}
}

async function loadSavedBlocks() {
    try {
        const resp = await axios.get(route('email.editor.saved-blocks'));
        savedBlocks.value = resp.data?.data || [];
    } catch (e) {}
}

function insertSavedBlock(block) {
    if (editor && block.html_content) {
        editor.addComponents(block.html_content);
    }
}

async function saveCurrentAsBlock(category = 'custom') {
    const selected = editor.getSelected();
    if (!selected) {
        alert('Selecione um componente primeiro.');
        return;
    }

    const name = prompt('Nome do bloco:');
    if (!name) return;

    try {
        const html = selected.toHTML();
        await axios.post(route('email.editor.store-saved-block'), {
            name,
            category,
            html_content: html,
        });
        await loadSavedBlocks();
        alert('Bloco salvo!');
    } catch (e) {
        alert('Erro ao salvar bloco.');
    }
}

async function searchWooProducts() {
    wooLoading.value = true;
    try {
        const resp = await axios.get(route('email.editor.woo-products'), { params: { search: wooSearch.value } });
        wooProducts.value = resp.data?.data || [];
    } catch (e) {
        wooProducts.value = [];
    }
    wooLoading.value = false;
}

function insertWooProduct(product) {
    if (!editor) return;
    const html = `<table width="100%" cellpadding="0" cellspacing="0" style="max-width:280px;margin:10px;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;display:inline-table;">
        ${product.image ? `<tr><td><img src="${product.image}" style="width:100%;display:block;" alt="${product.name}" /></td></tr>` : ''}
        <tr><td style="padding:15px;">
            <h3 style="color:#111;font-family:Inter,Arial,sans-serif;font-size:16px;margin:0 0 8px;">${product.name}</h3>
            ${product.short_description ? `<p style="color:#666;font-size:13px;margin:0 0 8px;">${product.short_description.substring(0, 80)}</p>` : ''}
            <p style="color:#6366f1;font-family:Inter,Arial,sans-serif;font-size:20px;font-weight:700;margin:0 0 12px;">R$ ${product.price}</p>
            <a href="${product.permalink}" style="display:block;text-align:center;padding:10px;background:#6366f1;color:#fff;font-size:14px;font-weight:600;text-decoration:none;border-radius:6px;">Ver Produto</a>
        </td></tr>
    </table>`;

    editor.addComponents(html);
}

async function generateWithAI() {
    if (!aiPrompt.value) return;
    aiLoading.value = true;
    aiResult.value = null;

    try {
        const resp = await axios.post(route('email.editor.generate-ai'), {
            prompt: aiPrompt.value,
            type: aiType.value,
        });

        if (resp.data?.success) {
            aiResult.value = resp.data.content;
        } else {
            alert('Erro: ' + (resp.data?.error || 'Falha na geração'));
        }
    } catch (e) {
        alert('Erro: ' + (e.response?.data?.error || e.message));
    }
    aiLoading.value = false;
}

function applyAiResult() {
    if (!editor || !aiResult.value) return;

    // Suspender listeners durante operação em lote
    isEmitting = true;
    try {
        if (aiType.value === 'full_template') {
            editor.setComponents('');
            const bodyMatch = aiResult.value.match(/<body[^>]*>([\s\S]*)<\/body>/i);
            const content = bodyMatch ? bodyMatch[1] : aiResult.value;
            editor.setComponents(content);
        } else {
            editor.addComponents(aiResult.value);
        }
        aiResult.value = null;
        aiPrompt.value = '';
    } finally {
        // Emitir uma única vez após operação completa
        setTimeout(() => {
            isEmitting = false;
            emitChanges();
        }, 300);
    }
}

function discardAiResult() {
    aiResult.value = null;
}

function triggerSave() {
    // Forçar coleta de dados antes de salvar (bypass debounce)
    if (emitTimer) clearTimeout(emitTimer);
    isEmitting = false; // Reset guard
    emitChanges();
    // Pequeno delay para garantir que os dados foram emitidos
    setTimeout(() => emit('save'), 200);
}

// Upload via asset manager
async function uploadImage(event) {
    const files = event.target.files;
    if (!files.length) return;

    const formData = new FormData();
    formData.append('file', files[0]);

    try {
        const resp = await axios.post(route('email.editor.upload-asset'), formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (resp.data?.data?.src && editor) {
            editor.AssetManager.add({ src: resp.data.data.src, name: resp.data.data.name });
            editor.addComponents(`<img src="${resp.data.data.src}" style="max-width:100%;height:auto;" />`);
        }
    } catch (e) {
        alert('Erro no upload: ' + (e.response?.data?.message || e.message));
    }
    event.target.value = '';
}
</script>

<template>
    <div class="flex h-[calc(100vh-140px)]">
        <!-- Editor Canvas -->
        <div class="flex-1 relative">
            <div ref="editorContainer" class="w-full h-full"></div>
        </div>

        <!-- Right Panel -->
        <div class="w-80 bg-gray-900 border-l border-gray-800 flex flex-col overflow-hidden shrink-0">
            <!-- Panel tabs -->
            <div class="flex border-b border-gray-800 shrink-0">
                <button v-for="tab in ['blocks', 'styles', 'products', 'saved', 'ai']" :key="tab"
                    @click="activePanel = tab"
                    :class="['flex-1 px-2 py-2.5 text-xs font-medium transition', activePanel === tab ? 'text-indigo-400 border-b-2 border-indigo-400 bg-gray-800/50' : 'text-gray-500 hover:text-gray-300']">
                    {{ { blocks: 'Blocos', styles: 'Estilos', products: 'Produtos', saved: 'Salvos', ai: 'IA' }[tab] }}
                </button>
            </div>

            <!-- Panel content -->
            <div class="flex-1 overflow-y-auto">
                <!-- Blocks -->
                <div v-show="activePanel === 'blocks'" id="gjs-blocks-container" class="p-3"></div>

                <!-- Styles -->
                <div v-show="activePanel === 'styles'" id="gjs-styles-container" class="p-3"></div>

                <!-- WooCommerce Products -->
                <div v-show="activePanel === 'products'" class="p-3">
                    <div class="flex gap-2 mb-3">
                        <input v-model="wooSearch" @keyup.enter="searchWooProducts" placeholder="Buscar produto..." class="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-xs" />
                        <button @click="searchWooProducts" :disabled="wooLoading" class="px-3 py-2 bg-indigo-600 text-white rounded-lg text-xs disabled:opacity-50">
                            {{ wooLoading ? '...' : 'Buscar' }}
                        </button>
                    </div>

                    <div v-if="wooProducts.length === 0 && !wooLoading" class="text-center py-6 text-gray-500 text-xs">
                        Busque produtos WooCommerce para inserir no email.
                    </div>

                    <div v-for="p in wooProducts" :key="p.id" class="mb-2 bg-gray-800 rounded-lg p-2 cursor-pointer hover:bg-gray-700 transition" @click="insertWooProduct(p)">
                        <div class="flex items-center gap-2">
                            <img v-if="p.image" :src="p.image" class="w-10 h-10 rounded object-cover" />
                            <div class="flex-1 min-w-0">
                                <p class="text-xs text-white truncate">{{ p.name }}</p>
                                <p class="text-xs text-indigo-400 font-bold">R$ {{ p.price }}</p>
                            </div>
                            <span class="text-[10px] text-gray-500">Inserir</span>
                        </div>
                    </div>
                </div>

                <!-- Saved Blocks -->
                <div v-show="activePanel === 'saved'" class="p-3">
                    <div class="flex justify-between items-center mb-3">
                        <span class="text-xs text-gray-400">Blocos Salvos</span>
                        <div class="flex gap-1">
                            <button @click="saveCurrentAsBlock('header')" class="px-2 py-1 text-[10px] bg-gray-800 text-gray-400 rounded hover:bg-gray-700" title="Salvar seleção como cabeçalho">+Cabeçalho</button>
                            <button @click="saveCurrentAsBlock('footer')" class="px-2 py-1 text-[10px] bg-gray-800 text-gray-400 rounded hover:bg-gray-700" title="Salvar seleção como rodapé">+Rodapé</button>
                            <button @click="saveCurrentAsBlock('custom')" class="px-2 py-1 text-[10px] bg-gray-800 text-gray-400 rounded hover:bg-gray-700" title="Salvar seleção como bloco">+Bloco</button>
                        </div>
                    </div>

                    <div v-if="savedBlocks.length === 0" class="text-center py-6 text-gray-500 text-xs">
                        Nenhum bloco salvo. Selecione um componente no editor e clique em "+Cabeçalho", "+Rodapé" ou "+Bloco" para salvar.
                    </div>

                    <div v-for="b in savedBlocks" :key="b.id" class="mb-2 bg-gray-800 rounded-lg p-3 cursor-pointer hover:bg-gray-700 transition" @click="insertSavedBlock(b)">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs text-white">{{ b.name }}</p>
                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-700 text-gray-400">{{ b.category }}</span>
                            </div>
                            <span class="text-[10px] text-indigo-400">Inserir</span>
                        </div>
                    </div>
                </div>

                <!-- AI Panel -->
                <div v-show="activePanel === 'ai'" class="p-3 space-y-3">
                    <div>
                        <label class="text-xs text-gray-400">Tipo de Geração</label>
                        <select v-model="aiType" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-xs">
                            <option value="full_template">Template Completo</option>
                            <option value="content">Bloco de Conteúdo</option>
                            <option value="subject">Assuntos (5 opções)</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-xs text-gray-400">Descreva o que deseja</label>
                        <textarea v-model="aiPrompt" rows="4" class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-xs" placeholder="Ex: Email promocional de Black Friday para loja de roupas, com destaque para 50% de desconto..."></textarea>
                    </div>

                    <button @click="generateWithAI" :disabled="aiLoading || !aiPrompt" class="w-full px-4 py-2.5 bg-indigo-600 text-white rounded-lg text-xs font-medium hover:bg-indigo-500 transition disabled:opacity-50">
                        {{ aiLoading ? 'Gerando...' : 'Gerar com IA' }}
                    </button>

                    <!-- Upload -->
                    <div class="pt-3 border-t border-gray-800">
                        <label class="text-xs text-gray-400 mb-1 block">Upload de Imagem</label>
                        <input type="file" @change="uploadImage" accept="image/*" class="w-full text-xs text-gray-400" />
                    </div>

                    <!-- AI Result -->
                    <div v-if="aiResult" class="mt-3 bg-gray-800 rounded-lg p-3 border border-indigo-500/30">
                        <p class="text-xs text-indigo-400 font-medium mb-2">Conteúdo Gerado:</p>
                        <div class="max-h-48 overflow-y-auto text-xs text-gray-300 bg-gray-900 rounded p-2 mb-3 font-mono whitespace-pre-wrap">
                            {{ aiResult.substring(0, 500) }}{{ aiResult.length > 500 ? '...' : '' }}
                        </div>
                        <div class="flex gap-2">
                            <button @click="applyAiResult" class="flex-1 px-3 py-2 bg-indigo-600 text-white rounded-lg text-xs font-medium hover:bg-indigo-500">Aplicar</button>
                            <button @click="discardAiResult" class="px-3 py-2 bg-gray-700 text-gray-300 rounded-lg text-xs hover:bg-gray-600">Descartar</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom actions -->
            <div class="border-t border-gray-800 p-3 shrink-0">
                <button @click="triggerSave" class="w-full px-4 py-2.5 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-500 transition">
                    Salvar Template
                </button>
            </div>
        </div>
    </div>
</template>
