-- ============================================================
-- VERIFICAÇÃO DO ESTADO ATUAL - diagnóstico completo
-- Corrigido para compatibilidade com MySQL 5.7/MariaDB
-- ============================================================

-- 1. Verificar se existe campanha #4
SELECT 'CAMPANHAS' as secao, id, name, status, total_recipients, email_provider_id 
FROM email_campaigns 
WHERE id = 4;

-- Ver todas as campanhas recentes
SELECT 'TODAS CAMPANHAS' as secao, id, name, status, total_recipients, email_provider_id
FROM email_campaigns 
ORDER BY id DESC
LIMIT 10;

-- 2. Verificar quantidade de eventos por campanha
SELECT 'EVENTOS POR CAMPANHA' as secao, 
    email_campaign_id, 
    COUNT(*) as total,
    SUM(CASE WHEN event_type='queued' THEN 1 ELSE 0 END) as queued,
    SUM(CASE WHEN event_type='sent' THEN 1 ELSE 0 END) as sent,
    SUM(CASE WHEN event_type='failed' THEN 1 ELSE 0 END) as failed
FROM email_campaign_events
GROUP BY email_campaign_id
ORDER BY email_campaign_id DESC
LIMIT 20;

-- 3. Verificar jobs na fila
SELECT 'JOBS NA FILA' as secao, 
    COUNT(*) as total_jobs,
    SUM(CASE WHEN queue='email' THEN 1 ELSE 0 END) as email_jobs
FROM jobs;

-- Detalhes dos jobs email
SELECT 'DETALHE JOBS EMAIL' as secao,
    id, queue, attempts, available_at, reserved_at
FROM jobs
WHERE queue = 'email'
ORDER BY available_at
LIMIT 10;

-- 4. Verificar jobs falhados
SELECT 'JOBS FALHADOS' as secao, 
    COUNT(*) as total_failed,
    MAX(failed_at) as ultima_falha
FROM failed_jobs
WHERE queue = 'email' OR failed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR);

-- 5. Verificar configuração do provedor
SELECT 'PROVEDOR CONFIG' as secao, 
    id, name, type, hourly_limit, daily_limit, 
    sends_this_hour, sends_today,
    last_hour_reset_at, last_reset_at
FROM email_providers
WHERE id IN (SELECT email_provider_id FROM email_campaigns WHERE id = 4)
   OR hourly_limit IS NOT NULL;

-- 6. Verificar contatos ativos
SELECT 'CONTATOS ATIVOS' as secao, COUNT(*) as total_ativos
FROM email_contacts
WHERE status = 'active';

-- 7. Verificar tabelas de relacionamento entre campanha e listas
-- (tente descobrir o nome correto)
SHOW TABLES LIKE '%list%';

-- 8. Verificar estrutura da tabela de eventos
DESCRIBE email_campaign_events;

-- 9. Verificar se há eventos sem campanha associada
SELECT 'EVENTOS ORFAOS' as secao, COUNT(*) as total
FROM email_campaign_events ece
LEFT JOIN email_campaigns ec ON ec.id = ece.email_campaign_id
WHERE ec.id IS NULL;

-- 10. Campanha com mais eventos (qualquer uma)
SELECT 'CAMPANHA COM MAIS EVENTOS' as secao,
    email_campaign_id,
    COUNT(*) as total_eventos
FROM email_campaign_events
GROUP BY email_campaign_id
ORDER BY total_eventos DESC
LIMIT 1;
