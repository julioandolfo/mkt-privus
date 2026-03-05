<?php

namespace App\Services\AI;

use App\Enums\AIModel;
use App\Models\Brand;
use Illuminate\Support\Facades\Log;

/**
 * Orquestrador inteligente de modelos de IA.
 *
 * Analisa o contexto de cada solicitação e seleciona automaticamente o modelo
 * mais adequado, sem exigir que o usuário escolha manualmente.
 *
 * Estratégia de roteamento em duas camadas:
 *  1. Verificação rápida por padrões (regex/heurísticas) — sem custo de API
 *  2. Classificação via Gemini Flash (modelo mais barato) — para casos ambíguos
 */
class SmartRouter
{
    /**
     * Mapeamento de tipo de tarefa → modelo ideal.
     * Baseado nos pontos fortes documentados de cada modelo.
     */
    private const array TASK_MODEL_MAP = [
        'technical'         => AIModel::GPT4o,          // Código, APIs, SQL, debugging, lógica
        'creative'          => AIModel::Claude35Sonnet,  // Storytelling, escrita criativa, narrativa
        'content_marketing' => AIModel::Claude35Haiku,   // Posts, captions, copy — rápido e eficiente
        'analysis'          => AIModel::Claude35Sonnet,  // Análise estratégica, relatórios, comparativos
        'simple'            => AIModel::GeminiFlash,     // Perguntas rápidas, respostas curtas, small talk
        'translation'       => AIModel::GPT4o,           // Tradução de alta qualidade entre idiomas
        'research'          => AIModel::GeminiPro,       // Pesquisa longa, documentos extensos (contexto 2M)
        'strategic'         => AIModel::Claude35Sonnet,  // Estratégia, planejamento, tomada de decisão
    ];

    /**
     * Rótulos em português para exibição na UI.
     */
    public const array TASK_TYPE_LABELS = [
        'technical'         => 'técnico',
        'creative'          => 'criativo',
        'content_marketing' => 'marketing',
        'analysis'          => 'análise',
        'simple'            => 'resposta rápida',
        'translation'       => 'tradução',
        'research'          => 'pesquisa',
        'strategic'         => 'estratégia',
    ];

    public function __construct(private readonly AIGateway $gateway) {}

    /**
     * Decide qual modelo usar com base na mensagem e contexto da conversa.
     *
     * @param string     $userMessage          Última mensagem do usuário
     * @param array      $conversationHistory  Histórico recente [{role, content}]
     * @param Brand|null $brand                Marca ativa (contexto)
     * @return array{model: AIModel, task_type: string, reason: string}
     */
    public function route(
        string $userMessage,
        array $conversationHistory = [],
        ?Brand $brand = null,
    ): array {
        // Camada 1: verificação rápida por padrões — sem custo de API
        $quickDecision = $this->quickRoute($userMessage);
        if ($quickDecision) {
            Log::debug('SmartRouter: decisão por padrão', $quickDecision);
            return $quickDecision;
        }

        // Camada 2: classificação via Gemini Flash (custo ~zero)
        return $this->llmRoute($userMessage, $conversationHistory);
    }

    /**
     * Retorna o label amigável de um task_type para exibir na UI.
     */
    public static function taskLabel(string $taskType): string
    {
        return self::TASK_TYPE_LABELS[$taskType] ?? $taskType;
    }

    // =========================================================================
    // CAMADA 1 — Verificação rápida por padrões
    // =========================================================================

    /**
     * Heurísticas rápidas baseadas em regex e tamanho da mensagem.
     * Retorna null quando não é possível decidir com confiança sem LLM.
     */
    private function quickRoute(string $message): ?array
    {
        // Bloco de código detectado → modelo técnico
        if (preg_match('/```[\w]*\n|<\?php\b|SELECT\s+\w+\s+FROM|def\s+\w+\s*\(|function\s+\w+\s*\(/i', $message)) {
            return [
                'model'     => AIModel::GPT4o,
                'task_type' => 'technical',
                'reason'    => 'code_block_detected',
            ];
        }

        // Mensagem muito curta e somente texto simples → rápido e barato
        $trimmed = trim($message);
        if (mb_strlen($trimmed) <= 45 && preg_match('/^[\p{L}\p{N}\s,!.?]+$/u', $trimmed)) {
            return [
                'model'     => AIModel::GeminiFlash,
                'task_type' => 'simple',
                'reason'    => 'short_simple_message',
            ];
        }

        return null;
    }

    // =========================================================================
    // CAMADA 2 — Classificação via LLM (Gemini Flash)
    // =========================================================================

    /**
     * Usa Gemini Flash para classificar o tipo de tarefa e retornar o modelo ideal.
     * Inclui as últimas trocas do histórico para melhorar a precisão.
     */
    private function llmRoute(string $message, array $history): array
    {
        $taskTypesList = implode(', ', array_keys(self::TASK_MODEL_MAP));

        // Montar contexto das últimas 2 trocas (máx. 4 mensagens)
        $contextSnippet = '';
        foreach (array_slice($history, -4) as $msg) {
            if ($msg['role'] !== 'system') {
                $contextSnippet .= ucfirst($msg['role']) . ': ' . mb_substr($msg['content'], 0, 120) . "\n";
            }
        }

        $classifierMessages = [
            [
                'role'    => 'system',
                'content' => <<<PROMPT
You are a task classifier for AI model routing. Classify the user message and return ONLY valid JSON.

Task type definitions:
- technical: coding, debugging, APIs, SQL, algorithms, math, programming languages, data structures
- creative: storytelling, poetry, fiction writing, creative narratives, imaginative brainstorming
- content_marketing: social media posts, captions, ad copy, email marketing, slogans, headlines, CTAs, product descriptions
- analysis: analyzing documents, comparing options, competitive analysis, SWOT, reports, metrics interpretation
- simple: greetings, short factual questions, basic lookups, yes/no questions, small talk
- translation: translating or rewriting text in another language
- research: in-depth research, comprehensive guides, technical explanations, literature review
- strategic: business strategy, planning, roadmaps, OKRs, decision-making frameworks, market positioning

Return ONLY a JSON object: {"task_type": "<one of [{$taskTypesList}]>", "confidence": <0.0-1.0>}
PROMPT,
            ],
            [
                'role'    => 'user',
                'content' => ($contextSnippet ? "Recent context:\n{$contextSnippet}\nNew message: " : '')
                    . mb_substr($message, 0, 800),
            ],
        ];

        try {
            $response = $this->gateway->chat(
                model: AIModel::GeminiFlash,
                messages: $classifierMessages,
                feature: 'routing',
                options: ['max_tokens' => 80, 'temperature' => 0.1],
            );

            // Limpar possível markdown em volta do JSON
            $raw = preg_replace(['/^```json?\s*/m', '/```\s*$/m'], '', trim($response['content']));
            $parsed = json_decode(trim($raw), true);

            $taskType = $parsed['task_type'] ?? null;

            if ($taskType && isset(self::TASK_MODEL_MAP[$taskType])) {
                return [
                    'model'     => self::TASK_MODEL_MAP[$taskType],
                    'task_type' => $taskType,
                    'reason'    => 'llm_classified',
                ];
            }

            Log::warning('SmartRouter: tipo desconhecido retornado pelo LLM', ['raw' => $raw]);
        } catch (\Exception $e) {
            Log::warning('SmartRouter: falha na classificação', ['error' => $e->getMessage()]);
        }

        // Fallback seguro: GPT-4o Mini (genérico, confiável, barato)
        return [
            'model'     => AIModel::GPT4oMini,
            'task_type' => 'simple',
            'reason'    => 'classification_failed_fallback',
        ];
    }
}
