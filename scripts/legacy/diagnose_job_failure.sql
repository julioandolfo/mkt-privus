-- ============================================================
-- DIAGNÓSTICO COMPLETO DA FALHA DOS JOBS
-- ============================================================

-- 1. TODOS OS JOBS FALHADOS (detalhe completo)
SELECT 
    'JOBS FALHADOS - DETALHE' as secao,
    id,
    failed_at,
    SUBSTRING(exception, 1, 200) as erro,
    SUBSTRING(payload, 1, 100) as payload_preview
FROM failed_jobs 
WHERE queue = 'email' 
   OR exception LIKE '%SendCampaignBatchJob%'
   OR failed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY failed_at DESC
LIMIT 10;

-- 2. CONTAGEM DE FALHAS POR TIPO DE ERRO
SELECT 
    'FALHAS POR TIPO' as secao,
    CASE 
        WHEN exception LIKE '%campaignId%' THEN 'campaignId não inicializado'
        WHEN exception LIKE '%must not be accessed before initialization%' THEN 'Propriedade não inicializada'
        WHEN exception LIKE '%Serialization%' THEN 'Erro de serialização'
        ELSE 'Outro erro'
    END as tipo_erro,
    COUNT(*) as quantidade
FROM failed_jobs
WHERE queue = 'email' 
   OR exception LIKE '%SendCampaignBatchJob%'
GROUP BY tipo_erro;

-- 3. JOBS NA FILA COM MUITAS TENTATIVAS (indicativo de falha repetida)
SELECT 
    'JOBS COM MUITAS TENTATIVAS' as alerta,
    COUNT(*) as quantidade,
    GROUP_CONCAT(id SEPARATOR ', ') as job_ids
FROM jobs
WHERE queue = 'email'
AND attempts >= 2;

-- 4. STATUS GERAL DA CAMPANHA #1 (a que está enviando)
SELECT 
    'CAMPANHA #1' as info,
    c.name,
    c.status,
    c.total_recipients,
    (
        SELECT COUNT(DISTINCT email_contact_id) 
        FROM email_campaign_events 
        WHERE email_campaign_id = 1 AND event_type = 'sent'
    ) as enviados,
    (
        SELECT COUNT(DISTINCT email_contact_id) 
        FROM email_campaign_events 
        WHERE email_campaign_id = 1 AND event_type = 'queued'
    ) as queued,
    (
        SELECT COUNT(DISTINCT email_contact_id) 
        FROM email_campaign_events 
        WHERE email_campaign_id = 1 AND event_type = 'failed'
    ) as falhos
FROM email_campaigns c
WHERE c.id = 1;

-- 5. PREVISÃO DE TÉRMINO
SELECT 
    'PREVISÃO' as info,
    c.total_recipients as total,
    (
        SELECT COUNT(DISTINCT email_contact_id) 
        FROM email_campaign_events 
        WHERE email_campaign_id = 1 AND event_type = 'sent'
    ) as enviados,
    c.total_recipients - (
        SELECT COUNT(DISTINCT email_contact_id) 
        FROM email_campaign_events 
        WHERE email_campaign_id = 1 AND event_type = 'sent'
    ) as restantes,
    ep.hourly_limit,
    CEIL(
        (c.total_recipients - (
            SELECT COUNT(DISTINCT email_contact_id) 
            FROM email_campaign_events 
            WHERE email_campaign_id = 1 AND event_type = 'sent'
        )) / ep.hourly_limit
    ) as horas_restantes
FROM email_campaigns c
JOIN email_providers ep ON ep.id = c.email_provider_id
WHERE c.id = 1;
