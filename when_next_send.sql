-- ============================================================
-- QUANDO SERÁ ENVIADO O PRÓXIMO EMAIL/BATCH?
-- ============================================================

-- 1. VERIFICAÇÃO RÁPIDA
SELECT 
    '🔍 PRÓXIMOS ENVIOS' as verificacao,
    COUNT(*) as total_jobs,
    SUM(CASE WHEN available_at <= UNIX_TIMESTAMP() THEN 1 ELSE 0 END) as prontos_agora,
    FROM_UNIXTIME(MIN(available_at)) as primeiro_job,
    FROM_UNIXTIME(MAX(available_at)) as ultimo_job
FROM jobs 
WHERE queue = 'email';

-- 2. DETALHE DOS PRÓXIMOS 10 JOBS COM HORÁRIO
SELECT 
    j.id as job_id,
    j.attempts as tentativas,
    FROM_UNIXTIME(j.available_at) as horario_execucao,
    -- Tempo restante
    CASE 
        WHEN j.available_at <= UNIX_TIMESTAMP() THEN '⚠️ ATRASADO - deveria já ter executado!'
        ELSE CONCAT(
            '⏳ Em ', 
            FLOOR((j.available_at - UNIX_TIMESTAMP()) / 3600), 'h ',
            FLOOR(((j.available_at - UNIX_TIMESTAMP()) % 3600) / 60), 'min'
        )
    END as quando_executa,
    -- Status
    CASE 
        WHEN j.reserved_at IS NOT NULL THEN '🔄 EXECUTANDO AGORA'
        WHEN j.available_at <= UNIX_TIMESTAMP() THEN '⏸️ PRONTO (esperando worker)'
        ELSE '⏰ AGENDADO'
    END as status,
    -- Tenta extrair campaign_id
    CASE 
        WHEN j.payload LIKE '%campaignId%' THEN 
            SUBSTRING_INDEX(
                SUBSTRING_INDEX(j.payload, 'campaignId', -1), 
                ';', 1
            )
        ELSE '-'
    END as campaign_id
FROM jobs j
WHERE j.queue = 'email'
ORDER BY j.available_at ASC
LIMIT 10;

-- 3. VERIFICAR SE O WORKER ESTÁ PROCESSANDO (jobs reservados)
SELECT 
    'Jobs sendo executados agora:' as situacao,
    COUNT(*) as quantidade,
    GROUP_CONCAT(id SEPARATOR ', ') as job_ids
FROM jobs 
WHERE queue = 'email' 
AND reserved_at IS NOT NULL;

-- 4. PREVISÃO BASEADA NO HOURLY_LIMIT
SELECT 
    '⏱️ PREVISÃO DE ENVIO' as info,
    ep.name as provedor,
    ep.hourly_limit,
    ep.sends_this_hour,
    (ep.hourly_limit - ep.sends_this_hour) as ainda_pode_enviar,
    -- Se atingiu o limite, quando reseta?
    CASE 
        WHEN ep.sends_this_hour >= ep.hourly_limit THEN 
            CONCAT('Aguardando reset (', 
                60 - TIMESTAMPDIFF(MINUTE, ep.last_hour_reset_at, NOW()), 
                ' min)')
        ELSE 'Pode enviar imediatamente'
    END as status_envio
FROM email_providers ep
WHERE ep.hourly_limit IS NOT NULL
  AND ep.id IN (SELECT DISTINCT email_provider_id FROM email_campaigns WHERE status = 'sending');

-- 5. VERIFICAR SE HÁ BLOQUEIO (jobs com muitas tentativas)
SELECT 
    '⚠️ JOBS COM PROBLEMAS' as alerta,
    COUNT(*) as jobs_com_falha
FROM jobs 
WHERE queue = 'email' 
AND attempts > 1;

-- 6. RESUMO FINAL - TUDO OK?
SELECT 
    '✅ STATUS GERAL' as resumo,
    CASE 
        WHEN NOT EXISTS (SELECT 1 FROM jobs WHERE queue = 'email') THEN 
            'Sem jobs na fila - Campanha pode ter terminado ou não iniciado'
        WHEN EXISTS (SELECT 1 FROM jobs WHERE queue = 'email' AND available_at <= UNIX_TIMESTAMP()) THEN 
            'Jobs PRONTOS - O worker deve processar em breve (ou pode estar parado)'
        WHEN EXISTS (SELECT 1 FROM jobs WHERE queue = 'email' AND available_at > UNIX_TIMESTAMP()) THEN 
            CONCAT('Jobs AGENDADOS - Próximo em ', 
                FLOOR((MIN(available_at) - UNIX_TIMESTAMP()) / 60),
                ' minutos')
        ELSE 'Status desconhecido'
    END as situacao
FROM (SELECT 1) dummy
LEFT JOIN jobs j ON j.queue = 'email'
GROUP BY dummy.1;
