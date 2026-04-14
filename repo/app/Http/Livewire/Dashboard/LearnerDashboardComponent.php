<?php

namespace App\Http\Livewire\Dashboard;

use App\Models\Reservation;
use App\Models\UserFavorite;
use App\Models\UserRecentView;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Learner dashboard: upcoming reservations, favorites, recent views, points.
 *
 * Scaffold state: wired to real data queries; UI is minimal shell.
 */
#[Layout('layouts.app')]
class LearnerDashboardComponent extends Component
{
    public function render()
    {
        $user = Auth::user();

        $upcomingReservations = Reservation::with(['service', 'timeSlot'])
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('time_slot_id', '!=', null)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        $favorites = UserFavorite::with(['service.category'])
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->limit(6)
            ->get();

        $recentViews = UserRecentView::with(['service.category'])
            ->where('user_id', $user->id)
            ->latest('viewed_at')
            ->limit(6)
            ->get();

        return view('livewire.dashboard.learner', compact(
            'upcomingReservations',
            'favorites',
            'recentViews',
        ))->with('pointsBalance', $user->pointsBalance());
    }
}
