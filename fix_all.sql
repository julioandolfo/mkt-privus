-- ============================================================
-- FIX ALL - Corrige todos os problemas de eventos duplicados
-- Execute tudo de uma vez no MySQL
-- ============================================================

-- 1. Remover 'failed' duplicados (manter apenas o mais recente)
CREATE TEMPORARY TABLE keep_failed AS 
SELECT MAX(id) as keep_id, email_campaign_id, email_contact_id 
FROM email_campaign_events 
WHERE event_type='failed' 
GROUP BY email_campaign_id, email_contact_id;

DELETE ece FROM email_campaign_events ece 
LEFT JOIN keep_failed ks ON ece.id = ks.keep_id 
WHERE ece.event_type='failed' AND ks.keep_id IS NULL;

DROP TEMPORARY TABLE keep_failed;

-- 2. Remover 'sent' duplicados (manter apenas o mais recente)
CREATE TEMPORARY TABLE keep_sent AS 
SELECT MAX(id) as keep_id, email_campaign_id, email_contact_id 
FROM email_campaign_events 
WHERE event_type='sent' 
GROUP BY email_campaign_id, email_contact_id;

DELETE ece FROM email_campaign_events ece 
LEFT JOIN keep_sent ks ON ece.id = ks.keep_id 
WHERE ece.event_type='sent' AND ks.keep_id IS NULL;

DROP TEMPORARY TABLE keep_sent;

-- 3. Remover 'failed' que já têm 'sent' (sent tem prioridade)
DELETE failed FROM email_campaign_events failed
INNER JOIN email_campaign_events sent 
    ON failed.email_campaign_id = sent.email_campaign_id 
    AND failed.email_contact_id = sent.email_contact_id 
    AND sent.event_type = 'sent'
WHERE failed.event_type = 'failed';

-- 4. Remover 'queued' duplicados se houver
CREATE TEMPORARY TABLE keep_queued AS 
SELECT MAX(id) as keep_id, email_campaign_id, email_contact_id 
FROM email_campaign_events 
WHERE event_type='queued' 
GROUP BY email_campaign_id, email_contact_id;

DELETE ece FROM email_campaign_events ece 
LEFT JOIN keep_queued ks ON ece.id = ks.keep_id 
WHERE ece.event_type='queued' AND ks.keep_id IS NULL;

DROP TEMPORARY TABLE keep_queued;

-- 5. Resetar o contador hourly do provedor se necessário (caso esteja bloqueado)
UPDATE email_providers 
SET sends_this_hour = 0, last_hour_reset_at = NOW()
WHERE hourly_limit IS NOT NULL 
AND (last_hour_reset_at IS NULL OR last_hour_reset_at < DATE_SUB(NOW(), INTERVAL 1 HOUR));

-- 6. Mostrar resultado da limpeza para campanha #4
SELECT 
    'RESULTADO DA LIMPEZA' as info,
    ece.event_type,
    COUNT(*) as total_eventos,
    COUNT(DISTINCT ece.email_contact_id) as contatos_distintos
FROM email_campaign_events ece
WHERE ece.email_campaign_id = 4
GROUP BY ece.event_type;

-- 7. Verificar status da campanha #4
SELECT 
    ec.id,
    ec.name,
    ec.status,
    ec.total_recipients,
    (
        SELECT COUNT(DISTINCT email_contact_id) 
        FROM email_campaign_events 
        WHERE email_campaign_id = 4 AND event_type = 'sent'
    ) as actually_sent,
    (
        SELECT COUNT(DISTINCT email_contact_id) 
        FROM email_campaign_events 
        WHERE email_campaign_id = 4 AND event_type = 'queued'
    ) as queued,
    ep.hourly_limit,
    ep.sends_this_hour
FROM email_campaigns ec
LEFT JOIN email_providers ep ON ec.email_provider_id = ep.id
WHERE ec.id = 4;
