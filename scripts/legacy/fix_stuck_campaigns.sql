-- =====================================================
-- CORRIGIR CAMPANHAS TRAVADAS
-- Campanha 1: 99 pendentes (10 dias stuck)
-- Campanha 3: 194 pendentes (4 dias stuck)
-- =====================================================

-- ========================
-- CAMPANHA 3 (Cera Capilar)
-- 1936 enviados, 2 failed, 194 pendentes
-- Opção: completar (ignorar os 194 que nunca foram tentados)
-- ========================

-- Ver quem são os 194 pendentes
SELECT 
    'PENDENTES CAMPANHA 3' as info,
    COUNT(*) as total
FROM email_campaign_events e1
WHERE e1.email_campaign_id = 3
  AND e1.event_type = 'queued'
  AND NOT EXISTS (
      SELECT 1 FROM email_campaign_events e2
      WHERE e2.email_campaign_id = 3
        AND e2.email_contact_id = e1.email_contact_id
        AND e2.event_type IN ('sent', 'failed')
  );

-- Ver quem são os 99 pendentes da campanha 1
SELECT 
    'PENDENTES CAMPANHA 1' as info,
    COUNT(*) as total
FROM email_campaign_events e1
WHERE e1.email_campaign_id = 1
  AND e1.event_type = 'queued'
  AND NOT EXISTS (
      SELECT 1 FROM email_campaign_events e2
      WHERE e2.email_campaign_id = 1
        AND e2.email_contact_id = e1.email_contact_id
        AND e2.event_type IN ('sent', 'failed')
  );

-- =====================================================
-- OPÇÃO A: FORÇAR CONCLUSÃO (ignora pendentes)
-- Use se não quiser reenviar para os pendentes
-- =====================================================

-- UPDATE email_campaigns 
-- SET status = 'sent', completed_at = NOW(), updated_at = NOW()
-- WHERE id IN (1, 3);

-- =====================================================
-- OPÇÃO B: MARCAR PENDENTES COMO FAILED E CONCLUIR
-- Registra que não foram enviados e fecha a campanha
-- =====================================================

-- Marcar pendentes da campanha 1 como failed
INSERT INTO email_campaign_events (email_campaign_id, email_contact_id, event_type, occurred_at, metadata)
SELECT 
    1 as campaign_id,
    e1.email_contact_id,
    'failed' as etype,
    NOW() as ts,
    '{"error": "Contato pendente — campanha travada, nao foi possivel enviar"}' as meta
FROM email_campaign_events e1
WHERE e1.email_campaign_id = 1
  AND e1.event_type = 'queued'
  AND NOT EXISTS (
      SELECT 1 FROM email_campaign_events e2
      WHERE e2.email_campaign_id = 1
        AND e2.email_contact_id = e1.email_contact_id
        AND e2.event_type IN ('sent', 'failed')
  );

-- Marcar pendentes da campanha 3 como failed
INSERT INTO email_campaign_events (email_campaign_id, email_contact_id, event_type, occurred_at, metadata)
SELECT 
    3 as campaign_id,
    e1.email_contact_id,
    'failed' as etype,
    NOW() as ts,
    '{"error": "Contato pendente — campanha travada, nao foi possivel enviar"}' as meta
FROM email_campaign_events e1
WHERE e1.email_campaign_id = 3
  AND e1.event_type = 'queued'
  AND NOT EXISTS (
      SELECT 1 FROM email_campaign_events e2
      WHERE e2.email_campaign_id = 3
        AND e2.email_contact_id = e1.email_contact_id
        AND e2.event_type IN ('sent', 'failed')
  );

-- Concluir as campanhas
UPDATE email_campaigns 
SET status = 'sent', 
    completed_at = NOW(), 
    updated_at = NOW()
WHERE id IN (1, 3);

-- =====================================================
-- VERIFICAR RESULTADO
-- =====================================================
SELECT 
    id, name, status, total_recipients,
    (SELECT COUNT(DISTINCT email_contact_id) FROM email_campaign_events WHERE email_campaign_id = ec.id AND event_type = 'sent') as enviados,
    (SELECT COUNT(DISTINCT email_contact_id) FROM email_campaign_events WHERE email_campaign_id = ec.id AND event_type = 'failed') as falhas,
    completed_at
FROM email_campaigns ec
WHERE id IN (1, 3);