-- ============================================================
-- CORRIGIR ERRO DO JOB E REINICIAR PROCESSAMENTO
-- ============================================================

-- 1. LIMPAR JOBS FALHADOS DO TIPO SendCampaignBatchJob
DELETE FROM failed_jobs 
WHERE queue = 'email' 
AND exception LIKE '%SendCampaignBatchJob%';

-- 2. RESETAR TENTATIVAS DOS JOBS NA FILA (para permitir novo processamento)
-- Isso faz com que jobs que falharam temporariamente possam tentar novamente
UPDATE jobs 
SET attempts = 0,
    reserved_at = NULL
WHERE queue = 'email';

-- 3. ATUALIZAR JOBS PARA EXECUTAR IMEDIATAMENTE (opcional)
-- Descomente se quiser forçar execução agora ao invés de esperar o horário agendado
-- UPDATE jobs 
-- SET available_at = UNIX_TIMESTAMP()
-- WHERE queue = 'email';

-- 4. RESETAR CONTADOR DO PROVEDOR (para garantir que pode enviar)
UPDATE email_providers 
SET sends_this_hour = 0, 
    last_hour_reset_at = NOW()
WHERE hourly_limit IS NOT NULL;

-- 5. VERIFICAR STATUS APÓS CORREÇÃO
SELECT '✅ STATUS APÓS CORREÇÃO' as info,
    (SELECT COUNT(*) FROM jobs WHERE queue = 'email') as jobs_na_fila,
    (SELECT COUNT(*) FROM failed_jobs WHERE queue = 'email') as jobs_falhados,
    (SELECT FROM_UNIXTIME(MIN(available_at)) FROM jobs WHERE queue = 'email') as proximo_job;

-- 6. LISTAR PRÓXIMOS JOBS
SELECT 
    id,
    attempts as tentativas_resetadas,
    FROM_UNIXTIME(available_at) as horario_execucao,
    CASE 
        WHEN available_at <= UNIX_TIMESTAMP() THEN '⏳ Pronto para executar'
        ELSE CONCAT('Aguardando ', FLOOR((available_at - UNIX_TIMESTAMP())/60), ' min')
    END as status
FROM jobs 
WHERE queue = 'email'
ORDER BY available_at ASC
LIMIT 10;

-- ============================================================
-- INSTRUÇÕES:
-- ============================================================
-- 1. Execute este SQL
-- 2. Reinicie o container/worker do Docker/Coolify
-- 3. Acompanhe os logs: docker logs <nome-do-worker>
-- 4. Verifique se os emails começam a ser enviados
-- ============================================================
