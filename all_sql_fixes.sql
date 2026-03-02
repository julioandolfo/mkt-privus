-- ============================================================
-- TODAS AS CORREÇÕES SQL EM UM ARQUIVO
-- Execute cada seção conforme necessidade
-- ============================================================

-- ============================================================
-- 1. ADICIONAR COLUNA source_url NA TABELA email_assets
-- ============================================================

-- Verificar se coluna existe
SELECT 
    '1. VERIFICAÇÃO' as info,
    CASE 
        WHEN EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_NAME = 'email_assets' 
            AND COLUMN_NAME = 'source_url'
        ) THEN '✅ Coluna já existe - pule esta etapa'
        ELSE '❌ Coluna não existe - execute o ALTER TABLE abaixo'
    END as status;

-- Adicionar coluna (descomente e execute se necessário):
-- ALTER TABLE email_assets ADD COLUMN source_url TEXT NULL AFTER alt_text;

-- ============================================================
-- 2. CORRIGIR FALHAS POR QUOTA (reprocessar emails)
-- ============================================================

-- Ver falhas por quota
SELECT 
    '2. FALHAS POR QUOTA' as info,
    COUNT(*) as quantidade
FROM email_campaign_events
WHERE email_campaign_id = 1
AND event_type = 'failed'
AND (
    metadata LIKE '%limite diário%' OR metadata LIKE '%limite diario%'
    OR metadata LIKE '%daily limit%' OR metadata LIKE '%quota%'
);

-- Remover falhas por quota (para reprocessar):
-- DELETE FROM email_campaign_events
-- WHERE email_campaign_id = 1
-- AND event_type = 'failed'
-- AND (metadata LIKE '%limite%' OR metadata LIKE '%quota%' OR metadata LIKE '%429%');

-- ============================================================
-- 3. RESETAR CONTADOR DO PROVEDOR (liberar envios)
-- ============================================================

-- Resetar quota:
UPDATE email_providers 
SET sends_this_hour = 0,
    sends_today = 0,
    last_hour_reset_at = NOW(),
    last_reset_at = NOW()
WHERE id IN (SELECT email_provider_id FROM email_campaigns WHERE status = 'sending');

-- ============================================================
-- 4. LIMPAR JOBS ANTIGOS E REAGENDAR (se necessário)
-- ============================================================

-- Ver jobs na fila:
-- SELECT COUNT(*) as total_jobs FROM jobs WHERE queue = 'email';

-- Limpar jobs atrasados:
-- DELETE FROM jobs WHERE queue = 'email' AND available_at < UNIX_TIMESTAMP();

-- Atualizar jobs para executar imediatamente:
-- UPDATE jobs SET available_at = UNIX_TIMESTAMP() WHERE queue = 'email';

-- ============================================================
-- 5. VERIFICAR STATUS GERAL
-- ============================================================

SELECT 
    '5. STATUS GERAL' as info,
    (SELECT COUNT(*) FROM email_campaign_events WHERE email_campaign_id = 1 AND event_type = 'queued') as queued,
    (SELECT COUNT(*) FROM email_campaign_events WHERE email_campaign_id = 1 AND event_type = 'sent') as sent,
    (SELECT COUNT(*) FROM email_campaign_events WHERE email_campaign_id = 1 AND event_type = 'failed') as failed,
    (SELECT COUNT(*) FROM email_campaign_events WHERE email_campaign_id = 1 AND event_type = 'delivered') as delivered,
    (SELECT COUNT(*) FROM jobs WHERE queue = 'email') as jobs_na_fila;

-- ============================================================
-- RESUMO DAS EXECUÇÕES NECESSÁRIAS:
-- ============================================================
-- Execute na ordem se necessário:
-- 1. ALTER TABLE (se coluna source_url não existir)
-- 2. DELETE de falhas por quota (se quiser reprocessar)
-- 3. UPDATE de reset de quota (sempre bom resetar)
-- 4. UPDATE de jobs (se estiverem atrasados)
-- 5. SELECT final para verificar
-- ============================================================
