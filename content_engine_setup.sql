-- =====================================================
-- CONTENT ENGINE V2 - Setup SQL
-- Queries para configurar tabelas sem usar migrate
-- =====================================================

-- 1. Adicionar coluna content_engine_config na tabela brands
ALTER TABLE brands ADD COLUMN content_engine_config JSON NULL AFTER ai_context;

-- 2. Criar tabela campaigns_generated
CREATE TABLE campaigns_generated (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    brand_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    concept TEXT NOT NULL,
    objective VARCHAR(50) NOT NULL,
    platforms JSON NOT NULL,
    format VARCHAR(50) NOT NULL,
    posts_data JSON NOT NULL,
    metadata JSON NULL,
    status VARCHAR(20) DEFAULT 'active',
    rejected_at TIMESTAMP NULL,
    rejection_reason TEXT NULL,
    converted_at TIMESTAMP NULL,
    converted_posts JSON NULL,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_brand_status (brand_id, status),
    INDEX idx_brand_created (brand_id, created_at),
    
    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Valores padrão para marcas existentes (opcional)
-- =====================================================

-- Atualiza marcas existentes com configuração padrão do Content Engine
UPDATE brands 
SET content_engine_config = JSON_OBJECT(
    'posts_per_campaign', 3,
    'campaigns_per_generation', 5,
    'generate_images', true,
    'auto_hashtags', true,
    'caption_style', 'medium',
    'preferred_platforms', JSON_ARRAY(),
    'content_tone_override', NULL,
    'include_cta', true,
    'include_emojis', true,
    'hashtag_count', 8,
    'post_types', JSON_ARRAY('feed', 'carousel'),
    'best_times_source', 'auto'
)
WHERE content_engine_config IS NULL;

-- =====================================================
-- Verificação
-- =====================================================

-- Verificar se a coluna foi criada
DESCRIBE brands;

-- Verificar se a tabela foi criada
DESCRIBE campaigns_generated;

-- Contar marcas com configuração
SELECT COUNT(*) as marcas_com_config FROM brands WHERE content_engine_config IS NOT NULL;
