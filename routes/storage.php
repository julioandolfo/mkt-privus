<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Storage público — serve arquivos sem middleware web/session.
|
| Necessário para que APIs externas (Instagram Graph API, Facebook, LinkedIn)
| consigam baixar mídias diretamente via URL pública.
| Sem middleware web = sem Cache-Control:private, sem CSP:sandbox.
|--------------------------------------------------------------------------
*/
Route::get('/storage/{path}', function (string $path) {
    // Bloquear path traversal
    if (str_contains($path, '..')) {
        abort(403);
    }

    $fullPath = storage_path('app/public/' . $path);

    if (!file_exists($fullPath)) {
        abort(404);
    }

    $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';
    $size     = filesize($fullPath);

    return response()->stream(function () use ($fullPath) {
        readfile($fullPath);
    }, 200, [
        'Content-Type'   => $mimeType,
        'Content-Length'  => $size,
        'Cache-Control'  => 'public, max-age=31536000, immutable',
        'Accept-Ranges'  => 'bytes',
    ]);
})->where('path', '.*')->name('storage.serve');
