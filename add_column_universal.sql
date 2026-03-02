-- ============================================================
-- ADICIONAR COLUNA source_url - FUNCIONA EM QUALQUER MYSQL/MARIADB
-- ============================================================

-- MÉTODO 1: Usando PROCEDURE (mais seguro - verifica antes de criar)
DELIMITER $$

DROP PROCEDURE IF EXISTS AddSourceUrlColumn$$

CREATE PROCEDURE AddSourceUrlColumn()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_NAME = 'email_assets' 
        AND COLUMN_NAME = 'source_url'
    ) THEN
        ALTER TABLE email_assets ADD COLUMN source_url TEXT NULL AFTER alt_text;
        SELECT '✅ Coluna source_url adicionada com sucesso' as resultado;
    ELSE
        SELECT 'ℹ️ Coluna source_url já existe - nenhuma alteração necessária' as resultado;
    END IF;
END$$

DELIMITER ;

-- Executar a procedure
CALL AddSourceUrlColumn();

-- Limpar procedure
DROP PROCEDURE IF EXISTS AddSourceUrlColumn;

-- ============================================================
-- MÉTODO 2: Apenas verificação (se Method 1 falhar)
-- ============================================================
-- Passo 1: Verifique se a coluna existe
-- SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'email_assets' AND COLUMN_NAME = 'source_url';

-- Passo 2: Se NÃO retornar resultado, execute manualmente:
-- ALTER TABLE email_assets ADD COLUMN source_url TEXT NULL AFTER alt_text;

-- ============================================================
-- MÉTODO 3: MariaDB/MySQL 8.0+ (versões modernas)
-- ============================================================
-- ALTER TABLE email_assets ADD COLUMN IF NOT EXISTS source_url TEXT NULL AFTER alt_text;

-- ============================================================
-- VERIFICAÇÃO FINAL
-- ============================================================
SELECT 
    'VERIFICAÇÃO' as info,
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'email_assets' 
AND COLUMN_NAME = 'source_url';
