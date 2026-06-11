-- ============================================================
-- DEBUG DETALHADO DOS EVENTOS FAILED
-- Corrigido para colunas reais do system_logs
-- ============================================================

-- 1. ÚLTIMOS FAILED COM DETALHES COMPLETOS
SELECT 
    'FAILED RECENTES' as secao,
    ece.id as event_id,
    ece.email_campaign_id,
    ece.event_type,
    ec.email as contato_email,
    ec.first_name,
    ece.occurred_at,
    -- Extrair erro do metadata JSON
    CASE 
        WHEN ece.metadata LIKE '%error%' THEN 
            JSON_UNQUOTE(JSON_EXTRACT(ece.metadata, '$.error'))
        WHEN ece.metadata LIKE '%reason%' THEN 
            JSON_UNQUOTE(JSON_EXTRACT(ece.metadata, '$.reason'))
        ELSE ece.metadata
    END as erro_detalhe,
    ece.metadata as metadata_completo
FROM email_campaign_events ece
LEFT JOIN email_contacts ec ON ec.id = ece.email_contact_id
WHERE ece.email_campaign_id = 1
AND ece.event_type = 'failed'
ORDER BY ece.occurred_at DESC
LIMIT 30;

-- 2. FAILED AGRUPADOS POR MOTIVO (TOP 10)
SELECT 
    'FAILED POR MOTIVO' as secao,
    CASE 
        WHEN ece.metadata LIKE '%quota%' THEN '❌ Quota excedida'
        WHEN ece.metadata LIKE '%Unauthorized%' THEN '❌ Não autorizado (email remetente inválido)'
        WHEN ece.metadata LIKE '%message_id%' THEN '⚠️ Sem message_id (pode ter sido enviado)'
        WHEN ece.metadata LIKE '%SMTP%' THEN '❌ Erro SMTP'
        WHEN ece.metadata LIKE '%timeout%' THEN '⏱️ Timeout'
        WHEN ece.metadata LIKE '%connection%' THEN '🔌 Erro de conexão'
        WHEN ece.metadata LIKE '%not be accessed%' THEN '🔧 Erro de propriedade não inicializada (Job)'
        WHEN ece.metadata IS NULL OR ece.metadata = '' OR ece.metadata = '{}' THEN '❓ Sem detalhe de erro'
        ELSE CONCAT('📋 Outro: ', LEFT(ece.metadata, 50))
    END as motivo,
    COUNT(*) as quantidade,
    MIN(ece.occurred_at) as primeira_ocorrencia,
    MAX(ece.occurred_at) as ultima_ocorrencia
FROM email_campaign_events ece
WHERE ece.email_campaign_id = 1
AND ece.event_type = 'failed'
GROUP BY motivo
ORDER BY quantidade DESC
LIMIT 10;

-- 3. COMPARAR: FAILED vs NÃO APARECE NO SENDPULSE
SELECT 
    'ANALISE DE FALHAS' as secao,
    SUM(CASE 
        WHEN ece.metadata IS NULL 
          OR ece.metadata = '' 
          OR ece.metadata = '{}'
          OR ece.metadata LIKE '%connection%'
          OR ece.metadata LIKE '%timeout%'
          OR ece.metadata LIKE '%SMTP%'
          OR ece.metadata LIKE '%not be accessed%'
        THEN 1 
        ELSE 0 
    END) as provavel_nao_chegou_sendpulse,
    SUM(CASE 
        WHEN ece.metadata LIKE '%quota%' 
          OR ece.metadata LIKE '%Unauthorized%'
          OR ece.metadata LIKE '%message_id%'
        THEN 1 
        ELSE 0 
    END) as chegou_mas_falhou_no_sendpulse,
    COUNT(*) as total_failed
FROM email_campaign_events ece
WHERE ece.email_campaign_id = 1
AND ece.event_type = 'failed';

-- 4. LOGS DO SISTEMA PARA CONTATOS ESPECÍFICOS QUE DERAM FAILED
-- Usando colunas corretas: action e context
SELECT 
    'LOGS DE ENVIO' as secao,
    sl.created_at,
    sl.action,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.contact_email')) as email,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.result_success')) as sucesso,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.result_error')) as erro_api,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.response_status')) as http_status
FROM system_logs sl
WHERE sl.channel = 'email'
AND sl.action IN ('batch.send_result', 'batch.send_exception')
AND sl.created_at >= DATE_SUB(NOW(), INTERVAL 3 HOUR)
AND (
    sl.context LIKE '%result_success":false%'
    OR sl.context LIKE '%exception%'
    OR sl.context LIKE '%error%'
)
ORDER BY sl.created_at DESC
LIMIT 20;

-- 5. VERIFICAR SE HÁ EMAILS COM SENT E FAILED (CONFLITO)
SELECT 
    'CONFLITO SENT + FAILED' as alerta,
    ece1.email_contact_id,
    ec.email,
    COUNT(DISTINCT CASE WHEN ece1.event_type = 'sent' THEN ece1.id END) as vezes_sent,
    COUNT(DISTINCT CASE WHEN ece1.event_type = 'failed' THEN ece1.id END) as vezes_failed
FROM email_campaign_events ece1
LEFT JOIN email_contacts ec ON ec.id = ece1.email_contact_id
WHERE ece1.email_campaign_id = 1
AND ece1.event_type IN ('sent', 'failed')
GROUP BY ece1.email_contact_id, ec.email
HAVING vezes_sent > 0 AND vezes_failed > 0
ORDER BY vezes_failed DESC
LIMIT 10;

-- 6. RESUMO EXECUTIVO
SELECT 
    'RESUMO EXECUTIVO' as info,
    (SELECT COUNT(DISTINCT email_contact_id) 
     FROM email_campaign_events WHERE email_campaign_id = 1 AND event_type = 'sent') as enviados_ok,
    (SELECT COUNT(DISTINCT email_contact_id) 
     FROM email_campaign_events WHERE email_campaign_id = 1 AND event_type = 'failed') as falhas,
    (SELECT COUNT(DISTINCT email_contact_id) 
     FROM email_campaign_events WHERE email_campaign_id = 1 AND event_type = 'delivered') as entregues,
    CONCAT(
        ROUND(
            (SELECT COUNT(DISTINCT email_contact_id) FROM email_campaign_events WHERE email_campaign_id = 1 AND event_type = 'sent') / 
            (SELECT COUNT(DISTINCT email_contact_id) FROM email_campaign_events WHERE email_campaign_id = 1 AND event_type = 'queued') * 100,
            1
        ), '%'
    ) as taxa_sucesso;
