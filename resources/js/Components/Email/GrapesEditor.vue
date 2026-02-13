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
                    properties: ['background-color', 'background-image', 'background-repeat', 'background-position', 'background-size'],
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

    // Emitir mudanças
    editor.on('component:update', emitChanges);
    editor.on('component:add', emitChanges);
    editor.on('component:remove', emitChanges);

    // Customizar tema escuro do editor
    applyDarkTheme();

    // Carregar blocos salvos
    loadSavedBlocks();
}

function getDefaultBlocks() {
    return [
        {
            id: 'section',
            label: 'Seção',
            category: 'Layout',
            content: `<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;"><tr><td style="padding:20px;">
                <p style="margin:0;">Conteúdo da seção</p>
            </td></tr></table>`,
            attributes: { class: 'gjs-block-section' },
        },
        {
            id: 'columns-2',
            label: '2 Colunas',
            category: 'Layout',
            content: `<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;"><tr>
                <td width="50%" style="padding:10px;" valign="top"><p>Coluna 1</p></td>
                <td width="50%" style="padding:10px;" valign="top"><p>Coluna 2</p></td>
            </tr></table>`,
        },
        {
            id: 'columns-3',
            label: '3 Colunas',
            category: 'Layout',
            content: `<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;"><tr>
                <td width="33%" style="padding:10px;" valign="top"><p>Col 1</p></td>
                <td width="34%" style="padding:10px;" valign="top"><p>Col 2</p></td>
                <td width="33%" style="padding:10px;" valign="top"><p>Col 3</p></td>
            </tr></table>`,
        },
        {
            id: 'heading',
            label: 'Título',
            category: 'Texto',
            content: '<h1 style="color:#333;font-family:Inter,Arial,sans-serif;font-size:28px;font-weight:700;margin:0 0 10px;">Título do Email</h1>',
        },
        {
            id: 'text-block',
            label: 'Texto',
            category: 'Texto',
            content: '<p style="color:#555;font-family:Inter,Arial,sans-serif;font-size:16px;line-height:1.6;margin:0 0 15px;">Seu texto aqui. Clique para editar.</p>',
        },
        {
            id: 'image-block',
            label: 'Imagem',
            category: 'Mídia',
            content: '<img src="https://placehold.co/600x300/6366f1/ffffff?text=Sua+Imagem" style="width:100%;max-width:600px;height:auto;display:block;" alt="Imagem" />',
        },
        {
            id: 'button-cta',
            label: 'Botão CTA',
            category: 'Ação',
            content: `<table cellpadding="0" cellspacing="0" style="margin:20px auto;"><tr><td>
                <a href="#" style="display:inline-block;padding:14px 32px;background:#6366f1;color:#ffffff;font-family:Inter,Arial,sans-serif;font-size:16px;font-weight:600;text-decoration:none;border-radius:8px;">Saiba Mais</a>
            </td></tr></table>`,
        },
        {
            id: 'divider',
            label: 'Separador',
            category: 'Layout',
            content: '<hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0;" />',
        },
        {
            id: 'spacer',
            label: 'Espaçador',
            category: 'Layout',
            content: '<div style="height:30px;"></div>',
        },
        {
            id: 'hero-section',
            label: 'Hero',
            category: 'Seções',
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
            content: '<span>{{first_name}}</span>',
        },
        {
            id: 'merge-email',
            label: 'Merge: Email',
            category: 'Merge Tags',
            content: '<span>{{email}}</span>',
        },
        {
            id: 'merge-fullname',
            label: 'Merge: Nome Completo',
            category: 'Merge Tags',
            content: '<span>{{full_name}}</span>',
        },
    ];
}

function emitChanges() {
    if (!editor) return;
    const html = editor.getHtml();
    const css = editor.getCss();
    const fullHtml = `<!DOCTYPE html><html><head><style>${css}</style></head><body>${html}</body></html>`;

    emit('update:htmlContent', fullHtml);
    emit('update:jsonContent', editor.getProjectData());
}

function applyDarkTheme() {
    const frame = editorContainer.value;
    if (!frame) return;

    const style = document.createElement('style');
    style.textContent = `
        .gjs-one-bg { background-color: #1f2937 !important; }
        .gjs-two-color { color: #e5e7eb !important; }
        .gjs-three-bg { background-color: #374151 !important; }
        .gjs-four-color, .gjs-four-color-h:hover { color: #6366f1 !important; }
        .gjs-cv-canvas { background-color: #374151 !important; }
        .gjs-block { color: #e5e7eb; background-color: #1f2937; border: 1px solid #374151; border-radius: 8px; }
        .gjs-block:hover { border-color: #6366f1; }
        .gjs-block__media { display: none; }
        .gjs-category-title, .gjs-layer-title, .gjs-sm-sector-title { background-color: #111827 !important; color: #e5e7eb !important; }
        .gjs-field, .gjs-field input, .gjs-field select, .gjs-field textarea { background-color: #1f2937 !important; color: #e5e7eb !important; border-color: #374151 !important; }
        .gjs-pn-panel { background-color: #111827 !important; border-color: #1f2937 !important; }
        .gjs-sm-property { color: #9ca3af !important; }
    `;
    document.head.appendChild(style);
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

    if (aiType.value === 'full_template') {
        // Limpar e substituir todo conteudo
        editor.setComponents('');
        // Extrair body content
        const bodyMatch = aiResult.value.match(/<body[^>]*>([\s\S]*)<\/body>/i);
        const content = bodyMatch ? bodyMatch[1] : aiResult.value;
        editor.setComponents(content);
    } else {
        editor.addComponents(aiResult.value);
    }
    aiResult.value = null;
    aiPrompt.value = '';
    emitChanges();
}

function discardAiResult() {
    aiResult.value = null;
}

function triggerSave() {
    emitChanges();
    emit('save');
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
            // Inserir imagem no canvas
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
