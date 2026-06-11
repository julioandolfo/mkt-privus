-- =====================================================
-- RECUPERAR CAMPANHA 2
-- Todos os 1966 falharam com "Internal server error"
-- Precisamos limpar os eventos 'failed' e deixar como 'queued'
-- para que o sistema reprocesse
-- =====================================================

-- 1. Verificar estado atual
SELECT 
    'ANTES' as fase,
    event_type, COUNT(*) as total 
FROM email_campaign_events 
WHERE email_campaign_id = 2 
GROUP BY event_type;

-- 2. Deletar eventos 'failed' que foram erro temporário do SendPulse
-- (mantém os 'queued' originais para reprocessamento)
DELETE FROM email_campaign_events 
WHERE email_campaign_id = 2 
  AND event_type = 'failed';

-- 3. Limpar jobs antigos desta campanha na fila (vão ser recriados)
DELETE FROM jobs 
WHERE queue = 'email'
  AND payload LIKE '%SendCampaignBatchJob%'
  AND payload LIKE '%"campaignId":2%';

-- 4. Resetar status da campanha para draft (permite reenviar)
UPDATE email_campaigns 
SET status = 'draft',
    total_sent = 0,
    total_delivered = 0,
    total_bounced = 0,
    total_opened = 0,
    total_clicked = 0,
    started_at = NULL,
    completed_at = NULL,
    updated_at = NOW()
WHERE id = 2;

-- 5. Deletar os eventos 'queued' também (serão recriados ao enviar)
DELETE FROM email_campaign_events 
WHERE email_campaign_id = 2;

-- 6. Verificar estado final
SELECT 
    'DEPOIS' as fase,
    id, name, status, total_recipients, total_sent 
FROM email_campaigns 
WHERE id = 2;

SELECT 
    'EVENTOS RESTANTES' as fase,
    event_type, COUNT(*) as total 
FROM email_campaign_events 
WHERE email_campaign_id = 2 
GROUP BY event_type;