-- ============================================================
-- ADICIONAR COLUNA source_url NA TABELA email_assets
-- Sem precisar rodar migration
-- ============================================================

-- 1. VERIFICAR SE COLUNA JÁ EXISTE
SELECT 
    'VERIFICAÇÃO' as info,
    CASE 
        WHEN EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_NAME = 'email_assets' 
            AND COLUMN_NAME = 'source_url'
        ) THEN '✅ Coluna source_url já existe'
        ELSE '❌ Coluna não existe - será criada'
    END as status;

-- 2. ADICIONAR COLUNA source_url (execute apenas se não existir)
-- DESCOMENTE E EXECUTE SE A COLUNA NÃO EXISTIR:

-- ALTER TABLE email_assets 
-- ADD COLUMN source_url TEXT NULL 
-- AFTER alt_text;

-- 3. CRIAR ÍNDICE PARA source_url (opcional, melhora performance)
-- CREATE INDEX idx_email_assets_source_url ON email_assets(source_url(255));

-- 4. VERIFICAR ESTRUTURA APÓS ALTERAÇÃO
SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'email_assets'
ORDER BY ORDINAL_POSITION;

-- ============================================================
-- INSTRUÇÕES:
-- ============================================================
-- 1. Execute a query de verificação primeiro
-- 2. Se mostrar "Coluna não existe", descomente e execute o ALTER TABLE
-- 3. Opcional: crie o índice para melhorar performance de busca
-- 4. Execute a verificação final para confirmar
-- ============================================================
