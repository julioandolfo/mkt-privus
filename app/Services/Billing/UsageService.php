<?php

namespace App\Services\Billing;

use App\Enums\BrandRole;
use App\Exceptions\QuotaExceededException;
use App\Models\AiUsageLog;
use App\Models\Brand;
use App\Models\EmailCampaign;
use App\Models\Plan;
use App\Models\SmsCampaign;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mede o uso e aplica os limites do plano de cada conta.
 *
 * A "conta" para fins de cobrança é o usuário Owner da marca: todo o consumo
 * (e-mails, tokens de IA, marcas, assentos) das marcas que ele possui conta
 * contra o plano dele, independentemente de qual membro executou a ação.
 *
 * Com billing desabilitado (config/billing.php) todos os checks liberam.
 */
class UsageService
{
    public function enabled(): bool
    {
        return (bool) config('billing.enabled');
    }

    public function activeSubscription(User $user): ?Subscription
    {
        return $user->subscriptions()
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING])
            ->latest('id')
            ->get()
            ->first(fn (Subscription $subscription) => $subscription->isValid());
    }

    public function planFor(User $user): ?Plan
    {
        return $this->activeSubscription($user)?->plan;
    }

    /**
     * O usuário tem acesso ao sistema? Vale assinatura própria OU ser membro
     * (assento) de alguma marca cujo Owner tem assinatura válida.
     */
    public function userHasAccess(User $user): bool
    {
        if (!$this->enabled()) {
            return true;
        }

        if ($this->activeSubscription($user)) {
            return true;
        }

        foreach ($user->brands as $brand) {
            $owner = $this->billingOwnerFor($brand);

            if ($owner && $owner->id !== $user->id && $this->activeSubscription($owner)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Usuário responsável pela cobrança de uma marca (Owner).
     */
    public function billingOwnerFor(Brand $brand): ?User
    {
        return $brand->users()
            ->wherePivot('role', BrandRole::Owner->value)
            ->orderBy('brand_user.created_at')
            ->first();
    }

    /**
     * Limite do plano para a conta. null = ilimitado.
     * Sem assinatura válida (billing habilitado) o limite é 0.
     */
    public function limitFor(User $user, string $key): ?int
    {
        if (!$this->enabled()) {
            return null;
        }

        $plan = $this->planFor($user);

        return $plan ? $plan->limit($key) : 0;
    }

    /** @return int[] IDs das marcas em que o usuário é Owner */
    public function ownedBrandIds(User $user): array
    {
        return $user->brands()
            ->wherePivot('role', BrandRole::Owner->value)
            ->pluck('brands.id')
            ->all();
    }

    // ===== CONTADORES =====

    public function brandsCount(User $user): int
    {
        return count($this->ownedBrandIds($user));
    }

    /** Membros únicos (assentos) em todas as marcas do Owner */
    public function membersCount(User $user): int
    {
        $brandIds = $this->ownedBrandIds($user);

        if (empty($brandIds)) {
            return 0;
        }

        return DB::table('brand_user')
            ->whereIn('brand_id', $brandIds)
            ->distinct()
            ->count('user_id');
    }

    public function aiTokensThisMonth(User $user): int
    {
        $brandIds = $this->ownedBrandIds($user);

        return (int) AiUsageLog::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->where(function ($query) use ($user, $brandIds) {
                $query->whereIn('brand_id', $brandIds ?: [0])
                    ->orWhere(fn ($q) => $q->whereNull('brand_id')->where('user_id', $user->id));
            })
            ->sum(DB::raw('input_tokens + output_tokens'));
    }

    public function emailsSentThisMonth(User $user): int
    {
        $brandIds = $this->ownedBrandIds($user);

        if (empty($brandIds)) {
            return 0;
        }

        return (int) EmailCampaign::withoutBrandScope()
            ->whereIn('brand_id', $brandIds)
            ->where('started_at', '>=', now()->startOfMonth())
            ->sum('total_sent');
    }

    public function smsSentThisMonth(User $user): int
    {
        $brandIds = $this->ownedBrandIds($user);

        if (empty($brandIds)) {
            return 0;
        }

        return (int) SmsCampaign::withoutBrandScope()
            ->whereIn('brand_id', $brandIds)
            ->where('started_at', '>=', now()->startOfMonth())
            ->sum('total_sent');
    }

    // ===== CHECKS =====

    public function canCreateBrand(User $user): bool
    {
        $limit = $this->limitFor($user, 'max_brands');

        return $limit === null || $this->brandsCount($user) < $limit;
    }

    public function canAddMember(User $owner): bool
    {
        $limit = $this->limitFor($owner, 'max_users');

        return $limit === null || $this->membersCount($owner) < $limit;
    }

    public function canUseAi(User $user, ?Brand $brand = null): bool
    {
        $owner = $brand ? ($this->billingOwnerFor($brand) ?? $user) : $user;
        $limit = $this->limitFor($owner, 'monthly_ai_tokens');

        return $limit === null || $this->aiTokensThisMonth($owner) < $limit;
    }

    public function canSendEmails(Brand $brand, int $count): bool
    {
        $owner = $this->billingOwnerFor($brand);

        if (!$owner) {
            return !$this->enabled();
        }

        $limit = $this->limitFor($owner, 'monthly_emails');

        return $limit === null || ($this->emailsSentThisMonth($owner) + $count) <= $limit;
    }

    public function assertCanUseAi(User $user, ?Brand $brand = null): void
    {
        if (!$this->canUseAi($user, $brand)) {
            throw new QuotaExceededException(
                'O limite mensal de uso de IA do seu plano foi atingido. Faça upgrade para continuar gerando conteúdo.'
            );
        }
    }

    public function assertCanSendEmails(Brand $brand, int $count): void
    {
        if (!$this->canSendEmails($brand, $count)) {
            throw new QuotaExceededException(
                'O limite mensal de envio de e-mails do seu plano foi atingido. Faça upgrade para continuar enviando campanhas.'
            );
        }
    }

    public function canSendSms(Brand $brand, int $count): bool
    {
        $owner = $this->billingOwnerFor($brand);

        if (!$owner) {
            return !$this->enabled();
        }

        $limit = $this->limitFor($owner, 'monthly_sms');

        return $limit === null || ($this->smsSentThisMonth($owner) + $count) <= $limit;
    }

    public function assertCanSendSms(Brand $brand, int $count): void
    {
        if (!$this->canSendSms($brand, $count)) {
            throw new QuotaExceededException(
                'O limite mensal de envio de SMS do seu plano foi atingido. Faça upgrade para continuar enviando campanhas.'
            );
        }
    }

    /**
     * Resumo de uso x limites para a página de assinatura
     */
    public function summary(User $user): array
    {
        $plan = $this->planFor($user);

        $metric = fn (string $key, int $used) => [
            'used' => $used,
            'limit' => $this->enabled() ? ($plan?->limit($key)) : null,
        ];

        return [
            'brands' => $metric('max_brands', $this->brandsCount($user)),
            'users' => $metric('max_users', $this->membersCount($user)),
            'monthly_emails' => $metric('monthly_emails', $this->emailsSentThisMonth($user)),
            'monthly_sms' => $metric('monthly_sms', $this->smsSentThisMonth($user)),
            'monthly_ai_tokens' => $metric('monthly_ai_tokens', $this->aiTokensThisMonth($user)),
        ];
    }
}
