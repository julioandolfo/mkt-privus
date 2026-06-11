-- ============================================================
-- VERIFICAR SE WEBHOOKS ESTÃO FUNCIONANDO
-- Execute após configurar webhook no SendPulse
-- ============================================================

-- 1. VERIFICAR ÚLTIMOS WEBHOOKS RECEBIDOS (últimos 30 minutos)
SELECT 
    'WEBHOOKS RECEBIDOS (30min)' as verificacao,
    sl.created_at,
    sl.action,
    sl.message,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.email')) as email,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.event')) as evento_sendpulse,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.campaign_id')) as campanha
FROM system_logs sl
WHERE sl.created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
AND (
    sl.channel LIKE '%webhook%'
    OR sl.action LIKE '%webhook%'
    OR sl.source LIKE '%webhook%'
)
ORDER BY sl.created_at DESC
LIMIT 20;

-- 2. CONTAR EVENTOS POR TIPO (webhook vs sistema)
SELECT 
    'EVENTOS DA CAMPANHA #1' as info,
    event_type,
    COUNT(*) as total,
    MAX(occurred_at) as ultimo_evento
FROM email_campaign_events
WHERE email_campaign_id = 1
AND event_type IN ('sent', 'delivered', 'opened', 'clicked', 'bounced', 'complained')
GROUP BY event_type
ORDER BY 
    CASE event_type
        WHEN 'sent' THEN 1
        WHEN 'delivered' THEN 2
        WHEN 'opened' THEN 3
        WHEN 'clicked' THEN 4
        ELSE 5
    END;

-- 3. VERIFICAR SE HÁ WEBHOOKS DE ABERTURA (open)
SELECT 
    'WEBHOOKS DE ABERTURA' as check_opened,
    COUNT(*) as total_aberturas_webhook,
    MIN(occurred_at) as primeira,
    MAX(occurred_at) as ultima
FROM email_campaign_events
WHERE email_campaign_id = 1
AND event_type = 'opened'
AND occurred_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR);

-- 4. VERIFICAR SE HÁ WEBHOOKS DE ENTREGA (delivered)
SELECT 
    'WEBHOOKS DE ENTREGA' as check_delivered,
    COUNT(*) as total_entregues_webhook,
    MIN(occurred_at) as primeira,
    MAX(occurred_at) as ultima
FROM email_campaign_events
WHERE email_campaign_id = 1
AND event_type = 'delivered';

-- 5. RESUMO DE FUNCIONAMENTO
SELECT 
    'STATUS DOS WEBHOOKS' as resumo,
    CASE 
        WHEN EXISTS (
            SELECT 1 FROM system_logs 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            AND (channel LIKE '%webhook%' OR action LIKE '%webhook%')
        ) THEN '✅ WEBHOOKS RECEBENDO DADOS'
        WHEN EXISTS (
            SELECT 1 FROM email_campaign_events 
            WHERE email_campaign_id = 1 
            AND event_type IN ('opened', 'clicked', 'delivered')
            AND occurred_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ) THEN '✅ EVENTOS SENDO REGISTRADOS'
        ELSE '⏳ AGUARDANDO PRIMEIRO WEBHOOK...'
    END as status,
    NOW() as hora_verificacao;

-- ============================================================
-- RESULTADO ESPERADO:
-- ============================================================
-- Se webhooks estiverem funcionando:
-- - A tabela 2 vai mostrar 'opened', 'clicked' aumentando
-- - A tabela 4 vai mostrar 'delivered' > 0 (Entregues)
--
-- Se ainda não recebeu nenhum webhook:
-- - O status vai mostrar "AGUARDANDO PRIMEIRO WEBHOOK"
-- - Isso é normal nos primeiros minutos após configurar
-- ============================================================
