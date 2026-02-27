-- ============================================================
-- VERIFICAÇÃO RÁPIDA - Resumo Visual
-- Execute para ver status completo em uma tela
-- ============================================================

-- RESUMO GERAL
SELECT 
    '=== RESUMO GERAL ===' as info,
    '' as detalhe
UNION ALL
SELECT 
    'Jobs na fila email:',
    CAST(COUNT(*) as CHAR)
FROM jobs WHERE queue = 'email'
UNION ALL
SELECT 
    'Jobs prontos (atrasados):',
    CAST(SUM(CASE WHEN available_at <= UNIX_TIMESTAMP() THEN 1 ELSE 0 END) as CHAR)
FROM jobs WHERE queue = 'email'
UNION ALL
SELECT 
    'Jobs falhados (24h):',
    CAST(COUNT(*) as CHAR)
FROM failed_jobs WHERE queue = 'email' AND failed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
UNION ALL
SELECT 
    'Campanhas enviando:',
    CAST(COUNT(*) as CHAR)
FROM email_campaigns WHERE status = 'sending'
UNION ALL
SELECT 
    '--- PRÓXIMO ENVIO ---',
    ''
UNION ALL
SELECT 
    CASE 
        WHEN COUNT(*) = 0 THEN 'Sem jobs na fila'
        WHEN MIN(available_at) <= UNIX_TIMESTAMP() THEN 'IMEDIATO (atrasado)'
        ELSE CONCAT('Em ', FLOOR((MIN(available_at) - UNIX_TIMESTAMP()) / 60), ' minutos')
    END,
    FROM_UNIXTIME(MIN(available_at))
FROM jobs WHERE queue = 'email'
UNION ALL
SELECT 
    '--- QUOTA DO PROVEDOR ---',
    ''
UNION ALL
SELECT 
    CONCAT(name, ':'),
    CONCAT(
        sends_this_hour, '/', COALESCE(hourly_limit, '∞'), 
        ' (reset: ', COALESCE(TIMESTAMPDIFF(MINUTE, last_hour_reset_at, NOW()), 0), ' min atrás)'
    )
FROM email_providers 
WHERE hourly_limit IS NOT NULL;

-- DETALHE DOS JOBS NA FILA (próximos 10)
SELECT 
    '=== PRÓXIMOS JOBS ===' as secao,
    id as job_id,
    attempts as tentativas,
    CASE 
        WHEN available_at <= UNIX_TIMESTAMP() THEN 'AGORA'
        ELSE CONCAT(FLOOR((available_at - UNIX_TIMESTAMP()) / 60), ' min')
    END as em_quantos_min,
    CASE 
        WHEN reserved_at IS NOT NULL THEN 'EXECUTANDO'
        WHEN available_at <= UNIX_TIMESTAMP() THEN 'ATRASADO'
        ELSE 'AGENDADO'
    END as status
FROM jobs 
WHERE queue = 'email'
ORDER BY available_at ASC
LIMIT 10;

-- STATUS DAS CAMPANHAS ATIVAS
SELECT 
    '=== CAMPANHAS ===' as secao,
    c.id,
    LEFT(c.name, 30) as nome,
    (
        SELECT COUNT(DISTINCT email_contact_id) 
        FROM email_campaign_events 
        WHERE email_campaign_id = c.id AND event_type = 'sent'
    ) as enviados,
    c.total_recipients as total,
    CONCAT(
        ROUND(
            (SELECT COUNT(DISTINCT email_contact_id) 
             FROM email_campaign_events 
             WHERE email_campaign_id = c.id AND event_type = 'sent'
            ) / c.total_recipients * 100, 1
        ), '%'
    ) as progresso
FROM email_campaigns c
WHERE c.status = 'sending'
ORDER BY c.id DESC;
