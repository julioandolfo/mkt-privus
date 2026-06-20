<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Consultas públicas usadas no wizard de cadastro: CNPJ (BrasilAPI) e
 * CEP (ViaCEP). Proxy no backend para evitar CORS e centralizar cache/limites.
 * Rotas são throttled (ver routes/web.php).
 *
 * Requer acesso de saída do servidor a brasilapi.com.br e viacep.com.br.
 */
class OnboardingLookupController extends Controller
{
    public function cnpj(string $cnpj): JsonResponse
    {
        $digits = preg_replace('/\D/', '', $cnpj);

        if (strlen($digits) !== 14) {
            return response()->json(['error' => 'CNPJ inválido.'], 422);
        }

        $data = Cache::remember("cnpj:{$digits}", now()->addDay(), function () use ($digits) {
            try {
                $response = Http::timeout(10)->acceptJson()
                    ->get("https://brasilapi.com.br/api/cnpj/v1/{$digits}");

                if (!$response->successful()) {
                    return null;
                }

                $d = $response->json();

                return [
                    'cnpj' => $this->formatCnpj($digits),
                    'legal_name' => $d['razao_social'] ?? null,
                    'trade_name' => $d['nome_fantasia'] ?: ($d['razao_social'] ?? null),
                    'segment' => $d['cnae_fiscal_descricao'] ?? null,
                    'phone' => $this->joinPhone($d['ddd_telefone_1'] ?? null),
                    'cep' => $this->formatCep($d['cep'] ?? null),
                    'address_street' => $d['logradouro'] ?? null,
                    'address_number' => $d['numero'] ?? null,
                    'address_complement' => $d['complemento'] ?? null,
                    'address_neighborhood' => $d['bairro'] ?? null,
                    'address_city' => $d['municipio'] ?? null,
                    'address_state' => $d['uf'] ?? null,
                ];
            } catch (\Throwable) {
                return null;
            }
        });

        if (!$data) {
            Cache::forget("cnpj:{$digits}");

            return response()->json(['error' => 'Não foi possível consultar este CNPJ.'], 502);
        }

        return response()->json($data);
    }

    public function cep(string $cep): JsonResponse
    {
        $digits = preg_replace('/\D/', '', $cep);

        if (strlen($digits) !== 8) {
            return response()->json(['error' => 'CEP inválido.'], 422);
        }

        $data = Cache::remember("cep:{$digits}", now()->addDays(7), function () use ($digits) {
            try {
                $response = Http::timeout(10)->acceptJson()
                    ->get("https://viacep.com.br/ws/{$digits}/json/");

                if (!$response->successful() || ($response->json('erro') === true)) {
                    return null;
                }

                $d = $response->json();

                return [
                    'cep' => $this->formatCep($digits),
                    'address_street' => $d['logradouro'] ?? null,
                    'address_neighborhood' => $d['bairro'] ?? null,
                    'address_city' => $d['localidade'] ?? null,
                    'address_state' => $d['uf'] ?? null,
                ];
            } catch (\Throwable) {
                return null;
            }
        });

        if (!$data) {
            Cache::forget("cep:{$digits}");

            return response()->json(['error' => 'CEP não encontrado.'], 404);
        }

        return response()->json($data);
    }

    private function formatCnpj(string $d): string
    {
        return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $d);
    }

    private function formatCep(?string $cep): ?string
    {
        if (!$cep) {
            return null;
        }
        $d = preg_replace('/\D/', '', $cep);

        return strlen($d) === 8 ? preg_replace('/^(\d{5})(\d{3})$/', '$1-$2', $d) : $cep;
    }

    private function joinPhone(?string $phone): ?string
    {
        return $phone ? trim($phone) : null;
    }
}
