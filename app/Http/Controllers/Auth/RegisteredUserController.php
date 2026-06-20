<?php

namespace App\Http\Controllers\Auth;

use App\Enums\BrandRole;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * Onboarding self-service: cria o usuário, a primeira marca (como Owner)
     * e, com billing habilitado, inicia o período de teste do primeiro plano.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],

            // Empresa (obrigatória)
            'brand_name' => 'required|string|max:255',
            'cnpj' => 'nullable|string|max:18',
            'legal_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30',
            'company_size' => 'nullable|string|max:30',
            'segment' => 'nullable|string|max:255',

            // Endereço
            'cep' => 'nullable|string|max:9',
            'address_street' => 'nullable|string|max:255',
            'address_number' => 'nullable|string|max:30',
            'address_complement' => 'nullable|string|max:255',
            'address_neighborhood' => 'nullable|string|max:255',
            'address_city' => 'nullable|string|max:255',
            'address_state' => 'nullable|string|max:2',

            // Onboarding
            'goals' => 'nullable|array|max:20',
            'goals.*' => 'string|max:100',
            'objective' => 'nullable|string|max:2000',
        ]);

        $user = DB::transaction(function () use ($request, $validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            $brandName = trim($validated['brand_name']);

            $brand = Brand::create([
                'name' => $brandName,
                'slug' => $this->uniqueSlug($brandName),
                'is_active' => true,
                'cnpj' => $validated['cnpj'] ?? null,
                'legal_name' => $validated['legal_name'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'company_size' => $validated['company_size'] ?? null,
                'segment' => $validated['segment'] ?? null,
                'cep' => $validated['cep'] ?? null,
                'address_street' => $validated['address_street'] ?? null,
                'address_number' => $validated['address_number'] ?? null,
                'address_complement' => $validated['address_complement'] ?? null,
                'address_neighborhood' => $validated['address_neighborhood'] ?? null,
                'address_city' => $validated['address_city'] ?? null,
                'address_state' => $validated['address_state'] ?? null,
                'goals' => $validated['goals'] ?? null,
                'objective' => $validated['objective'] ?? null,
            ]);

            $brand->users()->attach($user->id, ['role' => BrandRole::Owner->value]);
            $user->update(['current_brand_id' => $brand->id]);

            // Trial automático no primeiro plano ativo
            if (\App\Support\BillingSettings::enabled()) {
                $plan = Plan::active()->first();

                if ($plan) {
                    $user->subscriptions()->create([
                        'plan_id' => $plan->id,
                        'status' => Subscription::STATUS_TRIALING,
                        'trial_ends_at' => now()->addDays(\App\Support\BillingSettings::trialDays()),
                    ]);
                }
            }

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'marca';
        $slug = $base;

        while (Brand::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }
}
