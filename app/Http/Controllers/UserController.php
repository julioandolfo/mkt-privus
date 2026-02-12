<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class UserController extends Controller
{
    /**
     * Vincular usuario a todas as marcas existentes (acesso total).
     */
    private function syncAllBrands(User $user): void
    {
        $allBrandIds = Brand::pluck('id');
        $syncData = [];
        foreach ($allBrandIds as $brandId) {
            $syncData[$brandId] = ['role' => 'admin'];
        }
        $user->brands()->sync($syncData);

        // Definir current_brand_id se nao tem
        if (!$user->current_brand_id && $allBrandIds->isNotEmpty()) {
            $user->update(['current_brand_id' => $allBrandIds->first()]);
        }
    }

    /**
     * Listar todos os usuários (JSON para uso na aba de Settings).
     */
    public function index(Request $request)
    {
        $query = User::query();

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('is_active', $request->status === 'active');
        }

        $users = $query->orderBy('name')
            ->get()
            ->map(fn(User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'email_verified_at' => $user->email_verified_at?->format('d/m/Y H:i'),
                'last_login_at' => $user->last_login_at?->format('d/m/Y H:i'),
                'last_login_ip' => $user->last_login_ip,
                'brands_count' => $user->brands()->count(),
                'posts_count' => $user->posts()->count(),
                'created_at' => $user->created_at->format('d/m/Y H:i'),
                'is_current' => $user->id === auth()->id(),
            ]);

        return response()->json($users);
    }

    /**
     * Criar novo usuário.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'is_active' => 'boolean',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'is_active' => $validated['is_active'] ?? true,
            'email_verified_at' => now(),
        ]);

        // Vincular automaticamente a todas as marcas
        $this->syncAllBrands($user);

        return back()->with('success', "Usuário \"{$user->name}\" criado com sucesso.");
    }

    /**
     * Atualizar usuário.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
            'is_active' => 'boolean',
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->is_active = $validated['is_active'] ?? $user->is_active;

        if (!empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->save();

        // Garantir que continua vinculado a todas as marcas
        $this->syncAllBrands($user);

        return back()->with('success', "Usuário \"{$user->name}\" atualizado com sucesso.");
    }

    /**
     * Excluir usuário.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        // Impedir que o usuário delete a si mesmo
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Você não pode excluir sua própria conta.');
        }

        $name = $user->name;
        $user->delete();

        return back()->with('success', "Usuário \"{$name}\" excluído com sucesso.");
    }

    /**
     * Ativar/Desativar usuário.
     */
    public function toggle(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Você não pode desativar sua própria conta.');
        }

        $user->update(['is_active' => !$user->is_active]);

        return back()->with('success', $user->is_active
            ? "Usuário \"{$user->name}\" ativado."
            : "Usuário \"{$user->name}\" desativado."
        );
    }
}
