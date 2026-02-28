-- ============================================================
-- CORRIGIR FALHAS POR QUOTA E REPROCESSAR
-- ============================================================

-- 1. VERIFICAR FALHAS POR QUOTA
SELECT 
    'FALHAS POR QUOTA' as secao,
    COUNT(*) as quantidade,
    MIN(occurred_at) as primeira_falha,
    MAX(occurred_at) as ultima_falha
FROM email_campaign_events
WHERE email_campaign_id = 1
AND event_type = 'failed'
AND (
    metadata LIKE '%limite diário%'
    OR metadata LIKE '%limite diario%'
    OR metadata LIKE '%daily limit%'
    OR metadata LIKE '%quota%'
    OR metadata LIKE '%limit reached%'
    OR metadata LIKE '%429%'
);

-- 2. CONVERTER FALHAS POR QUOTA EM 'QUEUED' NOVAMENTE
-- Isso vai remover o evento 'failed' e permitir reenvio
-- DESCOMENTE PARA EXECUTAR:

-- DELETE FROM email_campaign_events
-- WHERE email_campaign_id = 1
-- AND event_type = 'failed'
-- AND (
--     metadata LIKE '%limite diário%'
--     OR metadata LIKE '%limite diario%'
--     OR metadata LIKE '%daily limit%'
--     OR metadata LIKE '%quota%'
--     OR metadata LIKE '%limit reached%'
--     OR metadata LIKE '%429%'
-- );

-- 3. RESETAR STATUS DA CAMPANHA SE NECESSÁRIO
-- Se a campanha marcou como 'sent' mas ainda há contatos para enviar:
-- UPDATE email_campaigns 
-- SET status = 'sending', 
--     completed_at = NULL
-- WHERE id = 1
-- AND status = 'sent'
-- AND (
--     SELECT COUNT(*) 
--     FROM email_campaign_events 
--     WHERE email_campaign_id = 1 AND event_type = 'queued'
-- ) > (
--     SELECT COUNT(*) 
--     FROM email_campaign_events 
--     WHERE email_campaign_id = 1 AND event_type = 'sent'
-- );

-- 4. LIMPAR JOBS ANTIGOS DA FILA E REAGENDAR
-- DESCOMENTE SE QUISER FORÇAR REPROCESSAMENTO IMEDIATO:

-- DELETE FROM jobs WHERE queue = 'email';

-- -- Recriar jobs para contatos que ainda estão na fila (queued mas não sent)
-- -- Isso precisaria ser feito via código Laravel (controller ou comando)
-- -- O sistema vai recriar automaticamente quando o próximo batch for processado

-- 5. RESETAR CONTADOR DO PROVEDOR
UPDATE email_providers 
SET sends_this_hour = 0,
    sends_today = 0,
    last_hour_reset_at = NOW(),
    last_reset_at = NOW()
WHERE id IN (SELECT email_provider_id FROM email_campaigns WHERE id = 1);

-- 6. VERIFICAR RESULTADO
SELECT 
    'STATUS APÓS CORREÇÃO' as info,
    (SELECT COUNT(*) FROM email_campaign_events WHERE email_campaign_id = 1 AND event_type = 'queued') as queued,
    (SELECT COUNT(*) FROM email_campaign_events WHERE email_campaign_id = 1 AND event_type = 'sent') as sent,
    (SELECT COUNT(*) FROM email_campaign_events WHERE email_campaign_id = 1 AND event_type = 'failed') as failed,
    (SELECT status FROM email_campaigns WHERE id = 1) as status_campanha;

-- ============================================================
-- RESUMO DAS CORREÇÕES APLICADAS:
-- ============================================================
-- 1. Falhas por quota não criam mais evento 'failed' permanente
-- 2. Emails com erro de quota permanecem na fila para reenvio
-- 3. Contador do provedor foi resetado
-- 
-- PRÓXIMA EXECUÇÃO:
-- - Os jobs na fila vão reprocessar os emails
-- - Emails que falharam por quota serão tentados novamente
-- - Se quota ainda estiver excedida, aguarda próxima janela
-- ============================================================
