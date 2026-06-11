<?php

namespace App\Http\Controllers;

use App\Mail\BrandInvitationMail;
use App\Models\Brand;
use App\Models\BrandInvitation;
use App\Models\SystemLog;
use App\Services\Billing\UsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class BrandInvitationsController extends Controller
{
    public function __construct(private UsageService $usage) {}

    /**
     * Convida um membro para a marca (Owner/Admin apenas — via can:update,brand)
     */
    public function store(Request $request, Brand $brand): RedirectResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'role' => ['required', Rule::in(['admin', 'editor', 'viewer'])],
        ]);

        $email = strtolower($validated['email']);

        if ($brand->users()->where('email', $email)->exists()) {
            return back()->with('error', 'Este e-mail já é membro da marca.');
        }

        // Limite de assentos do plano do Owner
        $owner = $this->usage->billingOwnerFor($brand);
        if ($owner && !$this->usage->canAddMember($owner)) {
            return back()->with('error', 'O limite de usuários do plano foi atingido. Faça upgrade para convidar mais membros.');
        }

        $invitation = BrandInvitation::updateOrCreate(
            ['brand_id' => $brand->id, 'email' => $email],
            [
                'invited_by' => $request->user()->id,
                'role' => $validated['role'],
                'token' => BrandInvitation::generateToken(),
                'accepted_at' => null,
                'expires_at' => now()->addDays(7),
            ]
        );

        Mail::to($email)->send(new BrandInvitationMail($invitation));

        SystemLog::info('brands', 'invitation.sent', "Convite enviado para {$email} na marca {$brand->name}", [
            'brand_id' => $brand->id,
            'user_id' => $request->user()->id,
            'role' => $validated['role'],
        ]);

        return back()->with('success', "Convite enviado para {$email}.");
    }

    /**
     * Aceita um convite (usuário autenticado com o mesmo e-mail do convite)
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = BrandInvitation::where('token', $token)->first();

        if (!$invitation || !$invitation->isPending()) {
            return redirect()
                ->route('dashboard')
                ->with('error', 'Convite inválido ou expirado. Peça um novo convite.');
        }

        $user = $request->user();

        if (strtolower($user->email) !== strtolower($invitation->email)) {
            return redirect()
                ->route('dashboard')
                ->with('error', 'Este convite foi enviado para outro e-mail. Entre com a conta correta.');
        }

        $brand = $invitation->brand;

        if (!$brand->users()->whereKey($user->id)->exists()) {
            $brand->users()->attach($user->id, ['role' => $invitation->role()->value]);
        }

        $invitation->update(['accepted_at' => now()]);
        $user->update(['current_brand_id' => $brand->id]);

        SystemLog::info('brands', 'invitation.accepted', "{$user->email} entrou na marca {$brand->name}", [
            'brand_id' => $brand->id,
            'user_id' => $user->id,
        ]);

        return redirect()
            ->route('dashboard')
            ->with('success', "Você agora faz parte da marca {$brand->name}.");
    }

    /**
     * Revoga um convite pendente
     */
    public function destroy(Brand $brand, BrandInvitation $invitation): RedirectResponse
    {
        abort_unless($invitation->brand_id === $brand->id, 404);

        $invitation->delete();

        return back()->with('success', 'Convite revogado.');
    }
}
