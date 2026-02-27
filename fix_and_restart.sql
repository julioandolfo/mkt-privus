-- ============================================================
-- CORRIGIR E REINICIAR CAMPANHA - MySQL 5.7 Compatible
-- ============================================================

-- PASSO 0: Descobrir a estrutura das tabelas de listas
-- Execute primeiro para ver o nome correto das tabelas:
-- SHOW TABLES LIKE '%list%';
-- SHOW TABLES LIKE '%campaign%';

-- ============================================================
-- PARTE 1: LIMPAR EVENTOS DUPLICADOS/PROBLEMATICOS
-- ============================================================

-- 1.1 Remover eventos 'failed' que já têm 'sent' para o mesmo contato
-- (manter apenas o 'sent')
DELETE failed FROM email_campaign_events failed
INNER JOIN email_campaign_events sent 
    ON failed.email_campaign_id = sent.email_campaign_id 
    AND failed.email_contact_id = sent.email_contact_id 
    AND sent.event_type = 'sent'
WHERE failed.event_type = 'failed';

-- 1.2 Remover 'sent' duplicados - manter apenas o mais recente (maior ID)
CREATE TEMPORARY TABLE keep_sent AS 
SELECT MAX(id) as keep_id, email_campaign_id, email_contact_id 
FROM email_campaign_events 
WHERE event_type='sent' 
GROUP BY email_campaign_id, email_contact_id;

DELETE ece FROM email_campaign_events ece 
LEFT JOIN keep_sent ks ON ece.id = ks.keep_id 
WHERE ece.event_type='sent' AND ks.keep_id IS NULL;

DROP TEMPORARY TABLE keep_sent;

-- 1.3 Remover 'failed' duplicados
CREATE TEMPORARY TABLE keep_failed AS 
SELECT MAX(id) as keep_id, email_campaign_id, email_contact_id 
FROM email_campaign_events 
WHERE event_type='failed' 
GROUP BY email_campaign_id, email_contact_id;

DELETE ece FROM email_campaign_events ece 
LEFT JOIN keep_failed ks ON ece.id = ks.keep_id 
WHERE ece.event_type='failed' AND ks.keep_id IS NULL;

DROP TEMPORARY TABLE keep_failed;

-- 1.4 Remover 'queued' duplicados
CREATE TEMPORARY TABLE keep_queued AS 
SELECT MAX(id) as keep_id, email_campaign_id, email_contact_id 
FROM email_campaign_events 
WHERE event_type='queued' 
GROUP BY email_campaign_id, email_contact_id;

DELETE ece FROM email_campaign_events ece 
LEFT JOIN keep_queued ks ON ece.id = ks.keep_id 
WHERE ece.event_type='queued' AND ks.keep_id IS NULL;

DROP TEMPORARY TABLE keep_queued;

-- ============================================================
-- PARTE 2: LIMPAR TODOS OS EVENTOS DA CAMPANHA #4 (RESET COMPLETO)
-- ============================================================
-- Descomente a linha abaixo para limpar todos os eventos da campanha 4:
-- DELETE FROM email_campaign_events WHERE email_campaign_id = 4;

-- ============================================================
-- PARTE 3: RESETAR STATUS DA CAMPANHA
-- ============================================================

-- Ver status atual
SELECT 'STATUS ANTES' as info, id, name, status, total_recipients, email_provider_id
FROM email_campaigns WHERE id = 4;

-- Resetar para draft (descomente para executar):
-- UPDATE email_campaigns 
-- SET status = 'draft', 
--     started_at = NULL, 
--     completed_at = NULL,
--     total_recipients = 0
-- WHERE id = 4;

-- OU resetar para 'sending' se quiser continuar (mas precisa de eventos):
-- UPDATE email_campaigns 
-- SET status = 'sending', 
--     completed_at = NULL
-- WHERE id = 4;

-- ============================================================
-- PARTE 4: RECRIAR EVENTOS 'QUEUED' (SE NECESSÁRIO)
-- ============================================================
-- 
-- PRIMEIRO, verifique como são as relações de lista:
-- SHOW TABLES LIKE '%list%';
-- 
-- Depois de confirmar as tabelas, use o SQL apropriado abaixo.
-- Opções comuns de nomes de tabelas:

-- OPÇÃO A: Se a tabela for email_list_contact (padrão Laravel):
/*
INSERT INTO email_campaign_events 
(email_campaign_id, email_contact_id, event_type, occurred_at, created_at, updated_at)
SELECT DISTINCT
    4 as email_campaign_id,
    lc.email_contact_id,
    'queued' as event_type,
    NOW() as occurred_at,
    NOW() as created_at,
    NOW() as updated_at
FROM email_list_contact lc
JOIN email_campaign_list cl ON cl.email_list_id = lc.email_list_id
JOIN email_contacts c ON c.id = lc.email_contact_id AND c.status = 'active'
WHERE cl.email_campaign_id = 4
AND cl.type = 'include'
ON DUPLICATE KEY UPDATE updated_at = NOW();
*/

-- OPÇÃO B: Se a estrutura for diferente, primeiro descubra com:
-- SHOW CREATE TABLE email_list_contact;
-- SHOW CREATE TABLE email_campaign_list;

-- ============================================================
-- PARTE 5: RESETAR CONTADOR DO PROVEDOR
-- ============================================================

UPDATE email_providers 
SET sends_this_hour = 0, 
    last_hour_reset_at = NOW()
WHERE hourly_limit IS NOT NULL;

-- ============================================================
-- PARTE 6: VERIFICAR RESULTADO
-- ============================================================

SELECT 'RESULTADO' as info,
    ece.event_type,
    COUNT(*) as total_eventos,
    COUNT(DISTINCT ece.email_contact_id) as contatos_distintos
FROM email_campaign_events ece
WHERE ece.email_campaign_id = 4
GROUP BY ece.event_type;

-- Status final da campanha
SELECT 'STATUS DEPOIS' as info, id, name, status, total_recipients
FROM email_campaigns WHERE id = 4;
