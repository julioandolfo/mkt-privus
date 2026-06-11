-- =====================================================
-- DIAGNÓSTICO COMPLETO DA CAMPANHA
-- Substitua o ID_CAMPANHA pelo ID real
-- =====================================================

-- 1. Estado atual da campanha
SELECT
    'CAMPANHA' as info,
    id, name, status, 
    total_recipients, total_sent, total_delivered,
    started_at, completed_at
FROM email_campaigns 
WHERE id = 2;

-- 2. Contagem de eventos por tipo
SELECT
    'EVENTOS POR TIPO' as info,
    event_type,
    COUNT(*) as total,
    COUNT(DISTINCT email_contact_id) as contatos_unicos
FROM email_campaign_events
WHERE email_campaign_id = 2
GROUP BY event_type
ORDER BY total DESC;

-- 3. Últimos 20 eventos (mais recentes)
SELECT
    'ULTIMOS EVENTOS' as info,
    id, event_type, email_contact_id, occurred_at,
    SUBSTRING(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.error')), 1, 200) as erro,
    SUBSTRING(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.message_id')), 1, 100) as message_id
FROM email_campaign_events
WHERE email_campaign_id = 2
ORDER BY id DESC
LIMIT 20;

-- 4. Jobs na fila para esta campanha
SELECT
    'JOBS NA FILA' as info,
    id, queue, attempts, available_at,
    SUBSTRING(payload, 1, 200) as payload_preview
FROM jobs
WHERE queue = 'email'
ORDER BY id DESC
LIMIT 10;

-- 5. Jobs que falharam
SELECT
    'JOBS FALHADOS' as info,
    id, queue, failed_at,
    SUBSTRING(exception, 1, 300) as erro
FROM failed_jobs
WHERE queue = 'email'
ORDER BY failed_at DESC
LIMIT 5;

-- 6. Logs recentes de erro para email
SELECT
    'LOGS DE ERRO' as info,
    id, action, message,
    SUBSTRING(context, 1, 300) as contexto,
    created_at
FROM system_logs
WHERE channel = 'email'
  AND level IN ('error', 'warning')
  AND created_at >= NOW() - INTERVAL 6 HOUR
ORDER BY id DESC
LIMIT 20;

-- 7. Logs do batch (para ver se processou)
SELECT
    'LOGS BATCH' as info,
    id, action, message,
    SUBSTRING(context, 1, 300) as contexto,
    created_at
FROM system_logs
WHERE channel = 'email'
  AND action LIKE 'batch%'
  AND created_at >= NOW() - INTERVAL 6 HOUR
ORDER BY id DESC
LIMIT 15;

-- 8. Logs do SendPulse (API calls)
SELECT
    'LOGS SENDPULSE API' as info,
    id, action, level, message,
    SUBSTRING(context, 1, 400) as contexto,
    created_at
FROM system_logs
WHERE channel = 'email'
  AND (action LIKE 'sendpulse%' OR action LIKE 'provider%')
  AND created_at >= NOW() - INTERVAL 6 HOUR
ORDER BY id DESC
LIMIT 15;