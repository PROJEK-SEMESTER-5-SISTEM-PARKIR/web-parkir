<?php

namespace App\Http\Controllers\adminParkir;

use App\Exceptions\FaceEnrollmentException;
use App\Exceptions\SlotPenuhException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberRequest;
use App\Models\FaceProfile;
use App\Models\Member;
use App\Models\SubscriptionPlan;
use App\Models\Vehicle;
use App\Services\SlotService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class PendaftaranController extends Controller
{
    /** Jumlah foto minimal yang dikirim browser untuk verifikasi identitas wajah. */
    private const MIN_FRAMES = 3;

    /** Berapa persen foto harus dikenali sebagai orang yang sama (0.6 = 60%). */
    private const RASIO_COCOK = 0.6;

    public function index(): View
    {
        return view('admin_parkir.pendaftaran', [
            'plans' => SubscriptionPlan::where('is_active', true)
                ->orderBy('sort_order')
                ->get(),
            'slots' => app(SlotService::class)->ringkasan(),
        ]);
    }

    /**
     * Dipanggil browser per frame saat pengambilan foto (AJAX).
     * Meneruskan frame ke Python /recognize (YOLO) lalu menjawab:
     *   - face      : wajah terdeteksi atau tidak
     *   - accepted  : wajah dikenali sebagai salah satu kelas model dengan confidence di atas ambang
     *   - label     : nama kelas hasil tebakan
     *   - duplicate : label itu sudah dipakai member lain
     */
    public function cekWajah(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'image', 'max:4096'],
        ]);

        try {
            $hasil = $this->kenali(file_get_contents($request->file('photo')->getRealPath()));
        } catch (ConnectionException) {
            return response()->json([
                'error' => 'Layanan pengenalan wajah tidak bisa dihubungi. Pastikan Python service sedang berjalan.',
            ], 503);
        } catch (FaceEnrollmentException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        $diterima = $hasil['face'] && $hasil['label'] && $hasil['conf'] >= $this->ambang();
        $member = $diterima ? Member::where('face_label', $hasil['label'])->first() : null;

        return response()->json([
            'face' => $hasil['face'],
            'accepted' => $diterima,
            'label' => $diterima ? $hasil['label'] : null,
            'display' => $diterima ? $this->namaTampil($hasil['label']) : null,
            'confidence' => round($hasil['conf'], 3),
            'duplicate' => $member !== null,
            'name' => $member?->name,
        ]);
    }

    public function store(StoreMemberRequest $request): RedirectResponse
    {
        $data = $request->validated();

        /** @var UploadedFile[] $photos */
        $photos = array_values((array) $request->file('photos'));

        if (count($photos) < self::MIN_FRAMES) {
            return back()->withInput()->withErrors([
                'photos' => 'Foto wajah kurang. Ambil ulang dari kamera sampai semua foto terkumpul.',
            ]);
        }

        // 1. Samakan format No. HP & plat nomor, lalu cek duplikat sebelum menyentuh DB
        $phone = $this->normalizePhone((string) ($data['phone'] ?? ''));

        if (! preg_match('/^0\d{8,14}$/', $phone)) {
            return back()->withInput()->withErrors([
                'phone' => 'No. HP wajib diisi dengan benar (9–15 digit) karena dipakai untuk login aplikasi.',
            ]);
        }

        if (Member::where('phone', $phone)->exists()) {
            return back()->withInput()->withErrors(['phone' => 'No. HP ini sudah terdaftar sebagai member.']);
        }

        $plates = [];
        foreach ((array) ($data['plates'] ?? []) as $i => $plate) {
            $number = $this->normalizePlate((string) $plate);

            if ($number !== '') {
                $plates[] = [
                    'number' => $number,
                    'type' => ($data['vehicle_types'][$i] ?? 'mobil') === 'motor' ? 'motor' : 'mobil',
                ];
            }
        }

        $numbers = array_column($plates, 'number');

        if ($numbers === []) {
            return back()->withInput()->withErrors(['plates' => 'Isi minimal satu plat nomor.']);
        }

        if (count($numbers) !== count(array_unique($numbers))) {
            return back()->withInput()->withErrors(['plates' => 'Ada plat nomor yang diisi dua kali.']);
        }

        $taken = Vehicle::whereIn('plate_number', $numbers)->pluck('plate_number');

        if ($taken->isNotEmpty()) {
            return back()->withInput()->withErrors([
                'plates' => 'Plat nomor sudah terdaftar: '.$taken->map(fn ($p) => $this->formatPlate($p))->implode(', '),
            ]);
        }

        // 2. Identitas wajah: server mengenali sendiri wajah itu milik siapa (dari model YOLO).
        //    Hasil dari browser tidak dipercaya, semua foto dicek ulang di sini.
        try {
            $label = $this->tentukanIdentitas($photos);
        } catch (ConnectionException) {
            return back()->withInput()->withErrors([
                'photos' => 'Layanan pengenalan wajah tidak bisa dihubungi. Pastikan Python service sedang berjalan.',
            ]);
        } catch (FaceEnrollmentException $e) {
            return back()->withInput()->withErrors(['photos' => $e->getMessage()]);
        }

        if ($ada = Member::where('face_label', $label)->first()) {
            return back()->withInput()->withErrors([
                'photos' => 'Wajah ini sudah terdaftar sebagai '.$ada->name.'. Satu orang hanya boleh punya satu akun member.',
            ]);
        }

        // 3. Slot: kapasitas per jenis kendaraan (cek awal supaya gagal cepat)
        $perJenis = array_count_values(array_column($plates, 'type'));

        if ($pesanSlot = app(SlotService::class)->kekurangan($perJenis)) {
            return back()->withInput()->withErrors(['plates' => $pesanSlot]);
        }

        // 4. Paket & tagihan dihitung di server (angka dari browser tidak dipercaya)
        $plan = SubscriptionPlan::where('is_active', true)->find($data['plan_id']);

        if (! $plan) {
            return back()->withInput()->withErrors(['plan_id' => 'Paket tidak ditemukan atau sudah tidak aktif.']);
        }

        $total = 0;
        foreach ($plates as $plate) {
            $total += (float) ($plate['type'] === 'motor' ? $plan->price_motor : $plan->price_mobil);
        }

        // Hari terakhir ikut dihitung: mulai 10 Okt + 30 hari berakhir 8 Nov.
        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = $start->copy()->addDays($plan->duration_days - 1);

        // 5. Simpan SATU foto representatif (frame tengah) di disk privat sebagai foto profil.
        $path = $photos[intdiv(count($photos), 2)]->store('faces', 'local');
        $password = $this->generatePassword();
        $adminId = $request->user()?->id;

        try {
            $member = DB::transaction(function () use (
                $data, $phone, $plates, $path, $password, $plan, $start, $end, $total, $adminId, $perJenis, $label
            ) {
                // Cek ulang slot dengan kunci: dua pendaftaran bersamaan tidak boleh
                // sama-sama mengambil slot terakhir.
                $slot = app(SlotService::class);
                $slot->kunci();
                $slot->pastikanMuat($perJenis);

                $member = Member::create([
                    'name' => $data['name'],
                    'phone' => $phone,
                    'email' => $data['email'] ?? null,
                    'is_active' => true,
                ]);

                // Akun aplikasi & label wajah diisi langsung supaya tidak bergantung pada $fillable.
                $member->password = Hash::make($password);
                $member->must_change_password = true;
                $member->face_label = $label;   // nama kelas di model YOLO, harus unik
                $member->save();

                foreach ($plates as $plate) {
                    Vehicle::create([
                        'member_id' => $member->id,
                        'plate_number' => $plate['number'],
                        'vehicle_type' => $plate['type'],
                    ]);
                }

                FaceProfile::create([
                    'member_id' => $member->id,
                    'photo_path' => $path,
                ]);

                $subscription = $member->subscriptions()->create([
                    'subscription_plan_id' => $plan->id,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'status' => 'active',
                ]);

                $subscription->payments()->create([
                    'amount_due' => $total,
                    'amount_paid' => (int) $data['amount_paid'],
                    'method' => $data['payment_method'],
                    'paid_at' => now(),
                    'created_by' => $adminId,
                ]);

                return $member;
            });
        } catch (SlotPenuhException $e) {
            Storage::disk('local')->delete($path);

            return back()->withInput()->withErrors(['plates' => $e->getMessage()]);
        } catch (UniqueConstraintViolationException) {
            Storage::disk('local')->delete($path);

            return back()->withInput()->withErrors(['name' => 'No. HP, plat nomor, atau wajah sudah terdaftar.']);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);
            report($e);

            return back()->withInput()->withErrors(['name' => 'Gagal menyimpan data member. Silakan coba lagi.']);
        }

        // 6. Kembali ke form; kata sandi sementara hanya tampil sekali lewat flash session
        return back()->with([
            'success' => 'Member berhasil didaftarkan.',
            'created' => [
                'name' => $member->name,
                'phone' => $phone,
                'password' => $password,
                'vehicles' => array_map(fn ($p) => $this->formatPlate($p['number']), $plates),
                'plan' => $plan->name,
                'start' => $start->copy()->locale('id')->translatedFormat('j M Y'),
                'end' => $end->copy()->locale('id')->translatedFormat('j M Y'),
            ],
        ]);
    }

    /** Ambang confidence untuk menerima sebuah wajah sebagai kelas tertentu. */
    private function ambang(): float
    {
        return (float) config('services.cv.accept_conf', 0.75);
    }

    /** "Dimas-Dwi-Nugroho" -> "Dimas Dwi Nugroho" */
    private function namaTampil(string $label): string
    {
        return str_replace(['-', '_'], ' ', $label);
    }

    /**
     * Kirim satu frame ke Python /recognize (YOLO).
     *
     * @return array{face: bool, label: ?string, conf: float}
     *
     * @throws ConnectionException
     * @throws FaceEnrollmentException
     */
    private function kenali(string $isiFoto): array
    {
        $response = Http::timeout(15)
            ->attach('photo', $isiFoto, 'frame.jpg')
            ->post(config('services.cv.url', 'http://127.0.0.1:5000').'/recognize');

        if ($response->failed()) {
            throw new FaceEnrollmentException(
                $response->json('error') ?? 'Layanan pengenalan wajah tidak merespons.'
            );
        }

        $json = $response->json();

        if (! ($json['face_detected'] ?? false)) {
            return ['face' => false, 'label' => null, 'conf' => 0.0];
        }

        return [
            'face' => true,
            'label' => $json['predicted_name'] ?? null,
            'conf' => (float) ($json['confidence'] ?? 0),
        ];
    }

    /**
     * Tentukan wajah di foto-foto itu milik siapa.
     * Syarat: minimal RASIO_COCOK dari semua foto dikenali sebagai orang yang sama
     * dengan confidence di atas ambang.
     *
     * @param  UploadedFile[]  $photos
     * @return string nama kelas (face_label)
     *
     * @throws ConnectionException
     * @throws FaceEnrollmentException
     */
    private function tentukanIdentitas(array $photos): string
    {
        $hits = [];

        foreach ($photos as $foto) {
            $h = $this->kenali(file_get_contents($foto->getRealPath()));

            if ($h['face'] && $h['label'] && $h['conf'] >= $this->ambang()) {
                $hits[$h['label']] = ($hits[$h['label']] ?? 0) + 1;
            }
        }

        if ($hits === []) {
            throw new FaceEnrollmentException(
                'Wajah tidak dikenali oleh sistem. Hanya wajah yang sudah dilatih ke model yang bisa didaftarkan.'
            );
        }

        arsort($hits);
        $label = array_key_first($hits);
        $perlu = (int) ceil(count($photos) * self::RASIO_COCOK);

        if ($hits[$label] < $perlu) {
            throw new FaceEnrollmentException(
                'Wajah tidak konsisten dikenali sebagai satu orang. Ambil ulang foto dengan pencahayaan yang lebih baik.'
            );
        }

        return $label;
    }

    /** "+62 812-3456-7890" / "6281234567890" / "81234567890" -> "081234567890" */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (str_starts_with($digits, '62')) {
            return '0'.substr($digits, 2);
        }

        if (str_starts_with($digits, '8')) {
            return '0'.$digits;
        }

        return $digits;
    }

    /** "b 1234 abc" / "B-1234-ABC" -> "B1234ABC" (format simpan, cocok dengan hasil OCR) */
    private function normalizePlate(string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $plate));
    }

    /** "B1234ABC" -> "B 1234 ABC" (format tampil) */
    private function formatPlate(string $plate): string
    {
        if (preg_match('/^([A-Z]{1,2})(\d{1,4})([A-Z]{0,3})$/', $plate, $m)) {
            return trim("{$m[1]} {$m[2]} {$m[3]}");
        }

        return $plate;
    }

    /** Kata sandi acak 8 karakter tanpa huruf/angka yang mirip (0/O, 1/l/I) */
    private function generatePassword(int $length = 8): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $out;
    }
}