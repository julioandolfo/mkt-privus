-- ============================================================
-- VERIFICAR STATUS DE ENTREGAS E WEBHOOKS
-- Corrigido para colunas reais
-- ============================================================

-- 1. COMPARATIVO SENT vs DELIVERED (webhook)
SELECT 
    'ENTREGAS (SENT vs DELIVERED)' as secao,
    (SELECT COUNT(DISTINCT email_contact_id) 
     FROM email_campaign_events 
     WHERE email_campaign_id = 1 AND event_type = 'sent') as enviados,
    (SELECT COUNT(DISTINCT email_contact_id) 
     FROM email_campaign_events 
     WHERE email_campaign_id = 1 AND event_type = 'delivered') as entregues_webhook,
    (SELECT COUNT(DISTINCT email_contact_id) 
     FROM email_campaign_events 
     WHERE email_campaign_id = 1 AND event_type = 'bounced') as bounced,
    (SELECT COUNT(DISTINCT email_contact_id) 
     FROM email_campaign_events 
     WHERE email_campaign_id = 1 AND event_type = 'opened') as aberturas;

-- 2. WEBHOCKS RECEBIDOS RECENTEMENTE
SELECT 
    'WEBHOCKS RECEBIDOS' as secao,
    DATE(occurred_at) as data,
    HOUR(occurred_at) as hora,
    event_type,
    COUNT(*) as quantidade
FROM email_campaign_events
WHERE email_campaign_id = 1
AND event_type IN ('delivered', 'bounced', 'opened', 'clicked')
AND occurred_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY DATE(occurred_at), HOUR(occurred_at), event_type
ORDER BY data DESC, hora DESC;

-- 3. TEMPO MÉDIO ENTRE SENT E DELIVERED
SELECT 
    'TEMPO SENT -> DELIVERED' as secao,
    AVG(TIMESTAMPDIFF(MINUTE, sent.occurred_at, delivered.occurred_at)) as minutos_medios
FROM email_campaign_events sent
INNER JOIN email_campaign_events delivered 
    ON sent.email_campaign_id = delivered.email_campaign_id
    AND sent.email_contact_id = delivered.email_contact_id
    AND delivered.event_type = 'delivered'
WHERE sent.event_type = 'sent'
AND sent.email_campaign_id = 1;

-- 4. SENT SEM DELIVERED (aguardando webhook ou falhou)
SELECT 
    'SENT SEM CONFIRMACAO' as alerta,
    COUNT(DISTINCT sent.email_contact_id) as quantidade
FROM email_campaign_events sent
LEFT JOIN email_campaign_events delivered 
    ON sent.email_campaign_id = delivered.email_campaign_id
    AND sent.email_contact_id = delivered.email_contact_id
    AND delivered.event_type = 'delivered'
WHERE sent.event_type = 'sent'
AND sent.email_campaign_id = 1
AND delivered.id IS NULL;

-- 5. LOGS DE WEBHOOK NO SISTEMA - usando colunas corretas
SELECT 
    'WEBHOCK LOGS' as secao,
    sl.created_at,
    sl.action,
    sl.message,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.email')) as email,
    JSON_UNQUOTE(JSON_EXTRACT(sl.context, '$.event')) as webhook_event_type
FROM system_logs sl
WHERE sl.channel LIKE '%webhook%'
   OR (sl.action LIKE '%webhook%' AND sl.message LIKE '%SendPulse%')
ORDER BY sl.created_at DESC
LIMIT 20;

-- 6. RESUMO FINAL
SELECT 
    'RESUMO CAMPANHA #1' as info,
    c.total_sent as total_enviados_sistema,
    c.total_delivered as total_entregues_webhook,
    c.total_bounced as total_bounced,
    c.total_opened as total_aberturas,
    CASE 
        WHEN c.total_delivered = 0 AND c.total_sent > 0 THEN '⚠️ WEBHOCKS NAO CONFIGURADOS ou URL INACESSIVEL'
        WHEN c.total_delivered > 0 THEN '✅ Webhooks funcionando'
        ELSE 'N/A'
    END as status_webhooks
FROM email_campaigns c
WHERE c.id = 1;
