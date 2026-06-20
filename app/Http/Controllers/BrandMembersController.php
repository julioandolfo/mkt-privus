<?php

namespace App\Http\Controllers;

use App\Enums\BrandRole;
use App\Models\Brand;
use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gerencia membros de uma marca (rotas protegidas por can:update,brand).
 * O papel Owner é imutável por aqui: não pode ser alterado nem removido.
 */
class BrandMembersController extends Controller
{
    public function update(Request $request, Brand $brand, User $member): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(['admin', 'editor', 'viewer'])],
        ]);

        $currentRole = $member->roleInBrand($brand);

        if (!$currentRole) {
            return back()->with('error', 'Este usuário não é membro da marca.');
        }

        if ($currentRole === BrandRole::Owner) {
            return back()->with('error', 'O papel do proprietário não pode ser alterado.');
        }

        $brand->users()->updateExistingPivot($member->id, ['role' => $validated['role']]);

        SystemLog::info('brands', 'member.role_updated', "Papel de {$member->email} na marca {$brand->name}: {$currentRole->value} → {$validated['role']}", [
            'brand_id' => $brand->id,
            'user_id' => $request->user()->id,
        ]);

        return back()->with('success', 'Papel atualizado.');
    }

    public function destroy(Request $request, Brand $brand, User $member): RedirectResponse
    {
        $currentRole = $member->roleInBrand($brand);

        if (!$currentRole) {
            return back()->with('error', 'Este usuário não é membro da marca.');
        }

        if ($currentRole === BrandRole::Owner) {
            return back()->with('error', 'O proprietário não pode ser removido da marca.');
        }

        $brand->users()->detach($member->id);

        // Se a marca removida era a ativa do membro, aponta para outra marca dele
        if ($member->current_brand_id === $brand->id) {
            $member->update(['current_brand_id' => $member->brands()->first()?->id]);
        }

        SystemLog::info('brands', 'member.removed', "{$member->email} removido da marca {$brand->name}", [
            'brand_id' => $brand->id,
            'user_id' => $request->user()->id,
        ]);

        return back()->with('success', 'Membro removido da marca.');
    }
}
