<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // sudah dibatasi middleware role di route
    }

    public function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255', 'unique:members,email'],

            'plates'   => ['required', 'array', 'min:1'],
            'plates.*' => ['required', 'string', 'max:20', 'distinct', 'unique:vehicles,plate_number'],

            'vehicle_types'   => ['required', 'array', 'min:1'],
            'vehicle_types.*' => ['required', 'in:mobil'],

            // Foto wajah dari kamera (browser mengirim 5 frame JPEG yang wajahnya dikenali model).
            // Identitas wajah dicek ulang di PendaftaranController lewat Python /recognize.
            'photos'   => ['required', 'array', 'min:3', 'max:10'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png', 'max:4096'],

            // Langganan pertama & pembayarannya. Kunci ini yang dibaca PendaftaranController.
            'plan_id'        => ['required', 'integer'],
            'start_date'     => ['required', 'date'],
            'amount_paid'    => ['required', 'integer', 'min:0'],
            'payment_method' => ['required', 'string', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required'          => 'No. HP wajib diisi karena dipakai untuk login aplikasi.',

            'plates.*.unique'         => 'Plat nomor :input sudah terdaftar.',
            'plates.*.distinct'       => 'Ada plat nomor yang ditulis dobel.',

            'photos.required'         => 'Foto wajah wajib diambil dari kamera.',
            'photos.array'            => 'Format foto wajah tidak valid. Ambil ulang dari kamera.',
            'photos.min'              => 'Foto wajah kurang (minimal :min foto). Ambil ulang dari kamera sampai semua foto terkumpul.',
            'photos.max'              => 'Terlalu banyak foto (maksimal :max). Ambil ulang dari kamera.',
            'photos.*.image'          => 'Salah satu foto bukan gambar yang valid. Ambil ulang dari kamera.',
            'photos.*.mimes'          => 'Foto wajah harus berformat JPG atau PNG.',
            'photos.*.max'            => 'Salah satu foto terlalu besar (maksimal 4 MB).',
            'photos.*.uploaded'       => 'Upload foto gagal. Periksa batas upload server (max_file_uploads di php.ini).',

            'plan_id.required'        => 'Pilih paket langganan.',
            'start_date.required'     => 'Tanggal mulai wajib diisi.',
            'start_date.date'         => 'Tanggal mulai tidak valid.',
            'amount_paid.required'    => 'Jumlah yang dibayar wajib diisi.',
            'amount_paid.integer'     => 'Jumlah yang dibayar harus berupa angka bulat, tanpa titik atau koma.',
            'amount_paid.min'         => 'Jumlah yang dibayar tidak boleh negatif.',
            'payment_method.required' => 'Pilih metode pembayaran.',
        ];
    }
}