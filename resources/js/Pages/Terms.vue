<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Termos de Uso" />

    <div class="min-h-screen bg-gray-100 pt-6 sm:pt-0 dark:bg-gray-900">
        <div class="mx-auto flex flex-col items-center pt-6 sm:pt-12">
            <Link href="/">
                <ApplicationLogo class="h-20 w-20 fill-current text-gray-500" />
            </Link>
        </div>

        <div class="mx-auto mt-6 w-full max-w-4xl overflow-hidden bg-white px-6 py-8 shadow-md sm:rounded-lg sm:px-12 sm:py-10 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
            <h1 class="mb-6 text-3xl font-bold text-gray-900 dark:text-gray-100">Termos de Uso</h1>
            
            <div class="prose prose-sm sm:prose-base dark:prose-invert max-w-none">
                <p><strong>Última atualização:</strong> {{ new Date().toLocaleDateString('pt-BR') }}</p>

                <p>Bem-vindo ao <strong>MKT Privus</strong>. Ao acessar e usar nossa plataforma, você concorda em cumprir e estar vinculado aos seguintes Termos de Uso. Leia-os com atenção.</p>

                <h2 class="text-xl font-semibold mt-6 mb-3 text-gray-800 dark:text-gray-200">1. Aceitação dos Termos</h2>
                <p class="mb-4">Ao acessar o site <strong>mkt.privus.com.br</strong> e usar nossos serviços de marketing e gestão (o "Serviço"), você concorda em ficar vinculado a estes Termos de Uso e a todas as leis e regulamentos aplicáveis. Se discordar de qualquer parte destes termos, não deverá acessar nem utilizar a plataforma.</p>

                <h2 class="text-xl font-semibold mt-6 mb-3 text-gray-800 dark:text-gray-200">2. Descrição do Serviço</h2>
                <p class="mb-4">O <strong>MKT Privus</strong> oferece um conjunto de ferramentas para automação de marketing, gestão de conteúdo, inteligência artificial integrada, e-mail marketing, campanhas de SMS, agendamento de postagens sociais, entre outros serviços focados em facilitar o trabalho de marketing.</p>

                <h2 class="text-xl font-semibold mt-6 mb-3 text-gray-800 dark:text-gray-200">3. Conta do Usuário e Segurança</h2>
                <p class="mb-4">Para usar o Serviço, você pode precisar criar e possuir uma conta ativa. Você é integralmente responsável por manter a confidencialidade das credenciais de acesso da sua conta e por todas as atividades que ocorrem sob ela.</p>
                <p class="mb-4">Você concorda em nos notificar imediatamente sobre qualquer uso não autorizado da sua conta ou qualquer outra violação de segurança. O MKT Privus não será responsável por quaisquer perdas e danos resultantes do não cumprimento desta obrigação de segurança por sua parte.</p>

                <h2 class="text-xl font-semibold mt-6 mb-3 text-gray-800 dark:text-gray-200">4. Uso Aceitável</h2>
                <p class="mb-2">Você concorda em não usar o Serviço para fins ilícitos ou para:</p>
                <ul class="list-disc pl-6 mb-4 space-y-2">
                    <li>Enviar spam, e-mails ou mensagens não solicitadas em massa ou violar regulamentos e leis de anti-spam (como LGPD/GDPR e a lei CAN-SPAM).</li>
                    <li>Publicar, compartilhar ou transmitir conteúdo que seja ilegal, abusivo, ameaçador, difamatório, obsceno ou que viole direitos de privacidade.</li>
                    <li>Envolver-se em fraudes, esquemas ou atividades enganosas.</li>
                    <li>Transmitir vírus, malware, worms ou qualquer outro código malicioso de natureza destrutiva.</li>
                    <li>Burlar ou tentar contornar quaisquer restrições de segurança ou faturamento do sistema.</li>
                </ul>

                <h2 class="text-xl font-semibold mt-6 mb-3 text-gray-800 dark:text-gray-200">5. Propriedade Intelectual</h2>
                <p class="mb-4">Nós e nossos licenciadores detemos os direitos de propriedade intelectual de todo o material e tecnologia presentes no <strong>MKT Privus</strong>. Nenhum licenciamento é concedido para modificação, reprodução ou distribuição da nossa tecnologia-base (código-fonte, interfaces de software). O conteúdo gerado pelos usuários continua sendo de propriedade de seus respectivos criadores.</p>

                <h2 class="text-xl font-semibold mt-6 mb-3 text-gray-800 dark:text-gray-200">6. Disponibilidade e Modificação de Serviços</h2>
                <p class="mb-4">Esforçamo-nos para manter o Serviço operante continuamente; no entanto, interrupções podem ocorrer por motivos de manutenção, atualizações ou falhas técnicas imprevistas. Reservamo-nos o direito de modificar ou descontinuar funcionalidades se considerarmos essencial e procuraremos informar os usuários sobre modificações substanciais em tempo útil.</p>

                <h2 class="text-xl font-semibold mt-6 mb-3 text-gray-800 dark:text-gray-200">7. Limitação de Responsabilidade</h2>
                <p class="mb-4">O <strong>MKT Privus</strong> e seus mantenedores não serão responsáveis, sob qualquer circunstância, por perdas lucros, dados corrompidos, danos indiretos ou incidentais resultantes da incapacidade temporária de usar o serviço ou da ação de ataques maliciosos externos sofridos pelas redes digitais.</p>

                <h2 class="text-xl font-semibold mt-6 mb-3 text-gray-800 dark:text-gray-200">8. Alterações aos Termos</h2>
                <p class="mb-4">Podemos atualizar ou modificar estes Termos de Uso ocasionalmente para refletir mudanças na lei ou nos nossos serviços. A continuidade no uso da plataforma após as alterações significa sua concordância irrestrita aos novos Termos em vigor.</p>

                <h2 class="text-xl font-semibold mt-6 mb-3 text-gray-800 dark:text-gray-200">9. Contato e Suporte</h2>
                <p class="mb-4">Para esclarecimentos adicionais, relatórios de problemas ou dúvidas que digam respeito aos Termos de Uso aplicados à plataforma, é possível contatar a equipe técnica pelas vias apropriadas ou pelo e-mail <strong>suporte@privus.com.br</strong> ou pelo administrador de sistema do domínio.</p>
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
