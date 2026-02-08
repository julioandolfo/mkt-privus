<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $brand = $user->getActiveBrand();

        return Inertia::render('Dashboard/Index', [
            'stats' => [
                'posts_this_month' => $brand ? $brand->posts()
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->count() : 0,
                'scheduled_posts' => $brand ? $brand->posts()
                    ->where('status', 'scheduled')
                    ->count() : 0,
                'published_posts' => $brand ? $brand->posts()
                    ->where('status', 'published')
                    ->count() : 0,
                'connected_platforms' => $brand ? $brand->socialAccounts()
                    ->where('is_active', true)
                    ->count() : 0,
            ],
        ]);
    }
}
