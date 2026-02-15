<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialMetricTemplate extends Model
{
    protected $fillable = [
        'platform',
        'metric_key',
        'name',
        'description',
        'unit',
        'value_prefix',
        'value_suffix',
        'decimal_places',
        'direction',
        'aggregation',
        'color',
        'icon',
        'category',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'decimal_places' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * Templates padrao para todas as plataformas
     */
    public static function getDefaults(): array
    {
        return [
            // ===== INSTAGRAM =====
            ['platform' => 'instagram', 'metric_key' => 'followers_count', 'name' => 'Seguidores', 'description' => 'Total de seguidores do perfil', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'last', 'color' => '#E4405F', 'icon' => 'users', 'category' => 'growth', 'sort_order' => 1],
            ['platform' => 'instagram', 'metric_key' => 'net_followers', 'name' => 'Novos Seguidores (dia)', 'description' => 'Seguidores ganhos menos perdidos no dia', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#E4405F', 'icon' => 'user-plus', 'category' => 'growth', 'sort_order' => 2],
            ['platform' => 'instagram', 'metric_key' => 'reach', 'name' => 'Alcance', 'description' => 'Contas unicas que viram seu conteudo', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#E4405F', 'icon' => 'eye', 'category' => 'engagement', 'sort_order' => 3],
            ['platform' => 'instagram', 'metric_key' => 'impressions', 'name' => 'Impressoes', 'description' => 'Total de vezes que seu conteudo foi exibido', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#E4405F', 'icon' => 'eye', 'category' => 'engagement', 'sort_order' => 4],
            ['platform' => 'instagram', 'metric_key' => 'engagement', 'name' => 'Engajamento Total', 'description' => 'Curtidas + comentarios + salvos + compartilhamentos', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#E4405F', 'icon' => 'heart', 'category' => 'engagement', 'sort_order' => 5],
            ['platform' => 'instagram', 'metric_key' => 'engagement_rate', 'name' => 'Taxa de Engajamento', 'description' => 'Engajamento / Seguidores x 100', 'unit' => 'percentage', 'value_suffix' => '%', 'decimal_places' => 2, 'direction' => 'up', 'aggregation' => 'avg', 'color' => '#E4405F', 'icon' => 'percent', 'category' => 'engagement', 'sort_order' => 6],
            ['platform' => 'instagram', 'metric_key' => 'likes', 'name' => 'Curtidas', 'description' => 'Total de curtidas recebidas', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#E4405F', 'icon' => 'heart', 'category' => 'engagement', 'sort_order' => 7],
            ['platform' => 'instagram', 'metric_key' => 'comments', 'name' => 'Comentarios', 'description' => 'Total de comentarios recebidos', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#E4405F', 'icon' => 'message-circle', 'category' => 'engagement', 'sort_order' => 8],
            ['platform' => 'instagram', 'metric_key' => 'saves', 'name' => 'Salvamentos', 'description' => 'Total de vezes que seu conteudo foi salvo', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#E4405F', 'icon' => 'bookmark', 'category' => 'engagement', 'sort_order' => 9],
            ['platform' => 'instagram', 'metric_key' => 'shares', 'name' => 'Compartilhamentos', 'description' => 'Total de compartilhamentos do conteudo', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#E4405F', 'icon' => 'share', 'category' => 'engagement', 'sort_order' => 10],
            ['platform' => 'instagram', 'metric_key' => 'posts_count', 'name' => 'Total de Posts', 'description' => 'Quantidade total de publicacoes', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'last', 'color' => '#E4405F', 'icon' => 'image', 'category' => 'content', 'sort_order' => 11],
            ['platform' => 'instagram', 'metric_key' => 'profile_views', 'name' => 'Visitas ao Perfil', 'description' => 'Visitas ao perfil no periodo', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#E4405F', 'icon' => 'user', 'category' => 'engagement', 'sort_order' => 12],
            ['platform' => 'instagram', 'metric_key' => 'website_clicks', 'name' => 'Cliques no Site', 'description' => 'Cliques no link do site na bio', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#E4405F', 'icon' => 'external-link', 'category' => 'conversion', 'sort_order' => 13],

            // ===== FACEBOOK =====
            ['platform' => 'facebook', 'metric_key' => 'followers_count', 'name' => 'Seguidores da Pagina', 'description' => 'Total de seguidores da pagina', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'last', 'color' => '#1877F2', 'icon' => 'users', 'category' => 'growth', 'sort_order' => 1],
            ['platform' => 'facebook', 'metric_key' => 'net_followers', 'name' => 'Novos Seguidores (dia)', 'description' => 'Seguidores ganhos no dia', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#1877F2', 'icon' => 'user-plus', 'category' => 'growth', 'sort_order' => 2],
            ['platform' => 'facebook', 'metric_key' => 'reach', 'name' => 'Alcance da Pagina', 'description' => 'Pessoas unicas que viram conteudo da pagina', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#1877F2', 'icon' => 'eye', 'category' => 'engagement', 'sort_order' => 3],
            ['platform' => 'facebook', 'metric_key' => 'impressions', 'name' => 'Impressoes da Pagina', 'description' => 'Total de impressoes de conteudo da pagina', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#1877F2', 'icon' => 'eye', 'category' => 'engagement', 'sort_order' => 4],
            ['platform' => 'facebook', 'metric_key' => 'engagement', 'name' => 'Engajamento Total', 'description' => 'Reacoes + comentarios + compartilhamentos', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#1877F2', 'icon' => 'heart', 'category' => 'engagement', 'sort_order' => 5],
            ['platform' => 'facebook', 'metric_key' => 'engagement_rate', 'name' => 'Taxa de Engajamento', 'description' => 'Engajamento / Alcance x 100', 'unit' => 'percentage', 'value_suffix' => '%', 'decimal_places' => 2, 'direction' => 'up', 'aggregation' => 'avg', 'color' => '#1877F2', 'icon' => 'percent', 'category' => 'engagement', 'sort_order' => 6],
            ['platform' => 'facebook', 'metric_key' => 'clicks', 'name' => 'Cliques na Pagina', 'description' => 'Total de cliques em links e CTA da pagina', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#1877F2', 'icon' => 'mouse-pointer', 'category' => 'conversion', 'sort_order' => 7],
            ['platform' => 'facebook', 'metric_key' => 'video_views', 'name' => 'Views de Videos', 'description' => 'Total de visualizacoes de videos', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#1877F2', 'icon' => 'play', 'category' => 'content', 'sort_order' => 8],

            // ===== YOUTUBE =====
            ['platform' => 'youtube', 'metric_key' => 'followers_count', 'name' => 'Inscritos', 'description' => 'Total de inscritos do canal', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'last', 'color' => '#FF0000', 'icon' => 'users', 'category' => 'growth', 'sort_order' => 1],
            ['platform' => 'youtube', 'metric_key' => 'net_followers', 'name' => 'Novos Inscritos (periodo)', 'description' => 'Inscritos ganhos menos perdidos no periodo', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#FF0000', 'icon' => 'user-plus', 'category' => 'growth', 'sort_order' => 2],
            ['platform' => 'youtube', 'metric_key' => 'reach', 'name' => 'Alcance (Views 30d)', 'description' => 'Total de visualizacoes nos ultimos 30 dias', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#FF0000', 'icon' => 'eye', 'category' => 'engagement', 'sort_order' => 3],
            ['platform' => 'youtube', 'metric_key' => 'video_views', 'name' => 'Visualizacoes Totais', 'description' => 'Total de visualizacoes acumuladas do canal', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'last', 'color' => '#FF0000', 'icon' => 'play', 'category' => 'engagement', 'sort_order' => 4],
            ['platform' => 'youtube', 'metric_key' => 'posts_count', 'name' => 'Total de Videos', 'description' => 'Quantidade total de videos publicados', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'last', 'color' => '#FF0000', 'icon' => 'video', 'category' => 'content', 'sort_order' => 5],
            ['platform' => 'youtube', 'metric_key' => 'engagement', 'name' => 'Engajamento Total', 'description' => 'Curtidas + comentarios + compartilhamentos', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#FF0000', 'icon' => 'heart', 'category' => 'engagement', 'sort_order' => 6],
            ['platform' => 'youtube', 'metric_key' => 'engagement_rate', 'name' => 'Taxa de Engajamento', 'description' => 'Engajamento / Inscritos x 100', 'unit' => 'percentage', 'value_suffix' => '%', 'decimal_places' => 2, 'direction' => 'up', 'aggregation' => 'avg', 'color' => '#FF0000', 'icon' => 'percent', 'category' => 'engagement', 'sort_order' => 7],
            ['platform' => 'youtube', 'metric_key' => 'likes', 'name' => 'Curtidas', 'description' => 'Total de curtidas nos videos (30d)', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#FF0000', 'icon' => 'thumbs-up', 'category' => 'engagement', 'sort_order' => 8],
            ['platform' => 'youtube', 'metric_key' => 'comments', 'name' => 'Comentarios', 'description' => 'Total de comentarios nos videos (30d)', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#FF0000', 'icon' => 'message-circle', 'category' => 'engagement', 'sort_order' => 9],
            ['platform' => 'youtube', 'metric_key' => 'shares', 'name' => 'Compartilhamentos', 'description' => 'Total de compartilhamentos de videos (30d)', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#FF0000', 'icon' => 'share', 'category' => 'engagement', 'sort_order' => 10],
            ['platform' => 'youtube', 'metric_key' => 'watch_time_minutes', 'name' => 'Tempo de Exibicao', 'description' => 'Minutos totais assistidos (30d)', 'unit' => 'number', 'value_suffix' => 'min', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#FF0000', 'icon' => 'clock', 'category' => 'engagement', 'sort_order' => 11],

            // ===== TIKTOK =====
            ['platform' => 'tiktok', 'metric_key' => 'followers_count', 'name' => 'Seguidores', 'description' => 'Total de seguidores', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'last', 'color' => '#000000', 'icon' => 'users', 'category' => 'growth', 'sort_order' => 1],
            ['platform' => 'tiktok', 'metric_key' => 'likes', 'name' => 'Curtidas Totais', 'description' => 'Total de curtidas recebidas', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'last', 'color' => '#000000', 'icon' => 'heart', 'category' => 'engagement', 'sort_order' => 2],
            ['platform' => 'tiktok', 'metric_key' => 'video_views', 'name' => 'Visualizacoes', 'description' => 'Total de visualizacoes dos videos', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#000000', 'icon' => 'play', 'category' => 'engagement', 'sort_order' => 3],
            ['platform' => 'tiktok', 'metric_key' => 'engagement', 'name' => 'Engajamento Total', 'description' => 'Curtidas + comentarios + compartilhamentos dos videos', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#000000', 'icon' => 'heart', 'category' => 'engagement', 'sort_order' => 4],
            ['platform' => 'tiktok', 'metric_key' => 'engagement_rate', 'name' => 'Taxa de Engajamento', 'description' => 'Engajamento / Seguidores x 100', 'unit' => 'percentage', 'value_suffix' => '%', 'decimal_places' => 2, 'direction' => 'up', 'aggregation' => 'avg', 'color' => '#000000', 'icon' => 'percent', 'category' => 'engagement', 'sort_order' => 5],
            ['platform' => 'tiktok', 'metric_key' => 'comments', 'name' => 'Comentarios', 'description' => 'Total de comentarios recebidos nos videos', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#000000', 'icon' => 'message-circle', 'category' => 'engagement', 'sort_order' => 6],
            ['platform' => 'tiktok', 'metric_key' => 'shares', 'name' => 'Compartilhamentos', 'description' => 'Total de compartilhamentos dos videos', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#000000', 'icon' => 'share', 'category' => 'engagement', 'sort_order' => 7],
            ['platform' => 'tiktok', 'metric_key' => 'posts_count', 'name' => 'Total de Videos', 'description' => 'Quantidade total de videos publicados', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'last', 'color' => '#000000', 'icon' => 'video', 'category' => 'content', 'sort_order' => 8],

            // ===== LINKEDIN =====
            ['platform' => 'linkedin', 'metric_key' => 'followers_count', 'name' => 'Seguidores', 'description' => 'Total de seguidores da pagina', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'last', 'color' => '#0A66C2', 'icon' => 'users', 'category' => 'growth', 'sort_order' => 1],
            ['platform' => 'linkedin', 'metric_key' => 'impressions', 'name' => 'Impressoes', 'description' => 'Impressoes dos posts', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#0A66C2', 'icon' => 'eye', 'category' => 'engagement', 'sort_order' => 2],
            ['platform' => 'linkedin', 'metric_key' => 'engagement', 'name' => 'Engajamento', 'description' => 'Interacoes totais com o conteudo', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#0A66C2', 'icon' => 'heart', 'category' => 'engagement', 'sort_order' => 3],
            ['platform' => 'linkedin', 'metric_key' => 'likes', 'name' => 'Curtidas', 'description' => 'Total de curtidas nos posts', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#0A66C2', 'icon' => 'thumbs-up', 'category' => 'engagement', 'sort_order' => 4],
            ['platform' => 'linkedin', 'metric_key' => 'comments', 'name' => 'Comentarios', 'description' => 'Total de comentarios nos posts', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#0A66C2', 'icon' => 'message-circle', 'category' => 'engagement', 'sort_order' => 5],
            ['platform' => 'linkedin', 'metric_key' => 'shares', 'name' => 'Compartilhamentos', 'description' => 'Total de compartilhamentos dos posts', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#0A66C2', 'icon' => 'share', 'category' => 'engagement', 'sort_order' => 6],
            ['platform' => 'linkedin', 'metric_key' => 'clicks', 'name' => 'Cliques', 'description' => 'Cliques no conteudo', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#0A66C2', 'icon' => 'mouse-pointer', 'category' => 'conversion', 'sort_order' => 7],

            // ===== PINTEREST =====
            ['platform' => 'pinterest', 'metric_key' => 'followers_count', 'name' => 'Seguidores', 'description' => 'Total de seguidores', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'last', 'color' => '#E60023', 'icon' => 'users', 'category' => 'growth', 'sort_order' => 1],
            ['platform' => 'pinterest', 'metric_key' => 'impressions', 'name' => 'Impressoes', 'description' => 'Impressoes dos pins', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#E60023', 'icon' => 'eye', 'category' => 'engagement', 'sort_order' => 2],
            ['platform' => 'pinterest', 'metric_key' => 'engagement', 'name' => 'Engajamento Total', 'description' => 'Salvamentos + cliques nos pins', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#E60023', 'icon' => 'heart', 'category' => 'engagement', 'sort_order' => 3],
            ['platform' => 'pinterest', 'metric_key' => 'engagement_rate', 'name' => 'Taxa de Engajamento', 'description' => 'Engajamento / Seguidores x 100', 'unit' => 'percentage', 'value_suffix' => '%', 'decimal_places' => 2, 'direction' => 'up', 'aggregation' => 'avg', 'color' => '#E60023', 'icon' => 'percent', 'category' => 'engagement', 'sort_order' => 4],
            ['platform' => 'pinterest', 'metric_key' => 'saves', 'name' => 'Salvamentos', 'description' => 'Pins salvos pelos usuarios', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#E60023', 'icon' => 'bookmark', 'category' => 'engagement', 'sort_order' => 5],
            ['platform' => 'pinterest', 'metric_key' => 'clicks', 'name' => 'Cliques no Link', 'description' => 'Cliques em links dos pins', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'sum', 'color' => '#E60023', 'icon' => 'mouse-pointer', 'category' => 'conversion', 'sort_order' => 6],
            ['platform' => 'pinterest', 'metric_key' => 'posts_count', 'name' => 'Total de Pins', 'description' => 'Quantidade total de pins publicados', 'unit' => 'number', 'direction' => 'up', 'aggregation' => 'last', 'color' => '#E60023', 'icon' => 'image', 'category' => 'content', 'sort_order' => 7],
        ];
    }

    /**
     * Seed dos templates padrao
     */
    public static function seedDefaults(): void
    {
        foreach (static::getDefaults() as $template) {
            static::updateOrCreate(
                ['platform' => $template['platform'], 'metric_key' => $template['metric_key']],
                $template
            );
        }
    }
}
