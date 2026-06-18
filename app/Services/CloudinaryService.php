<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Exception;

class CloudinaryService
{
    protected $cloudinary;

    public function __construct()
    {
        try {
            $config = DB::select("SELECT cloud_name, api_key, api_secret FROM cloudinary_configs WHERE is_active = 1 LIMIT 1");
            
            if (!empty($config)) {
                $credentials = $config[0];
                $this->cloudinary = new Cloudinary([
                    'cloud' => [
                        'cloud_name' => $credentials->cloud_name,
                        'api_key'    => $credentials->api_key,
                        'api_secret' => $credentials->api_secret,
                    ]
                ]);
            } else {
                $this->cloudinary = null;
            }
        } catch (Exception $e) {
            Log::error("Error initializing Cloudinary from DB: " . $e->getMessage());
            $this->cloudinary = null;
        }
    }

    public function upload($file, string $folder = 'ecommerce'): string
    {
        $filePath = is_string($file) ? $file : $file->getRealPath();

        if ($this->cloudinary !== null) {
            try {
                $result = $this->cloudinary->uploadApi()->upload($filePath, [
                    'folder' => $folder
                ]);
                return $result['secure_url'];
            } catch (Exception $e) {
                Log::error("Failed Cloudinary upload ({$folder}): " . $e->getMessage() . ". Using local fallback.");
            }
        }

        $extension = 'jpg';
        if (!is_string($file) && method_exists($file, 'getClientOriginalExtension')) {
            $extension = $file->getClientOriginalExtension();
        } else {
            $pathExt = pathinfo($filePath, PATHINFO_EXTENSION);
            if ($pathExt && $pathExt !== 'tmp') {
                $extension = $pathExt;
            }
        }

        $fileName = uniqid() . '.' . $extension;
        $destination = $folder . "/" . $fileName;

        if (file_exists($filePath)) {
            Storage::disk('public')->put($destination, file_get_contents($filePath));
        }

        return url("storage/" . $folder . "/" . $fileName);
    }

    public function delete(string $imageUrl): bool
    {
        if ($this->cloudinary === null || empty($imageUrl)) {
            return false;
        }

        if (str_contains($imageUrl, 'storage/')) {
            $relativePath = str_replace(url('/storage') . '/', 'public/', $imageUrl);
            if (Storage::exists($relativePath)) {
                Storage::delete($relativePath);
                return true;
            }
            return false;
        }

        try {
            $parts = explode('/upload/', $imageUrl);
            if (count($parts) < 2) return false;
            
            $pathAfterUpload = $parts[1];
            $pathParts = explode('/', $pathAfterUpload);
            if (preg_match('/^v\d+$/', $pathParts[0])) {
                array_shift($pathParts);
            }
            
            $fullPathWithExt = implode('/', $pathParts);
            $publicId = pathinfo($fullPathWithExt, PATHINFO_DIRNAME) . '/' . pathinfo($fullPathWithExt, PATHINFO_FILENAME);
            if (str_starts_with($publicId, './')) {
                $publicId = substr($publicId, 2);
            }

            $result = $this->cloudinary->uploadApi()->destroy($publicId);
            return ($result['result'] ?? '') === 'ok';
        } catch (Exception $e) {
            Log::error("Failed Cloudinary delete ({$imageUrl}): " . $e->getMessage());
            return false;
        }
    }
}
