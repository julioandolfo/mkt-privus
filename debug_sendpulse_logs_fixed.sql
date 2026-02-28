-- ============================================================
-- DEBUG DOS LOGS DO SENDPULSE
-- Corrigido para colunas reais do system_logs
-- ============================================================

-- 1. LOGS DE ENVIO PARA CONTATOS ESPECÍFICOS QUE DERAM FAILED
-- Usando 'action' ao invés de 'event'
SELECT 
    sl.created_at,
    sl.action,
    sl.message,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.contact_email')) as email,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.result_error')) as erro_sendpulse,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.status_code')) as http_status,
    sl.context
FROM system_logs sl
WHERE sl.channel = 'email'
AND sl.action IN ('batch.send_result', 'batch.send_exception', 'provider.sendpulse.error')
AND sl.created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
AND (
    sl.context LIKE '%failed%' 
    OR sl.context LIKE '%error%'
    OR sl.context LIKE '%Unauthorized%'
)
ORDER BY sl.created_at DESC
LIMIT 30;

-- 2. LOGS DE SUCESSO (para comparar)
SELECT 
    'SUCESSOS' as tipo,
    COUNT(*) as quantidade,
    MIN(sl.created_at) as primeiro,
    MAX(sl.created_at) as ultimo
FROM system_logs sl
WHERE sl.channel = 'email'
AND sl.action = 'batch.send_result'
AND sl.context LIKE '%"result_success":true%'
AND sl.created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR);

-- 3. LOGS DE FALHA
SELECT 
    'FALHAS' as tipo,
    COUNT(*) as quantidade,
    MIN(sl.created_at) as primeiro,
    MAX(sl.created_at) as ultimo
FROM system_logs sl
WHERE sl.channel = 'email'
AND (
    sl.action = 'batch.send_result' AND sl.context LIKE '%"result_success":false%'
    OR sl.action = 'batch.send_exception'
)
AND sl.created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR);

-- 4. ERROS ESPECÍFICOS DO SENDPULSE
SELECT 
    'ERROS SENDPULSE' as secao,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.result_error')) as erro,
    COUNT(*) as quantidade
FROM system_logs sl
WHERE sl.channel = 'email'
AND sl.action = 'batch.send_result'
AND sl.context LIKE '%"result_success":false%'
AND sl.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY erro
ORDER BY quantidade DESC;

-- 5. VERIFICAR SE HÁ RESPOSTAS DA API DO SENDPULSE
SELECT 
    'RESPOSTAS API' as info,
    sl.created_at,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.response_status')) as status,
    LEFT(JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.response_body')), 100) as resposta
FROM system_logs sl
WHERE sl.channel = 'email'
AND sl.action LIKE '%sendpulse%'
AND sl.created_at >= DATE_SUB(NOW(), INTERVAL 3 HOUR)
ORDER BY sl.created_at DESC
LIMIT 10;
