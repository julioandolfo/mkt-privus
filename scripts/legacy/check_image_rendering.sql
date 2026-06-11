-- ============================================================
-- VERIFICAR PROCESSAMENTO DE IMAGENS NO ENVIO
-- ============================================================

-- 1. Ver logs de renderização de imagens (últimos 30 min)
SELECT 
    'RENDERIZAÇÃO DE IMAGENS' as secao,
    sl.created_at,
    sl.action,
    sl.message,
    CASE 
        WHEN sl.context IS NOT NULL THEN 
            CONCAT(
                'processed: ', JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.processed')), ', ',
                'skipped: ', JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.skipped')), ', ',
                'failed: ', JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.failed'))
            )
        ELSE ''
    END as resumo
FROM system_logs sl
WHERE sl.action LIKE 'render.%'
   OR sl.action LIKE 'image.%'
AND sl.created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
ORDER BY sl.created_at DESC
LIMIT 30;

-- 2. Ver imagens que foram convertidas para base64
SELECT 
    'IMAGENS CONVERTIDAS PARA BASE64' as secao,
    sl.created_at,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.path')) as caminho,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.mime_type')) as formato,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.size')) as tamanho
FROM system_logs sl
WHERE sl.action = 'render.base64_created'
AND sl.created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
ORDER BY sl.created_at DESC;

-- 3. Ver falhas na conversão
SELECT 
    'FALHAS NA CONVERSÃO' as secao,
    sl.created_at,
    sl.action,
    sl.message,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.path')) as caminho
FROM system_logs sl
WHERE (sl.action = 'render.storage_not_found' OR sl.action = 'render.base64_failed')
AND sl.created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
ORDER BY sl.created_at DESC;

-- 4. Verificar se há envios recentes e seu status
SELECT 
    'ENVIOS RECENTES' as secao,
    sl.created_at,
    sl.action,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.campaign_id')) as campanha,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.contact_email')) as email,
    sl.message
FROM system_logs sl
WHERE sl.channel = 'email'
AND (sl.action LIKE '%send%' OR sl.action LIKE '%batch%')
AND sl.created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
ORDER BY sl.created_at DESC
LIMIT 20;
