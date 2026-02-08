<?php

namespace App\Services\Social;

use App\Enums\PostType;
use App\Enums\SocialPlatform;
use App\Models\ContentRule;

/**
 * Biblioteca de prompts otimizados para geracao de conteudo social.
 * Prompts configurados por plataforma com boas praticas de cada rede.
 */
class SocialPrompts
{
    /**
     * Limites de caracteres por plataforma
     */
    public static function charLimit(SocialPlatform $platform): int
    {
        return match ($platform) {
            SocialPlatform::Instagram => 2200,
            SocialPlatform::Facebook => 63206,
            SocialPlatform::LinkedIn => 3000,
            SocialPlatform::TikTok => 2200,
            SocialPlatform::YouTube => 5000,
            SocialPlatform::Pinterest => 500,
        };
    }

    /**
     * Quantidade recomendada de hashtags por plataforma
     */
    public static function hashtagCount(SocialPlatform $platform): array
    {
        return match ($platform) {
            SocialPlatform::Instagram => ['min' => 5, 'max' => 15, 'ideal' => 10],
            SocialPlatform::Facebook => ['min' => 1, 'max' => 5, 'ideal' => 3],
            SocialPlatform::LinkedIn => ['min' => 3, 'max' => 5, 'ideal' => 4],
            SocialPlatform::TikTok => ['min' => 3, 'max' => 8, 'ideal' => 5],
            SocialPlatform::YouTube => ['min' => 5, 'max' => 15, 'ideal' => 10],
            SocialPlatform::Pinterest => ['min' => 2, 'max' => 10, 'ideal' => 5],
        };
    }

    /**
     * Prompt de sistema para geracao de legendas
     */
    public static function captionSystemPrompt(SocialPlatform $platform, ?string $brandContext = null): string
    {
        $platformGuide = self::platformGuide($platform);
        $charLimit = self::charLimit($platform);

        $prompt = <<<PROMPT
Voce e um especialista em marketing digital e criacao de conteudo para redes sociais.
Sua tarefa e criar legendas envolventes e eficazes para {$platform->label()}.

## Diretrizes para {$platform->label()}:
{$platformGuide}

## Regras:
- Limite de caracteres: {$charLimit}
- Responda APENAS com a legenda, sem explicacoes extras
- Inclua emojis relevantes de forma natural (nao em excesso)
- Use quebras de linha para facilitar a leitura
- Adapte o tom conforme solicitado
- NAO inclua hashtags na legenda (elas serao geradas separadamente)
- Escreva em portugues do Brasil
PROMPT;

        if ($brandContext) {
            $prompt .= "\n\n## Contexto da Marca:\n{$brandContext}";
        }

        return $prompt;
    }

    /**
     * Prompt de sistema para geracao de hashtags
     */
    public static function hashtagSystemPrompt(SocialPlatform $platform): string
    {
        $counts = self::hashtagCount($platform);

        return <<<PROMPT
Voce e um especialista em hashtags para {$platform->label()}.
Gere hashtags relevantes e estrategicas para maximizar o alcance.

## Regras:
- Gere entre {$counts['min']} e {$counts['max']} hashtags (ideal: {$counts['ideal']})
- Misture hashtags populares (alto volume) com hashtags de nicho (menor competicao)
- Todas em portugues, exceto termos universais do segmento
- Formato: cada hashtag com # seguido da palavra, sem espacos
- Responda APENAS com as hashtags separadas por espaco, sem explicacoes
- NAO use acentos nas hashtags
PROMPT;
    }

    /**
     * Guia de boas praticas por plataforma
     */
    public static function platformGuide(SocialPlatform $platform): string
    {
        return match ($platform) {
            SocialPlatform::Instagram => <<<'GUIDE'
- Comece com um gancho forte nas primeiras 2 linhas (antes do "ver mais")
- Use storytelling quando apropriado
- Inclua call-to-action (CTA) no final
- Emojis moderados e estrategicos
- Quebras de linha para facilitar leitura
- Para Reels: legendas mais curtas e diretas
- Para Carousel: referencia ao conteudo dos slides
GUIDE,

            SocialPlatform::Facebook => <<<'GUIDE'
- Conteudo pode ser mais longo e detalhado
- Tom mais conversacional e pessoal
- Perguntas para estimular comentarios
- Links podem ser incluidos diretamente
- Use storytelling e contexto emocional
- Posts com imagem performam melhor
GUIDE,

            SocialPlatform::LinkedIn => <<<'GUIDE'
- Tom profissional mas acessivel
- Comece com insight ou dado impactante
- Compartilhe aprendizados e experiencias profissionais
- Use quebras de linha curtas (estilo "hooks")
- Inclua dados e estatisticas quando possivel
- CTA para interacao (opiniao, experiencia similar)
- Evite linguagem muito informal ou giriAs
GUIDE,

            SocialPlatform::TikTok => <<<'GUIDE'
- Legendas curtas e diretas
- Linguagem jovem e descontraida
- Use tendencias e memes quando relevante
- Perguntas para gerar comentarios
- Referencia ao conteudo do video
- CTAs como "salve para depois", "marque alguem"
GUIDE,

            SocialPlatform::YouTube => <<<'GUIDE'
- Descricao completa do video nas primeiras 2 linhas
- Inclua timestamps quando relevante
- SEO: palavras-chave naturais no texto
- CTA para inscrever-se e ativar notificacoes
- Links relevantes podem ser mencionados
- Tom adequado ao tipo de conteudo do canal
GUIDE,

            SocialPlatform::Pinterest => <<<'GUIDE'
- Descricoes concisas e descritivas
- Foque em palavras-chave para busca (SEO do Pinterest)
- Descreva o que o pin oferece (dica, tutorial, inspiracao)
- Inclua CTA como "clique para saber mais"
- Tom aspiracional e inspirador
- Linguagem simples e direta
GUIDE,
        };
    }

    /**
     * Prompt para gerar variacoes de uma legenda
     */
    public static function variationPrompt(int $count = 3): string
    {
        return <<<PROMPT
Gere {$count} variacoes da legenda fornecida. Cada variacao deve:
- Manter a mesma mensagem/ideia central
- Usar abordagens/ganchos diferentes
- Variar o tom (mais formal, mais descontraido, mais direto)
- Incluir emojis de forma natural

Responda no formato JSON:
[
    {"variation": "texto da variacao 1", "tone": "tom usado"},
    {"variation": "texto da variacao 2", "tone": "tom usado"},
    {"variation": "texto da variacao 3", "tone": "tom usado"}
]

Responda APENAS com o JSON, sem markdown ou explicacoes.
PROMPT;
    }

    /**
     * Prompt para sugerir melhores horarios de postagem
     */
    public static function bestTimesPrompt(SocialPlatform $platform): string
    {
        return <<<PROMPT
Com base nas melhores praticas e dados de engajamento para {$platform->label()}, sugira os 5 melhores horarios para postar conteudo no Brasil.

Considere:
- Fuso horario de Brasilia (UTC-3)
- Dias da semana e finais de semana separadamente
- Horarios de pico de uso da plataforma no Brasil

Responda no formato JSON:
[
    {"day": "Segunda-feira", "time": "09:00", "reason": "motivo"},
    {"day": "Terca-feira", "time": "12:00", "reason": "motivo"}
]

Responda APENAS com o JSON, sem markdown ou explicacoes.
PROMPT;
    }

    /**
     * Prompt para gerar conteudo baseado em uma pauta configurada
     */
    public static function contentRulePrompt(string $brandContext, ContentRule $rule): string
    {
        $categoryDescriptions = [
            'dica' => 'uma dica prática e útil para o público-alvo',
            'novidade' => 'uma novidade ou tendência do setor',
            'bastidores' => 'conteúdo dos bastidores, mostrando o lado humano da marca',
            'promocao' => 'conteúdo promocional que gere desejo e urgência',
            'educativo' => 'conteúdo educativo que agregue valor ao público',
            'inspiracional' => 'conteúdo inspirador e motivacional',
            'engajamento' => 'conteúdo que estimule interação (perguntas, enquetes, desafios)',
            'produto' => 'apresentação de produto ou serviço de forma atrativa',
        ];

        $categoryGuide = $categoryDescriptions[$rule->category] ?? "conteúdo sobre {$rule->category}";
        $tone = $rule->tone_override ?? 'o tom de voz padrão da marca';
        $ruleName = $rule->name;
        $postType = $rule->post_type;
        $platforms = is_array($rule->platforms) ? implode(', ', $rule->platforms) : 'Instagram';

        return <<<PROMPT
Você é um especialista em marketing digital e criação de conteúdo.
Sua tarefa é criar um post completo e pronto para publicação.

{$brandContext}

## Pauta: {$ruleName}
- Objetivo: Criar {$categoryGuide}
- Tom de voz: {$tone}
- Tipo de post: {$postType}
- Plataformas alvo: {$platforms}

## Regras:
- Crie uma legenda COMPLETA, pronta para publicar
- Use emojis de forma natural e moderada
- Inclua call-to-action (CTA) quando apropriado
- Adapte o tamanho ao tipo de plataforma
- NÃO inclua hashtags (serão geradas separadamente)
- Escreva em português do Brasil
- Seja criativo e evite clichês
- O conteúdo deve parecer autêntico, não genérico
PROMPT;
    }

    /**
     * Prompt para gerar sugestões inteligentes automaticamente
     */
    public static function smartSuggestionPrompt(string $brandContext, string $recentPosts, int $count = 3): string
    {
        return <<<PROMPT
Você é um estrategista de conteúdo digital experiente.
Sua tarefa é analisar o contexto da marca e criar {$count} sugestões de posts DIFERENTES e VARIADOS.

{$brandContext}

## Posts Recentes (para evitar repetição):
{$recentPosts}

## Sua tarefa:
Gere {$count} sugestões de posts, cada uma com abordagem e categoria DIFERENTE.
Varie entre: dicas, bastidores, engajamento, educativo, inspiracional, produto, novidade.

## Formato de resposta (JSON):
Responda APENAS com um array JSON, sem markdown, sem explicações:
[
    {
        "title": "Título curto da sugestão",
        "caption": "Legenda completa pronta para publicar, com emojis e CTA. NÃO incluir hashtags.",
        "hashtags": ["#hashtag1", "#hashtag2", "#hashtag3"],
        "platforms": ["instagram"],
        "post_type": "feed",
        "category": "dica"
    }
]

## Regras:
- Cada sugestão DEVE ter uma abordagem/categoria diferente
- NÃO repita temas dos posts recentes
- Legendas em português do Brasil
- Emojis moderados e naturais
- Inclua CTA em cada legenda
- Hashtags: 5 a 10 por sugestão, sem acentos
- Plataformas disponíveis: instagram, facebook, linkedin, tiktok, youtube, pinterest
- Tipos de post: feed, carousel, story, reel, video, pin
- Responda APENAS com o JSON
PROMPT;
    }
}
