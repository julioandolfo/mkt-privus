<?php

namespace App\Services\Social;

use App\Enums\AIModel;
use App\Models\Brand;
use App\Services\AI\AIGateway;
use Illuminate\Support\Facades\Log;

/**
 * Editor natural de conteúdo inspirado no Pomeli
 * Permite refinar posts via comandos em linguagem natural em português
 */
class NaturalLanguageEditorService
{
    /**
     * Comandos pré-definidos comuns
     */
    private const PRESET_COMMANDS = [
        'mais_descontraido' => [
            'command' => 'Torne este conteúdo mais descontraído, leve e amigável',
            'description' => 'Mais leve e amigável',
        ],
        'mais_formal' => [
            'command' => 'Torne este conteúdo mais formal e profissional',
            'description' => 'Mais formal',
        ],
        'mais_curto' => [
            'command' => 'Reduza este conteúdo para a metade do tamanho, mantendo apenas o essencial',
            'description' => 'Resumir',
        ],
        'mais_longo' => [
            'command' => 'Expanda este conteúdo com mais detalhes, exemplos e contexto',
            'description' => 'Expandir',
        ],
        'mais_urgente' => [
            'command' => 'Adicione senso de urgência e escassez a este conteúdo',
            'description' => 'Urgência',
        ],
        'mais_emocional' => [
            'command' => 'Torne este conteúdo mais emotivo, conecte-se com os sentimentos do leitor',
            'description' => 'Mais emotivo',
        ],
        'mais_tecnico' => [
            'command' => 'Adicione termos técnicos e detalhes específicos do segmento',
            'description' => 'Mais técnico',
        ],
        'mais_simples' => [
            'command' => 'Simplifique este conteúdo para leigos entenderem facilmente',
            'description' => 'Simplificar',
        ],
        'adicionar_emojis' => [
            'command' => 'Adicione emojis apropriados e bem distribuídos no conteúdo',
            'description' => 'Adicionar emojis',
        ],
        'remover_emojis' => [
            'command' => 'Remova todos os emojis do conteúdo',
            'description' => 'Sem emojis',
        ],
        'melhorar_cta' => [
            'command' => 'Melhore o call-to-action, torne-o mais persuasivo e claro',
            'description' => 'Melhor CTA',
        ],
        'adicionar_humor' => [
            'command' => 'Adicione leveza e humor apropriado ao conteúdo',
            'description' => 'Com humor',
        ],
        'foco_beneficios' => [
            'command' => 'Reorganize focando nos benefícios para o cliente, não características',
            'description' => 'Foco em benefícios',
        ],
        'adicionar_storytelling' => [
            'command' => 'Reescreva como uma história com início, meio e fim',
            'description' => 'Storytelling',
        ],
        'mais_persuasivo' => [
            'command' => 'Torne mais persuasivo usando técnicas de copywriting',
            'description' => 'Mais persuasivo',
        ],
    ];

    public function __construct(
        private AIGateway $aiGateway,
        private BrandDNAAnalyzerService $brandAnalyzer
    ) {}

    /**
     * Processa comando natural e aplica ao conteúdo
     *
     * @param string $originalContent Conteúdo original
     * @param string $naturalCommand Comando em linguagem natural
     * @param Brand $brand Marca para contexto
     * @param string|null $platform Plataforma (para contexto)
     * @return array Resultado da edição
     */
    public function editContent(
        string $originalContent,
        string $naturalCommand,
        Brand $brand,
        ?string $platform = null
    ): array {
        try {
            // Obtém Brand DNA para contexto
            $brandDNA = $this->brandAnalyzer->getCachedAnalysis($brand) ??
                       $this->brandAnalyzer->getBasicBrandDNA($brand);

            $prompt = $this->buildEditPrompt(
                $originalContent,
                $naturalCommand,
                $brand,
                $brandDNA,
                $platform
            );

            $response = $this->aiGateway->chat(
                model: AIModel::GPT4oMini,
                messages: [['role' => 'user', 'content' => $prompt]],
                brand: $brand,
                feature: 'natural_language_edit',
                options: [
                    'temperature' => 0.6,
                    'max_tokens' => 2000,
                ]
            );

            $result = json_decode($response['content'], true);

            if (json_last_error() !== JSON_ERROR_NONE || !isset($result['edited_content'])) {
                // Fallback: retorna conteúdo editado simples
                return [
                    'edited_content' => $this->simpleEdit($originalContent, $naturalCommand),
                    'changes_made' => ['Edição aplicada via comando: ' . $naturalCommand],
                    'suggestions' => [],
                    'original_length' => mb_strlen($originalContent),
                    'new_length' => mb_strlen($this->simpleEdit($originalContent, $naturalCommand)),
                ];
            }

            // Adiciona metadados
            $result['original_length'] = mb_strlen($originalContent);
            $result['new_length'] = mb_strlen($result['edited_content']);
            $result['command_used'] = $naturalCommand;

            return $result;
        } catch (\Exception $e) {
            Log::error("Natural language edit failed: {$e->getMessage()}", [
                'brand_id' => $brand->id,
                'command' => $naturalCommand,
            ]);

            return [
                'edited_content' => $originalContent,
                'changes_made' => [],
                'suggestions' => ['Erro ao processar comando. Tente novamente.'],
                'error' => true,
            ];
        }
    }

    /**
     * Sugere melhorias automáticas baseadas no tipo de conteúdo
     *
     * @param string $content Conteúdo a analisar
     * @param string $platform Plataforma (instagram, facebook, linkedin, etc)
     * @param Brand $brand Marca
     * @return array Lista de sugestões
     */
    public function suggestImprovements(string $content, string $platform, Brand $brand): array
    {
        $platformGuidelines = $this->getPlatformGuidelines($platform);

        $prompt = <<<PROMPT
Analise este conteúdo para {$platform} e sugira melhorias específicas.

CONTEÚDO:
{$content}

DIRETRIZES DA PLATAFORMA {$platform}:
{$platformGuidelines}

Tarefa: Identifique oportunidades de melhoria e retorne JSON:
{
  "score": "nota 0-100",
  "strengths": ["pontos fortes do conteúdo"],
  "improvements": [
    {
      "area": "hook|cta|tom|comprimento|visual|engajamento",
      "issue": "problema identificado",
      "suggestion": "como melhorar",
      "priority": "alta|média|baixa"
    }
  ],
  "quick_fixes": ["sugestões rápidas que podem ser aplicadas"],
  "best_practices_check": {
    "has_hook": true|false,
    "has_cta": true|false,
    "has_emojis_appropriately": true|false,
    "optimal_length": true|false,
    "platform_optimized": true|false
  }
}

Seja honesto mas construtivo. Foque em melhorias acionáveis.
PROMPT;

        try {
            $response = $this->aiGateway->chat(
                model: AIModel::GeminiFlash,
                messages: [['role' => 'user', 'content' => $prompt]],
                brand: $brand,
                feature: 'content_suggestions',
                options: ['temperature' => 0.4, 'max_tokens' => 1500]
            );

            $result = json_decode($response['content'], true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $result;
            }

            return $this->getBasicSuggestions($content, $platform);
        } catch (\Exception $e) {
            return $this->getBasicSuggestions($content, $platform);
        }
    }

    /**
     * Aplica comando pré-definido
     *
     * @param string $content Conteúdo original
     * @param string $presetKey Chave do comando pré-definido
     * @param Brand $brand Marca
     * @return array Resultado
     */
    public function applyPreset(string $content, string $presetKey, Brand $brand): array
    {
        if (!isset(self::PRESET_COMMANDS[$presetKey])) {
            return [
                'edited_content' => $content,
                'error' => 'Comando pré-definido não encontrado',
            ];
        }

        $preset = self::PRESET_COMMANDS[$presetKey];

        return $this->editContent(
            $content,
            $preset['command'],
            $brand
        );
    }

    /**
     * Gera variações do conteúdo
     *
     * @param string $content Conteúdo base
     * @param int $count Quantidade de variações
     * @param Brand $brand Marca
     * @return array Variações geradas
     */
    public function generateVariations(string $content, int $count, Brand $brand): array
    {
        $prompt = <<<PROMPT
Crie {$count} variações deste conteúdo, cada uma com ângulo diferente.

CONTEÚDO ORIGINAL:
{$content}

Crie variações que:
1. Mantenham a mensagem central
2. Tenham ângulos diferentes (educativo, emocional, prático, inspiracional)
3. Sejam apropriadas para redes sociais

Retorne JSON:
{
  "variations": [
    {
      "angle": "tipo de abordagem",
      "content": "texto da variação",
      "best_for": "quando usar esta versão"
    }
  ]
}
PROMPT;

        try {
            $response = $this->aiGateway->chat(
                model: AIModel::GPT4oMini,
                messages: [['role' => 'user', 'content' => $prompt]],
                brand: $brand,
                feature: 'content_variations',
                options: ['temperature' => 0.8, 'max_tokens' => 2000]
            );

            $result = json_decode($response['content'], true);

            return $result['variations'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Retorna lista de comandos pré-definidos disponíveis
     */
    public function getPresetCommands(): array
    {
        return self::PRESET_COMMANDS;
    }

    /**
     * Interpreta comando natural (converte comando do usuário para instrução estruturada)
     *
     * @param string $userCommand Comando em linguagem natural do usuário
     * @return string Instrução estruturada
     */
    public function interpretCommand(string $userCommand): string
    {
        $commandLower = mb_strtolower($userCommand);

        // Mapeia comandos comuns
        $mappings = [
            // Tom de voz
            '/mais (descontraído|leve|amigável|informal|casual)/' => 'Torne este conteúdo mais descontraído, leve e amigável',
            '/mais (formal|sério|profissional|corporativo)/' => 'Torne este conteúdo mais formal e profissional',
            '/mais (emocional|sentimental|tocante)/' => 'Torne este conteúdo mais emotivo e conecte-se com os sentimentos',
            '/mais (técnico|especializado|detalhado)/' => 'Adicione termos técnicos e detalhes específicos',
            '/mais (simples|fácil|básico)/' => 'Simplifique para leigos entenderem facilmente',
            '/mais (persuasivo|vendedor|convicente)/' => 'Torne mais persuasivo usando copywriting',

            // Tamanho
            '/(mais curto|resumir|diminuir|encurtar)/' => 'Reduza para metade do tamanho, mantendo apenas o essencial',
            '/(mais longo|expandir|aumentar|detalhar)/' => 'Expanda com mais detalhes, exemplos e contexto',

            // Elementos
            '/(adicionar|colocar|incluir) emoji/' => 'Adicione emojis apropriados bem distribuídos',
            '/(remover|tirar) emoji/' => 'Remova todos os emojis',
            '/(melhorar|fortalecer) (cta|call to action|chamada)/' => 'Melhore o call-to-action, torne-o mais persuasivo',
            '/(adicionar|incluir) (humor|piada)/' => 'Adicione leveza e humor apropriado',

            // Estrutura
            '/(foco em|focar) (benefícios|beneficio)/' => 'Reorganize focando nos benefícios, não características',
            '/(storytelling|história|narrativa)/' => 'Reescreva como uma história com início, meio e fim',
            '/(urgência|urgente|corra|últimas)/' => 'Adicione senso de urgência e escassez',

            // Estilo
            '/(título|headline) (maior|melhor|forte)/' => 'Melhore o título/headline para ser mais impactante',
            '/(título|headline) (menor|menos)/' => 'Simplifique o título/headline',
            '/(tom|tons) (mais quente|mais calor)/' => 'Use uma paleta de cores mais quente e acolhedora',
            '/(tom|tons) (mais frio|mais profissional)/' => 'Use uma paleta de cores mais profissional e sóbria',
        ];

        foreach ($mappings as $pattern => $instruction) {
            if (preg_match($pattern, $commandLower)) {
                return $instruction;
            }
        }

        // Se não encontrou mapeamento, retorna comando original
        return $userCommand;
    }

    /**
     * Constrói prompt de edição
     */
    private function buildEditPrompt(
        string $content,
        string $command,
        Brand $brand,
        array $brandDNA,
        ?string $platform
    ): string {
        $platformContext = $platform ? "PLATAFORMA: {$platform}\n" : '';
        $voiceTone = $brandDNA['voice_tone'] ?? [];

        return <<<PROMPT
Você é um editor de conteúdo especialista em marketing digital. Edite o conteúdo seguindo o comando.

=== CONTEXTO ===
MARCA: {$brand->name}
Tom de Voz: {$voiceTone['tone']}
Personalidade: {$voiceTone['personality']}
{$platformContext}

=== CONTEÚDO ORIGINAL ===
{$content}

=== COMANDO DO USUÁRIO ===
{$command}

=== INSTRUÇÕES DE EDIÇÃO ===
1. Aplique o comando mantendo a essência da mensagem
2. Preserve o tom de voz da marca ({$voiceTone['tone']})
3. Mantenha a personalidade da marca ({$voiceTone['personality']})
4. {$platform} Gere conteúdo otimizado para {$platform}
5. Não adicione explicações, retorne apenas o conteúdo editado

=== FORMATO DE SAÍDA ===
Retorne JSON:
{
  "edited_content": "conteúdo completo editado",
  "changes_made": ["lista específica do que foi alterado"],
  "suggestions": ["sugestões adicionais opcionais para melhorar ainda mais"],
  "tone_check": "como o tom mudou após a edição"
}
PROMPT;
    }

    /**
     * Retorna diretrizes da plataforma
     */
    private function getPlatformGuidelines(string $platform): string
    {
        return match ($platform) {
            'instagram' => '- Optimal: 125-150 caracteres para caption\n- Use emojis estrategicamente\n- Hook forte nas primeiras 2 linhas\n- Hashtags: 5-10 relevantes\n- CTA claro no final',
            'facebook' => '- Pode ser mais longo (até 500 chars)\n- Storytelling funciona bem\n- Links funcionam diretamente\n- Engaje com perguntas\n- Use formatação com quebras',
            'linkedin' => '- Tom profissional\n- Conteúdo educativo valorizado\n- 1300 caracteres ideal\n- Evite excesso de emojis\n- Foco em insights',
            'tiktok' => '- Curto e direto\n- Tom descontraído\n- Use trends quando relevante\n- Hook imediato\n- Muitos emojis',
            'twitter' => '- Limite 280 caracteres\n- Direto ao ponto\n- Use threads para conteúdo longo\n- Timing é crucial',
            default => '- Mantenha claro e objetivo\n- CTA sempre presente\n- Value proposition clara',
        };
    }

    /**
     * Sugestões básicas de fallback
     */
    private function getBasicSuggestions(string $content, string $platform): array
    {
        $length = mb_strlen($content);

        $suggestions = [
            'score' => 70,
            'strengths' => ['Conteúdo estruturado'],
            'improvements' => [],
            'quick_fixes' => [],
            'best_practices_check' => [
                'has_hook' => $length > 50,
                'has_cta' => str_contains(mb_strtolower($content), ['clique', 'acesse', 'saiba', 'confira', 'veja']),
                'has_emojis_appropriately' => true,
                'optimal_length' => $length > 100 && $length < 500,
                'platform_optimized' => true,
            ],
        ];

        if ($length < 100) {
            $suggestions['improvements'][] = [
                'area' => 'comprimento',
                'issue' => 'Conteúdo muito curto',
                'suggestion' => 'Adicione mais contexto e detalhes',
                'priority' => 'média',
            ];
        }

        if (!str_contains(mb_strtolower($content), ['clique', 'acesse', 'saiba', 'confira', 'veja', 'comente', 'compartilhe'])) {
            $suggestions['improvements'][] = [
                'area' => 'cta',
                'issue' => 'Call-to-action ausente ou fraco',
                'suggestion' => 'Adicione um CTA claro no final',
                'priority' => 'alta',
            ];
        }

        return $suggestions;
    }

    /**
     * Edição simples de fallback
     */
    private function simpleEdit(string $content, string $command): string
    {
        $commandLower = mb_strtolower($command);

        // Aplica transformações básicas
        if (str_contains($commandLower, 'emoji')) {
            if (str_contains($commandLower, 'remover')) {
                return preg_replace('/[\x{1F600}-\x{1F64F}|\x{1F300}-\x{1F5FF}|\x{1F680}-\x{1F6FF}|\x{2600}-\x{26FF}|\x{2700}-\x{27BF}]/u', '', $content);
            }
            // Adicionar emojis seria complexo sem IA, mantém original
        }

        if (str_contains($commandLower, ['mais curto', 'resumir', 'diminuir'])) {
            return mb_substr($content, 0, intval(mb_strlen($content) * 0.6)) . '...';
        }

        return $content;
    }
}
