<?php

namespace App\Http\Controllers;

use App\Models\EmailList;
use App\Models\EmailContact;
use App\Models\EmailListSource;
use App\Models\AnalyticsConnection;
use App\Services\Email\EmailListSyncService;
use App\Jobs\SyncEmailListSourceJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class EmailListController extends Controller
{
    public function index(Request $request)
    {
        $brandId = session('current_brand_id');

        $lists = EmailList::with('sources')
            ->forBrand($brandId)
            ->withCount('contacts')
            ->latest()
            ->get()
            ->map(fn($l) => [
                'id' => $l->id,
                'name' => $l->name,
                'description' => $l->description,
                'type' => $l->type,
                'contacts_count' => $l->contacts_count,
                'active_contacts' => $l->getActiveContactsCount(),
                'tags' => $l->tags,
                'is_active' => $l->is_active,
                'sources' => $l->sources->map(fn($s) => [
                    'id' => $s->id,
                    'type' => $s->type,
                    'sync_frequency' => $s->sync_frequency,
                    'sync_status' => $s->sync_status,
                    'last_synced_at' => $s->last_synced_at?->format('d/m/Y H:i'),
                    'records_synced' => $s->records_synced,
                ]),
                'created_at' => $l->created_at->format('d/m/Y'),
            ]);

        return Inertia::render('Email/Lists/Index', [
            'lists' => $lists,
        ]);
    }

    public function create()
    {
        $brandId = session('current_brand_id');

        // Buscar conexoes WooCommerce disponiveis
        $wcConnections = AnalyticsConnection::where('platform', 'woocommerce')
            ->where('is_active', true)
            ->where(function ($q) use ($brandId) {
                $q->where('brand_id', $brandId)->orWhereNull('brand_id');
            })
            ->get(['id', 'name', 'platform']);

        return Inertia::render('Email/Lists/Create', [
            'wcConnections' => $wcConnections,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'tags' => 'nullable|array',
        ]);

        $list = EmailList::create([
            'brand_id' => session('current_brand_id'),
            'user_id' => Auth::id(),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'tags' => $validated['tags'] ?? null,
        ]);

        return redirect()->route('email.lists.show', $list)
            ->with('success', 'Lista criada com sucesso!');
    }

    public function show(EmailList $list)
    {
        $contacts = $list->contacts()
            ->orderByDesc('email_list_contact.added_at')
            ->paginate(50)
            ->through(fn($c) => [
                'id' => $c->id,
                'email' => $c->email,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'full_name' => $c->full_name,
                'phone' => $c->phone,
                'company' => $c->company,
                'status' => $c->status,
                'source' => $c->source,
                'added_at' => $c->pivot->added_at,
            ]);

        $sources = $list->sources()->get()->map(fn($s) => [
            'id' => $s->id,
            'type' => $s->type,
            'sync_frequency' => $s->sync_frequency,
            'sync_status' => $s->sync_status,
            'sync_error' => $s->sync_error,
            'last_synced_at' => $s->last_synced_at?->format('d/m/Y H:i'),
            'records_synced' => $s->records_synced,
            'config_summary' => $this->getSourceConfigSummary($s),
        ]);

        $brandId = session('current_brand_id');
        $wcConnections = AnalyticsConnection::where('platform', 'woocommerce')
            ->where('is_active', true)
            ->where(function ($q) use ($brandId) {
                $q->where('brand_id', $brandId)->orWhereNull('brand_id');
            })
            ->get(['id', 'name', 'platform']);

        return Inertia::render('Email/Lists/Show', [
            'list' => [
                'id' => $list->id,
                'name' => $list->name,
                'description' => $list->description,
                'type' => $list->type,
                'tags' => $list->tags,
                'is_active' => $list->is_active,
                'contacts_count' => $list->contacts()->count(),
                'active_contacts' => $list->getActiveContactsCount(),
                'created_at' => $list->created_at->format('d/m/Y H:i'),
            ],
            'contacts' => $contacts,
            'sources' => $sources,
            'wcConnections' => $wcConnections,
        ]);
    }

    public function update(Request $request, EmailList $list)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'tags' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $list->update($validated);

        return back()->with('success', 'Lista atualizada.');
    }

    public function destroy(EmailList $list)
    {
        $list->delete();
        return redirect()->route('email.lists.index')
            ->with('success', 'Lista removida.');
    }

    /**
     * Adiciona contato manualmente
     */
    public function addContact(Request $request, EmailList $list)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30',
            'company' => 'nullable|string|max:255',
        ]);

        $contact = EmailContact::updateOrCreate(
            ['brand_id' => $list->brand_id, 'email' => strtolower(trim($validated['email']))],
            array_filter([
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'company' => $validated['company'] ?? null,
                'source' => 'manual',
                'subscribed_at' => now(),
            ], fn($v) => $v !== null)
        );

        $list->contacts()->syncWithoutDetaching([$contact->id => ['added_at' => now()]]);
        $list->refreshContactsCount();

        return back()->with('success', 'Contato adicionado!');
    }

    /**
     * Remove contato da lista
     */
    public function removeContact(EmailList $list, EmailContact $contact)
    {
        $list->contacts()->detach($contact->id);
        $list->refreshContactsCount();

        return back()->with('success', 'Contato removido da lista.');
    }

    /**
     * Import de arquivo CSV/XLSX
     */
    public function import(Request $request, EmailList $list, EmailListSyncService $syncService)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx|max:10240',
            'mapping' => 'nullable|array',
        ]);

        $file = $request->file('file');
        $path = $file->store('email-imports', 'local');
        $mapping = $request->input('mapping', []);

        $result = $syncService->importFromUpload($list, $path, $mapping);

        if ($result['success']) {
            $list->refreshContactsCount();
            return back()->with('success', "Importados {$result['synced']} contatos! ({$result['skipped']} já existentes)");
        }

        return back()->with('error', 'Erro na importação: ' . ($result['error'] ?? 'desconhecido'));
    }

    /**
     * Adiciona uma fonte externa
     */
    public function addSource(Request $request, EmailList $list)
    {
        $validated = $request->validate([
            'type' => 'required|in:woocommerce,mysql,google_sheets',
            'sync_frequency' => 'required|in:manual,daily,weekly,monthly',
            // WooCommerce
            'analytics_connection_id' => 'nullable|exists:analytics_connections,id',
            'min_orders' => 'nullable|integer|min:0',
            // MySQL
            'host' => 'nullable|string',
            'port' => 'nullable|integer',
            'database' => 'nullable|string',
            'table' => 'nullable|string',
            'email_column' => 'nullable|string',
            'username' => 'nullable|string',
            'password' => 'nullable|string',
            'where_clause' => 'nullable|string',
            'name_columns' => 'nullable|array',
            // Google Sheets
            'spreadsheet_id' => 'nullable|string',
            'sheet_name' => 'nullable|string',
        ]);

        $config = match ($validated['type']) {
            'woocommerce' => [
                'analytics_connection_id' => $validated['analytics_connection_id'],
                'filters' => [
                    'min_orders' => $validated['min_orders'] ?? 0,
                ],
            ],
            'mysql' => array_filter([
                'host' => $validated['host'],
                'port' => $validated['port'] ?? 3306,
                'database' => $validated['database'],
                'table' => $validated['table'],
                'email_column' => $validated['email_column'],
                'username' => $validated['username'],
                'password' => $validated['password'],
                'where_clause' => $validated['where_clause'] ?? null,
                'name_columns' => $validated['name_columns'] ?? [],
            ]),
            'google_sheets' => [
                'spreadsheet_id' => $validated['spreadsheet_id'],
                'sheet_name' => $validated['sheet_name'] ?? 'Sheet1',
                'email_column' => $validated['email_column'] ?? 'email',
                'name_columns' => $validated['name_columns'] ?? [],
            ],
        };

        $source = EmailListSource::create([
            'email_list_id' => $list->id,
            'type' => $validated['type'],
            'config' => $config,
            'sync_frequency' => $validated['sync_frequency'],
        ]);

        return back()->with('success', 'Fonte adicionada! Use "Sincronizar" para importar os contatos.');
    }

    /**
     * Sincroniza uma fonte
     */
    public function syncSource(EmailList $list, EmailListSource $source)
    {
        SyncEmailListSourceJob::dispatch($source->id);
        return back()->with('success', 'Sincronização iniciada em segundo plano.');
    }

    /**
     * Remove uma fonte
     */
    public function removeSource(EmailList $list, EmailListSource $source)
    {
        $source->delete();
        return back()->with('success', 'Fonte removida.');
    }

    private function getSourceConfigSummary(EmailListSource $source): array
    {
        $config = $source->config;
        return match ($source->type) {
            'woocommerce' => ['connection_id' => $config['analytics_connection_id'] ?? '-'],
            'mysql' => ['host' => $config['host'] ?? '-', 'database' => $config['database'] ?? '-', 'table' => $config['table'] ?? '-'],
            'google_sheets' => ['spreadsheet_id' => substr($config['spreadsheet_id'] ?? '', 0, 15) . '...'],
            default => [],
        };
    }
}
