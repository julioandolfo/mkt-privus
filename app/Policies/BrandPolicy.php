<?php

namespace App\Policies;

use App\Enums\BrandRole;
use App\Models\Brand;
use App\Models\User;

class BrandPolicy
{
    /**
     * Qualquer membro da marca pode visualizá-la (e ativá-la via switch)
     */
    public function view(User $user, Brand $brand): bool
    {
        return $user->roleInBrand($brand) !== null;
    }

    /**
     * Owner e Admin podem editar a marca, gerenciar assets e convidar membros
     */
    public function update(User $user, Brand $brand): bool
    {
        return $user->roleInBrand($brand)?->canManage() ?? false;
    }

    /**
     * Apenas o Owner pode excluir a marca
     */
    public function delete(User $user, Brand $brand): bool
    {
        return $user->roleInBrand($brand) === BrandRole::Owner;
    }
}
