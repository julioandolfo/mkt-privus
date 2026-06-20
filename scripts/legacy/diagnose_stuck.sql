-- =====================================================
-- DIAGNÓSTICO DE CAMPANHAS TRAVADAS
-- =====================================================

-- 1. Todas campanhas em status 'sending' há mais de 1 hora
SELECT 
    'CAMPANHAS TRAVADAS' as info,
    id, name, status,
    total_recipients,
    started_at,
    TIMESTAMPDIFF(HOUR, started_at, NOW()) as horas_enviando
FROM email_campaigns
WHERE status = 'sending'
  AND started_at < NOW() - INTERVAL 1 HOUR;

-- 2. Para cada campanha sending, contar eventos (substitua os IDs)
SELECT 
    'EVENTOS POR CAMPANHA' as info,
    ec.id, ec.name, ec.status, ec.total_recipients,
    SUM(CASE WHEN ece.event_type = 'queued' THEN 1 ELSE 0 END) as queued,
    SUM(CASE WHEN ece.event_type = 'sent' THEN 1 ELSE 0 END) as sent,
    SUM(CASE WHEN ece.event_type = 'failed' THEN 1 ELSE 0 END) as failed,
    COUNT(DISTINCT CASE WHEN ece.event_type IN ('sent','failed') THEN ece.email_contact_id END) as processados,
    COUNT(DISTINCT CASE WHEN ece.event_type = 'queued' THEN ece.email_contact_id END) as total_queued
FROM email_campaigns ec
LEFT JOIN email_campaign_events ece ON ece.email_campaign_id = ec.id
WHERE ec.status = 'sending'
GROUP BY ec.id, ec.name, ec.status, ec.total_recipients;

-- 3. Jobs na fila para campanhas stuck
SELECT 
    'JOBS NA FILA' as info,
    j.id, j.queue, j.attempts,
    FROM_UNIXTIME(j.available_at) as disponivel_em,
    FROM_UNIXTIME(j.created_at) as criado_em,
    SUBSTRING(j.payload, 200, 300) as payload_trecho
FROM jobs j
WHERE j.queue = 'email'
ORDER BY j.available_at ASC
LIMIT 20;

-- 4. Jobs falhados recentes
SELECT 
    'JOBS FALHADOS' as info,
    id, queue, failed_at,
    SUBSTRING(exception, 1, 400) as erro
FROM failed_jobs
WHERE queue = 'email'
ORDER BY failed_at DESC
LIMIT 10;

-- 5. Logs de erro das campanhas stuck (últimas 24h)
SELECT 
    'LOGS ERRO' as info,
    sl.id, sl.action, sl.level,
    SUBSTRING(sl.message, 1, 200) as mensagem,
    SUBSTRING(sl.context, 1, 300) as contexto,
    sl.created_at
FROM system_logs sl
WHERE sl.channel = 'email'
  AND sl.level IN ('error', 'warning')
  AND sl.created_at >= NOW() - INTERVAL 24 HOUR
ORDER BY sl.id DESC
LIMIT 30;

-- 6. Último log de batch por campanha (ver quando parou)
SELECT 
    'ULTIMO BATCH POR CAMPANHA' as info,
    MAX(id) as ultimo_log_id,
    JSON_UNQUOTE(JSON_EXTRACT(context, '$.campaign_id')) as campaign_id,
    action,
    MAX(created_at) as ultimo_evento
FROM system_logs
WHERE channel = 'email'
  AND action LIKE 'batch%'
  AND created_at >= NOW() - INTERVAL 24 HOUR
GROUP BY JSON_UNQUOTE(JSON_EXTRACT(context, '$.campaign_id')), action
ORDER BY campaign_id, ultimo_evento DESC;