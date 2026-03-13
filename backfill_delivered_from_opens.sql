-- ============================================================
-- Backfill de eventos "delivered" para campanhas existentes
-- Premissa: se o contato abriu ou clicou, o email foi entregue.
-- Execute APENAS UMA VEZ para corrigir campanhas passadas.
-- ============================================================

-- Preview: quantos serão inseridos por campanha
SELECT
    e.email_campaign_id,
    COUNT(DISTINCT e.email_contact_id) AS abrindo_sem_delivered
FROM email_campaign_events e
WHERE e.event_type IN ('opened', 'clicked')
  AND NOT EXISTS (
      SELECT 1
      FROM email_campaign_events d
      WHERE d.email_campaign_id = e.email_campaign_id
        AND d.email_contact_id  = e.email_contact_id
        AND d.event_type = 'delivered'
  )
GROUP BY e.email_campaign_id;

-- Inserir eventos "delivered" faltantes (baseado em aberturas/cliques)
INSERT INTO email_campaign_events (email_campaign_id, email_contact_id, event_type, occurred_at, metadata, created_at, updated_at)
SELECT
    e.email_campaign_id,
    e.email_contact_id,
    'delivered'                                        AS event_type,
    MIN(e.occurred_at)                                 AS occurred_at,
    '{"source":"inferred_from_engagement_backfill"}'   AS metadata,
    NOW()                                              AS created_at,
    NOW()                                              AS updated_at
FROM email_campaign_events e
WHERE e.event_type IN ('opened', 'clicked')
  AND NOT EXISTS (
      SELECT 1
      FROM email_campaign_events d
      WHERE d.email_campaign_id = e.email_campaign_id
        AND d.email_contact_id  = e.email_contact_id
        AND d.event_type = 'delivered'
  )
GROUP BY e.email_campaign_id, e.email_contact_id;

-- Atualizar o total_delivered na tabela de campanhas
UPDATE email_campaigns c
SET total_delivered = (
    SELECT COUNT(DISTINCT email_contact_id)
    FROM email_campaign_events
    WHERE email_campaign_id = c.id
      AND event_type = 'delivered'
);

-- Confirmar resultado
SELECT
    id,
    subject,
    total_sent,
    total_delivered,
    total_opened,
    total_clicked
FROM email_campaigns
WHERE total_sent > 0
ORDER BY id;
