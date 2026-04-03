<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Política de Privacidade" />

    <div class="min-h-screen bg-gray-100 pt-6 sm:pt-0 dark:bg-gray-900">
        <div class="mx-auto flex flex-col items-center pt-6 sm:pt-12">
            <Link href="/">
                <ApplicationLogo class="h-20 w-20 fill-current text-gray-500" />
            </Link>
        </div>

        <div class="mx-auto mt-6 w-full max-w-4xl overflow-hidden bg-white px-6 py-8 shadow-md sm:rounded-lg sm:px-12 sm:py-10 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
            <h1 class="mb-6 text-3xl font-bold text-gray-900 dark:text-gray-100">Política de Privacidade</h1>
            
            <div class="prose prose-sm sm:prose-base dark:prose-invert max-w-none">
                <p><strong>Última atualização:</strong> {{ new Date().toLocaleDateString('pt-BR') }}</p>

                <p class="mb-4">A privacidade dos nossos usuários é de extrema importância para o <strong>MKT Privus</strong>. Esta Política de Privacidade descreve como coletamos, usamos, armazenamos e protegemos as suas informações pessoais ao utilizar nossa plataforma (mkt.privus.com.br) e nossos serviços.</p>

                <p class="mb-4">Buscamos operar em total concordância com a Lei Geral de Proteção de Dados Pessoais (LGPD - Lei nº 13.709/2018) e as regulamentações aplicáveis à manipulação de dados pessoais no Brasil.</p>

                <h2 class="text-xl font-semibold mt-6 mb-3 text-gray-800 dark:text-gray-200">1. Informações que Coletamos</h2>
                <p class="mb-2">A plataforma compila diferentes tipos de informações atreladas ou relacionadas a você para tornar nossos serviços mais eficazes:</p>
                <ul class="list-disc pl-6 mb-4 space-y-2">
                    <li><strong>Dados Pessoais Mínimos:</strong> Nome, endereço de e-mail e dados de contato associados à sua conta (essenciais para autenticação de usuário).</li>
                    <li><strong>Dados Operacionais ou de Clientes:</strong> Leads e listas de contatos geridas por você no sistema (para uso na elaboração de campanhas de e-mail e SMS), armazenados de forma sigilosa em nosso banco de dados unicamente para seu controle.</li>
                    <li><strong>Integrações Externas (OAuth):</strong> Dados provenientes de contas de mídia social, ferramentas de CMS (WordPress), lojas físicas/online ou provedores de correio eletrônico que você optar conectar. Tais conexões são geridas sob as políticas de privacidade desses terceiros, limitadas pelo escopo dos tokens autorizados por você.</li>
                    <li><strong>Dados de Utilização:</strong> Relatórios sobre sua navegação (logs básicos de sistema, IPs durante ações cruciais, cookies de sessão) com o intuito fundamental garantir a segurança contra falhas de integridade e invasões da rede.</li>
                </ul>

                <h2 class="text-xl font-semibold mt-6 mb-3 text-gray-800 dark:text-gray-200">2. Como Usamos as Informações</h2>
                <p class="mb-2">Os dados coletados são usados nos seguintes contextos:</p>
                <ul class="list-disc pl-6 mb-4 space-y-2">
                    <li>Disponibilização, execução de tarefas em segundo plano (Autopilots, webhooks) e manutenção robusta da plataforma.</li>
                    <li>Executar a automação de e-mails ou mensagens para as <em>suas</em> listas de contato – cujos destinatários finais não receberão ações promocionais nossas.</li>
                    <li>Comunicar ao titular sobre avisos e atualizações essenciais aos serviços contratados.</li>
                    <li>Aplicar capacidades de Inteligência Artificial usando provedores externos parceiros (OpenAI, Anthropic, Gemini, SendPulse, entre outros) sem repassar os dados originais brutos de caráter sigiloso.</li>
                </ul>

                <h2 class="text-xl font-semibold mt-6 mb-3 text-gray-800 dark:text-gray-200">3. Compartilhamento de Dados</h2>
                <p class="mb-4">O MKT Privus não vende e não repassa de bom grado ou como modelo de negócios a informação dos usuários – de leads obtidos, métricas provadas ou textos confidenciais inseridos e trabalhados – para prestadores alheios ou companhias de propaganda externa que vislumbrem campanhas comerciais.</p>
                <p class="mb-4">Os dados podem vir a transitar em APIs confiáveis (Servidores de AI ou gateways de envio) para que as peças de marketing possam se manifestar, existindo um Contrato subjacente de Processamento Segurado via APIs entre o MKT Privus e os mesmos. Tais companhias não detém direito para treinar os seus próprios LLMs através dos prompts encaminhados via nossa plataforma.</p>

                <h2 class="text-xl font-semibold mt-6 mb-3 text-gray-800 dark:text-gray-200">4. Armazenamento e Proteção</h2>
                <p class="mb-4">Temos medidas administrativas, técnicas de software e proteções baseadas em nuvem desenhadas para atuar em salvaguarda contra o extravio ou adulteração das credenciais guardadas, inclusive chaves baseadas no conceito forte de 'encryption at rest' quando possível nas chaves OAuth.</p>

                <h2 class="text-xl font-semibold mt-6 mb-3 text-gray-800 dark:text-gray-200">5. Seus Direitos Exclusivos (LGPD)</h2>
                <p class="mb-2">Independentemente do estágio, os usuários têm como prerrogativas da Legislação atual no Brasil:</p>
                <ul class="list-disc pl-6 mb-4 space-y-2">
                    <li>O direito de obter um descritivo informando a confirmação da presença e manipulação dos dados de conta.</li>
                    <li>Pedir correções a informações inexatas e, a qualquer instante viável, a inativação ou exclusão definitiva dos bancos do serviço as próprias credenciais, ou listas agregadas (apague tudo).</li>
                </ul>

                <h2 class="text-xl font-semibold mt-6 mb-3 text-gray-800 dark:text-gray-200">6. Cookies e Rastreamento</h2>
                <p class="mb-4">A plataforma impõe para uso operacional pequenos identificadores criptografados instalados internamente via browser para distinguir os sessões in-log dos utilizadores – algo imprescindível à operabilidade contemporânea. Dispositivos analíticos auxiliares ou secundários, na forma de scripts orgânicos de terceiros, podem colher os camiões em anonimização com vista a re-engajamento do tráfego ou melhora da UX da interface global.</p>

                <h2 class="text-xl font-semibold mt-6 mb-3 text-gray-800 dark:text-gray-200">7. Contato para Dúvidas com Dados</h2>
                <p class="mb-4">Sugerimos comunicar os nossos responsáveis (no contato oficial da matriz controladora): contate o Encarregado pelo Tratamento de Dados de Privacidade em caso de incidentes reportados localmente ou requisições afins atráves do e-mail de atendimento: <strong>suporte@privus.com.br</strong> ou entre em contato com seu gestor comercial de contas respectivo.</p>
            </div>
            
            <div class="mt-10 pt-6 border-t border-gray-200 dark:border-gray-700 text-center">
                <Link href="/" class="text-sm font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300 transition-colors">
                    &larr; Voltar para a página inicial
                </Link>
            </div>
        </div>
        <div class="pb-12"></div>
    </div>
</template>
