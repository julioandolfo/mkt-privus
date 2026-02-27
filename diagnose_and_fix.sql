-- ============================================================
-- DIAGNÓSTICO E CORREÇÃO DE EVENTOS DUPLICADOS
-- Execute estas queries no MySQL/MariaDB
-- ============================================================

-- --------------------------------------------------------
-- 1. DIAGNÓSTICO - Verificar eventos duplicados por campanha
-- --------------------------------------------------------
SELECT 
    ece.email_campaign_id,
    ec.name as campaign_name,
    ece.event_type,
    COUNT(*) as total_events,
    COUNT(DISTINCT ece.email_contact_id) as distinct_contacts,
    (COUNT(*) - COUNT(DISTINCT ece.email_contact_id)) as duplicates
FROM email_campaign_events ece
JOIN email_campaigns ec ON ec.id = ece.email_campaign_id
GROUP BY ece.email_campaign_id, ece.event_type
HAVING COUNT(*) > COUNT(DISTINCT ece.email_contact_id)
ORDER BY duplicates DESC;

-- --------------------------------------------------------
-- 2. DIAGNÓSTICO - Verificar se há 'sent' e 'failed' para mesmo contato
-- (prioridade: sent, remover failed)
-- --------------------------------------------------------
SELECT 
    ece1.email_campaign_id,
    COUNT(*) as failed_with_existing_sent
FROM email_campaign_events ece1
INNER JOIN email_campaign_events ece2 
    ON ece1.email_campaign_id = ece2.email_campaign_id
    AND ece1.email_contact_id = ece2.email_contact_id
    AND ece2.event_type = 'sent'
WHERE ece1.event_type = 'failed'
GROUP BY ece1.email_campaign_id;

-- --------------------------------------------------------
-- 3. CORREÇÃO - Remover eventos 'failed' que já têm 'sent' para o mesmo contato
-- (manter apenas 'sent', pois ele tem prioridade)
-- --------------------------------------------------------
-- PRIMEIRO, verifique quantos serão afetados (modo preview):
SELECT COUNT(*) as will_be_deleted
FROM email_campaign_events failed
INNER JOIN email_campaign_events sent
    ON failed.email_campaign_id = sent.email_campaign_id
    AND failed.email_contact_id = sent.email_contact_id
    AND sent.event_type = 'sent'
WHERE failed.event_type = 'failed';

-- DEPOIS, execute o DELETE (descomente quando quiser executar):
-- DELETE failed FROM email_campaign_events failed
-- INNER JOIN email_campaign_events sent
--     ON failed.email_campaign_id = sent.email_campaign_id
--     AND failed.email_contact_id = sent.email_contact_id
--     AND sent.event_type = 'sent'
-- WHERE failed.event_type = 'failed';

-- --------------------------------------------------------
-- 4. CORREÇÃO - Remover eventos 'sent' duplicados (manter apenas o mais recente)
-- --------------------------------------------------------
-- Criar tabela temporária com os IDs a manter (mais recente de cada contato)
CREATE TEMPORARY TABLE IF NOT EXISTS keep_sent AS
SELECT MAX(id) as keep_id, email_campaign_id, email_contact_id
FROM email_campaign_events
WHERE event_type = 'sent'
GROUP BY email_campaign_id, email_contact_id;

-- Verificar quantos 'sent' duplicados serão removidos:
SELECT COUNT(*) as sent_duplicates_to_delete
FROM email_campaign_events ece
LEFT JOIN keep_sent ks 
    ON ece.id = ks.keep_id
WHERE ece.event_type = 'sent'
AND ks.keep_id IS NULL;

-- Remover 'sent' duplicados (descomente quando quiser executar):
-- DELETE ece FROM email_campaign_events ece
-- LEFT JOIN keep_sent ks 
--     ON ece.id = ks.keep_id
-- WHERE ece.event_type = 'sent'
-- AND ks.keep_id IS NULL;

-- Limpar tabela temporária
DROP TEMPORARY TABLE IF EXISTS keep_sent;

-- --------------------------------------------------------
-- 5. CORREÇÃO - Remover eventos 'failed' duplicados (manter apenas o mais recente)
-- --------------------------------------------------------
CREATE TEMPORARY TABLE IF NOT EXISTS keep_failed AS
SELECT MAX(id) as keep_id, email_campaign_id, email_contact_id
FROM email_campaign_events
WHERE event_type = 'failed'
GROUP BY email_campaign_id, email_contact_id;

-- Verificar quantos 'failed' duplicados serão removidos:
SELECT COUNT(*) as failed_duplicates_to_delete
FROM email_campaign_events ece
LEFT JOIN keep_failed ks 
    ON ece.id = ks.keep_id
WHERE ece.event_type = 'failed'
AND ks.keep_id IS NULL;

-- Remover 'failed' duplicados (descomente quando quiser executar):
-- DELETE ece FROM email_campaign_events ece
-- LEFT JOIN keep_failed ks 
--     ON ece.id = ks.keep_id
-- WHERE ece.event_type = 'failed'
-- AND ks.keep_id IS NULL;

-- Limpar tabela temporária
DROP TEMPORARY TABLE IF EXISTS keep_failed;

-- --------------------------------------------------------
-- 6. VERIFICAÇÃO FINAL - Contagem após limpeza
-- --------------------------------------------------------
SELECT 
    ece.email_campaign_id,
    ec.name as campaign_name,
    ece.event_type,
    COUNT(*) as total_events,
    COUNT(DISTINCT ece.email_contact_id) as distinct_contacts
FROM email_campaign_events ece
JOIN email_campaigns ec ON ec.id = ece.email_campaign_id
WHERE ece.email_campaign_id = 4  -- específico para campanha #4
GROUP BY ece.email_campaign_id, ece.event_type;

-- --------------------------------------------------------
-- 7. FORÇAR STATUS DA CAMPANHA PARA 'sending' SE NECESSÁRIO
-- --------------------------------------------------------
-- Se a campanha está travada com status errado, descomente e execute:
-- UPDATE email_campaigns 
-- SET status = 'sending', 
--     completed_at = NULL
-- WHERE id = 4 
-- AND status IN ('draft', 'scheduled', 'paused')
-- AND EXISTS (SELECT 1 FROM email_campaign_events WHERE email_campaign_id = 4 AND event_type = 'queued');

-- --------------------------------------------------------
-- 8. FINALIZAR CAMPANHA SE TODOS JÁ FORAM ENVIADOS
-- --------------------------------------------------------
-- Se todos já foram enviados mas status não é 'sent':
-- UPDATE email_campaigns ec
-- SET status = 'sent',
--     completed_at = NOW()
-- WHERE id = 4
-- AND status = 'sending'
-- AND (
--     SELECT COUNT(DISTINCT email_contact_id) 
--     FROM email_campaign_events 
--     WHERE email_campaign_id = 4 AND event_type = 'sent'
-- ) >= (
--     SELECT COUNT(DISTINCT email_contact_id) 
--     FROM email_campaign_events 
--     WHERE email_campaign_id = 4 AND event_type = 'queued'
-- );

-- --------------------------------------------------------
-- 9. LIMPAR FILA DE JOBS (se necessário recomeçar)
-- --------------------------------------------------------
-- Para ver jobs na fila:
-- SELECT * FROM jobs WHERE queue = 'email' ORDER BY available_at;

-- Para remover todos os jobs de email da fila (CUIDADO!):
-- DELETE FROM jobs WHERE queue = 'email';

-- Para ver jobs falhados:
-- SELECT * FROM failed_jobs WHERE queue = 'email' ORDER BY failed_at DESC;

-- Para limpar jobs falhados antigos:
-- DELETE FROM failed_jobs WHERE queue = 'email' AND failed_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
