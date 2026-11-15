<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Throwable;

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
        } catch (Throwable $e) {
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
                if (empty($result['secure_url'])) {
                    throw new \RuntimeException('Cloudinary returned no secure image URL.');
                }

                return $result['secure_url'];
            } catch (Throwable $e) {
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

        if (!is_string($filePath) || !is_file($filePath)) {
            throw new \RuntimeException('The uploaded image file could not be read.');
        }

        $contents = file_get_contents($filePath);
        if ($contents === false || !Storage::disk('public')->put($destination, $contents)) {
            throw new \RuntimeException('The uploaded image could not be saved to public storage.');
        }

        return Storage::disk('public')->url($destination);
    }

    public function delete(string $imageUrl): bool
    {
        if (empty($imageUrl)) {
            return false;
        }

        $publicUrl = config('filesystems.disks.public.url', url('/storage'));
        $publicPrefix = trim((string) parse_url($publicUrl, PHP_URL_PATH), '/');
        $imagePath = rawurldecode((string) parse_url($imageUrl, PHP_URL_PATH));
        $normalizedImagePath = ltrim($imagePath, '/');

        if ($publicPrefix !== '' && str_starts_with($normalizedImagePath, $publicPrefix . '/')) {
            $relativePath = substr($normalizedImagePath, strlen($publicPrefix) + 1);
            $segments = explode('/', $relativePath);

            if ($relativePath === '' || in_array('..', $segments, true) || str_contains($relativePath, "\\")) {
                return false;
            }

            $disk = Storage::disk('public');
            return !$disk->exists($relativePath) || $disk->delete($relativePath);
        }

        $host = strtolower((string) parse_url($imageUrl, PHP_URL_HOST));
        $isCloudinaryUrl = $host === 'cloudinary.com' || str_ends_with($host, '.cloudinary.com');

        // Images from other remote providers have no object to delete here;
        // removing their database reference is still a valid gallery action.
        if (!$isCloudinaryUrl) {
            return true;
        }

        if ($this->cloudinary === null) {
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
        } catch (Throwable $e) {
            Log::error("Failed Cloudinary delete ({$imageUrl}): " . $e->getMessage());
            return false;
        }
    }
}
