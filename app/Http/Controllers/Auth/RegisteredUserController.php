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
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'brand_name' => 'nullable|string|max:255',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            $brandName = trim((string) $request->input('brand_name')) ?: 'Minha Marca';

            $brand = Brand::create([
                'name' => $brandName,
                'slug' => $this->uniqueSlug($brandName),
                'is_active' => true,
            ]);

            $brand->users()->attach($user->id, ['role' => BrandRole::Owner->value]);
            $user->update(['current_brand_id' => $brand->id]);

            // Trial automático no primeiro plano ativo
            if (config('billing.enabled')) {
                $plan = Plan::active()->first();

                if ($plan) {
                    $user->subscriptions()->create([
                        'plan_id' => $plan->id,
                        'status' => Subscription::STATUS_TRIALING,
                        'trial_ends_at' => now()->addDays((int) config('billing.trial_days', 14)),
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
