<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ProductController extends Controller
{
    private function safeErrorMessage(\Throwable $e, string $fallback = 'Terjadi kesalahan pada server.'): string
    {
        return config('app.debug') ? $e->getMessage() : $fallback;
    }

    private function formatProduct($p): array
    {
        $mbps = (int) ($p->kecepatan_mbps ?? $p->speed ?? 10);
        $harga = (double) ($p->harga ?? $p->price ?? 0);
        $nama = $p->nama_paket ?? $p->name ?? $p->nama ?? 'Paket Internet #' . $p->id;

        $gambar = $p->url_gambar
            ?? $p->gambar
            ?? $p->image
            ?? $p->foto
            ?? $p->image_url
            ?? null;

        $deskripsi = $p->deskripsi ?? $p->description ?? 'Paket internet fiber optic ultra stabil.';

        return [
            'id'             => $p->id,
            'nama_paket'     => $nama,
            'name'           => $nama,
            'nama'           => $nama,
            'kecepatan_mbps' => $mbps,
            'speed'          => $mbps . ' Mbps',
            'harga'          => $harga,
            'price'          => $harga,
            'deskripsi'      => $deskripsi,
            'description'    => $deskripsi,
            'url_gambar'     => $gambar,
            'gambar'         => $gambar,
            'image'          => $gambar,
            'image_url'      => $gambar,
            'foto'           => $gambar,
            'created_at'     => $p->created_at,
        ];
    }

    private function sanitizeImageUrl(?string $url): ?string
    {
        if ($url === null) return null;
        $clean = trim($url);
        if ($clean === '') return null;

        // Cegah skema berbahaya seperti javascript: atau data:
        if (preg_match('/^(javascript|vbscript|data):/i', $clean)) {
            throw new \InvalidArgumentException('URL gambar tidak valid.');
        }

        return mb_substr($clean, 0, 500);
    }

    /**
     * Mengambil semua paket produk internet
     */
    public function index()
    {
        try {
            $products = Product::orderBy('id', 'asc')->get();
            $data = $products->map(fn($p) => $this->formatProduct($p));

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'data'    => $data,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Gagal memuat produk: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal memuat produk: ' . $this->safeErrorMessage($e),
            ], 500);
        }
    }

    /**
     * Mengambil detail satu paket produk
     */
    public function show($id)
    {
        try {
            $product = Product::find($id);
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'status'  => 'error',
                    'message' => "Paket dengan ID #$id tidak ditemukan",
                ], 404);
            }

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'data'    => $this->formatProduct($product),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Gagal memuat detail produk: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal memuat produk: ' . $this->safeErrorMessage($e),
            ], 500);
        }
    }

    /**
     * Menyimpan paket baru
     */
    public function store(Request $request)
    {
        try {
            $namaPaket = mb_substr(trim((string) ($request->input('nama_paket')
                ?? $request->input('nama')
                ?? $request->input('name')
                ?? 'Paket Baru')), 0, 150);

            if ($namaPaket === '') {
                return response()->json(['success' => false, 'status' => 'error', 'message' => 'Nama paket wajib diisi.'], 422);
            }

            $kecepatanInput = (string) ($request->input('kecepatan_mbps') ?? $request->input('kecepatan') ?? $request->input('speed') ?? '10');
            $mbps = (int) preg_replace('/[^0-9]/', '', $kecepatanInput);
            if ($mbps <= 0) $mbps = 10;

            $harga = (int) ($request->input('harga') ?? $request->input('price') ?? 0);
            if ($harga < 0) {
                return response()->json(['success' => false, 'status' => 'error', 'message' => 'Harga paket tidak boleh bernilai negatif.'], 422);
            }

            $urlGambarRaw = $request->input('url_gambar')
                ?? $request->input('gambar')
                ?? $request->input('image')
                ?? $request->input('image_url')
                ?? $request->input('foto');
            $urlGambar = $this->sanitizeImageUrl($urlGambarRaw !== null ? (string) $urlGambarRaw : null);

            $deskripsi = $request->input('deskripsi') ?? $request->input('description');
            if ($deskripsi !== null) {
                $deskripsi = mb_substr(trim((string) $deskripsi), 0, 1000);
            }

            $dataSimpan = [
                'nama_paket'     => $namaPaket,
                'kecepatan_mbps' => $mbps,
                'harga'          => $harga,
            ];

            if ($urlGambar) {
                if (Schema::hasColumn('products', 'url_gambar')) {
                    $dataSimpan['url_gambar'] = $urlGambar;
                } elseif (Schema::hasColumn('products', 'gambar')) {
                    $dataSimpan['gambar'] = $urlGambar;
                } elseif (Schema::hasColumn('products', 'image')) {
                    $dataSimpan['image'] = $urlGambar;
                } elseif (Schema::hasColumn('products', 'foto')) {
                    $dataSimpan['foto'] = $urlGambar;
                }
            }

            if ($deskripsi) {
                if (Schema::hasColumn('products', 'deskripsi')) {
                    $dataSimpan['deskripsi'] = $deskripsi;
                } elseif (Schema::hasColumn('products', 'description')) {
                    $dataSimpan['description'] = $deskripsi;
                }
            }

            $product = Product::create($dataSimpan);

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Paket berhasil disimpan',
                'data'    => $product,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Gagal simpan paket: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal simpan paket: ' . $this->safeErrorMessage($e),
            ], 500);
        }
    }

    /**
     * Update / Edit paket yang sudah ada
     */
    public function update(Request $request, $id)
    {
        try {
            $product = Product::find($id);
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'status'  => 'error',
                    'message' => "Paket dengan ID #$id tidak ditemukan",
                ], 404);
            }

            $namaPaket = mb_substr(trim((string) ($request->input('nama_paket')
                ?? $request->input('nama')
                ?? $request->input('name')
                ?? $product->nama_paket)), 0, 150);

            if ($namaPaket === '') {
                return response()->json(['success' => false, 'status' => 'error', 'message' => 'Nama paket wajib diisi.'], 422);
            }

            $kecepatanInput = (string) ($request->input('kecepatan_mbps') ?? $request->input('kecepatan') ?? $request->input('speed') ?? $product->kecepatan_mbps);
            $mbps = (int) preg_replace('/[^0-9]/', '', $kecepatanInput);
            if ($mbps <= 0) $mbps = (int) $product->kecepatan_mbps;

            $harga = (int) ($request->input('harga') ?? $request->input('price') ?? $product->harga);
            if ($harga < 0) {
                return response()->json(['success' => false, 'status' => 'error', 'message' => 'Harga paket tidak boleh bernilai negatif.'], 422);
            }

            $urlGambarRaw = $request->input('url_gambar')
                ?? $request->input('gambar')
                ?? $request->input('image')
                ?? $request->input('image_url')
                ?? $request->input('foto')
                ?? $product->url_gambar
                ?? $product->gambar
                ?? $product->image;
            $urlGambar = $this->sanitizeImageUrl($urlGambarRaw !== null ? (string) $urlGambarRaw : null);

            $deskripsi = $request->input('deskripsi')
                ?? $request->input('description')
                ?? $product->deskripsi;
            if ($deskripsi !== null) {
                $deskripsi = mb_substr(trim((string) $deskripsi), 0, 1000);
            }

            $product->nama_paket = $namaPaket;
            $product->kecepatan_mbps = $mbps;
            $product->harga = $harga;

            if ($urlGambar) {
                if (Schema::hasColumn('products', 'url_gambar')) $product->url_gambar = $urlGambar;
                if (Schema::hasColumn('products', 'gambar')) $product->gambar = $urlGambar;
                if (Schema::hasColumn('products', 'image')) $product->image = $urlGambar;
                if (Schema::hasColumn('products', 'foto')) $product->foto = $urlGambar;
            }

            if (Schema::hasColumn('products', 'deskripsi')) {
                $product->deskripsi = $deskripsi;
            } elseif (Schema::hasColumn('products', 'description')) {
                $product->description = $deskripsi;
            }

            $product->save();

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Paket berhasil diperbarui di database!',
                'data'    => $product,
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Gagal update paket: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal update paket: ' . $this->safeErrorMessage($e),
            ], 500);
        }
    }

    /**
     * Hapus paket
     */
    public function destroy($id)
    {
        try {
            $product = Product::find($id);
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'status'  => 'error',
                    'message' => "Paket dengan ID #$id tidak ditemukan",
                ], 404);
            }

            if (class_exists(Customer::class)) {
                $sedangDigunakan = Customer::where('product_id', $id)->count();
                if ($sedangDigunakan > 0) {
                    return response()->json([
                        'success' => false,
                        'status'  => 'error',
                        'message' => "Paket tidak dapat dihapus karena masih digunakan oleh $sedangDigunakan pelanggan aktif!",
                    ], 400);
                }
            }

            $product->delete();

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Paket berhasil dihapus',
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Gagal menghapus paket: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal menghapus paket: ' . $this->safeErrorMessage($e),
            ], 500);
        }
    }
}