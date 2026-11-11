<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ProductController extends Controller
{
    /**
     * Mengambil semua paket produk internet
     */
    public function index()
    {
        try {
            $products = Product::orderBy('id', 'asc')->get();

            // Format data agar seragam dan terbaca di semua model Flutter
            $data = $products->map(function ($p) {
                $mbps = (int) ($p->kecepatan_mbps ?? $p->speed ?? 10);
                $harga = (double) ($p->harga ?? $p->price ?? 0);
                $nama = $p->nama_paket ?? $p->name ?? $p->nama ?? 'Paket Internet #' . $p->id;

                // Ambil gambar dari berbagai variasi nama kolom
                $gambar = $p->url_gambar 
                    ?? $p->gambar 
                    ?? $p->image 
                    ?? $p->foto 
                    ?? $p->image_url 
                    ?? null;

                $deskripsi = $p->deskripsi ?? $p->description ?? 'Paket internet fiber optic ultra stabil.';

                return [
                    'id'             => $p->id,
                    // Nama Paket
                    'nama_paket'     => $nama,
                    'name'           => $nama,
                    'nama'           => $nama,
                    // Kecepatan
                    'kecepatan_mbps' => $mbps,
                    'speed'          => $mbps . ' Mbps',
                    // Harga
                    'harga'          => $harga,
                    'price'          => $harga,
                    // Deskripsi
                    'deskripsi'      => $deskripsi,
                    'description'    => $deskripsi,
                    // SEMUA VARIASI KEY GAMBAR AGAR TIDAK MISMATCH DI FLUTTER:
                    'url_gambar'     => $gambar,
                    'gambar'         => $gambar,
                    'image'          => $gambar,
                    'image_url'      => $gambar,
                    'foto'           => $gambar,
                    'created_at'     => $p->created_at,
                ];
            });

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'data'    => $data,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal memuat produk: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Menyimpan paket baru
     */
    public function store(Request $request)
    {
        try {
            $namaPaket = $request->input('nama_paket') 
                ?? $request->input('nama') 
                ?? $request->input('name') 
                ?? 'Paket Baru';

            $kecepatanInput = (string) ($request->input('kecepatan_mbps') ?? $request->input('kecepatan') ?? $request->input('speed') ?? '10');
            $mbps = (int) preg_replace('/[^0-9]/', '', $kecepatanInput);
            if ($mbps <= 0) $mbps = 10;

            $harga = (int) ($request->input('harga') ?? $request->input('price') ?? 0);
            
            // Tangkap semua kemungkinan nama key gambar dari Flutter
            $urlGambar = $request->input('url_gambar') 
                ?? $request->input('gambar') 
                ?? $request->input('image') 
                ?? $request->input('image_url') 
                ?? $request->input('foto');

            $deskripsi = $request->input('deskripsi') ?? $request->input('description');

            $dataSimpan = [
                'nama_paket'     => $namaPaket,
                'kecepatan_mbps' => $mbps,
                'harga'          => $harga,
            ];

            // Cek nama kolom gambar yang tersedia di database Anda
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

            // Masukkan deskripsi jika kolomnya tersedia
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
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal simpan paket: ' . $e->getMessage(),
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

            $namaPaket = $request->input('nama_paket') 
                ?? $request->input('nama') 
                ?? $request->input('name') 
                ?? $product->nama_paket;

            $kecepatanInput = (string) ($request->input('kecepatan_mbps') ?? $request->input('kecepatan') ?? $request->input('speed') ?? $product->kecepatan_mbps);
            $mbps = (int) preg_replace('/[^0-9]/', '', $kecepatanInput);
            if ($mbps <= 0) $mbps = (int) $product->kecepatan_mbps;

            $harga = (int) ($request->input('harga') ?? $request->input('price') ?? $product->harga);
            
            // Tangkap semua kemungkinan nama key gambar yang dikirim Flutter
            $urlGambar = $request->input('url_gambar') 
                ?? $request->input('gambar') 
                ?? $request->input('image') 
                ?? $request->input('image_url') 
                ?? $request->input('foto')
                ?? $product->url_gambar 
                ?? $product->gambar 
                ?? $product->image;

            $deskripsi = $request->input('deskripsi') 
                ?? $request->input('description') 
                ?? $product->deskripsi;

            // Update ke objek model secara langsung
            $product->nama_paket = $namaPaket;
            $product->kecepatan_mbps = $mbps;
            $product->harga = $harga;

            // Simpan gambar ke kolom yang benar di tabel products
            if ($urlGambar) {
                if (Schema::hasColumn('products', 'url_gambar')) {
                    $product->url_gambar = $urlGambar;
                }
                if (Schema::hasColumn('products', 'gambar')) {
                    $product->gambar = $urlGambar;
                }
                if (Schema::hasColumn('products', 'image')) {
                    $product->image = $urlGambar;
                }
                if (Schema::hasColumn('products', 'foto')) {
                    $product->foto = $urlGambar;
                }
            }

            if (Schema::hasColumn('products', 'deskripsi')) {
                $product->deskripsi = $deskripsi;
            } elseif (Schema::hasColumn('products', 'description')) {
                $product->description = $deskripsi;
            }

            // Simpan perubahan ke MySQL
            $product->save();

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Paket berhasil diperbarui di database!',
                'data'    => $product,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal update paket: ' . $e->getMessage(),
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

            // CEK APAKAH PAKET MASIH DIGUNAKAN OLEH CUSTOMER
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
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal menghapus paket: ' . $e->getMessage(),
            ], 500);
        }
    }
}