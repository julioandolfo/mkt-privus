<?php

namespace App\Exceptions;

use Exception;

/**
 * Lançada quando uma ação excede os limites do plano do usuário.
 * A mensagem é segura para exibição ao usuário final.
 */
class QuotaExceededException extends Exception
{
    public function render($request)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'error' => $this->getMessage(),
                'message' => $this->getMessage(),
            ], 429);
        }

        return redirect()
            ->route('billing.index')
            ->with('error', $this->getMessage());
    }
}
