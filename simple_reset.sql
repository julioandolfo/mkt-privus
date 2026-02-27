-- ============================================================
-- RESET SIMPLES DA CAMPANHA #4
-- Funciona em qualquer versão MySQL/MariaDB
-- ============================================================

-- 1. LIMPAR TODOS OS EVENTOS DA CAMPANHA #4
DELETE FROM email_campaign_events WHERE email_campaign_id = 4;

-- 2. RESETAR STATUS PARA DRAFT (rascunho)
UPDATE email_campaigns 
SET status = 'draft', 
    started_at = NULL, 
    completed_at = NULL,
    total_recipients = 0
WHERE id = 4;

-- 3. RESETAR CONTADOR DO PROVEDOR (para liberar envio imediato)
UPDATE email_providers 
SET sends_this_hour = 0, 
    last_hour_reset_at = NOW()
WHERE id IN (SELECT email_provider_id FROM email_campaigns WHERE id = 4);

-- OU resetar todos os provedores com hourly_limit:
-- UPDATE email_providers 
-- SET sends_this_hour = 0, 
--     last_hour_reset_at = NOW()
-- WHERE hourly_limit IS NOT NULL;

-- 4. LIMPAR JOBS ANTIGOS DA FILA (opcional - cuidado!)
-- DELETE FROM jobs WHERE queue = 'email';

-- 5. VERIFICAR RESULTADO
SELECT 'CAMPANHA #4 RESETADA' as info, 
    id, name, status, total_recipients
FROM email_campaigns 
WHERE id = 4;

-- 6. CONTAR EVENTOS (deve ser 0 para todos)
SELECT 'EVENTOS RESTANTES' as info, event_type, COUNT(*) as total
FROM email_campaign_events 
WHERE email_campaign_id = 4
GROUP BY event_type;

-- ============================================================
-- INSTRUÇÕES APÓS EXECUTAR:
-- ============================================================
-- 1. Acesse a página da campanha #4 no navegador
-- 2. Clique em "Enviar" ou "Agendar"
-- 3. O sistema vai recalcular os destinatários e criar novos eventos
-- ============================================================
