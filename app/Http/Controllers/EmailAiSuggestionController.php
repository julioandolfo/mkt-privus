<?php

namespace App\Http\Controllers;

use App\Models\EmailAiSuggestion;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class EmailAiSuggestionController extends Controller
{
    public function index(Request $request)
    {
        $brandId = session('current_brand_id');
        $status = $request->input('status');

        $suggestions = EmailAiSuggestion::forBrand($brandId)
            ->when($status, fn($q) => $q->where('status', $status))
            ->latest()
            ->paginate(20)
            ->through(fn($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'description' => $s->description,
                'suggested_subject' => $s->suggested_subject,
                'suggested_preview' => $s->suggested_preview,
                'target_audience' => $s->target_audience,
                'content_type' => $s->content_type,
                'status' => $s->status,
                'suggested_send_date' => $s->suggested_send_date?->format('d/m/Y'),
                'reference_data' => $s->reference_data,
                'created_at' => $s->created_at->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Email/AiSuggestions/Index', [
            'suggestions' => $suggestions,
            'currentFilter' => $status,
        ]);
    }

    public function accept(EmailAiSuggestion $suggestion)
    {
        $suggestion->accept();
        return back()->with('success', 'Sugestão aceita!');
    }

    public function reject(EmailAiSuggestion $suggestion)
    {
        $suggestion->reject();
        return back()->with('success', 'Sugestão rejeitada.');
    }

    /**
     * Cria uma campanha a partir de uma sugestão
     */
    public function createCampaign(EmailAiSuggestion $suggestion)
    {
        $suggestion->markUsed();

        return redirect()->route('email.campaigns.create', [
            'from_suggestion' => $suggestion->id,
            'subject' => $suggestion->suggested_subject,
            'name' => $suggestion->title,
        ])->with('info', 'Campanha pré-preenchida com a sugestão da IA.');
    }

    /**
     * Gerar sugestões manualmente agora
     */
    public function generate(Request $request)
    {
        $brandId = session('current_brand_id');
        $brand = Brand::find($brandId);

        if (!$brand) {
            return back()->with('error', 'Selecione uma marca primeiro.');
        }

        $service = app(\App\Services\Email\EmailAiSuggestionService::class);
        $count = $service->generateForBrand($brand);

        return back()->with('success', "Geradas {$count} sugestões de email marketing!");
    }
}
