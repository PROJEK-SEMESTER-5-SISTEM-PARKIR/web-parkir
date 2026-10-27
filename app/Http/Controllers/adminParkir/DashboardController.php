<?php

namespace App\Http\Controllers\adminParkir;

use App\Http\Controllers\Controller;
use App\Models\AccessRequest;
use App\Models\MemberSubscription;
use App\Models\ParkingSession;
use App\Models\Member;
use App\Services\SlotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $today = today();

        // Kartu ringkasan
        $stats = [
            'member_terdaftar'    => Member::count(),
            // Member aktif yang langganannya belum berakhir
            'member_berlangganan' => Member::where('is_active', true)
                ->whereHas('subscriptions', fn ($q) => $q
                    ->where('status', 'active')
                    ->whereDate('end_date', '>=', $today))
                ->count(),
            'sedang_parkir'   => ParkingSession::active()->count(),
            'masuk_hari_ini'  => ParkingSession::whereDate('entry_time', $today)->count(),
            'keluar_hari_ini' => ParkingSession::whereDate('exit_time', $today)->count(),
            'gagal_hari_ini'  => $this->failedRequests()->whereDate('requested_at', $today)->count(),
        ];

        // Slot parkir per jenis kendaraan (terisi, sisa, kapasitas)
        $slots = app(SlotService::class)->ringkasan();

        // Daftar aktivitas verifikasi terbaru
        $recentRequests = AccessRequest::with('member')
            ->latest('requested_at')
            ->limit(6)
            ->get();

        // Panel "Perlu perhatian"
        $recentFailed = $this->failedRequests()
            ->latest('requested_at')
            ->limit(3)
            ->get();

        $expiringSoon = MemberSubscription::with(['member', 'plan'])
            ->where('status', 'active')
            ->whereBetween('end_date', [$today, $today->copy()->addDays(7)])
            ->orderBy('end_date')
            ->limit(3)
            ->get();

        $expiredCount = MemberSubscription::where('status', 'active')
            ->where('end_date', '<', $today)
            ->count();

        // Status layanan AI (di-cache 15 detik supaya halaman tidak lambat)
        $aiOnline = Cache::remember('ai_service_online', 15, function () {
            try {
                return Http::timeout(1)->get('http://127.0.0.1:5000/health')->successful();
            } catch (\Throwable $e) {
                return false;
            }
        });

        return view('admin_parkir.dashboard', compact(
            'stats', 'slots', 'recentRequests', 'recentFailed', 'expiringSoon', 'expiredCount', 'aiOnline'
        ));
    }

    /** Gagal = plat TIDAK cocok ATAU wajah TIDAK cocok. */
    private function failedRequests()
    {
        return AccessRequest::query()->where(function ($q) {
            $q->where('plate_match', false)->orWhere('face_match', false);
        });
    }
}