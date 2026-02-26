<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminDashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'users'        => User::count(),
            'users_today'  => User::whereDate('created_at', today())->count(),
            'users_7d'     => User::where('created_at', '>=', now()->subDays(7))->count(),
        ];

        return view('admin.dashboard', [
            'admin' => Auth::guard('admin')->user(),
            'stats' => $stats,
        ]);
    }
}
