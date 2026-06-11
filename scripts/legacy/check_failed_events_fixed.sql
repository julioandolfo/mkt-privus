-- ============================================================
-- DIAGNÓSTICO DE EVENTOS FAILED E ENTREGUES
-- Corrigido para colunas reais do system_logs
-- ============================================================

-- 1. ÚLTIMOS EVENTOS FAILED COM DETALHES
SELECT 
    'FAILED RECENTES' as secao,
    ece.id,
    ece.event_type,
    ec.email as contato,
    ece.metadata as erro_json,
    ece.occurred_at
FROM email_campaign_events ece
LEFT JOIN email_contacts ec ON ec.id = ece.email_contact_id
WHERE ece.email_campaign_id = 1
AND ece.event_type = 'failed'
ORDER BY ece.occurred_at DESC
LIMIT 20;

-- 2. CONTAGEM DE FAILED COM MOTIVO (baseado no metadata JSON)
SELECT 
    'FAILED POR MOTIVO' as secao,
    CASE 
        WHEN ece.metadata LIKE '%quota%' THEN 'Quota excedida'
        WHEN ece.metadata LIKE '%Unauthorized%' THEN 'Não autorizado (email remetente)'
        WHEN ece.metadata LIKE '%message_id%' THEN 'Enviado mas sem confirmação'
        WHEN ece.metadata LIKE '%SMTP%' THEN 'Erro SMTP'
        WHEN ece.metadata LIKE '%timeout%' THEN 'Timeout'
        WHEN ece.metadata LIKE '%connection%' THEN 'Erro de conexão'
        WHEN ece.metadata IS NULL OR ece.metadata = '' OR ece.metadata = '{}' THEN 'Sem detalhe'
        ELSE 'Outro'
    END as motivo,
    COUNT(*) as quantidade
FROM email_campaign_events ece
WHERE ece.email_campaign_id = 1
AND ece.event_type = 'failed'
GROUP BY motivo
ORDER BY quantidade DESC;

-- 3. EVENTOS SENT SEM MESSAGE_ID (pode indicar problema)
SELECT 
    'SENT SEM MESSAGE_ID' as alerta,
    COUNT(*) as quantidade
FROM email_campaign_events
WHERE email_campaign_id = 1
AND event_type = 'sent'
AND (metadata IS NULL OR metadata = '{}' OR metadata NOT LIKE '%message_id%');

-- 4. COMPARATIVO: SENT vs DELIVERED (webhook)
SELECT 
    'COMPARATIVO' as secao,
    (SELECT COUNT(*) FROM email_campaign_events 
     WHERE email_campaign_id = 1 AND event_type = 'sent') as enviados,
    (SELECT COUNT(*) FROM email_campaign_events 
     WHERE email_campaign_id = 1 AND event_type = 'delivered') as entregues_webhook,
    (SELECT COUNT(*) FROM email_campaign_events 
     WHERE email_campaign_id = 1 AND event_type = 'failed') as falhos;

-- 5. LOGS DO SISTEMA - usando colunas corretas (action ao invés de event)
SELECT 
    'LOGS RECENTES' as secao,
    sl.created_at,
    sl.action,
    sl.message,
    sl.context
FROM system_logs sl
WHERE sl.channel = 'email'
AND (sl.action LIKE '%error%' OR sl.action LIKE '%exception%' OR sl.action LIKE '%failed%')
AND sl.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY sl.created_at DESC
LIMIT 20;

-- 6. VERIFICAR SE HÁ WEBHOOKS CONFIGURADOS
SELECT 
    'WEBHOOK DELIVERED' as info,
    COUNT(*) as total_delivered_events
FROM email_campaign_events
WHERE email_campaign_id = 1
AND event_type = 'delivered';

-- 7. RESUMO FINAL
SELECT 
    'RESUMO CAMPANHA #1' as info,
    c.name,
    c.status,
    c.total_recipients,
    c.total_sent,
    c.total_delivered,
    c.total_opened,
    (c.total_sent - c.total_delivered) as nao_confirmados
FROM email_campaigns c
WHERE c.id = 1;
