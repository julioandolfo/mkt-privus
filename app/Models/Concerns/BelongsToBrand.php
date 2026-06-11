<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Isolamento de dados por marca (multi-tenancy).
 *
 * Aplica um global scope que restringe todas as queries à marca ativa do
 * usuário autenticado. Em contexto sem usuário autenticado (console, filas,
 * webhooks públicos) nenhum escopo é aplicado — jobs e webhooks devem sempre
 * filtrar explicitamente pelos IDs que receberam.
 *
 * Modelos cujo brand_id pode ser NULL (registros "globais", ex: conexões
 * OAuth ainda não vinculadas a uma marca) devem declarar:
 *
 *     public bool $brandScopeIncludesNull = true;
 *
 * Na criação, brand_id é preenchido automaticamente com a marca ativa quando
 * não informado (exceto em modelos que aceitam registros globais).
 *
 * Para consultas administrativas/entre marcas use:
 *
 *     Model::withoutBrandScope()->...
 */
trait BelongsToBrand
{
    public static function bootBelongsToBrand(): void
    {
        static::addGlobalScope('brand', function (Builder $builder) {
            if (!Auth::hasUser()) {
                return;
            }

            $model = $builder->getModel();
            $brandId = Auth::user()->current_brand_id;

            $builder->where(function (Builder $query) use ($model, $brandId) {
                $model->applyBrandScopeConstraint($query, $brandId);
            });
        });

        static::creating(function (Model $model) {
            $includesNull = (bool) ($model->brandScopeIncludesNull ?? false);

            if (!$includesNull && empty($model->brand_id) && Auth::hasUser()) {
                $model->brand_id = Auth::user()->current_brand_id;
            }
        });
    }

    /**
     * Restrição padrão: filtra pela coluna brand_id da própria tabela.
     * Modelos com visibilidade própria (ex: Post via pivô post_brand) podem sobrescrever.
     */
    public function applyBrandScopeConstraint(Builder $query, ?int $brandId): void
    {
        $table = $this->getTable();

        if ($brandId !== null) {
            $query->where("{$table}.brand_id", $brandId);
        } else {
            // Usuário sem marca ativa não enxerga registros de marca alguma
            $query->whereRaw('1 = 0');
        }

        if ($this->brandScopeIncludesNull ?? false) {
            $query->orWhereNull("{$table}.brand_id");
        }
    }

    /**
     * Query sem o escopo de marca (uso administrativo ou agregações entre marcas)
     */
    public static function withoutBrandScope(): Builder
    {
        return static::query()->withoutGlobalScope('brand');
    }
}
