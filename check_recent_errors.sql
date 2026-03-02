-- ============================================================
-- VERIFICAR ERROS RECENTES NO SISTEMA
-- ============================================================

-- 1. ÚLTIMOS ERROS (últimos 30 minutos)
SELECT 
    'ERROS RECENTES' as secao,
    sl.created_at,
    sl.channel,
    sl.action,
    sl.level,
    sl.message,
    sl.context
FROM system_logs sl
WHERE sl.level = 'error'
   OR sl.action LIKE '%error%'
   OR sl.action LIKE '%exception%'
ORDER BY sl.created_at DESC
LIMIT 20;

-- 2. LOGS DE CAMPANHA (últimos 30 minutos)
SELECT 
    'LOGS DE CAMPANHA' as secao,
    sl.created_at,
    sl.action,
    sl.message,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.campaign_id')) as campaign_id,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.error')) as erro_detalhe
FROM system_logs sl
WHERE sl.channel = 'email'
AND sl.created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
ORDER BY sl.created_at DESC
LIMIT 20;

-- 3. TODOS OS LOGS RECENTES (últimos 15 minutos)
SELECT 
    sl.created_at,
    sl.channel,
    sl.action,
    sl.level,
    LEFT(sl.message, 100) as mensagem
FROM system_logs sl
WHERE sl.created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
ORDER BY sl.created_at DESC
LIMIT 50;
