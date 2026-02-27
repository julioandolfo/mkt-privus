-- ============================================================
-- VERIFICAR AGENDAMENTO DOS PRÓXIMOS ENVIOS
-- ============================================================

-- 1. VER TODOS OS JOBS NA FILA 'email' (ordem de execução)
SELECT 
    'JOBS NA FILA EMAIL' as secao,
    j.id,
    j.queue,
    j.attempts,
    FROM_UNIXTIME(j.available_at) as executar_em,
    CASE 
        WHEN j.available_at <= UNIX_TIMESTAMP() THEN 'PRONTO (atrasado)'
        WHEN j.reserved_at IS NOT NULL THEN 'EXECUTANDO'
        ELSE CONCAT(
            FLOOR((j.available_at - UNIX_TIMESTAMP()) / 3600), 'h ',
            FLOOR(((j.available_at - UNIX_TIMESTAMP()) % 3600) / 60), 'm'
        )
    END as status_tempo,
    j.reserved_at,
    -- Extrair campaign_id do payload JSON (se possível)
    CASE 
        WHEN j.payload LIKE '%campaignId%' THEN 
            SUBSTRING(j.payload FROM LOCATE('campaignId', j.payload) + 13 FOR 10)
        ELSE 'N/A'
    END as campaign_id_extraido
FROM jobs j
WHERE j.queue = 'email'
ORDER BY j.available_at ASC
LIMIT 20;

-- 2. RESUMO DA FILA
SELECT 
    'RESUMO DA FILA' as secao,
    COUNT(*) as total_jobs,
    SUM(CASE WHEN available_at <= UNIX_TIMESTAMP() THEN 1 ELSE 0 END) as prontos_para_executar,
    SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) as em_execucao,
    SUM(CASE WHEN available_at > UNIX_TIMESTAMP() THEN 1 ELSE 0 END) as agendados_futuro,
    MIN(FROM_UNIXTIME(available_at)) as proximo_job,
    MAX(FROM_UNIXTIME(available_at)) as ultimo_job
FROM jobs
WHERE queue = 'email';

-- 3. JOBS FALHADOS RECENTES (últimas 24h)
SELECT 
    'JOBS FALHADOS 24h' as secao,
    COUNT(*) as total_falhos,
    MAX(failed_at) as ultima_falha,
    GROUP_CONCAT(DISTINCT SUBSTRING(exception, 1, 100) SEPARATOR ' | ') as erros_comuns
FROM failed_jobs
WHERE queue = 'email'
AND failed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR);

-- 4. ESTADO DO PROVEDOR (quota e limites)
SELECT 
    'ESTADO DO PROVEDOR' as secao,
    id,
    name,
    type,
    hourly_limit,
    sends_this_hour,
    daily_limit,
    sends_today,
    last_hour_reset_at,
    CASE 
        WHEN hourly_limit IS NULL THEN 'Sem limite'
        WHEN sends_this_hour >= hourly_limit THEN 'QUOTA ESGOTADA'
        ELSE CONCAT(hourly_limit - sends_this_hour, ' emails disponíveis')
    END as status_quota,
    CASE
        WHEN hourly_limit IS NULL THEN 'N/A'
        WHEN last_hour_reset_at IS NULL THEN 'Reset pendente'
        ELSE CONCAT(
            FLOOR(TIMESTAMPDIFF(SECOND, last_hour_reset_at, NOW()) / 3600), 'h ',
            FLOOR((TIMESTAMPDIFF(SECOND, last_hour_reset_at, NOW()) % 3600) / 60), 'm',
            ' desde último reset'
        )
    END as tempo_desde_reset
FROM email_providers
WHERE hourly_limit IS NOT NULL
   OR id IN (SELECT email_provider_id FROM email_campaigns WHERE status = 'sending');

-- 5. CAMPANHAS ENVIANDO ATUALMENTE
SELECT 
    'CAMPANHAS ENVIANDO' as secao,
    c.id,
    c.name,
    c.status,
    (
        SELECT COUNT(DISTINCT email_contact_id) 
        FROM email_campaign_events 
        WHERE email_campaign_id = c.id AND event_type = 'sent'
    ) as enviados,
    (
        SELECT COUNT(DISTINCT email_contact_id) 
        FROM email_campaign_events 
        WHERE email_campaign_id = c.id AND event_type = 'queued'
    ) as na_fila,
    c.total_recipients,
    ep.name as provedor,
    ep.hourly_limit
FROM email_campaigns c
LEFT JOIN email_providers ep ON ep.id = c.email_provider_id
WHERE c.status = 'sending'
ORDER BY c.id DESC;

-- 6. PRÓXIMA EXECUÇÃO ESTIMADA
SELECT 
    'PRÓXIMA EXECUÇÃO' as secao,
    CASE 
        WHEN COUNT(*) = 0 THEN 'Nenhum job na fila'
        WHEN MIN(available_at) <= UNIX_TIMESTAMP() THEN 'IMEDIATA (jobs atrasados)'
        ELSE FROM_UNIXTIME(MIN(available_at))
    END as proximo_envio,
    CASE 
        WHEN COUNT(*) = 0 THEN 0
        WHEN MIN(available_at) <= UNIX_TIMESTAMP() THEN 0
        ELSE FLOOR((MIN(available_at) - UNIX_TIMESTAMP()) / 60)
    END as minutos_ate_proximo
FROM jobs
WHERE queue = 'email';

-- 7. CONTAGEM DE EVENTOS POR CAMPANHA ATIVA
SELECT 
    'EVENTOS CAMPANHAS ATIVAS' as secao,
    ece.email_campaign_id,
    c.name as campanha,
    SUM(CASE WHEN ece.event_type = 'queued' THEN 1 ELSE 0 END) as queued,
    SUM(CASE WHEN ece.event_type = 'sent' THEN 1 ELSE 0 END) as sent,
    SUM(CASE WHEN ece.event_type = 'failed' THEN 1 ELSE 0 END) as failed,
    COUNT(DISTINCT CASE WHEN ece.event_type = 'queued' THEN ece.email_contact_id END) as queued_distinct,
    COUNT(DISTINCT CASE WHEN ece.event_type IN ('sent','failed') THEN ece.email_contact_id END) as processed_distinct
FROM email_campaign_events ece
JOIN email_campaigns c ON c.id = ece.email_campaign_id
WHERE c.status = 'sending'
GROUP BY ece.email_campaign_id, c.name
ORDER BY ece.email_campaign_id DESC;
